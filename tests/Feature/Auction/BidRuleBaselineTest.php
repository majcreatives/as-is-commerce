<?php

declare(strict_types=1);

use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Bid;

/*
 * The bid rules as they behave TODAY, pinned before they change.
 *
 * WHY THIS FILE EXISTS. The engine is about to gain a second bidding model
 * (see docs/PLAN_32_FIXED_BID_INCREMENT.md). The existing model is not being
 * deleted: it stays in the engine for old snapshots and for the several
 * hundred tests that place arbitrary amounts to set up known consumed credits.
 * These tests state, in one place, exactly what that model does -- including
 * two behaviours that are surprising and that the plan depends on:
 *
 *   - a bid consumes its WHOLE amount, so bidding 20 and then 21 consumes 41
 *   - with no increment configured, a bid at or below the leader is accepted
 *
 * Neither is an endorsement. They are the facts the new model is measured
 * against, and if a later change alters one of them by accident, this is where
 * it shows.
 *
 * Everything here runs against a single-highest auction: the amount the bidder
 * types is the amount that ranks.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->bids = app(HighestBidResolver::class);
});

/**
 * A live auction with the given bid rules.
 */
function baselineAuction(?int $minimum = null, ?int $increment = null, ?bool $allowIncrease = null): Auction
{
    $ruleset = AuctionRuleset::factory()
        ->active()
        ->withoutThrottle()
        ->withBidRules($minimum, $increment)
        ->create(['allow_bid_increase' => $allowIncrease]);

    return liveAuction(ruleset: $ruleset);
}

// ---------------------------------------------- What a bid consumes

it('consumes the whole amount of a bid, not the difference over the last one', function (): void {
    // The fact that drives the economics of the whole change: raising your own
    // bid from 20 to 21 does not cost 1 credit. It costs 21, on top of the 20
    // already gone.
    $auction = baselineAuction();
    $bidder = bidder(500);

    placeBid($auction, $bidder, 20);
    placeBid($auction, $bidder, 21);

    expect($this->bids->consumedCreditsBy($auction, $bidder->id))->toBe(41)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(500 - 41);
});

// ------------------------------- No increment: nothing relates a bid to the leader

it('accepts a bid below the standing highest when no increment is configured', function (): void {
    // Read from BidValidator: the comparison against the leader only runs
    // inside `if ($rules->hasMinimumIncrement())`. With no increment a bid
    // that cannot lead is still valid, and its credits are consumed.
    $auction = baselineAuction();
    $leader = bidder(500);
    $follower = bidder(500);

    placeBid($auction, $leader, 100);
    placeBid($auction, $follower, 5);

    expect($this->bids->highestBid($auction)->user_id)->toBe($leader->id)
        ->and($this->bids->highestAmount($auction))->toBe(100)
        // The credits are gone even though the bid could never lead.
        ->and(creditWalletFor($follower)->fresh()->balance)->toBe(495);
});

it('accepts a bid equal to the standing highest, which the earlier bid keeps', function (): void {
    $auction = baselineAuction();
    $first = bidder(500);
    $second = bidder(500);

    placeBid($auction, $first, 50);
    placeBid($auction, $second, 50);

    expect($this->bids->highestBid($auction)->user_id)->toBe($first->id);
});

// --------------------------------------------- An increment is a lower bound

it('treats a configured increment as a lower bound over the leader', function (): void {
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = baselineAuction(increment: 5 * $factor);
    $leader = bidder(2_000 * $factor);
    $challenger = bidder(2_000 * $factor);

    placeBid($auction, $leader, 100 * $factor);

    // One short of the increment: refused, and the message names what would do.
    expect(fn () => placeBid($auction, $challenger, 104 * $factor))
        ->toThrow(BidRejected::class, 'the next valid bid is 105 credits');

    // Exactly the increment.
    placeBid($auction, $challenger, 105 * $factor);

    // And any amount above it is just as valid: it is a floor, not a step.
    placeBid($auction, $leader, 500 * $factor);

    expect($this->bids->highestAmount($auction))->toBe(500 * $factor);
});

it('leaves nothing behind when a bid is refused', function (): void {
    $auction = baselineAuction(increment: 5);
    $leader = bidder(500);
    $challenger = bidder(500);

    placeBid($auction, $leader, 100);
    $before = Bid::query()->count();

    expect(fn () => placeBid($auction, $challenger, 101))->toThrow(BidRejected::class);

    expect(Bid::query()->count())->toBe($before)
        ->and(creditWalletFor($challenger)->fresh()->balance)->toBe(500)
        ->and($this->bids->consumedCreditsBy($auction, $challenger->id))->toBe(0);
});

it('enforces a minimum bid on every bid, including the first', function (): void {
    $factor = CreditAmount::SUBCREDITS_PER_CREDIT;
    $auction = baselineAuction(minimum: 20 * $factor);
    $bidder = bidder(500 * $factor);

    expect(fn () => placeBid($auction, $bidder, 19 * $factor))
        ->toThrow(BidRejected::class, 'below this auction\'s minimum of 20 credits');

    placeBid($auction, $bidder, 20 * $factor);

    expect($this->bids->highestAmount($auction))->toBe(20 * $factor);
});

// -------------------------------------------- Raising your own bid

it('refuses a leader raising their own bid only when the ruleset forbids it', function (): void {
    $forbidding = baselineAuction(allowIncrease: false);
    $leader = bidder(500);

    placeBid($forbidding, $leader, 10);

    expect(fn () => placeBid($forbidding, $leader, 20))
        ->toThrow(BidRejected::class, 'does not allow raising your own bid');

    $allowing = baselineAuction(allowIncrease: true);
    $other = bidder(500);

    placeBid($allowing, $other, 10);
    placeBid($allowing, $other, 20);

    expect($this->bids->highestAmount($allowing))->toBe(20);
});

// -------------------------------------------------- Who wins

it('ranks by the largest single bid, not by the largest total a bidder committed', function (): void {
    // The rule the cumulative model replaces. X commits 90 credits in total
    // across two bids; Y commits 60 in one. Today Y leads, because 60 is the
    // largest single bid -- and the resolver's own docblock says so. Under the
    // cumulative model X would lead. This test is the twin of that one.
    $auction = baselineAuction();
    $x = bidder(500);
    $y = bidder(500);

    placeBid($auction, $x, 40);
    placeBid($auction, $y, 60);
    placeBid($auction, $x, 50);

    expect($this->bids->consumedCreditsBy($auction, $x->id))->toBe(90)
        ->and($this->bids->highestBid($auction)->user_id)->toBe($y->id)
        ->and($this->bids->highestAmount($auction))->toBe(60);
});
