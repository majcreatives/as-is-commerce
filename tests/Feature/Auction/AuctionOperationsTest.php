<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CancelAuction;
use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Auction\Actions\ForfeitAuction;
use App\Domain\Auction\Contracts\SettlementHandoff;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Auction\Exceptions\InvalidAuctionTransition;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Shared\Money\Money;
use App\Enums\AuctionStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Auction;
use App\Models\AuctionRuleset;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;

/*
 * Operating an auction end to end: closing hands the winner a real checkout,
 * and every way an auction can stop closes that checkout with it.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->close = app(CloseAuction::class);
    $this->lifecycle = app(AuctionLifecycle::class);
});

// ------------------------------------------------------- Settlement handoff

it('hands the winner a settlement checkout when the auction closes', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);

    placeBid($auction, $winner, 180);

    $closed = $this->close->handle($auction, force: true);

    $order = $closed->settlementOrder;

    // The obligation exists the moment it is incurred, not when the winner
    // happens to visit the page with the deadline already running.
    expect($order)->not->toBeNull()
        ->and($order->user_id)->toBe($winner->id)
        ->and($order->source)->toBe(OrderSource::AuctionWin)
        ->and($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->total_minor)->toBe(10_000)
        ->and($order->winning_bid_id)->toBe($closed->winning_bid_id);
});

it('prices that checkout at the settlement amount and nothing else', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);

    placeBid($auction, $winner, 180);
    $order = $this->close->handle($auction, force: true)->settlementOrder;

    expect($order->total_minor)->toBe(10_000)
        // Not the product price, and not the bid as cedis.
        ->and($order->total_minor)->not->toBe(550_000)
        ->and($order->total_minor)->not->toBe(18_000)
        ->and($order->discount_minor)->toBe(0)
        ->and($order->pricing()->winningBidCredits)->toBe(180);
});

it('opens no checkout when nobody bid', function (): void {
    $auction = liveAuction();

    $closed = $this->close->handle($auction, force: true);

    expect($closed->status)->toBe(AuctionStatus::Unsold)
        ->and($closed->settlementOrder)->toBeNull()
        ->and(Order::count())->toBe(0);
});

it('opens one checkout however many times closing runs', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 100);

    foreach (range(1, 3) as $ignored) {
        $this->close->handle($auction->fresh(), force: true);
    }

    expect(Order::where('source', OrderSource::AuctionWin)->count())->toBe(1);
});

/*
 * The contract's promise: an auction that closed correctly must not be left
 * open because its paperwork failed. The highest bid won, the credits are
 * consumed, and an administrator can see a PendingSettlement auction with no
 * order -- which is far better than an auction that never closed.
 */
it('closes the auction even when the checkout cannot be opened', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    // A handoff that cannot open anything, standing in for a downstream
    // failure. The contract permits it to return null and closing must cope.
    app()->bind(SettlementHandoff::class, fn (): SettlementHandoff => new class implements SettlementHandoff
    {
        public function openFor(Auction $auction): ?int
        {
            return null;
        }

        public function closeFor(Auction $auction, string $reason): ?int
        {
            return null;
        }
    });

    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);

    expect($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($closed->winner_user_id)->toBe($winner->id)
        ->and($closed->winning_bid_id)->not->toBeNull()
        ->and($closed->settlementOrder)->toBeNull();
});

it('consumes no credits when a settlement checkout is opened', function (): void {
    $auction = liveAuction();
    $winner = bidder(1_000);

    placeBid($auction, $winner, 250);
    $before = creditWalletFor($winner)->fresh()->balance;

    $this->close->handle($auction, force: true);

    expect(creditWalletFor($winner)->fresh()->balance)->toBe($before)->toBe(750);
});

// ------------------------------------------------------------- Forfeiture

it('closes the winner checkout when the deadline lapses', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->create(['checkout_deadline_minutes' => 30]);

    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product, ruleset: $ruleset);
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $order = $this->close->handle($auction, force: true)->settlementOrder;
    expect($order->status)->toBe(OrderStatus::PendingPayment);

    $this->travel(40)->minutes();
    app(ForfeitAuction::class)->handle($auction->fresh());

    // Both, together. A forfeited auction with a payable order would let the
    // winner buy a unit that had just gone back on sale.
    expect($auction->fresh()->status)->toBe(AuctionStatus::Forfeited)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($product->fresh()->availableStock())->toBe(1);
});

it('returns no credits when a winner forfeits', function (): void {
    $auction = liveAuction();
    $winner = bidder(1_000);
    placeBid($auction, $winner, 250);

    $this->close->handle($auction, force: true);
    $this->travel(2)->hours();
    app(ForfeitAuction::class)->handle($auction->fresh());

    expect(creditWalletFor($winner)->fresh()->balance)->toBe(750);
});

/*
 * Nobody acquired the product when the winner forfeits, so no qualifying
 * bidder receives Store Wallet value (AGENTS.md §45). The loser's consumed
 * purchased credits were spent on the attempt; they are recorded, and the
 * platform owes nothing merely because the winner did not pay.
 */
it('issues no Store Wallet when a winner forfeits', function (): void {
    $auction = liveAuction();
    $loser = customerWithPurchasedCredits(1_000, 10_000);
    $winner = bidder(1_000);
    placeBid($auction, $loser, 200);
    placeBid($auction, $winner, 500);

    $this->close->handle($auction, force: true);

    // The loser is compensated for the loss -- a real acquirer won -- even
    // though the winner will forfeit; that issuance stands. The forfeit itself
    // must not compensate anyone a second time, the winner included.
    expect(storeWalletBalance($loser)->isPositive())->toBeTrue();

    $this->travel(2)->hours();
    app(ForfeitAuction::class)->handle($auction->fresh());

    expect(storeWalletBalance($loser)->toDecimalString())->toBe('20.00')
        ->and(storeWalletBalance($winner)->isZero())->toBeTrue();
});

it('forfeits once when run repeatedly', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 100);
    $this->close->handle($auction, force: true);

    $this->travel(2)->hours();

    foreach (range(1, 3) as $ignored) {
        app(ForfeitAuction::class)->handle($auction->fresh());
    }

    expect($auction->fresh()->transitions()
        ->where('to_status', AuctionStatus::Forfeited)->count())->toBe(1)
        // And one release, not three.
        ->and($auction->product->fresh()->availableStock())->toBe(1);
});

it('leaves a settled auction alone when the sweep reaches it', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $order = $this->close->handle($auction, force: true)->settlementOrder;
    payOrder($order);

    $this->travel(2)->hours();
    app(ForfeitAuction::class)->handle($auction->fresh());

    // Settled is terminal. A late sweep must not undo a completed sale.
    expect($auction->fresh()->status)->toBe(AuctionStatus::Settled)
        ->and($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('does not allow a late settlement payment to complete quietly', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $order = $this->close->handle($auction, force: true)->settlementOrder;
    $payment = initializePayment($order);

    // The deadline passes and the sweep forfeits.
    $this->travel(2)->hours();
    app(ForfeitAuction::class)->handle($auction->fresh());

    // The payment then lands. It is recorded, because the money is real, and
    // flagged -- never quietly completed against a released unit.
    payOrder($order->fresh(), $payment->fresh());

    expect($order->fresh()->isFulfilmentBlocked())->toBeTrue()
        ->and($auction->fresh()->status)->toBe(AuctionStatus::Forfeited)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0)
        ->and($product->fresh()->availableStock())->toBe(1);
});

// ------------------------------------------------------------ Cancellation

it('closes the winner checkout when an auction is cancelled', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $order = $this->close->handle($auction, force: true)->settlementOrder;

    app(CancelAuction::class)->handle($auction->fresh(), 'Product damaged in the warehouse.');

    expect($auction->fresh()->status)->toBe(AuctionStatus::Cancelled)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($product->fresh()->availableStock())->toBe(1);
});

it('leaves a paid settlement alone when an auction is cancelled', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $order = $this->close->handle($auction, force: true)->settlementOrder;
    payOrder($order);

    // Settled is terminal, so cancelling is refused outright. The completed
    // transaction stands.
    expect(fn () => app(CancelAuction::class)->handle($auction->fresh(), 'Too late.'))
        ->toThrow(InvalidAuctionTransition::class);

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('returns no credits when an auction is cancelled', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);
    placeBid($auction, $bidder, 300);

    app(CancelAuction::class)->handle($auction, 'Withdrawn.');

    expect(creditWalletFor($bidder)->fresh()->balance)->toBe(700);
});

/*
 * Cancellation with no acquiring customer issues no Store Wallet either
 * (AGENTS.md §45). A bidder who spent purchased credits on the attempt gets
 * neither credits nor cash value back; deciding whether the platform owes
 * anything for stopping an auction is a deliberate business decision, not
 * something this action may infer.
 */
it('issues no Store Wallet when an auction is cancelled', function (): void {
    $auction = liveAuction();
    $bidder = customerWithPurchasedCredits(1_000, 10_000);
    placeBid($auction, $bidder, 200);

    app(CancelAuction::class)->handle($auction, 'Withdrawn.');

    expect(creditWalletFor($bidder)->fresh()->balance)->toBe(800)
        ->and(storeWalletBalance($bidder)->isZero())->toBeTrue();
});

// -------------------------------------------------- Activation and closing

it('activates a scheduled auction without any browser involved', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $auction = app(CreateAuction::class)->handle(
        $product->fresh(),
        AuctionRuleset::factory()->active()->withoutThrottle()->create(),
        Money::fromMinor(10_000),
    );

    $this->lifecycle->schedule($auction, now()->addMinutes(5));

    $this->artisan('auctions:tick')->assertSuccessful();
    expect($auction->fresh()->status)->toBe(AuctionStatus::Scheduled);

    $this->travel(10)->minutes();
    $this->artisan('auctions:tick')->assertSuccessful();

    expect($auction->fresh()->status)->toBe(AuctionStatus::Live)
        ->and($auction->fresh()->ends_at)->not->toBeNull()
        // Scheduling already reserved the unit; activating must not take a
        // second one.
        ->and($product->fresh()->stock_reserved)->toBe(1);
});

it('activates once when the command runs repeatedly', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $auction = app(CreateAuction::class)->handle(
        $product->fresh(),
        AuctionRuleset::factory()->active()->withoutThrottle()->create(),
        Money::fromMinor(10_000),
    );

    $this->lifecycle->schedule($auction, now()->addMinute());
    $this->travel(5)->minutes();

    foreach (range(1, 3) as $ignored) {
        $this->artisan('auctions:tick')->assertSuccessful();
    }

    expect($auction->fresh()->transitions()
        ->where('to_status', AuctionStatus::Live)->count())->toBe(1)
        ->and($product->fresh()->stock_reserved)->toBe(1);
});

it('runs the whole clock end to end without a browser', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()
        ->create(['base_duration_seconds' => 300, 'closing_window_seconds' => 60]);

    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product, ruleset: $ruleset);
    $winner = bidder(500);

    placeBid($auction, $winner, 150);

    // Into the closing window.
    $this->travel(250)->seconds();
    $this->artisan('auctions:tick');
    expect($auction->fresh()->status)->toBe(AuctionStatus::Closing);

    // Past the end.
    $this->travel(120)->seconds();
    $this->artisan('auctions:tick');

    $closed = $auction->fresh();

    expect($closed->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($closed->winner_user_id)->toBe($winner->id)
        // And the winner has a checkout waiting.
        ->and($closed->settlementOrder)->not->toBeNull();
});

it('closes each auction once when the command runs repeatedly', function (): void {
    $auction = liveAuction();
    placeBid($auction, bidder(500), 100);

    $this->travel(10)->minutes();

    foreach (range(1, 3) as $ignored) {
        $this->artisan('auctions:tick')->assertSuccessful();
    }

    expect($auction->fresh()->transitions()
        ->where('to_status', AuctionStatus::PendingSettlement)->count())->toBe(1)
        ->and(Order::where('source', OrderSource::AuctionWin)->count())->toBe(1);
});

/*
 * The boundary case: a bid arriving as the clock runs out. It either lands
 * before the close and counts, or after it and is refused -- never both, and
 * never a bid accepted into a closed auction.
 */
it('refuses a bid once the auction has closed on the clock', function (): void {
    $auction = liveAuction();
    $early = bidder(1_000);
    $late = bidder(1_000);

    placeBid($auction, $early, 100);

    $this->travel(10)->minutes();
    $this->artisan('auctions:tick');

    expect(fn () => placeBid($auction->fresh(), $late, 900))
        ->toThrow(BidRejected::class);

    expect($auction->fresh()->winner_user_id)->toBe($early->id)
        ->and($auction->fresh()->highest_bid_credits)->toBe(100)
        // The late bidder's credits were never taken.
        ->and(creditWalletFor($late)->fresh()->balance)->toBe(1_000);
});

it('does not close an auction a late bid has just extended', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'base_duration_seconds' => 300,
        'closing_window_seconds' => 60,
        'extension_seconds' => 120,
        'max_extensions' => 5,
        'max_extension_total_seconds' => 600,
    ]);

    $auction = liveAuction(ruleset: $ruleset);

    $this->travel(280)->seconds();
    placeBid($auction->fresh(), bidder(500), 100);

    expect($auction->fresh()->extensions_applied)->toBe(1);

    // The clock would have run out by now without the extension.
    $this->travel(40)->seconds();
    $this->artisan('auctions:tick');

    expect($auction->fresh()->status->acceptsBids())->toBeTrue();
});
