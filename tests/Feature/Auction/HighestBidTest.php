<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionRuleset;

/*
 * Who wins, and why.
 *
 * The rule is the highest valid credit bid. These tests exist to make every
 * plausible alternative fail loudly: the last bidder, the most frequent
 * bidder, the largest total committed, the one who led longest.
 */

beforeEach(function (): void {
    $this->bids = app(HighestBidResolver::class);
    $this->close = app(CloseAuction::class);
});

// ------------------------------------------------------- The winner rule

it('gives the win to the highest bid', function (): void {
    $auction = liveAuction();

    placeBid($auction, bidder(), 20);
    placeBid($auction, bidder(), 50);
    placeBid($auction, bidder(), 100);
    $highest = placeBid($auction, bidder(), 150);

    $closed = $this->close->handle($auction, force: true);

    expect($closed->winning_bid_id)->toBe($highest->id)
        ->and($closed->winner_user_id)->toBe($highest->user_id)
        ->and($closed->closure_reason)->toBe(AuctionClosureReason::HighestBid)
        ->and($closed->status)->toBe(AuctionStatus::PendingSettlement);
});

/*
 * The single most important test in the stage. Under the model this replaced,
 * the last bidder won. Here they lose.
 */
it('does not give the win to the last bidder', function (): void {
    $auction = liveAuction();
    $biggest = bidder(500);
    $latest = bidder(500);

    placeBid($auction, $biggest, 300);
    // Bid afterwards, but smaller.
    placeBid($auction, $latest, 50);

    $closed = $this->close->handle($auction, force: true);

    expect($closed->winner_user_id)->toBe($biggest->id)
        ->and($closed->winner_user_id)->not->toBe($latest->id);
});

it('does not give the win to whoever bid most often', function (): void {
    $auction = liveAuction();
    $frequent = bidder(1_000);
    $decisive = bidder(1_000);

    // Six bids against one.
    foreach ([10, 20, 30, 40, 50, 60] as $amount) {
        placeBid($auction, $frequent, $amount);
    }

    placeBid($auction, $decisive, 500);

    $closed = $this->close->handle($auction, force: true);

    expect($closed->winner_user_id)->toBe($decisive->id)
        ->and($closed->bid_count)->toBe(7);
});

/*
 * A single bid's amount decides it. Credits committed across several bids do
 * not add up into a bigger one.
 */
it('does not add a bidder several bids together into one', function (): void {
    $auction = liveAuction();
    $accumulator = bidder(1_000);
    $single = bidder(1_000);

    // 400 credits in total, but never more than 100 in one bid.
    foreach ([100, 100, 100, 100] as $amount) {
        placeBid($auction, $accumulator, $amount);
    }

    placeBid($auction, $single, 150);

    $closed = $this->close->handle($auction, force: true);

    expect($closed->winner_user_id)->toBe($single->id)
        ->and($closed->highest_bid_credits)->toBe(150);
});

/*
 * Being overtaken is not disqualifying, and neither is having led for most of
 * the auction.
 */
it('lets an overtaken bidder win by bidding higher again', function (): void {
    $auction = liveAuction();
    $a = bidder(1_000);
    $b = bidder(1_000);

    placeBid($auction, $a, 20);
    placeBid($auction, $b, 100);
    placeBid($auction, $a, 150);

    $closed = $this->close->handle($auction, force: true);

    expect($closed->winner_user_id)->toBe($a->id)
        ->and($closed->highest_bid_credits)->toBe(150);
});

it('does not reward holding the lead longest', function (): void {
    $auction = liveAuction();
    $early = bidder(1_000);
    $late = bidder(1_000);

    // Leads for the whole auction until the very end.
    placeBid($auction, $early, 100);
    placeBid($auction, $late, 101);

    expect($this->close->handle($auction, force: true)->winner_user_id)->toBe($late->id);
});

// ------------------------------------------------------------- Tie-break

it('gives equal highest bids to whoever bid first', function (): void {
    $auction = liveAuction();
    $first = bidder(500);
    $second = bidder(500);

    $firstBid = placeBid($auction, $first, 200);
    $secondBid = placeBid($auction, $second, 200);

    $closed = $this->close->handle($auction, force: true);

    expect($closed->winning_bid_id)->toBe($firstBid->id)
        ->and($closed->winner_user_id)->toBe($first->id)
        // Same amount, and the later one loses purely on order.
        ->and($secondBid->amount_credits)->toBe($firstBid->amount_credits)
        ->and($secondBid->sequence)->toBeGreaterThan($firstBid->sequence);
});

it('breaks a tie the same way every time it is asked', function (): void {
    $auction = liveAuction();

    $firstBid = placeBid($auction, bidder(), 200);
    placeBid($auction, bidder(), 200);
    placeBid($auction, bidder(), 200);

    // Ten readings, one answer. The order is total, so nothing about query
    // planning or row order can change who leads.
    foreach (range(1, 10) as $ignored) {
        expect($this->bids->highestBid($auction->fresh())?->id)->toBe($firstBid->id);
    }
});

it('still names the earliest bid at the winning amount when lower bids came first', function (): void {
    $auction = liveAuction();

    placeBid($auction, bidder(), 50);
    $winner = placeBid($auction, bidder(), 200);
    placeBid($auction, bidder(), 200);
    placeBid($auction, bidder(), 10);

    // The tie-break applies among bids at the highest amount, not across the
    // whole auction: the first 200, not the first bid.
    expect($this->bids->highestBid($auction)?->id)->toBe($winner->id);
});

// ----------------------------------------------------------- No winner

it('closes without a winner when nobody bid', function (): void {
    $auction = liveAuction();

    $closed = $this->close->handle($auction, force: true);

    expect($closed->status)->toBe(AuctionStatus::Unsold)
        ->and($closed->closure_reason)->toBe(AuctionClosureReason::NoBids)
        ->and($closed->winner_user_id)->toBeNull()
        ->and($closed->winning_bid_id)->toBeNull();
});

it('gives the stock back when an auction closes with no bids', function (): void {
    $auction = liveAuction();

    expect($auction->product->fresh()->stock_reserved)->toBe(1);

    $this->close->handle($auction, force: true);

    expect($auction->product->fresh()->stock_reserved)->toBe(0)
        ->and($auction->product->fresh()->availableStock())->toBe(1);
});

it('keeps the unit reserved while a winner has yet to settle', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 100);

    $this->close->handle($auction, force: true);

    // Spoken for, not yet sold.
    expect($auction->product->fresh()->stock_reserved)->toBe(1)
        ->and($auction->product->fresh()->stock_on_hand)->toBe(1)
        ->and($auction->product->fresh()->availableStock())->toBe(0);
});

// ------------------------------------------------------------ Settlement

it('records what the winner owes, from the frozen snapshot', function (): void {
    $auction = liveAuction(settlementMinor: 10_000);
    placeBid($auction, bidder(), 180);

    $closed = $this->close->handle($auction, force: true);

    // 180 credits committed and gone. What is owed is GH 100 -- the auction's
    // settlement amount -- and not GH 180.
    expect($closed->highest_bid_credits)->toBe(180)
        ->and($closed->settlementAmount()->toDecimalString())->toBe('100.00')
        ->and($closed->settlement_amount_minor)->not->toBe(18_000);
});

it('sets the settlement deadline from the auction rules', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->create(['checkout_deadline_minutes' => 90]);

    $auction = liveAuction(ruleset: $ruleset);
    placeBid($auction, bidder(), 100);

    $closed = $this->close->handle($auction, force: true);

    expect(now()->diffInMinutes($closed->settlement_due_at))->toEqualWithDelta(90, 1);
});

it('does not take any payment when it closes', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $closed = $this->close->handle($auction, force: true);

    // Awaiting settlement, not settled. Collecting the money belongs to a
    // later stage, and marking it settled here would claim a payment that
    // never happened.
    expect($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($closed->settled_at)->toBeNull();
});

// ------------------------------------------------------------ Idempotence

it('closes an auction once however many times it is asked', function (): void {
    $auction = liveAuction();
    $winner = placeBid($auction, bidder(), 100);

    $first = $this->close->handle($auction, force: true);
    $second = $this->close->handle($auction->fresh(), force: true);

    expect($second->status)->toBe($first->status)
        ->and($second->winning_bid_id)->toBe($winner->id)
        // One closing transition, not two.
        ->and($auction->transitions()->where('to_status', AuctionStatus::PendingSettlement)->count())
        ->toBe(1);
});

it('does not close an auction whose clock is still running', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 100);

    $result = $this->close->handle($auction);

    expect($result->status)->toBe(AuctionStatus::Live)
        ->and($result->winner_user_id)->toBeNull();
});

it('closes an auction whose clock has run out', function (): void {
    // A genuinely published auction, then time moves past its end. Not a
    // factory state: closing releases the reserved unit, and an auction that
    // never reserved one is a state the engine cannot produce.
    $auction = liveAuction();

    expect($this->close->handle($auction)->status)->toBe(AuctionStatus::Live);

    $this->travel(10)->minutes();

    expect($this->close->handle($auction->fresh())->status)->toBe(AuctionStatus::Unsold);
});

// ------------------------------------------------------------- Resolver

it('reports the credits a user consumed on one auction and no other', function (): void {
    $first = liveAuction();
    $second = liveAuction();
    $user = bidder(1_000);

    placeBid($first, $user, 100);
    placeBid($first, $user, 50);
    placeBid($second, $user, 300);

    expect($this->bids->consumedCreditsBy($first, $user->id))->toBe(150)
        ->and($this->bids->consumedCreditsBy($second, $user->id))->toBe(300);
});

it('does not count another bidder credits towards a user', function (): void {
    $auction = liveAuction();
    $user = bidder(500);
    $other = bidder(500);

    placeBid($auction, $user, 100);
    placeBid($auction, $other, 200);

    expect($this->bids->consumedCreditsBy($auction, $user->id))->toBe(100);
});

it('reports no highest bid before anyone has bid', function (): void {
    $auction = liveAuction();

    // Null rather than zero: no bids at all is a different state from a bid
    // of nothing, and a floor computed from zero would be wrong.
    expect($this->bids->highestAmount($auction))->toBeNull()
        ->and($this->bids->highestBid($auction))->toBeNull()
        ->and($this->bids->bidCount($auction))->toBe(0);
});
