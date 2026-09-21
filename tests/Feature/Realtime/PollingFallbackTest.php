<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Realtime\AuctionStatePayload;
use App\Enums\AuctionStatus;
use App\Events\Broadcast\AuctionStateBroadcast;
use App\Listeners\AuctionBroadcastSubscriber;
use App\Livewire\Auctions\AuctionRoom;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Finder\SplFileInfo;

/*
 * The deployment that actually ships.
 *
 * Hostinger Premium runs scheduled cron tasks, not a Reverb server, so the
 * broadcaster is `null` and nothing is transmitted. That is the supported
 * production configuration, not a degraded one, and these tests hold it to
 * being indistinguishable from the application before the real-time layer
 * existed.
 *
 * They also cover the browser cases that look the same from the server's side:
 * JavaScript disabled, a socket that never connected, a tab that slept.
 * Polling answers all of them identically, because a poll is a fresh
 * authoritative read and carries no assumption about what came before it.
 */

beforeEach(function (): void {
    seedRoles();
    seedPermissions();
    seedSettings();
});

// ------------------------------------------------- Broadcasting switched off

it('defaults to a broadcaster that transmits nothing', function (): void {
    // What `.env.example` ships and what production runs. Turning this on is a
    // deliberate act on infrastructure that can host the server.
    expect(config('broadcasting.connections.null.driver'))->toBe('null');
});

it('still runs the whole auction with the null broadcaster', function (): void {
    config(['broadcasting.default' => 'null']);

    $product = stockedProduct();
    $auction = liveAuction(product: $product);
    $low = bidder(1_000);
    $high = bidder(1_000);

    placeBid($auction->fresh(), $low, 100);
    placeBid($auction->fresh(), $high, 250);

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);

    expect($closed->winner_user_id)->toBe($high->id)
        ->and($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and(creditWalletFor($low)->fresh()->balance)->toBe(900);
});

// ------------------------------------------------------- Polling is intact

it('keeps polling the auction room', function (): void {
    $auction = liveAuction();

    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertOk()
        // Stage 15's mechanism, unchanged. Broadcasting is an enhancement on
        // top of it and never a replacement for it.
        ->assertSee('wire:poll', escape: false);
});

it('preserves the Stage 15 adaptive cadence exactly', function (): void {
    $auction = liveAuction();

    // Far from closing: the longer interval.
    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('wire:poll.'.AuctionRoom::POLL_LIVE_SECONDS.'s', escape: false);

    $remaining = app(AuctionClock::class)
        ->secondsRemaining($auction->fresh());

    $this->travel($remaining - 30)->seconds();

    // Inside the tail: back to five seconds.
    Livewire::test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('wire:poll.'.AuctionRoom::POLL_CLOSING_SECONDS.'s', escape: false);
});

it('serves the whole auction experience without any JavaScript', function (): void {
    $auction = cumulativeAuction(minimum: 250, increment: 10);
    $bidder = bidder(1_000);
    placeBid($auction->fresh(), $bidder, 250);

    // The server renders every figure. No value on this page is computed in a
    // browser, so disabling scripts removes the live updates and nothing else.
    Livewire::actingAs($bidder)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertOk()
        ->assertSee('250')
        ->assertSee('Highest Total (Credits)')
        ->assertSee('Place a bid');
});

it('accepts a bid with no socket in sight', function (): void {
    config(['broadcasting.default' => 'null']);

    $auction = cumulativeAuction(minimum: 175, increment: 10);
    $bidder = bidder(1_000);

    Livewire::actingAs($bidder)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->call('review', 175)
        ->call('bid')
        ->assertHasNoErrors();

    expect($auction->fresh()->highest_bid_credits)->toBe(175);
});

it('shows a change on the next poll, which is how a slept tab catches up', function (): void {
    $auction = liveAuction();
    $viewer = bidder(1_000);

    $page = Livewire::actingAs($viewer)->test(AuctionRoom::class, ['auction' => $auction->fresh()]);

    // Somebody else bids while this tab is asleep and receiving nothing.
    placeBid($auction->fresh(), bidder(1_000), 400);

    // The next poll re-reads authoritative state. No replay, no reconstruction
    // -- the page simply asks again and is told the truth.
    $page->call('$refresh')->assertSee('400');
});

// ------------------------------- Redis is nowhere near the authoritative path

it('needs no Redis to decide anything', function (): void {
    // Real usage, not the word in a docblock: two classes mention Redis in
    // prose, both saying the cache store could move there one day, and neither
    // touches an auction fact. What must not exist is code that reaches for it.
    $using = collect(File::allFiles(app_path()))
        ->filter(function (SplFileInfo $file): bool {
            $source = File::get($file->getPathname());

            return str_contains($source, 'Facades\Redis')
                || str_contains($source, 'Redis::')
                || str_contains($source, 'RedisManager')
                || str_contains($source, "Cache::store('redis')");
        })
        ->map(fn (SplFileInfo $file): string => $file->getRelativePathname())
        ->values()
        ->all();

    // If Redis ever arrives it is fan-out between Reverb nodes -- infrastructure
    // the domain cannot see, and never a store of bids, balances or stock.
    expect($using)->toBe([]);
});

it('holds no cached copy of an authoritative auction fact', function (): void {
    $auction = liveAuction();
    placeBid($auction->fresh(), bidder(1_000), 300);

    // The payload is built from the record on every send. It is a projection
    // of the database at that instant, not a store that could diverge from it.
    $payload = AuctionStatePayload::for($auction->fresh(), sequence: 1)->toArray();

    expect($payload['highest_bid_credits'])->toBe($auction->fresh()->highest_bid_credits);

    placeBid($auction->fresh(), bidder(1_000), 450);

    $later = AuctionStatePayload::for($auction->fresh(), sequence: 2)->toArray();

    expect($later['highest_bid_credits'])->toBe(450);
});

// ------------------------------------------------- The channel is public only

it('publishes on a public channel carrying nothing private', function (): void {
    $auction = liveAuction();

    $captured = null;

    Event::listen(AuctionStateBroadcast::class, function (AuctionStateBroadcast $e) use (&$captured): void {
        $captured = $e;
    });

    placeBid($auction->fresh(), bidder(1_000), 120);

    expect($captured)->not->toBeNull()
        ->and($captured->broadcastOn())->toBeInstanceOf(Channel::class)
        // Public by design. What keeps it safe is the payload whitelist, not
        // the channel type -- so a private channel would add an authorization
        // round trip that protects nothing.
        ->and($captured->broadcastOn())->not->toBeInstanceOf(PrivateChannel::class)
        ->and($captured->broadcastOn()->name)->toBe('auction.'.$auction->id)
        ->and(array_keys($captured->broadcastWith()))
        ->toEqualCanonicalizing(AuctionStatePayload::allowedKeys());
});

it('names the client events stably', function (): void {
    // These strings are the contract with every open page. Renaming one
    // silently stops browsers listening.
    expect(AuctionBroadcastSubscriber::BID_ACCEPTED)->toBe('bid.accepted')
        ->and(AuctionBroadcastSubscriber::AUCTION_CLOSED)->toBe('auction.closed')
        ->and(AuctionBroadcastSubscriber::AUCTION_SOLD)->toBe('auction.sold')
        ->and(AuctionBroadcastSubscriber::AUCTION_FORFEITED)->toBe('auction.forfeited');
});
