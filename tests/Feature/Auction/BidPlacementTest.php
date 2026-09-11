<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\PlaceBid;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Auction\Exceptions\HighestBidMutationForbidden;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Enums\BidStatus;
use App\Enums\CreditTransactionType;
use App\Enums\UserStatus;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Bid;
use App\Models\CreditLotConsumption;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Database\QueryException;

/*
 * Placing a bid: the amount is variable, the credits are really consumed, and
 * neither half can exist without the other.
 */

beforeEach(function (): void {
    $this->lifecycle = app(AuctionLifecycle::class);
    $this->bids = app(HighestBidResolver::class);
});

// -------------------------------------------------------- Variable amounts

it('consumes exactly the credits the bid commits', function (int $amount): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    $bid = placeBid($auction, $user, $amount);

    expect($bid->amount_credits)->toBe($amount)
        // Not one credit per bid. The amount the bidder chose, exactly.
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(1_000 - $amount);
})->with([20, 50, 100, 150]);

it('accepts a sequence of different amounts from the same auction', function (): void {
    $auction = liveAuction();
    $a = bidder(500);
    $b = bidder(500);

    placeBid($auction, $a, 20);
    placeBid($auction, $b, 50);
    placeBid($auction, $a, 100);
    placeBid($auction, $b, 150);

    expect($auction->fresh()->bid_count)->toBe(4)
        // Ordered by sequence explicitly. An unordered query has no defined
        // order -- this previously read back in insertion order only because
        // of which index MySQL happened to scan, and it changed the moment
        // `bids_highest_bid_index` became descending. `sequence` is the column
        // that makes bid order a fact rather than an accident: it is allocated
        // under the auction row lock and carries a unique index.
        ->and(Bid::orderBy('sequence')->pluck('amount_credits')->all())->toBe([20, 50, 100, 150])
        // Four bids, 320 credits between them: nothing charged a flat rate.
        ->and((int) Bid::sum('amount_credits'))->toBe(320);
});

it('never treats one bid as one credit', function (): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    placeBid($auction, $user, 150);

    expect(creditWalletFor($user)->fresh()->balance)->toBe(850)
        ->and(creditWalletFor($user)->fresh()->balance)->not->toBe(999);
});

// ------------------------------------------------------ The audit chain

it('links the bid to the credit transaction that paid for it', function (): void {
    $auction = liveAuction();
    $user = bidder(500);

    $bid = placeBid($auction, $user, 150);
    $transaction = $bid->creditTransaction;

    expect($transaction)->not->toBeNull()
        ->and($transaction->type)->toBe(CreditTransactionType::BidDebit)
        ->and($transaction->amount)->toBe(-150)
        // The credit ledger points back at the auction, so an auditor reading
        // it alone can see what the credits went to.
        ->and($transaction->reference_type)->toBe(Auction::class)
        ->and($transaction->reference_id)->toBe($auction->id);
});

it('records which lots the bid credits came out of', function (): void {
    $auction = liveAuction();
    $user = bidder(500);

    $bid = placeBid($auction, $user, 150);

    $consumptions = CreditLotConsumption::where('credit_transaction_id', $bid->credit_transaction_id)->get();

    // The chain an auditor follows to answer "exactly which credits did this
    // user spend bidding on this auction".
    expect($consumptions)->not->toBeEmpty()
        ->and((int) $consumptions->sum('amount'))->toBe(150);
});

it('leaves the wallet projection agreeing with the ledger', function (): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    placeBid($auction, $user, 150);
    placeBid($auction, $user, 200);

    $wallet = creditWalletFor($user)->fresh();
    $ledgerSum = (int) CreditTransaction::where('credit_wallet_id', $wallet->id)->sum('amount');

    expect($wallet->balance)->toBe(650)->toBe($ledgerSum);
});

// ------------------------------------------ Neither half without the other

it('records no bid when the credits cannot be consumed', function (): void {
    $auction = liveAuction();
    $user = bidder(50);

    expect(fn (): Bid => placeBid($auction, $user, 500))
        ->toThrow(BidRejected::class);

    expect(Bid::count())->toBe(0)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(50)
        ->and($auction->fresh()->bid_count)->toBe(0);
});

it('consumes no credits when the bid is refused for any other reason', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withBidRules(minimum: 100)->create();
    $auction = liveAuction(ruleset: $ruleset);
    $user = bidder(1_000);

    expect(fn (): Bid => placeBid($auction, $user, 20))
        ->toThrow(BidRejected::class, 'below this auction');

    // Validation and consumption share a transaction, so a refusal leaves
    // nothing behind at all.
    expect(creditWalletFor($user)->fresh()->balance)->toBe(1_000)
        ->and(CreditTransaction::where('type', CreditTransactionType::BidDebit)->count())->toBe(0)
        ->and(Bid::count())->toBe(0);
});

it('cannot record a bid without a credit transaction', function (): void {
    $auction = liveAuction();
    $user = bidder();

    // The foreign key is NOT NULL, so the invariant holds below the
    // application as well as inside it.
    expect(fn () => DB::table('bids')->insert([
        'auction_id' => $auction->id,
        'user_id' => $user->id,
        'amount_credits' => 100,
        'sequence' => 1,
        'status' => 'accepted',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

// --------------------------------------------------------- Credits are gone

it('does not give a losing bidder their credits back', function (): void {
    $auction = liveAuction();
    $loser = bidder(500);
    $winner = bidder(500);

    placeBid($auction, $loser, 100);
    placeBid($auction, $winner, 200);

    app(CloseAuction::class)->handle($auction, force: true);

    expect($auction->fresh()->winner_user_id)->toBe($winner->id)
        // The loser's 100 credits are gone. Losing does not refund them.
        ->and(creditWalletFor($loser)->fresh()->balance)->toBe(400)
        // And neither does winning.
        ->and(creditWalletFor($winner)->fresh()->balance)->toBe(300);
});

it('posts no refund transaction of any kind when an auction closes', function (): void {
    $auction = liveAuction();
    $user = bidder(500);

    placeBid($auction, $user, 150);
    app(CloseAuction::class)->handle($auction, force: true);

    expect(CreditTransaction::whereIn('type', [
        CreditTransactionType::Refund,
        CreditTransactionType::Reversal,
    ])->count())->toBe(0);
});

// ------------------------------------------------------------- Bid rules

it('refuses a bid below the auction minimum', function (): void {
    $auction = liveAuction(ruleset: AuctionRuleset::factory()->active()->withBidRules(minimum: 20)->create());

    expect(fn (): Bid => placeBid($auction, bidder(), 19))
        ->toThrow(BidRejected::class, 'minimum of 20 credits');
});

it('accepts a bid exactly at the minimum', function (): void {
    $auction = liveAuction(ruleset: AuctionRuleset::factory()->active()->withBidRules(minimum: 20)->create());

    expect(placeBid($auction, bidder(), 20)->amount_credits)->toBe(20);
});

it('refuses a bid that does not clear the increment', function (): void {
    $auction = liveAuction(
        ruleset: AuctionRuleset::factory()->active()->withoutThrottle()->withBidRules(minimum: 20, increment: 10)->create()
    );

    placeBid($auction, bidder(), 100);

    expect(fn (): Bid => placeBid($auction, bidder(), 105))
        ->toThrow(BidRejected::class, 'next valid bid is 110 credits');
});

it('accepts a bid that clears the increment', function (): void {
    $auction = liveAuction(
        ruleset: AuctionRuleset::factory()->active()->withoutThrottle()->withBidRules(minimum: 20, increment: 10)->create()
    );

    placeBid($auction, bidder(), 100);

    expect(placeBid($auction, bidder(), 150)->amount_credits)->toBe(150);
});

/*
 * An unset rule is not a rule of one. Nothing may invent a floor the business
 * never chose.
 */
it('applies no floor at all when no bid rules are configured', function (): void {
    $auction = liveAuction();

    expect($auction->rules()->minimumBidCredits)->toBeNull()
        ->and($auction->rules()->smallestValidBid())->toBeNull()
        ->and(placeBid($auction, bidder(), 1)->amount_credits)->toBe(1);
});

it('refuses a bidder raising their own leading bid when the rules forbid it', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create(['allow_bid_increase' => false]);
    $auction = liveAuction(ruleset: $ruleset);
    $user = bidder(1_000);

    placeBid($auction, $user, 100);

    expect(fn (): Bid => placeBid($auction, $user, 200))
        ->toThrow(BidRejected::class, 'does not allow raising your own bid');
});

it('lets a bidder raise their bid once someone has overtaken them', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create(['allow_bid_increase' => false]);
    $auction = liveAuction(ruleset: $ruleset);
    $a = bidder(1_000);
    $b = bidder(1_000);

    placeBid($auction, $a, 100);
    placeBid($auction, $b, 200);

    // The rule stops you bidding against yourself while you are already
    // leading, not from competing once you have been passed.
    expect(placeBid($auction, $a, 300)->amount_credits)->toBe(300);
});

it('allows a bidder to raise their own bid when the rules permit it', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create(['allow_bid_increase' => true]);
    $auction = liveAuction(ruleset: $ruleset);
    $user = bidder(1_000);

    placeBid($auction, $user, 100);

    expect(placeBid($auction, $user, 200)->amount_credits)->toBe(200);
});

it('refuses a bid of nothing or less', function (int $amount): void {
    $auction = liveAuction();

    expect(fn (): Bid => placeBid($auction, bidder(), $amount))
        ->toThrow(BidRejected::class, 'at least 1 credit');
})->with([0, -1, -150]);

it('enforces the bid interval when one is configured', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create(['minimum_bid_interval_ms' => 60_000]);
    $auction = liveAuction(ruleset: $ruleset);
    $user = bidder(1_000);

    placeBid($auction, $user, 100);

    expect(fn (): Bid => placeBid($auction, $user, 200))
        ->toThrow(BidRejected::class, 'Bids are limited');
});

it('does not apply one bidder throttle to another bidder', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create(['minimum_bid_interval_ms' => 60_000]);
    $auction = liveAuction(ruleset: $ruleset);

    placeBid($auction, bidder(), 100);

    // The throttle exists to stop one person hammering the endpoint, not to
    // stop a busy auction from being busy.
    expect(placeBid($auction, bidder(), 200)->amount_credits)->toBe(200);
});

it('does not let a rejected bid restart the bid interval', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create(['minimum_bid_interval_ms' => 60_000]);
    $auction = liveAuction(ruleset: $ruleset);
    $user = bidder(1_000);

    placeBid($auction, $user, 100);

    // Rejected for a reason of its own, so not because of the cooldown, and
    // leaving no row behind: a rejected bid never becomes "the last bid".
    expect(fn (): Bid => placeBid($auction, $user, 0))
        ->toThrow(BidRejected::class, 'at least 1 credit');

    // The interval still runs from the one accepted bid, not from the
    // rejection that happened in between.
    expect(fn (): Bid => placeBid($auction, $user, 200))
        ->toThrow(BidRejected::class, 'Bids are limited');
});

// ------------------------------------------------- Cooldown boundaries

/*
 * The timestamp the interval is measured from is stored to the second, so a
 * test must stay clear of the millisecond edge: 59s of a 60s interval is
 * guaranteed below it however the stored second landed, and 60s is guaranteed
 * at or above it.
 */
it('refuses a bid just before the interval elapses', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create(['minimum_bid_interval_ms' => 60_000]);
    $auction = liveAuction(ruleset: $ruleset);
    $user = bidder(1_000);

    placeBid($auction, $user, 100);

    $this->travel(59)->seconds();

    expect(fn (): Bid => placeBid($auction, $user, 200))
        ->toThrow(BidRejected::class, 'Bids are limited');
});

it('accepts a bid exactly when the interval elapses', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create(['minimum_bid_interval_ms' => 60_000]);
    $auction = liveAuction(ruleset: $ruleset);
    $user = bidder(1_000);

    placeBid($auction, $user, 100);

    $this->travel(60)->seconds();

    expect(placeBid($auction, $user, 200)->amount_credits)->toBe(200);
});

it('accepts a bid once the interval has passed', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create(['minimum_bid_interval_ms' => 60_000]);
    $auction = liveAuction(ruleset: $ruleset);
    $user = bidder(1_000);

    placeBid($auction, $user, 100);

    $this->travel(61)->seconds();

    expect(placeBid($auction, $user, 200)->amount_credits)->toBe(200);
});

/*
 * Two bids from the same user inside one interval must land as one bid with
 * one credit consumption. The per-user check runs under the auction row lock,
 * so the "concurrent" case -- the second request arriving while the first is
 * still in flight -- resolves to this same state after the first commits.
 */
it('consumes credits exactly once when the same user bids twice inside one interval', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->create(['minimum_bid_interval_ms' => 3000]);
    $auction = liveAuction(ruleset: $ruleset);
    $user = bidder(1_000);

    placeBid($auction, $user, 100);

    expect(fn (): Bid => placeBid($auction, $user, 200))
        ->toThrow(BidRejected::class, 'Bids are limited');

    expect(creditWalletFor($user)->fresh()->balance)->toBe(900)
        ->and(Bid::where('auction_id', $auction->id)->where('user_id', $user->id)->count())->toBe(1);
});

// ------------------------------------------------------- Auction state

it('refuses a bid on a draft auction', function (): void {
    $auction = Auction::factory()->create();

    expect(fn (): Bid => placeBid($auction, bidder(), 100))
        ->toThrow(BidRejected::class, 'not accepting bids');
});

it('refuses a bid on a scheduled auction that has not opened', function (): void {
    $auction = Auction::factory()->scheduled()->create();

    expect(fn (): Bid => placeBid($auction, bidder(), 100))
        ->toThrow(BidRejected::class, 'not accepting bids');
});

it('refuses a bid on a closed auction', function (): void {
    $auction = liveAuction();
    app(CloseAuction::class)->handle($auction, force: true);

    expect(fn (): Bid => placeBid($auction, bidder(), 100))
        ->toThrow(BidRejected::class);
});

it('refuses a bid on a cancelled auction', function (): void {
    $auction = liveAuction();
    $this->lifecycle->cancel($auction, 'Withdrawn.');

    expect(fn (): Bid => placeBid($auction, bidder(), 100))
        ->toThrow(BidRejected::class, 'not accepting bids');
});

/*
 * The gap between an auction's clock running out and the sweep noticing. The
 * status still says Live, and a bid arriving in that window must still be
 * refused -- the timestamps are the authority, not the column.
 */
it('refuses a bid after the clock has run out even while the status says live', function (): void {
    $auction = Auction::factory()->expired()->create();

    expect($auction->status->acceptsBids())->toBeTrue()
        ->and(fn (): Bid => placeBid($auction, bidder(), 100))
        ->toThrow(BidRejected::class, 'already ended');
});

it('refuses a bid from a suspended account', function (): void {
    $auction = liveAuction();
    $user = bidder(500);

    // Set directly: status is not fillable on User, because an account's
    // standing is never changed by mass assignment from request input.
    $user->status = UserStatus::Suspended;
    $user->save();

    expect(fn (): Bid => placeBid($auction, $user->fresh(), 100))
        ->toThrow(BidRejected::class, 'cannot place bids');
});

it('refuses a bid from an account without permission to bid', function (): void {
    $auction = liveAuction();
    // A user holding no role at all. Revoking the permission from a customer
    // would not work: they hold it through the role rather than directly, so
    // the revoke would remove nothing and the test would pass by accident.
    seedPermissions();
    $user = User::factory()->create();
    grantCredits($user, 500);

    expect($user->can('bids.place'))->toBeFalse()
        ->and(fn (): Bid => placeBid($auction, $user, 100))
        ->toThrow(BidRejected::class, 'not permitted to bid');
});

// -------------------------------------------------------------- Sequence

it('numbers bids in the order they were accepted', function (): void {
    $auction = liveAuction();

    placeBid($auction, bidder(), 100);
    placeBid($auction, bidder(), 200);
    placeBid($auction, bidder(), 300);

    expect(Bid::orderBy('id')->pluck('sequence')->all())->toBe([1, 2, 3]);
});

it('refuses a duplicate sequence within one auction', function (): void {
    $auction = liveAuction();
    $bid = placeBid($auction, bidder(), 100);

    expect(fn () => DB::table('bids')->insert([
        'auction_id' => $auction->id,
        'user_id' => $bid->user_id,
        'amount_credits' => 200,
        'sequence' => 1,
        'status' => 'accepted',
        'credit_transaction_id' => $bid->credit_transaction_id,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('numbers each auction independently', function (): void {
    $first = liveAuction();
    $second = liveAuction();

    placeBid($first, bidder(), 100);
    $bid = placeBid($second, bidder(), 100);

    expect($bid->sequence)->toBe(1);
});

// ------------------------------------------------------------- Append-only

it('refuses to change a bid once accepted', function (): void {
    $auction = liveAuction();
    $bid = placeBid($auction, bidder(), 100);

    expect(fn () => DB::table('bids')->where('id', $bid->id)->update(['amount_credits' => 9_999]))
        ->toThrow(QueryException::class, 'append-only');

    expect($bid->fresh()->amount_credits)->toBe(100);
});

it('refuses to delete a bid', function (): void {
    $auction = liveAuction();
    $bid = placeBid($auction, bidder(), 100);

    expect(fn () => DB::table('bids')->where('id', $bid->id)->delete())
        ->toThrow(QueryException::class, 'append-only');

    expect(Bid::count())->toBe(1);
});

it('records every accepted bid as accepted', function (): void {
    $auction = liveAuction();

    placeBid($auction, bidder(), 100);

    expect(Bid::first()?->status)->toBe(BidStatus::Accepted);
});

it('refuses a bid amount of zero at the database level', function (): void {
    $auction = liveAuction();
    $bid = placeBid($auction, bidder(), 100);

    expect(fn () => DB::table('bids')->insert([
        'auction_id' => $auction->id,
        'user_id' => $bid->user_id,
        'amount_credits' => 0,
        'sequence' => 2,
        'status' => 'accepted',
        'credit_transaction_id' => $bid->credit_transaction_id,
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

// ------------------------------------------------------------ Idempotency

it('consumes the credits once when the same request arrives twice', function (): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    $first = placeBid($auction, $user, 150, 'retry-me');
    $second = placeBid($auction, $user, 150, 'retry-me');

    expect($second->id)->toBe($first->id)
        ->and(Bid::count())->toBe(1)
        // 150 consumed, not 300.
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(850)
        ->and($auction->fresh()->bid_count)->toBe(1);
});

it('treats a different key as a different bid', function (): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    placeBid($auction, $user, 150, 'first');
    placeBid($auction, $user, 200, 'second');

    expect(Bid::count())->toBe(2)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(650);
});

it('releases the key when the bid was refused, so it can be retried', function (): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    // Refused: nothing happened, so the key must not be burnt.
    expect(fn (): Bid => placeBid($auction, $user, 99_999, 'attempt'))
        ->toThrow(BidRejected::class);

    expect(placeBid($auction, $user, 150, 'attempt')->amount_credits)->toBe(150);
});

it('keeps the bid idempotency key on the bid it produced', function (): void {
    $auction = liveAuction();

    expect(placeBid($auction, bidder(), 150, 'traceable')->idempotency_key)->toBe('traceable');
});

// ------------------------------------------------------------- Projection

it('keeps the highest-bid projection in step with the bids', function (): void {
    $auction = liveAuction();

    placeBid($auction, bidder(), 20);
    placeBid($auction, bidder(), 150);
    $middling = placeBid($auction, bidder(), 100);

    $auction->refresh();

    expect($auction->highest_bid_credits)->toBe(150)
        ->and($auction->bid_count)->toBe(3)
        ->and($auction->highest_bid_id)->not->toBe($middling->id)
        ->and($this->bids->verify($auction)['matches'])->toBeTrue();
});

it('refuses to write the highest-bid projection by hand', function (string $column, int $value): void {
    $auction = liveAuction();

    $auction->{$column} = $value;

    expect(fn (): bool => $auction->save())
        ->toThrow(HighestBidMutationForbidden::class);
})->with([
    'credits' => ['highest_bid_credits', 9_999],
    'count' => ['bid_count', 42],
]);

it('rebuilds the projection from the bids alone', function (): void {
    $auction = liveAuction();

    placeBid($auction, bidder(), 20);
    $highest = placeBid($auction, bidder(), 150);

    // Corrupt the cache the only way anything can -- straight through the
    // database, past the model guard.
    DB::table('auctions')->where('id', $auction->id)
        ->update(['highest_bid_credits' => 1, 'highest_bid_id' => null, 'bid_count' => 0]);

    expect($this->bids->verify($auction->fresh())['matches'])->toBeFalse();

    $this->bids->rebuild($auction->fresh());

    $auction->refresh();

    expect($auction->highest_bid_credits)->toBe(150)
        ->and($auction->highest_bid_id)->toBe($highest->id)
        ->and($auction->bid_count)->toBe(2);
});

it('reports a projection mismatch rather than quietly repairing it', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 150);

    DB::table('auctions')->where('id', $auction->id)->update(['bid_count' => 99]);

    $report = $this->bids->verify($auction->fresh());

    expect($report['matches'])->toBeFalse()
        ->and($report['projected_bid_count'])->toBe(99)
        ->and($report['actual_bid_count'])->toBe(1)
        // Reporting is not repairing: the stored value is untouched.
        ->and($auction->fresh()->bid_count)->toBe(99);
});

// ---------------------------------------------------------- Server decides

it('validates against the database rather than anything the caller passed', function (): void {
    $auction = liveAuction(ruleset: AuctionRuleset::factory()->active()->withBidRules(minimum: 100)->create());
    $user = bidder(1_000);

    // A stale instance, loaded before the auction was closed elsewhere.
    $stale = Auction::findOrFail($auction->id);
    $this->lifecycle->cancel($auction, 'Withdrawn.');

    expect(fn (): Bid => app(PlaceBid::class)->handle($stale, $user, 500, 'k'))
        ->toThrow(BidRejected::class, 'not accepting bids');

    expect(creditWalletFor($user)->fresh()->balance)->toBe(1_000);
});
