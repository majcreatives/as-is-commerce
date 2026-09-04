<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Enums\AuctionClosureReason;
use App\Enums\AuctionStatus;
use App\Enums\InventoryTransactionType;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The races that decide who ends up with the product, against real MySQL
 * locks.
 *
 * Truncation rather than a wrapping transaction: a second connection cannot
 * see rows an uncommitted transaction has written, so under RefreshDatabase
 * these tests would observe an empty database and pass without exercising
 * anything.
 *
 * The invariant every test here defends: one successful payment produces one
 * fulfilled order and one inventory sale. Never two.
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

/*
 * The primitive the rest rests on. Without a real lock on the order row, two
 * deliveries of the same payment both read "not yet paid" and both proceed.
 */
it('serializes access to an order row across connections', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);
    $order = buyNowCheckout(bidder(), $product->fresh());

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

    expect($blocked)->toBeTrue('A second connection must not take the order lock while it is held.');
});

// ------------------------------------------------------- Buy Now vs Buy Now

/*
 * Two customers, one unit, no auction. The reservation settles it before
 * either of them reaches a payment page.
 */
it('lets only one customer open a checkout on the last catalog unit', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $opened = 0;
    $refused = 0;

    foreach (range(1, 3) as $ignored) {
        try {
            buyNowCheckout(bidder(), Product::findOrFail($product->id));
            $opened++;
        } catch (InvalidCheckout) {
            $refused++;
        }
    }

    expect($opened)->toBe(1)
        ->and($refused)->toBe(2)
        ->and($product->fresh()->stock_reserved)->toBe(1)
        ->and($product->fresh()->availableStock())->toBe(0);
});

/*
 * On an auction, both customers may open a checkout -- neither reserves
 * anything, because the auction is holding the unit. The race is decided at
 * payment, which is exactly where it should be: nobody is blocked from trying,
 * and only one succeeds.
 */
it('lets only the first paid Buy Now take an auctioned product', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $orders = collect(range(1, 3))->map(
        fn (): Order => buyNowCheckout(bidder(), $product->fresh(), $auction->fresh())
    );

    expect($orders)->toHaveCount(3);

    $completed = 0;
    $blocked = 0;

    foreach ($orders as $order) {
        payOrder(Order::findOrFail($order->id));

        Order::findOrFail($order->id)->isFulfilmentBlocked() ? $blocked++ : $completed++;
    }

    $auction->refresh();

    // Every payment succeeded -- they were real payments. Exactly one bought
    // the product.
    expect($completed)->toBe(1)
        ->and($blocked)->toBe(2)
        ->and($auction->status)->toBe(AuctionStatus::Settled)
        ->and($auction->closure_reason)->toBe(AuctionClosureReason::BuyNow)
        // One unit, one sale.
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

// -------------------------------------------------- Buy Now vs settlement

/*
 * A customer opens a Buy Now checkout while the auction is live, then dawdles.
 * The auction closes with a winner. Both then try to pay.
 */
it('lets only one of a Buy Now and a settlement acquire the product', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);
    $buyer = bidder();

    placeBid($auction, $winner, 200);

    $buyNowOrder = buyNowCheckout($buyer, $product->fresh(), $auction->fresh());

    // The clock runs out and the winner is named.
    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    $settlementOrder = settlementCheckout($auction->fresh(), $winner);

    // The winner settles first.
    payOrder($settlementOrder);

    // The Buy Now payment then succeeds against an auction that is gone.
    payOrder($buyNowOrder);

    $auction->refresh();

    expect($auction->status)->toBe(AuctionStatus::Settled)
        // Won on the highest bid, not bought out.
        ->and($auction->closure_reason)->toBe(AuctionClosureReason::HighestBid)
        ->and($auction->winner_user_id)->toBe($winner->id)
        ->and($auction->buy_now_user_id)->toBeNull();

    // The late buyer's money is recorded and their order is flagged for a
    // person, rather than being silently marked complete or silently lost.
    expect($settlementOrder->fresh()->isFulfilmentBlocked())->toBeFalse()
        ->and($buyNowOrder->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($buyNowOrder->fresh()->isFulfilmentBlocked())->toBeTrue();

    // And still exactly one sale.
    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0);
});

it('lets a Buy Now win when it pays before the auction closes', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $leader = bidder(2_000);
    $buyer = bidder();

    placeBid($auction, $leader, 900);

    $order = buyNowCheckout($buyer, $product->fresh(), $auction->fresh());
    payOrder($order);

    // A closing sweep arriving afterwards finds nothing to do.
    app(CloseAuction::class)->handle($auction->fresh(), force: true);

    $auction->refresh();

    expect($auction->closure_reason)->toBe(AuctionClosureReason::BuyNow)
        ->and($auction->buy_now_user_id)->toBe($buyer->id)
        ->and($auction->winner_user_id)->toBeNull()
        // The leader's credits stay consumed.
        ->and(creditWalletFor($leader)->fresh()->balance)->toBe(1_100)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

// -------------------------------------------------- Repeated confirmations

it('produces one fulfilment when the same payment is confirmed many times', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    $payment = initializePayment($order);

    foreach (range(1, 6) as $ignored) {
        payOrder(Order::findOrFail($order->id), $payment->fresh());
    }

    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and($product->fresh()->stock_on_hand)->toBe(0)
        ->and(Order::findOrFail($order->id)->transitions()
            ->where('to_status', OrderStatus::Paid)->count())->toBe(1);
});

it('produces one fulfilment when a webhook and a callback race', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    $payment = initializePayment($order);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    $fulfil = app(FulfillOrderPayment::class);

    // Two routes into the same path, back to back.
    $first = $fulfil->handle($payment->fresh());
    $second = $fulfil->handle($payment->fresh());

    expect($first['already_fulfilled'])->toBeFalse()
        ->and($second['already_fulfilled'])->toBeTrue()
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1);
});

// ---------------------------------------------------------- Stale state

it('does not fulfil against a checkout that expired while paying', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    $payment = initializePayment($order);

    // The window closes and the sweep gives the unit back.
    $this->travel(2)->hours();
    app(OrderLifecycle::class)->expire(Order::findOrFail($order->id));

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => 'GHS',
    ]);

    // This used to throw, which returned a 5xx and had Paystack retrying the
    // same delivery forever. Under the ruled policy the payment is recorded --
    // it really did succeed -- and the order keeps its terminal status: a
    // payment success records a financial event, it does not resurrect a
    // closed order.
    app(FulfillOrderPayment::class)->handle($payment->fresh());

    $order = Order::findOrFail($order->id);

    expect($order->status)->toBe(OrderStatus::PaymentExpired)
        ->and($order->status)->not->toBe(OrderStatus::Paid)
        ->and($order->isFulfilmentBlocked())->toBeTrue()
        ->and($payment->fresh()->status)->toBe(OrderPaymentStatus::Success);

    // No sale, and the unit really is back on the shelf.
    expect(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(0)
        ->and($product->fresh()->availableStock())->toBe(1);
});

it('does not let two customers buy one unit through separate expiries', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $first = buyNowCheckout(bidder(), $product->fresh());

    // The first customer walks away and their hold lapses.
    $this->travel(2)->hours();
    app(OrderLifecycle::class)->expire(Order::findOrFail($first->id));

    // A second customer takes the unit and pays.
    $second = buyNowCheckout(bidder(), $product->fresh());
    payOrder($second);

    expect($product->fresh()->stock_on_hand)->toBe(0)
        ->and(InventoryTransaction::where('type', InventoryTransactionType::Sale)->count())->toBe(1)
        ->and(Order::findOrFail($first->id)->status)->toBe(OrderStatus::PaymentExpired);
});

// ------------------------------------------------------- Ledger integrity

it('consumes no credits through any of these races', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $bidder = bidder(1_000);

    placeBid($auction, $bidder, 200);
    $before = creditWalletFor($bidder)->fresh()->balance;

    $order = buyNowCheckout($bidder, $product->fresh(), $auction->fresh());
    payOrder($order);

    // Paying in cedis moves no credits, in either direction.
    expect(creditWalletFor($bidder)->fresh()->balance)->toBe($before)->toBe(800);
});
