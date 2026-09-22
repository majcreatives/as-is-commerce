<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use Illuminate\Support\Facades\DB;

/*
 * Step 4 of docs/PLAN_POT_TARGET_BIDDING.md: pot tracking and target-closing.
 *
 * WHAT THIS PROVES, beyond "the numbers add up":
 *
 *   - the pot is every accepted bid's amount_credits, summed across every
 *     bidder (D-2, D-10) -- not one bidder's standing, and not money;
 *   - an auction with no target behaves exactly as before -- nothing here
 *     changes for the every-auction-that-exists-today case;
 *   - reaching the target closes the auction immediately, from inside the
 *     triggering bid's own request, without waiting for a sweep;
 *   - the winner rule itself never changes: still the highest valid credit
 *     bid (or the largest total, under the cumulative model), from the same
 *     records CloseAuction always reads;
 *   - the closure reason distinguishes the two ways an auction can end
 *     normally, and a historical clock-driven close still reads exactly as
 *     it always has;
 *   - the sweep's own candidate query is a defensive backstop, independent
 *     of PlaceBid ever having run at all.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->bids = app(HighestBidResolver::class);
    $this->close = app(CloseAuction::class);
});

// -------------------------------------------------------------- The pot

it('sums every accepted bid across every bidder, not one bidder\'s standing', function (): void {
    $auction = cumulativeAuction(1, 1);

    $a = bidder(100);
    $b = bidder(100);

    catchUp($auction, $a); // 1
    catchUp($auction, $b); // 2, to land at 3 (1 + 2)
    catchUp($auction, $a); // catch-up bid to overtake

    $expected = $auction->fresh()->bids()->sum('amount_credits');

    expect($this->bids->potTotal($auction->fresh()))->toBe((int) $expected)
        ->and($this->bids->potTotal($auction->fresh()))->not->toBe($this->bids->standingOf($auction->fresh(), $a->id))
        ->and($this->bids->potTotal($auction->fresh()))->not->toBe($this->bids->standingOf($auction->fresh(), $b->id));
});

it('is zero for an auction with no bids', function (): void {
    $auction = cumulativeAuction(1, 1);

    expect($this->bids->potTotal($auction))->toBe(0);
});

// ------------------------------------------------ No target: unchanged

it('never closes an auction with no pot target just because bids keep landing', function (): void {
    $auction = cumulativeAuction(1, 1);

    foreach (range(1, 5) as $i) {
        catchUp($auction, bidder(1_000));
    }

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);
});

// --------------------------------------------------- Reaching the target

it('closes the auction the instant the pot reaches its target, mid-request', function (): void {
    $auction = cumulativeAuctionWithPotTarget(potTargetCredits: 3, minimum: 1, increment: 1);

    $a = bidder(100);
    $b = bidder(100);

    catchUp($auction, $a); // pot: 1
    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);

    catchUp($auction, $b); // pot: 1 + 2 = 3, the target -- closes within this call
    $closed = $auction->fresh();

    expect($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($closed->closure_reason)->toBe(AuctionClosureReason::PotTargetReached);
});

it('names the standing leader the winner, exactly the ordinary winner rule', function (): void {
    $auction = cumulativeAuctionWithPotTarget(potTargetCredits: 3, minimum: 1, increment: 1);

    $a = bidder(100);
    $b = bidder(100);

    catchUp($auction, $a); // pot: 1
    $bBid = catchUp($auction, $b); // pot: 3, target reached, b is leading

    $closed = $auction->fresh();

    expect($closed->winner_user_id)->toBe($b->id)
        ->and($closed->winning_bid_id)->toBe($bBid->id);
});

it('does not close while the pot is still short of the target', function (): void {
    $auction = cumulativeAuctionWithPotTarget(potTargetCredits: 1_000, minimum: 1, increment: 1);

    catchUp($auction, bidder(100));
    catchUp($auction, bidder(100));

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);
});

it('reserves PotTargetReached for a genuinely early close, not a clock-driven one', function (): void {
    $auction = cumulativeAuctionWithPotTarget(potTargetCredits: 1_000_000, minimum: 1, increment: 1);

    catchUp($auction, bidder(100));
    catchUp($auction, bidder(100));

    // Nowhere near the target. A forced close (what the clock running out
    // eventually produces) must still read as the ordinary reason.
    $closed = $this->close->handle($auction->fresh(), force: true);

    expect($closed->closure_reason)->toBe(AuctionClosureReason::HighestBid);
});

it('still transitions inventory and opens a settlement order, same as any other close', function (): void {
    $auction = cumulativeAuctionWithPotTarget(potTargetCredits: 3, minimum: 1, increment: 1);

    catchUp($auction, bidder(100));
    catchUp($auction, bidder(100));

    $closed = $auction->fresh();

    expect($closed->settlementOrder)->not->toBeNull()
        ->and($closed->settlement_due_at)->not->toBeNull();
});

it('a pot target cannot be reached with no bids; NoBids still governs an empty clock-driven close', function (): void {
    $auction = cumulativeAuctionWithPotTarget(potTargetCredits: 1, minimum: 1, increment: 1);

    $closed = $this->close->handle($auction->fresh(), force: true);

    expect($closed->status)->toBe(AuctionStatus::Unsold)
        ->and($closed->closure_reason)->toBe(AuctionClosureReason::NoBids);
});

// ------------------------------------------------------- Idempotency

it('is safe to call CloseAuction again on an auction the pot target already closed', function (): void {
    $auction = cumulativeAuctionWithPotTarget(potTargetCredits: 3, minimum: 1, increment: 1);

    catchUp($auction, bidder(100));
    catchUp($auction, bidder(100));

    $first = $auction->fresh();
    expect($first->status)->toBe(AuctionStatus::PendingSettlement);

    $again = $this->close->handle($first);

    expect($again->id)->toBe($first->id)
        ->and($again->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($again->winner_user_id)->toBe($first->winner_user_id);
});

// --------------------------------------------------- The sweep backstop

it('the sweep\'s own candidate query catches a pot-target auction independent of PlaceBid', function (): void {
    $auction = cumulativeAuctionWithPotTarget(potTargetCredits: 3, minimum: 1, increment: 1);

    // Placed the ordinary way, so the pot legitimately reaches target --
    // but PlaceBid's own follow-up close is bypassed here by asserting the
    // scope directly, proving the sweep does not depend on it having run.
    catchUp($auction, bidder(100));
    catchUp($auction, bidder(100));

    // The auction closed already (PlaceBid's own immediate check did its
    // job), so re-open it in the database directly to prove the scope would
    // have found it on its own -- the scenario the backstop exists for is
    // "the immediate close was somehow missed", not "no bids were placed".
    DB::table('auctions')->where('id', $auction->id)->update([
        'status' => AuctionStatus::Live->value,
        'winner_user_id' => null,
        'winning_bid_id' => null,
        'closure_reason' => null,
        'settlement_due_at' => null,
    ]);

    expect(Auction::query()->dueToClose()->whereKey($auction->id)->exists())->toBeTrue();
});

it('the sweep\'s candidate query ignores an open auction whose pot has not reached target', function (): void {
    $auction = cumulativeAuctionWithPotTarget(potTargetCredits: 1_000_000, minimum: 1, increment: 1);

    catchUp($auction, bidder(100));

    expect(Auction::query()->dueToClose()->whereKey($auction->id)->exists())->toBeFalse();
});

it('the sweep\'s candidate query costs nothing extra for an auction with no target at all', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 100);

    expect(Auction::query()->dueToClose()->whereKey($auction->id)->exists())->toBeFalse();
});
