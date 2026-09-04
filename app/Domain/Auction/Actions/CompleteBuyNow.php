<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Exceptions\BuyNowUnavailable;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\Auction\ValueObjects\BuyNowQuote;
use App\Domain\Shared\Idempotency\IdempotencyGuard;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * A Buy Now purchase has completed. End the auction and sell the unit.
 *
 * THIS IS THE POINT OF NO RETURN, NOT THE BUTTON. A click, a checkout
 * screen, a redirect to the payment provider and a payment attempt all leave
 * the auction running and every bid still valid. Only a payment confirmed by
 * the server reaches this action, and only this action ends the auction.
 * Terminating it any earlier would let an abandoned checkout kill a live
 * auction that other people are still bidding in.
 *
 * NOTHING CALLS THIS YET, AND THAT IS DELIBERATE. Taking the Buy Now payment
 * through Paystack belongs to a later stage. Wiring a customer button straight
 * to this action would be fabricating a payment success, so the customer
 * interface quotes the price and stops there. What exists here is the domain
 * and concurrency architecture that stage will attach to.
 *
 * THE FIRST COMPLETED PURCHASE WINS. Every attempt locks the auction row
 * before it looks at the status. The first one through changes it to Settled
 * and commits; the second blocks on that lock, then reads the row the first
 * left behind and is refused. Checking the status without the lock -- or in
 * the browser -- would let both believe they had bought the product.
 *
 * THE HIGHEST BIDDER DOES NOT WIN. However far ahead they are, a completed
 * Buy Now ends the auction and the product goes to the buyer. `winner_user_id`
 * stays null, the buyer is recorded in `buy_now_user_id`, and the closure
 * reason says `buy_now` -- a database constraint refuses any row that claims
 * both. Bidders keep nothing back: their credits were consumed and remain so.
 *
 * LOCK ORDER: auction, then product. The same order as everywhere else in this
 * stage, which is what lets a Buy Now and a bid race safely.
 *
 * PRICED ONCE, WHEN THERE IS AN ORDER. A caller that already froze a price --
 * a checkout does exactly that -- passes the quote it agreed with the customer,
 * and that is what the payment is checked against. Re-pricing here would
 * compare a payment made against yesterday's figure to today's, and refuse a
 * payment that was entirely correct when it was made. Without an agreed quote
 * the auction is priced fresh, which is the right behaviour for a caller that
 * has not committed to a figure yet.
 */
final class CompleteBuyNow
{
    public const OPERATION = 'auction.buy_now';

    public function __construct(
        private readonly IdempotencyGuard $idempotency,
        private readonly AuctionLifecycle $lifecycle,
        private readonly BuyNowPricer $pricer,
    ) {}

    /**
     * @param  Money  $amountPaid  What the buyer actually paid, as confirmed
     *                             by the payment provider. Checked against the
     *                             price this purchase was agreed at.
     * @param  string  $idempotencyKey  One purchase, however many times its
     *                                  confirmation is delivered.
     * @param  BuyNowQuote|null  $agreedQuote  The price frozen when the
     *                                         checkout was created. Supplied
     *                                         whenever an order exists, so the
     *                                         payment is judged against what
     *                                         the customer actually agreed to.
     * @return Auction The ended auction.
     */
    public function handle(
        Auction $auction,
        User $buyer,
        Money $amountPaid,
        string $idempotencyKey,
        ?BuyNowQuote $agreedQuote = null,
    ): Auction {
        $result = $this->idempotency->execute(
            operation: self::OPERATION,
            key: $idempotencyKey,
            userId: $buyer->id,
            work: fn (): array => $this->terminate($auction, $buyer, $amountPaid, $agreedQuote),
        );

        return Auction::findOrFail($result['auction_id']);
    }

    /**
     * @return array{auction_id: int, buyer_id: int, payable_minor: int, discount_minor: int, eligible_credits: int}
     */
    private function terminate(
        Auction $auction,
        User $buyer,
        Money $amountPaid,
        ?BuyNowQuote $agreedQuote,
    ): array {
        return DB::transaction(function () use ($auction, $buyer, $amountPaid, $agreedQuote): array {
            // The serialization point. Everything below is decided against a
            // row nobody else can change until this commits.
            $locked = $this->lifecycle->lock($auction);

            $this->assertStillAvailable($locked);

            // The price this purchase was agreed at. When a checkout froze one
            // it is authoritative: the customer paid that figure and it is
            // what their payment must be judged against. Bidding more after
            // opening a checkout does not retroactively change what was
            // already agreed and paid.
            //
            // With no agreed price -- a caller with no order behind it -- the
            // auction is priced here, against the locked row and this buyer's
            // own bids rather than against anything a screen displayed.
            $quote = $agreedQuote ?? $this->pricer->quote($locked, $buyer);

            if (! $amountPaid->equals($quote->payable)) {
                throw BuyNowUnavailable::because(
                    "The amount paid ({$amountPaid->format()}) does not match this auction's "
                    ."Buy Now price for this buyer ({$quote->payable->format()})."
                );
            }

            // Step 2 of the lock order. Turns this auction's own reservation
            // into a sale, so exactly one unit leaves stock rather than two.
            $this->lifecycle->sellUnit(
                $locked,
                $buyer,
                "Bought outright through auction #{$locked->id}.",
            );

            $now = Carbon::now();

            $locked->buy_now_ended_at = $now;
            $locked->buy_now_user_id = $buyer->id;
            $locked->buy_now_eligible_credits = $quote->eligibleCredits;
            $locked->buy_now_discount_minor = $quote->discount->minor;
            $locked->buy_now_payable_minor = $quote->payable->minor;

            // Recorded distinctly, so this ending can never be read later as a
            // normal highest-bid settlement.
            $locked->closure_reason = AuctionClosureReason::BuyNow;
            $locked->settled_at = $now;

            // `ends_at` is deliberately left alone. It is the time the auction
            // was scheduled to end, and overwriting it would lose that -- the
            // moment it actually stopped is `buy_now_ended_at`, and the two are
            // different facts worth keeping apart.

            $ended = $this->lifecycle->apply(
                $locked,
                AuctionStatus::Settled,
                "Bought outright for {$quote->payable->format()}"
                    .($quote->hasDiscount()
                        ? " after a {$quote->discount->format()} discount for {$quote->eligibleCredits} consumed bid credits."
                        : '.'),
                $buyer,
            );

            Log::info('Auction ended by a completed Buy Now', [
                'operation' => 'auction.buy_now.completed',
                'auction_id' => $ended->id,
                'buyer_id' => $buyer->id,
                'list_price_minor' => $quote->listPrice->minor,
                'eligible_credits' => $quote->eligibleCredits,
                'discount_minor' => $quote->discount->minor,
                'payable_minor' => $quote->payable->minor,
                // Stated in the log because it is the surprising part: the
                // leading bidder did not win, and their credits are still gone.
                'standing_highest_bid_credits' => $ended->highest_bid_credits,
                'winner_user_id' => null,
            ]);

            return [
                'auction_id' => $ended->id,
                'buyer_id' => $buyer->id,
                'payable_minor' => $quote->payable->minor,
                'discount_minor' => $quote->discount->minor,
                'eligible_credits' => $quote->eligibleCredits,
            ];
        });
    }

    /**
     * Refuse anything that is no longer a live opportunity.
     *
     * Read from the locked row. Between a buyer opening the page and their
     * payment confirming, the auction may have closed on the clock or been
     * bought by someone else, and both must refuse rather than override.
     */
    private function assertStillAvailable(Auction $auction): void
    {
        if (! $auction->rules()->buyNowEnabled) {
            throw BuyNowUnavailable::disabledByRules();
        }

        if ($auction->endedByBuyNow()) {
            throw BuyNowUnavailable::alreadyBought();
        }

        if (! $auction->status->acceptsBuyNow()) {
            throw BuyNowUnavailable::auctionEnded();
        }
    }
}
