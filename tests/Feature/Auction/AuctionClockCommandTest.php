<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\Product;

/*
 * The sweep that makes long-running auctions independent of any browser.
 *
 * Nothing here depends on a page being open, a JavaScript timer firing or a
 * process staying alive. The command reads timestamps and acts.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->lifecycle = app(AuctionLifecycle::class);
});

function scheduledAuction(int $secondsAhead = 60): Auction
{
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $auction = app(CreateAuction::class)->handle(
        $product->fresh(),
        AuctionRuleset::factory()->active()->withoutThrottle()->create(),
        Money::fromMinor(10_000),
    );

    return app(AuctionLifecycle::class)->schedule($auction, now()->addSeconds($secondsAhead));
}

it('opens a scheduled auction once its start time arrives', function (): void {
    $auction = scheduledAuction(60);

    $this->artisan('auctions:tick')->assertSuccessful();
    expect($auction->fresh()->status)->toBe(AuctionStatus::Scheduled);

    $this->travel(2)->minutes();

    $this->artisan('auctions:tick')->assertSuccessful();

    $auction->refresh();

    expect($auction->status)->toBe(AuctionStatus::Live)
        ->and($auction->starts_at)->not->toBeNull()
        ->and($auction->ends_at)->not->toBeNull();
});

it('closes an auction whose clock has run out and names the winner', function (): void {
    $auction = liveAuction();
    $loser = bidder(500);
    $winner = bidder(500);

    placeBid($auction, $loser, 100);
    placeBid($auction, $winner, 300);

    $this->travel(10)->minutes();

    $this->artisan('auctions:tick')->assertSuccessful();

    $auction->refresh();

    expect($auction->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($auction->winner_user_id)->toBe($winner->id);
});

it('does not close an auction whose clock is still running', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 100);

    $this->artisan('auctions:tick')->assertSuccessful();

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);
});

it('marks a live auction as closing once it enters the window', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 60,
    ]);

    $auction = liveAuction(ruleset: $ruleset);

    $this->artisan('auctions:tick');
    expect($auction->fresh()->status)->toBe(AuctionStatus::Live);

    $this->travel(250)->seconds();

    $this->artisan('auctions:tick');
    expect($auction->fresh()->status)->toBe(AuctionStatus::Closing);
});

it('forfeits a winner whose settlement deadline has passed', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->create(['checkout_deadline_minutes' => 30]);

    $auction = liveAuction(ruleset: $ruleset);
    placeBid($auction, bidder(), 100);

    $this->travel(10)->minutes();
    $this->artisan('auctions:tick');

    expect($auction->fresh()->status)->toBe(AuctionStatus::PendingSettlement);

    $this->travel(40)->minutes();
    $this->artisan('auctions:tick');

    expect($auction->fresh()->status)->toBe(AuctionStatus::Forfeited)
        // The unit goes back so it can be offered again.
        ->and($auction->product->fresh()->stock_reserved)->toBe(0);
});

/*
 * A missed run delays a closure; it never changes its outcome. The winner is
 * resolved from the bid records when the auction actually closes, and those
 * records do not change while the sweep is late.
 */
it('reaches the same outcome however late it runs', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);

    placeBid($auction, bidder(), 100);
    placeBid($auction, $winner, 400);

    // Three days without a single sweep, and no page open anywhere.
    $this->travel(3)->days();

    $this->artisan('auctions:tick')->assertSuccessful();

    expect($auction->fresh()->winner_user_id)->toBe($winner->id)
        ->and($auction->fresh()->highest_bid_credits)->toBe(400);
});

it('closes each auction once when run repeatedly', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 100);

    $this->travel(10)->minutes();

    foreach (range(1, 3) as $ignored) {
        $this->artisan('auctions:tick')->assertSuccessful();
    }

    expect($auction->fresh()->transitions()
        ->where('to_status', AuctionStatus::PendingSettlement)->count())->toBe(1);
});

it('advances the other auctions when one of them fails', function (): void {
    $healthy = liveAuction();
    placeBid($healthy, bidder(), 100);

    $broken = liveAuction();

    // Take the broken auction's reservation away behind the engine's back, so
    // releasing it at closure fails. A hand-run statement is the only way to
    // reach this state, which is exactly what the guard is for.
    DB::table('products')->where('id', $broken->product_id)
        ->update(['stock_reserved' => 0]);

    $this->travel(10)->minutes();

    $this->artisan('auctions:tick')->assertSuccessful();

    // One auction's problem must not leave every other auction on the platform
    // running past its end time.
    expect($healthy->fresh()->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($broken->fresh()->status)->toBe(AuctionStatus::Live);
});

it('honours the limit it is given', function (): void {
    foreach (range(1, 3) as $ignored) {
        $auction = liveAuction();
        placeBid($auction, bidder(), 100);
    }

    $this->travel(10)->minutes();

    $this->artisan('auctions:tick', ['--limit' => 1])->assertSuccessful();

    expect(Auction::where('status', AuctionStatus::PendingSettlement)->count())->toBe(1);
});

it('does nothing at all when there are no auctions', function (): void {
    $this->artisan('auctions:tick')
        ->expectsOutputToContain('Started 0, entered closing 0, closed 0, forfeited 0.')
        ->assertSuccessful();
});
