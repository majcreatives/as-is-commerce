<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Exceptions\BidRejected;
use App\Domain\Catalog\Exceptions\InvalidStockMovement;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Auction;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The operational races, against real MySQL locks.
 *
 * Truncation rather than a wrapping transaction: a second connection cannot
 * see rows an uncommitted transaction has written, so under RefreshDatabase
 * these would observe an empty database and pass without exercising anything.
 *
 * The invariant every test here defends: one physical unit is acquired once,
 * by one person, whichever legitimate paths were competing for it.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    config(['database.connections.second' => config('database.connections.mysql')]);
    DB::purge('second');
});

afterEach(function (): void {
    try {
        while (DB::connection('second')->transactionLevel() > 0) {
            DB::connection('second')->rollBack();
        }
    } catch (Throwable) {
        // Connection already gone.
    }

    DB::purge('second');
});

/**
 * A closed auction with a winner who has a settlement checkout waiting.
 *
 * @return array{Auction, Order, Product, User}
 */
function closedAuctionWithWinner(int $settlementMinor = 10_000): array
{
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: $settlementMinor);
    $winner = bidder(1_000);

    placeBid($auction, $winner, 200);

    $closed = app(CloseAuction::class)->handle($auction, force: true);

    return [$closed, $closed->settlementOrder, $product, $winner];
}

// -------------------------------------------- Settlement vs settlement

/*
 * The same paid settlement, fulfilled by two workers at once. Only one may
 * sell the unit.
 */
it('sells once when two workers fulfil the same settlement', function (): void {
    [, $order, $product] = closedAuctionWithWinner();
    $payment = initializePayment($order);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    $fulfil = app(FulfillOrderPayment::class);

    $first = $fulfil->handle($payment->fresh());
    $second = $fulfil->handle($payment->fresh());
    $third = $fulfil->handle($payment->fresh());

    expect($first['already_fulfilled'])->toBeFalse()
        ->and($second['already_fulfilled'])->toBeTrue()
        ->and($third['already_fulfilled'])->toBeTrue()
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and(Order::findOrFail($order->id)->transitions()
            ->where('to_status', OrderStatus::Paid)->count())->toBe(1);
});

it('settles the auction once across repeated fulfilment', function (): void {
    [$auction, $order] = closedAuctionWithWinner();
    $payment = initializePayment($order);

    foreach (range(1, 4) as $ignored) {
        payOrder(Order::findOrFail($order->id), $payment->fresh());
    }

    expect(Auction::findOrFail($auction->id)->transitions()
        ->where('to_status', AuctionStatus::Settled)->count())->toBe(1);
});

// ----------------------------------------- Settlement vs Buy Now payment

/*
 * Race B from the brief: the winner starts settling while somebody else's
 * Buy Now payment completes. Only one acquires the unit; the other's money is
 * recorded and flagged.
 */
it('lets only one of a settlement and a Buy Now acquire the unit', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(1_000);
    $buyer = bidder();

    placeBid($auction, $winner, 200);

    // A Buy Now checkout opened while the auction was still live.
    $buyNowOrder = buyNowCheckout($buyer, $product->fresh(), $auction->fresh());

    // The auction then closes and the winner gets a settlement checkout.
    $closed = app(CloseAuction::class)->handle($auction->fresh(), force: true);
    $settlementOrder = $closed->settlementOrder;

    // Both pay. The settlement lands first.
    payOrder($settlementOrder);
    payOrder($buyNowOrder);

    $auction = Auction::findOrFail($auction->id);

    expect($auction->status)->toBe(AuctionStatus::Settled)
        ->and($auction->closure_reason)->toBe(AuctionClosureReason::HighestBid)
        ->and($auction->winner_user_id)->toBe($winner->id)
        ->and($auction->buy_now_user_id)->toBeNull();

    // One sale. The Buy Now buyer's payment is real, recorded and flagged.
    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and(Order::findOrFail($settlementOrder->id)->isFulfilmentBlocked())->toBeFalse()
        ->and(Order::findOrFail($buyNowOrder->id)->status)->toBe(OrderStatus::Paid)
        ->and(Order::findOrFail($buyNowOrder->id)->isFulfilmentBlocked())->toBeTrue();
});

/*
 * Race A, the other way round: the Buy Now lands first, so the auction never
 * reaches a winner at all and the settlement never exists.
 */
it('lets a Buy Now take the unit before the auction ever closes', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $leader = bidder(2_000);
    $buyer = bidder();

    placeBid($auction, $leader, 900);

    payOrder(buyNowCheckout($buyer, $product->fresh(), $auction->fresh()));

    // A closing sweep arriving afterwards finds nothing to do.
    app(CloseAuction::class)->handle(Auction::findOrFail($auction->id), force: true);

    $auction = Auction::findOrFail($auction->id);

    expect($auction->closure_reason)->toBe(AuctionClosureReason::BuyNow)
        ->and($auction->buy_now_user_id)->toBe($buyer->id)
        ->and($auction->winner_user_id)->toBeNull()
        // No settlement checkout was ever opened, because there is no winner.
        ->and(Order::where('source', OrderSource::AuctionWin)->count())->toBe(0)
        // The leader's credits stay consumed.
        ->and(creditWalletFor($leader)->fresh()->balance)->toBe(1_100)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

// ------------------------------------------------ Payment vs closure

/*
 * Race D: an operational closing sweep runs while a Buy Now payment is being
 * completed. Whichever commits first decides, and the other finds the auction
 * already gone.
 */
it('does not let a closing sweep undo a completed Buy Now', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $leader = bidder(2_000);

    placeBid($auction, $leader, 500);

    $order = buyNowCheckout(bidder(), $product->fresh(), $auction->fresh());

    payOrder($order);

    // The clock runs out and the sweep runs. The auction has already gone.
    $this->travel(10)->minutes();
    $this->artisan('auctions:tick')->assertSuccessful();

    $auction = Auction::findOrFail($auction->id);

    expect($auction->closure_reason)->toBe(AuctionClosureReason::BuyNow)
        ->and($auction->winner_user_id)->toBeNull()
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

it('does not let a forfeit sweep undo a completed settlement', function (): void {
    [$auction, $order, $product] = closedAuctionWithWinner();

    payOrder($order);

    // The deadline passes and the sweep runs. The auction is already settled.
    $this->travel(3)->hours();
    $this->artisan('auctions:tick')->assertSuccessful();

    expect(Auction::findOrFail($auction->id)->status)->toBe(AuctionStatus::Settled)
        ->and(Order::findOrFail($order->id)->status)->toBe(OrderStatus::Paid)
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

// ------------------------------------------------ Duplicate commands

it('produces one outcome when the whole clock runs repeatedly', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    $winner = bidder(500);
    placeBid($auction, $winner, 100);

    $this->travel(10)->minutes();

    foreach (range(1, 5) as $ignored) {
        $this->artisan('auctions:tick')->assertSuccessful();
    }

    $auction = Auction::findOrFail($auction->id);

    expect($auction->status)->toBe(AuctionStatus::PendingSettlement)
        ->and($auction->transitions()->where('to_status', AuctionStatus::PendingSettlement)->count())->toBe(1)
        ->and(Order::where('source', OrderSource::AuctionWin)->count())->toBe(1)
        // Still exactly one unit reserved, never two released or two sold.
        ->and($product->fresh()->stock_reserved)->toBe(1);
});

it('forfeits and releases once when the clock runs repeatedly', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);
    placeBid($auction, bidder(500), 100);

    $this->travel(10)->minutes();
    $this->artisan('auctions:tick');

    $this->travel(3)->hours();

    foreach (range(1, 5) as $ignored) {
        $this->artisan('auctions:tick')->assertSuccessful();
    }

    expect(Auction::findOrFail($auction->id)->status)->toBe(AuctionStatus::Forfeited)
        // One release: available stock is 1, not 5.
        ->and($product->fresh()->availableStock())->toBe(1)
        ->and($product->fresh()->stock_reserved)->toBe(0);
});

// ------------------------------------------------- Bid vs credit balance

/*
 * One balance, several auctions. The wallet lock is what stops a bidder
 * committing credits they do not have across simultaneous bids.
 */
it('never lets one balance fund more bids than it covers', function (): void {
    $first = liveAuction();
    $second = liveAuction();
    $bidder = bidder(250);

    $accepted = 0;

    foreach ([$first, $second, $first, $second] as $auction) {
        try {
            placeBid(Auction::findOrFail($auction->id), $bidder, 100);
            $accepted++;
        } catch (BidRejected) {
            // Out of credits.
        }
    }

    expect($accepted)->toBe(2)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(50)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBeGreaterThanOrEqual(0);
});

// -------------------------------------------------- Inventory serialization

it('refuses a second auction on a single unit already reserved', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    liveAuction(product: $product->fresh());

    // Auction B cannot reserve what auction A is holding.
    expect(fn (): Auction => liveAuction(product: $product->fresh()))
        ->toThrow(InvalidStockMovement::class);

    expect($product->fresh()->stock_reserved)->toBe(1)
        ->and($product->fresh()->availableStock())->toBe(0);
});

it('lets stock decide how many auctions may run at once', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 3);

    foreach (range(1, 3) as $ignored) {
        liveAuction(product: $product->fresh());
    }

    expect(fn (): Auction => liveAuction(product: $product->fresh()))
        ->toThrow(InvalidStockMovement::class);

    expect(Auction::where('status', AuctionStatus::Live)->count())->toBe(3)
        ->and($product->fresh()->stock_reserved)->toBe(3);
});

it('never lets stock go negative through any operational path', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));

    $this->travel(10)->minutes();
    $this->artisan('auctions:tick');

    $fresh = $product->fresh();

    expect($fresh->stock_on_hand)->toBe(0)->toBeGreaterThanOrEqual(0)
        ->and($fresh->stock_reserved)->toBe(0)->toBeGreaterThanOrEqual(0)
        ->and($fresh->stock_reserved)->toBeLessThanOrEqual($fresh->stock_on_hand);
});

// ------------------------------------------------------ The order row lock

it('serializes access to a settlement order across connections', function (): void {
    [, $order] = closedAuctionWithWinner();

    DB::beginTransaction();
    DB::table('orders')->where('id', $order->id)->lockForUpdate()->first();

    DB::connection('second')->statement('SET SESSION innodb_lock_wait_timeout = 1');
    DB::connection('second')->beginTransaction();

    $blocked = false;

    try {
        DB::connection('second')->table('orders')
            ->where('id', $order->id)->lockForUpdate()->first();
    } catch (QueryException $e) {
        $blocked = str_contains(strtolower($e->getMessage()), 'lock wait timeout');
    }

    DB::connection('second')->rollBack();
    DB::rollBack();

    expect($blocked)->toBeTrue('A second worker must not take the order lock while it is held.');
});
