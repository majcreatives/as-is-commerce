<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Auction\Actions\RelistAuction;
use App\Domain\Auction\Exceptions\InvalidAuctionTransition;
use App\Domain\Auction\Services\AuctionClock;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\Catalog\Exceptions\InvalidStockMovement;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Enums\InventoryTransactionType;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\InventoryTransaction;
use App\Models\Product;
use Illuminate\Database\QueryException;

/*
 * The state machine, the clock, and the inventory that follows both.
 */

beforeEach(function (): void {
    seedPermissions();

    $this->lifecycle = app(AuctionLifecycle::class);
    $this->clock = app(AuctionClock::class);
    $this->close = app(CloseAuction::class);
});

// -------------------------------------------------------------- Transitions

it('walks the ordinary path from draft to settlement', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 100);

    expect($auction->status)->toBe(AuctionStatus::Live);

    $this->lifecycle->enterClosing($auction);
    expect($auction->fresh()->status)->toBe(AuctionStatus::Closing);

    $closed = $this->close->handle($auction->fresh(), force: true);
    expect($closed->status)->toBe(AuctionStatus::PendingSettlement);

    expect($this->lifecycle->settle($closed)->status)->toBe(AuctionStatus::Settled);
});

it('refuses a move the state machine does not allow', function (): void {
    $auction = Auction::factory()->create();

    // Draft straight to settled: no bidding, no closure, no payment.
    expect(fn (): Auction => $this->lifecycle->apply($auction, AuctionStatus::Settled, null, null))
        ->toThrow(InvalidAuctionTransition::class, 'draft');
});

it('refuses to reopen a settled auction', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 100);
    $settled = $this->lifecycle->settle($this->close->handle($auction, force: true));

    expect($settled->status->isTerminal())->toBeTrue()
        ->and(fn (): Auction => $this->lifecycle->apply($settled, AuctionStatus::Live, null, null))
        ->toThrow(InvalidAuctionTransition::class);
});

it('refuses to settle an auction that has no winner', function (): void {
    $auction = liveAuction();
    $unsold = $this->close->handle($auction, force: true);

    expect($unsold->status)->toBe(AuctionStatus::Unsold)
        ->and(fn (): Auction => $this->lifecycle->settle($unsold))
        ->toThrow(InvalidAuctionTransition::class, 'awaiting settlement');
});

it('refuses an invalid status straight through the database', function (): void {
    $auction = Auction::factory()->create();

    expect(fn () => DB::table('auctions')->where('id', $auction->id)->update(['status' => 'winning']))
        ->toThrow(QueryException::class);
});

it('records every transition with its reason', function (): void {
    $auction = liveAuction();
    $this->lifecycle->cancel($auction, 'Product damaged in the warehouse.');

    $transitions = $auction->transitions()->orderBy('id')->get();

    expect($transitions->pluck('to_status')->all())->toBe([
        AuctionStatus::Draft, AuctionStatus::Live, AuctionStatus::Cancelled,
    ])->and($transitions->last()->reason)->toBe('Product damaged in the warehouse.')
        ->and($transitions->last()->from_status)->toBe(AuctionStatus::Live);
});

it('keeps transition history append-only', function (): void {
    $auction = liveAuction();
    $transition = $auction->transitions()->first();

    expect(fn () => DB::table('auction_transitions')->where('id', $transition->id)
        ->update(['reason' => 'rewritten']))
        ->toThrow(QueryException::class, 'append-only');
});

// -------------------------------------------------------------- Scheduling

it('reserves a unit when an auction is scheduled', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 3);

    $auction = app(CreateAuction::class)->handle(
        $product->fresh(),
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    );

    $this->lifecycle->schedule($auction, now()->addHour());

    expect($auction->fresh()->status)->toBe(AuctionStatus::Scheduled)
        ->and($product->fresh()->stock_reserved)->toBe(1)
        ->and($product->fresh()->availableStock())->toBe(2);
});

it('does not reserve a second unit when a scheduled auction opens', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 2);

    $auction = app(CreateAuction::class)->handle(
        $product->fresh(),
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    );

    $this->lifecycle->schedule($auction, now()->addHour());
    $this->lifecycle->start($auction->fresh());

    expect($product->fresh()->stock_reserved)->toBe(1);
});

it('refuses to publish an auction with nothing available to reserve', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    liveAuction(product: $product->fresh());

    $second = app(CreateAuction::class)->handle(
        $product->fresh(),
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    );

    expect(fn (): Auction => $this->lifecycle->start($second))
        ->toThrow(InvalidStockMovement::class);

    expect($second->fresh()->status)->toBe(AuctionStatus::Draft);
});

it('runs several auctions on one product while stock covers them', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 3);

    foreach (range(1, 3) as $ignored) {
        $auction = app(CreateAuction::class)->handle(
            $product->fresh(),
            AuctionRuleset::factory()->active()->create(),
            Money::fromMinor(10_000),
        );

        $this->lifecycle->start($auction);
    }

    expect($product->fresh()->stock_reserved)->toBe(3)
        ->and(Auction::where('status', AuctionStatus::Live)->count())->toBe(3);
});

it('gives the unit back when an auction is cancelled', function (): void {
    $auction = liveAuction();

    $this->lifecycle->cancel($auction, 'Withdrawn.');

    expect($auction->product->fresh()->stock_reserved)->toBe(0)
        ->and($auction->fresh()->closure_reason)->toBe(AuctionClosureReason::Cancelled)
        ->and($auction->fresh()->cancelled_at)->not->toBeNull();
});

it('takes no stock movement when a draft is cancelled', function (): void {
    $auction = Auction::factory()->create();

    $this->lifecycle->cancel($auction, 'Never published.');

    expect($auction->fresh()->status)->toBe(AuctionStatus::Cancelled)
        ->and(InventoryTransaction::where('product_id', $auction->product_id)->count())->toBe(0);
});

it('sells the unit when a winner settles', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 100);
    $closed = $this->close->handle($auction, force: true);

    $this->lifecycle->settle($closed);

    $product = $auction->product->fresh();

    expect($product->stock_on_hand)->toBe(0)
        ->and($product->stock_reserved)->toBe(0)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

// --------------------------------------------------------------- Forfeiture

it('forfeits a winner who did not settle in time', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(), 100);
    $closed = $this->close->handle($auction, force: true);

    $forfeited = $this->lifecycle->forfeit($closed);

    expect($forfeited->status)->toBe(AuctionStatus::Forfeited)
        ->and($forfeited->closure_reason)->toBe(AuctionClosureReason::Forfeited)
        // The unit goes back so it can be sold again.
        ->and($auction->product->fresh()->stock_reserved)->toBe(0);
});

it('returns no credits when a winner forfeits', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $this->lifecycle->forfeit($this->close->handle($auction, force: true));

    // Forfeiting does not undo the bidding. The credits stay consumed.
    expect(creditWalletFor($winner)->fresh()->balance)->toBe(400);
});

// ---------------------------------------------------------------- The clock

it('takes its end time from the frozen base duration', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->create(['base_duration_seconds' => 600]);

    $auction = liveAuction(ruleset: $ruleset);

    expect($auction->starts_at)->not->toBeNull()
        ->and($auction->starts_at->diffInSeconds($auction->ends_at))->toEqualWithDelta(600, 1);
});

it('counts down on the server rather than in the browser', function (): void {
    $auction = liveAuction();

    $remaining = $this->clock->secondsRemaining($auction);

    expect($remaining)->toBeInt()->toBeGreaterThan(0)->toBeLessThanOrEqual(300);
});

it('reports no time remaining once the clock has run out', function (): void {
    $auction = Auction::factory()->expired()->create();

    expect($this->clock->secondsRemaining($auction))->toBe(0)
        ->and($this->clock->hasExpired($auction))->toBeTrue();
});

it('is unaffected by time passing while nothing runs', function (): void {
    $auction = liveAuction();
    $endsAt = $auction->ends_at;

    // Nothing polls, no page is open, no process is alive. The auction ends
    // when its timestamp says it does.
    $this->travel(10)->minutes();

    expect($auction->fresh()->ends_at->equalTo($endsAt))->toBeTrue()
        ->and($this->clock->hasExpired($auction->fresh()))->toBeTrue();
});

// ------------------------------------------------------------- Extensions

it('extends the clock for a bid inside the closing window', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 60,
        'extension_seconds' => 30,
        'max_extensions' => 5,
        'max_extension_total_seconds' => 150,
    ]);

    $auction = liveAuction(ruleset: $ruleset);
    $endsAt = $auction->ends_at;

    // Move into the closing window.
    $this->travel(250)->seconds();

    placeBid($auction->fresh(), bidder(), 100);

    $auction->refresh();

    expect($auction->extensions_applied)->toBe(1)
        ->and($auction->extension_seconds_applied)->toBe(30)
        ->and($auction->ends_at->diffInSeconds($endsAt))->toEqualWithDelta(-30, 1);
});

it('does not extend for a bid placed early in the auction', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 10,
        'extension_seconds' => 30,
        'max_extensions' => 5,
        'max_extension_total_seconds' => 150,
    ]);

    $auction = liveAuction(ruleset: $ruleset);
    $endsAt = $auction->ends_at;

    placeBid($auction, bidder(), 100);

    expect($auction->fresh()->extensions_applied)->toBe(0)
        ->and($auction->fresh()->ends_at->equalTo($endsAt))->toBeTrue();
});

/*
 * Time is moved forward between bids on purpose. Each extension pushes the end
 * time further away, which takes the auction back out of its closing window --
 * so bidding repeatedly at the same instant extends once and then stops, for a
 * reason that has nothing to do with the limits under test.
 */
it('stops extending once the total budget is spent', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 300,
        'extension_seconds' => 30,
        'max_extensions' => 100,
        'max_extension_total_seconds' => 60,
    ]);

    $auction = liveAuction(ruleset: $ruleset);

    foreach (range(1, 5) as $ignored) {
        placeBid($auction->fresh(), bidder(), 100);
        $this->travel(60)->seconds();
    }

    $auction->refresh();

    // Two extensions of 30 seconds and no more, however many bids arrive.
    expect($auction->extension_seconds_applied)->toBe(60)
        ->and($auction->extensions_applied)->toBe(2);
});

it('stops extending once the count limit is reached', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 300,
        'extension_seconds' => 10,
        'max_extensions' => 3,
        'max_extension_total_seconds' => 3_000,
    ]);

    $auction = liveAuction(ruleset: $ruleset);

    foreach (range(1, 6) as $ignored) {
        placeBid($auction->fresh(), bidder(), 100);
        $this->travel(40)->seconds();
    }

    expect($auction->fresh()->extensions_applied)->toBe(3);
});

/*
 * The ceiling the rules promise, honoured exactly: an auction cannot be
 * extended indefinitely by determined bidders.
 */
it('never runs longer than its rules allow', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 300,
        'extension_seconds' => 30,
        'max_extensions' => 100,
        'max_extension_total_seconds' => 60,
    ]);

    $auction = liveAuction(ruleset: $ruleset);
    $startedAt = $auction->starts_at;

    foreach (range(1, 10) as $ignored) {
        placeBid($auction->fresh(), bidder(), 100);
        $this->travel(30)->seconds();
    }

    $auction->refresh();

    expect($startedAt->diffInSeconds($auction->ends_at))
        ->toEqualWithDelta($auction->rules()->maximumPossibleDurationSeconds(), 1)
        ->toEqualWithDelta(360, 1);
});

it('never extends when extensions are switched off', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->withoutExtensions()
        ->create(['closing_window_seconds' => 0]);

    $auction = liveAuction(ruleset: $ruleset);
    $endsAt = $auction->ends_at;

    $this->travel(290)->seconds();
    placeBid($auction->fresh(), bidder(), 100);

    expect($auction->fresh()->ends_at->equalTo($endsAt))->toBeTrue();
});

/*
 * The point of the correction stage, restated as behaviour: extension buys
 * everyone time, not the extender a win.
 */
it('does not let a late bid win by extending the clock', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 60,
        'extension_seconds' => 30,
        'max_extensions' => 5,
        'max_extension_total_seconds' => 150,
    ]);

    $auction = liveAuction(ruleset: $ruleset);
    $big = bidder(1_000);
    $sniper = bidder(1_000);

    placeBid($auction, $big, 500);

    $this->travel(250)->seconds();

    // A late, small bid. It extends the auction and loses anyway.
    placeBid($auction->fresh(), $sniper, 50);

    expect($auction->fresh()->extensions_applied)->toBe(1);

    $closed = $this->close->handle($auction->fresh(), force: true);

    expect($closed->winner_user_id)->toBe($big->id);
});

// ---------------------------------------------------------------- Relisting

it('relists an unsold auction as a new one', function (): void {
    $auction = liveAuction();
    $unsold = $this->close->handle($auction, force: true);

    $replacement = app(RelistAuction::class)->handle(
        $unsold,
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(7_500),
    );

    expect($unsold->fresh()->status)->toBe(AuctionStatus::Relisted)
        ->and($replacement->status)->toBe(AuctionStatus::Draft)
        ->and($replacement->product_id)->toBe($auction->product_id)
        // A fresh snapshot, not the old one carried over.
        ->and($replacement->settlement_amount_minor)->toBe(7_500)
        ->and($replacement->id)->not->toBe($unsold->id);
});

it('keeps the old auction bids on the old auction', function (): void {
    $auction = liveAuction();
    $user = bidder(500);
    placeBid($auction, $user, 100);
    $forfeited = $this->lifecycle->forfeit($this->close->handle($auction, force: true));

    $replacement = app(RelistAuction::class)->handle(
        $forfeited,
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    );

    // Credits spent on the old auction do not carry over, and earn no
    // discount on the new one.
    expect($replacement->bids()->count())->toBe(0)
        ->and(app(BuyNowPricer::class)
            ->quote($replacement, $user)->eligibleCredits)->toBe(0)
        ->and($auction->fresh()->bids()->count())->toBe(1);
});

it('refuses to relist an auction that is still running', function (): void {
    $auction = liveAuction();

    expect(fn (): Auction => app(RelistAuction::class)->handle(
        $auction,
        AuctionRuleset::factory()->active()->create(),
        Money::fromMinor(10_000),
    ))->toThrow(InvalidAuctionTransition::class, 'cannot be relisted');
});
