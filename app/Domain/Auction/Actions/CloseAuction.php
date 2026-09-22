<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Contracts\AuctionLossCompensation;
use App\Domain\Auction\Contracts\SettlementHandoff;
use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Events\AuctionClosed;
use App\Events\SettlementCheckoutOpened;
use App\Models\Auction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Close an auction whose clock has run out, or whose pot target has been
 * reached, and name the winner.
 *
 * TWO WAYS IN, ONE WINNER RULE. docs/PLAN_POT_TARGET_BIDDING.md adds a second,
 * earlier reason to close alongside the clock: the sum of every accepted bid
 * reaching the auction's own `pot_target_credits` (null for every auction that
 * does not use it, which is every auction today). Neither changes who wins --
 * that is always the highest valid credit bid, resolved the same way, from the
 * same records. Pot-target closing only changes *when* that moment arrives,
 * the same way the clock's extensions only ever change *when*, never *who*.
 *
 * THE WINNER IS THE HIGHEST VALID CREDIT BID. Resolved from the bid records at
 * this moment, by amount. Not the last bidder, not the most frequent, not the
 * largest total across several bids, and not whoever was leading at any
 * earlier point. A bidder who was overtaken and later bid higher wins on that
 * higher bid, and one who led the whole way and was passed at the end does
 * not.
 *
 * Ties are broken by the earliest bid at the winning amount, which is a
 * property of the query rather than a change to the rule -- "highest wins"
 * still decides, the tie-break only makes equal values name one person.
 *
 * WHICH CLOSURE REASON IS RECORDED. The pot target is checked first: it only
 * ever grows as bids land, so if it reads as reached right now it was
 * genuinely reached, whatever else is also true by the time this runs.
 * `AuctionClosureReason::PotTargetReached` is written whenever that is so;
 * `HighestBid` covers everything else -- the clock ran out, or an
 * administrator forced an early close -- exactly the meaning it has always
 * had. No historical auction's recorded reason is reinterpreted.
 *
 * NO WINNER IS NOT AN ERROR. An auction can close with nobody having bid. It
 * becomes Unsold, the reserved unit goes back to stock, and no winner is
 * recorded -- rather than being marked settled, which would claim a sale that
 * did not happen. A pot target cannot be reached with no bids: the sum of
 * nothing is always below a positive target, so this path is reached only
 * through the clock or a forced close, exactly as before.
 *
 * WHAT THIS DOES NOT DO. It does not take payment, consume credits, return
 * credits or move stock. The winner owes the auction's frozen settlement
 * amount plus applicable charges; the unit stays reserved, spoken for but not
 * yet sold, until a verified payment turns it into a sale.
 *
 * WHAT IT DOES HAND OVER. A winner leaves here with a real checkout waiting
 * for them, opened through {@see SettlementHandoff}. The deadline starts
 * running at closure, so an obligation that existed only once the winner
 * happened to visit the page would be one they were already late for.
 *
 * Idempotent by construction: it locks the auction and returns unchanged if
 * the auction is no longer open, so two sweeps running together close it once
 * -- and so does a sweep running together with the immediate check
 * {@see PlaceBid} makes after the bid that
 * crosses the target, which calls this same entry point rather than deciding
 * anything about closing itself.
 */
final class CloseAuction
{
    public function __construct(
        private readonly AuctionLifecycle $lifecycle,
        private readonly HighestBidResolver $bids,
        private readonly AuctionClock $clock,
        private readonly SettlementHandoff $settlement,
        private readonly AuctionLossCompensation $compensation,
    ) {}

    /**
     * @param  bool  $force  Close even though the clock has not run out. Used
     *                       only by tests and by an administrator's deliberate
     *                       early close; the sweep never forces.
     */
    public function handle(Auction $auction, bool $force = false, ?Carbon $now = null): Auction
    {
        $now ??= Carbon::now();

        $before = $auction->status;

        $closed = DB::transaction(function () use ($auction, $force, $now): Auction {
            $locked = $this->lifecycle->lock($auction);

            // Somebody else closed it, or a Buy Now ended it, between this
            // sweep selecting the row and reaching it. Both are ordinary.
            if (! $locked->status->acceptsBids()) {
                return $locked;
            }

            // Checked first: it is what decides which closure reason gets
            // recorded, whatever else is also true by the time this runs.
            $potReached = $this->potTargetReached($locked);

            if (! $force && ! $this->clock->hasExpired($locked, $now) && ! $potReached) {
                return $locked;
            }

            // The authoritative query, not the cached projection. The
            // projection exists so listing pages need not aggregate; deciding
            // who won is exactly the case where it must not be trusted.
            $winningBid = $this->bids->highestBid($locked);

            if ($winningBid === null) {
                return $this->closeWithoutBids($locked, $now);
            }

            $locked->winner_user_id = $winningBid->user_id;
            $locked->winning_bid_id = $winningBid->id;
            $locked->closure_reason = $potReached
                ? AuctionClosureReason::PotTargetReached
                : AuctionClosureReason::HighestBid;
            $locked->ends_at = $locked->ends_at ?? $now;

            // The deadline the frozen rules allow the winner to settle in.
            // Read from the snapshot, so a ruleset edited since has no effect.
            $locked->settlement_due_at = $now->copy()
                ->addMinutes($locked->rules()->checkoutDeadlineMinutes);

            $auction = $this->lifecycle->apply(
                $locked,
                AuctionStatus::PendingSettlement,
                // What actually decided it, by the model this auction was frozen
                // with: a single bid's size, or the total committed.
                $locked->rules()->bidModel->closingNote($winningBid->rankingValue()),
                null,
            );

            // Inside the same transaction, with the row still locked: an
            // auction that closed with a winner and no obligation to pay would
            // be a debt nobody could settle.
            $orderId = $this->settlement->openFor($auction);

            // Everyone else bid and lost; their purchased credits' value goes
            // back as Store Wallet credit. The winner keeps none -- they won,
            // and their credits bought them the product. Idempotent by key, so
            // a second sweep closing the same auction issues nothing twice.
            $this->compensation->compensateLosers($auction, $winningBid->user_id);

            Log::info('Auction closed with a winner', [
                'operation' => 'auction.close',
                'auction_id' => $auction->id,
                'closure_reason' => $auction->closure_reason?->value,
                'winner_rule' => $auction->winnerRule(),
                'winner_user_id' => $winningBid->user_id,
                'winning_bid_id' => $winningBid->id,
                'winning_amount_credits' => $winningBid->amount_credits,
                // The figure that ranked them first: their total under the
                // cumulative model, which is not what any one bid consumed.
                'winning_standing_credits' => $winningBid->rankingValue(),
                // The credits are gone; this is what the winner owes in money.
                'settlement_amount_minor' => $auction->settlement_amount_minor,
                'settlement_due_at' => $auction->settlement_due_at?->toIso8601String(),
                'settlement_order_id' => $orderId,
            ]);

            return $auction;
        });

        // AFTER the transaction commits, never inside it. Telling people about
        // a closure that later rolled back would be worse than telling them
        // late, and a message that threw would take the closure with it.
        //
        // Only when this call is the one that closed it: a second sweep
        // arriving on an already-closed auction returns it unchanged and must
        // not tell everybody a second time.
        if ($before->acceptsBids() && ! $closed->status->acceptsBids()) {
            AuctionClosed::dispatch($closed);

            $settlement = $closed->settlementOrder;

            if ($settlement !== null) {
                SettlementCheckoutOpened::dispatch($settlement);
            }
        }

        return $closed;
    }

    /**
     * Nobody bid. Release the unit and say so plainly.
     */
    private function closeWithoutBids(Auction $auction, Carbon $now): Auction
    {
        $this->lifecycle->releaseUnit($auction, null, 'Auction closed with no bids.');

        $auction->closure_reason = AuctionClosureReason::NoBids;
        $auction->ends_at = $auction->ends_at ?? $now;

        $closed = $this->lifecycle->apply(
            $auction,
            AuctionStatus::Unsold,
            'Closed on the clock with no bids.',
            null,
        );

        Log::info('Auction closed without bids', [
            'operation' => 'auction.close',
            'auction_id' => $closed->id,
            'winner_user_id' => null,
        ]);

        return $closed;
    }

    /**
     * Whether the pot -- every accepted bid on this auction, summed -- has
     * reached the auction's own target.
     *
     * False for every auction without one, which is every auction until an
     * administrator sets `pot_target_credits` on it (docs/PLAN_POT_TARGET_BIDDING.md,
     * step 6). Denominated in Credits throughout (D-10): no conversion to
     * money anywhere in this comparison.
     */
    private function potTargetReached(Auction $auction): bool
    {
        if ($auction->pot_target_credits === null) {
            return false;
        }

        return $this->bids->potTotal($auction) >= $auction->pot_target_credits;
    }
}
