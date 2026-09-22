<?php

declare(strict_types=1);

namespace App\Domain\Auction\Actions;

use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\Services\BidValidator;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Domain\Credit\Services\CreditLedgerService;
use App\Domain\Shared\Idempotency\IdempotencyGuard;
use App\Enums\BidStatus;
use App\Enums\CreditTransactionType;
use App\Events\BidAccepted;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Place one bid: consume the credits, record the bid, update the standing.
 *
 * THE INVARIANT THIS EXISTS TO HOLD. A bid is never recorded without the
 * credit consumption that paid for it, and credits are never consumed without
 * the bid they paid for. Both happen in one transaction, and the bid row
 * carries a NOT NULL foreign key to the credit transaction, so neither half
 * can survive alone. If anything fails -- validation, the ledger, the insert
 * -- everything rolls back and nothing happened.
 *
 * VARIABLE AMOUNTS. Exactly the credits the bid commits are consumed: a bid of
 * 150 consumes 150, not one. There is no cost per bid action anywhere in this
 * path. Under the single-highest model the bidder chooses the amount. Under the
 * cumulative model there is exactly one valid amount -- the credits that land
 * the bidder one step ahead of the leader -- which the validator works out
 * under the lock and this action does not: it receives the amount the bidder
 * confirmed and the validator either accepts that figure or refuses it, and
 * nothing here ever substitutes another.
 *
 * PERMANENTLY CONSUMED. Nothing here or anywhere else gives them back. A
 * bidder who is overtaken keeps no claim on the credits they spent, and
 * neither does the winner. The one thing consumed credits earn is a reduction
 * of this auction's Buy Now price.
 *
 * ORDER OF OPERATIONS, and why:
 *
 *   1. The idempotency guard claims the key. A retried request -- a dropped
 *      connection, a double tap, a queued job running twice -- replays the
 *      first result instead of consuming the credits a second time.
 *
 *   2. The auction row is locked. Every decision below is made against state
 *      nobody else can change until this commits: the status, the clock, the
 *      standing highest bid, and the next sequence number.
 *
 *   3. Validation runs against that locked state, never against anything the
 *      browser sent.
 *
 *   4. The credits are consumed, which locks the wallet and then its lots in
 *      id order -- continuing the lock order the ledger established.
 *
 *   5. The bid is written, referencing that consumption.
 *
 *   6. The projection is rebuilt from the bid records, and a late bid may
 *      extend the clock.
 *
 * LOCK ORDER: auction, then wallet, then credit lots. The same order
 * everywhere in this stage, so concurrent bids queue rather than deadlock.
 *
 * POT-TARGET BIDDING. When this auction carries a `pot_target_credits`
 * (docs/PLAN_POT_TARGET_BIDDING.md; null for every auction until an
 * administrator sets one), this bid may be the one that reaches it. That
 * check runs inside this action's own transaction -- one more cheap `SUM`
 * under the lock already held, paid only by auctions that actually use the
 * feature. Closing itself never happens here: this action does not decide
 * anything about closing, credit consumption, or the winner. If the target
 * was just reached, {@see self::handle()} calls {@see CloseAuction} as a
 * completely separate operation, strictly after this bid's own transaction
 * has committed -- the same entry point the clock sweep uses, with its own
 * lock, its own transaction, and its own post-commit event dispatch. Calling
 * it from inside this transaction instead would fire a closure notification
 * before the bid that triggered it was actually durable, which is exactly
 * the "dispatch inside a transaction that might still roll back" bug the
 * notification rules exist to prevent.
 */
final class PlaceBid
{
    public const OPERATION = 'auction.bid';

    public function __construct(
        private readonly IdempotencyGuard $idempotency,
        private readonly BidValidator $validator,
        private readonly CreditLedgerService $credits,
        private readonly HighestBidResolver $bids,
        private readonly AuctionClock $clock,
        private readonly AuctionLifecycle $lifecycle,
        private readonly CloseAuction $closeAuction,
    ) {}

    /**
     * @param  int  $amountCredits  Credits the bidder chose to commit.
     * @param  string  $idempotencyKey  One logical bid, however many times the
     *                                  request is delivered.
     */
    public function handle(
        Auction $auction,
        User $user,
        int $amountCredits,
        string $idempotencyKey,
    ): Bid {
        $result = $this->idempotency->execute(
            operation: self::OPERATION,
            key: $idempotencyKey,
            userId: $user->id,
            work: fn (): array => $this->record($auction, $user, $amountCredits, $idempotencyKey),
        );

        // Re-read rather than returning a cached instance: on a replay there
        // is no in-memory bid to return, and on a fresh run the projection has
        // moved on since the row was written.
        $bid = Bid::findOrFail($result['bid_id']);

        // AFTER the transaction, never inside it. A notification written
        // inside a transaction that later rolled back would tell somebody
        // about a bid that does not exist, and one that threw would take the
        // bid down with it. The event carries the id of whoever this bid
        // displaced, read before the bid landed.
        //
        // A replayed request reaches here too, which is why the subscriber
        // keys its notifications on the bid: one bid, one confirmation.
        BidAccepted::dispatch(
            $bid,
            $result['previous_highest_bid_id'] === null
                ? null
                : Bid::find($result['previous_highest_bid_id']),
            $result['extended_seconds'],
        );

        // Also after the transaction. This runs on a replayed request too --
        // IdempotencyGuard returns the same stored result either way, with no
        // way to tell a fresh run from a replay -- which is fine only because
        // CloseAuction is idempotent by construction: it re-locks, re-reads
        // the auction's actual status, and does nothing if it is already
        // closed. Calling it redundantly costs one lock cycle; not calling it
        // on a genuine retry after a dropped response would leave a
        // target-reached auction open until the next sweep.
        if ($result['pot_target_reached']) {
            $this->closeAuction->handle($auction);
        }

        return $bid;
    }

    /**
     * @return array{bid_id: int, auction_id: int, amount_credits: int, sequence: int, credit_transaction_id: int, extended_seconds: int, previous_highest_bid_id: int|null, pot_target_reached: bool}
     */
    private function record(Auction $auction, User $user, int $amountCredits, string $idempotencyKey): array
    {
        return DB::transaction(function () use ($auction, $user, $amountCredits, $idempotencyKey): array {
            $now = Carbon::now();

            // Step 1 of the lock order. Everything below reads state that is
            // now frozen against other bidders and against a Buy Now.
            $locked = $this->lifecycle->lock($auction);

            // Against the locked row, not against what the page displayed.
            $this->validator->assertValid($locked, $user, $amountCredits, $now);

            // Decided before the bid lands, from the clock as it stands.
            $extension = $this->clock->extensionFor($locked, $now);

            // Read before this bid is written, so it is genuinely the bid
            // being displaced rather than the one about to be placed.
            $previousHighest = $this->bids->highestBidForUpdate($locked);

            $sequence = $this->bids->nextSequence($locked);

            // Where this bid leaves the bidder, under the cumulative model:
            // what they had already consumed on this auction plus what this bid
            // consumes. Read under the same auction lock the validator just
            // used, so it is the very figure the bid was checked against and
            // cannot have moved since. Null under the single-highest model,
            // which ranks by the amount and has no running total to record.
            $standing = $locked->rules()->bidModel->isCumulative()
                ? $this->bids->standingOf($locked, $user->id) + $amountCredits
                : null;

            // Steps 2 and 3 of the lock order, inside the ledger: the wallet,
            // then its lots by id. The reference is the auction, so an auditor
            // reading the credit ledger alone can see what the credits went to.
            $transaction = $this->credits->consumeCredits(
                wallet: $this->credits->walletFor($user),
                amount: $amountCredits,
                type: CreditTransactionType::BidDebit,
                reference: $locked,
                description: "Bid of {$amountCredits} credits on auction #{$locked->id}.",
                metadata: [
                    'auction_id' => $locked->id,
                    'product_id' => $locked->product_id,
                    'sequence' => $sequence,
                    // Only under the cumulative model, so an auditor reading the
                    // credit ledger alone can see where the credits left the
                    // bidder without opening the bid.
                    ...($standing !== null ? ['standing_credits' => $standing] : []),
                ],
                actor: $user,
                idempotencyKey: $idempotencyKey.':credits',
            );

            // Written only now, with the credits provably gone. The NOT NULL
            // foreign key means a bid cannot exist without this row.
            $bid = new Bid;
            $bid->auction_id = $locked->id;
            $bid->user_id = $user->id;
            $bid->amount_credits = $amountCredits;
            $bid->cumulative_credits = $standing;
            $bid->sequence = $sequence;
            $bid->status = BidStatus::Accepted;
            $bid->credit_transaction_id = $transaction->id;
            $bid->idempotency_key = $idempotencyKey;
            $bid->created_at = $now;
            $bid->save();

            // Recomputed from the bid records rather than compared against the
            // previous cached value, so the projection is right even if it was
            // wrong before this bid.
            $this->bids->rebuild($locked);

            // A late bid buys everyone else more time. It does not buy the
            // bidder the auction: the winner is still the highest valid credit
            // bid whenever the clock finally stops.
            if ($extension > 0) {
                $this->lifecycle->applyExtension($locked, $extension);
            }

            // Only for an auction that actually carries a target -- null for
            // every auction until step 6 gives an administrator a field for
            // it, so this costs an ordinary bid nothing. Read under the same
            // auction lock everything above used, so it includes the bid just
            // written and cannot be a stale reading from before it.
            $potTargetReached = $locked->pot_target_credits !== null
                && $this->bids->potTotal($locked) >= $locked->pot_target_credits;

            Log::info('Bid accepted', [
                'operation' => 'auction.bid.accepted',
                'auction_id' => $locked->id,
                'bid_id' => $bid->id,
                'user_id' => $user->id,
                'amount_credits' => $amountCredits,
                'standing_credits' => $standing,
                'sequence' => $sequence,
                'credit_transaction_id' => $transaction->id,
                'highest_bid_credits' => $locked->highest_bid_credits,
                'extended_seconds' => $extension,
                'pot_target_reached' => $potTargetReached,
            ]);

            return [
                'bid_id' => $bid->id,
                'auction_id' => $locked->id,
                'amount_credits' => $amountCredits,
                'sequence' => $sequence,
                'credit_transaction_id' => $transaction->id,
                'extended_seconds' => $extension,
                'previous_highest_bid_id' => $previousHighest?->id,
                'pot_target_reached' => $potTargetReached,
            ];
        });
    }
}
