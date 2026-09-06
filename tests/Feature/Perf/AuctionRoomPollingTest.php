<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Auction\Services\HighestBidResolver;
use App\Livewire\Auctions\AuctionRoom;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
 * What one poll of the auction room costs.
 *
 * The page polls for every viewer watching a live auction, so a query issued
 * twice per render is issued twice per viewer per interval. These tests pin
 * the cost down and, more importantly, pin down that no fact is read twice.
 *
 * None of this touches correctness. The queries are the same authoritative
 * reads as before; what changed is how many times one render asks.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();
});

/**
 * Every query one render of the auction room issues.
 *
 * @return list<string>
 */
function queriesForRender(Auction $auction, ?User $viewer = null): array
{
    $component = $viewer === null
        ? Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        : Livewire::actingAs($viewer)->test(AuctionRoom::class, ['auction' => $auction->fresh()]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $component->call('$refresh');

    $log = DB::getQueryLog();
    DB::disableQueryLog();

    return array_map(
        fn (array $q): string => (string) preg_replace('/\s+/', ' ', $q['query']),
        $log,
    );
}

/**
 * A busy auction with the viewer holding bids and having been outbid: the
 * branchiest path the template has.
 */
function busyAuctionFor(User $viewer): Auction
{
    $auction = liveAuction();
    $rival = bidder(1_000);

    placeBid($auction, $viewer, 20);
    placeBid($auction, $rival, 50);
    placeBid($auction, $viewer, 100);
    placeBid($auction, $rival, 150);

    return $auction->fresh();
}

// ----------------------------------------------------------- No duplicates

it('reads no fact twice in a single render', function (): void {
    $viewer = bidder(1_000);
    $queries = queriesForRender(busyAuctionFor($viewer), $viewer);

    $duplicated = array_filter(array_count_values($queries), fn (int $n): bool => $n > 1);

    // The optimization this test exists for. Before it, the viewer's committed
    // credits were summed three times and their own bids fetched in four
    // template branches.
    expect($duplicated)->toBe([]);
});

it('keeps one poll within a bounded number of queries', function (): void {
    $viewer = bidder(1_000);
    $queries = queriesForRender(busyAuctionFor($viewer), $viewer);

    // A ceiling, not a target. It exists so that adding a query to this page
    // is a deliberate act with a failing test behind it, rather than something
    // that happens quietly and is paid for by every viewer every interval.
    expect(count($queries))->toBeLessThanOrEqual(12);
});

it('does not grow more expensive as bids accumulate', function (): void {
    $viewer = bidder(1_000);
    $auction = busyAuctionFor($viewer);

    $before = count(queriesForRender($auction, $viewer));

    foreach ([200, 250, 300, 350, 400] as $amount) {
        placeBid($auction->fresh(), bidder(1_000), $amount);
    }

    // Bounded reads, not one per bid.
    expect(count(queriesForRender($auction->fresh(), $viewer)))->toBe($before);
});

it('costs a signed-out visitor less than a bidder', function (): void {
    $viewer = bidder(1_000);
    $auction = busyAuctionFor($viewer);

    // Nobody signed in: no wallet, no own bids, no settlement order to look
    // for. The page must not ask for any of it.
    expect(count(queriesForRender($auction)))
        ->toBeLessThan(count(queriesForRender($auction, $viewer)));
});

// ------------------------------------------------------ The index is used

it('resolves the highest bid through the descending index without sorting', function (): void {
    $auction = liveAuction();

    foreach ([20, 50, 100, 150] as $amount) {
        placeBid($auction->fresh(), bidder(1_000), $amount);
    }

    $plan = DB::select(
        'EXPLAIN SELECT * FROM bids WHERE auction_id = ? AND status = ? '
        .'ORDER BY amount_credits DESC, sequence ASC LIMIT 1',
        [$auction->id, 'accepted'],
    )[0];

    expect($plan->key)->toBe('bids_highest_bid_index')
        // The point of the descending column. A filesort here sorted every bid
        // on the auction to return one row, on the hottest query the platform
        // has.
        ->and($plan->Extra ?? '')->not->toContain('filesort');
});

it('keeps the highest bid authoritative and correctly tie-broken', function (): void {
    $auction = liveAuction();
    $first = bidder(1_000);
    $second = bidder(1_000);

    // Equal amounts. The earlier bid leads, by sequence.
    $earlier = placeBid($auction->fresh(), $first, 100);
    placeBid($auction->fresh(), $second, 100);

    $highest = app(HighestBidResolver::class)
        ->highestBid($auction->fresh());

    expect($highest->id)->toBe($earlier->id)
        ->and($highest->user_id)->toBe($first->id);
});

// ------------------------------------------- The cadence decides nothing

it('polls tightly inside the closing window and loosely far from it', function (): void {
    $auction = liveAuction();

    // Days out: no reason to ask every five seconds.
    expect(AuctionRoom::POLL_LIVE_SECONDS)->toBeGreaterThan(AuctionRoom::POLL_CLOSING_SECONDS);

    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('wire:poll.'.AuctionRoom::POLL_LIVE_SECONDS.'s', escape: false);

    // Inside the tightening window, the original five seconds is restored.
    $remaining = app(AuctionClock::class)->secondsRemaining($auction->fresh());
    $this->travel($remaining - 30)->seconds();

    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('wire:poll.'.AuctionRoom::POLL_CLOSING_SECONDS.'s', escape: false);
});

it('backs off once the auction has ended', function (): void {
    $auction = liveAuction();
    placeBid($auction->fresh(), bidder(1_000), 100);

    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('wire:poll.'.AuctionRoom::POLL_ENDED_SECONDS.'s', escape: false);
});

it('does not let a slow poll change who wins', function (): void {
    $auction = liveAuction();
    $low = bidder(1_000);
    $high = bidder(1_000);

    placeBid($auction->fresh(), $low, 100);
    placeBid($auction->fresh(), $high, 200);

    // Nobody is watching. No browser polls at all between the last bid and
    // the sweep, which is the case the clock exists to survive.
    $remaining = app(AuctionClock::class)->secondsRemaining($auction->fresh());
    $this->travel($remaining + 120)->seconds();

    $this->artisan('auctions:tick')->assertSuccessful();

    expect($auction->fresh()->winner_user_id)->toBe($high->id);
});

it('rejects a bid placed after the authoritative end, however the page looks', function (): void {
    $auction = liveAuction();
    $user = bidder(1_000);

    $remaining = app(AuctionClock::class)->secondsRemaining($auction->fresh());
    $this->travel($remaining + 5)->seconds();

    // Still marked Live -- the sweep has not run. The clock is checked as well
    // as the status, so the bid is refused anyway.
    expect($auction->fresh()->status->acceptsBids())->toBeTrue();

    expect(fn () => placeBid($auction->fresh(), $user, 100))
        ->toThrow(BidRejected::class);

    expect(Bid::where('auction_id', $auction->id)->count())->toBe(0)
        ->and(creditWalletFor($user)->fresh()->balance)->toBe(1_000);
});
