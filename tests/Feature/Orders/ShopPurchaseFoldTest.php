<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Orders\Actions\FoldShopPurchaseToCart;
use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Actions\PlaceCartOrder;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Domain\StoreWallet\Services\StoreWalletCheckout;
use App\Enums\OrderPaymentStatus;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use App\Models\StoreWalletTransaction;

/*
 * The fold, and the one rule it serves: a customer has one unfinished Shop
 * purchase, and the cart is its working surface.
 *
 * While an order is set aside awaiting payment, growing or editing the basket
 * would silently leave its frozen lines unplaced. So every cart change starts
 * by asking the pending order back into the basket -- release its reservation
 * and Store Wallet commitment, restore its lines -- and the next placement is
 * the single order covering everything. The folded order stays Cancelled
 * history. Auction-linked checkouts are never touched by any of it, expiry
 * and a failed charge restore through the same rail, and a payment verified
 * after the fold is recorded with a fulfilment block rather than thrown away.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
});

it('folds a pending order on add, merging all lines and releasing exactly once', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $second = Product::factory()->active()->pricedAt(200_000)->withStock(5)->create();
    $third = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300_000);

    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);
    $order = app(PlaceCartOrder::class)->handle($buyer);

    expect($order->store_wallet_applied_minor)->toBe(300_000);

    // Adding a third product calls the pending order back into the basket.
    app(AddToCart::class)->handle($buyer, $third->fresh(), 1);

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        // Every reservation the folded order was holding is free again.
        ->and($first->fresh()->stock_reserved)->toBe(0)
        ->and($second->fresh()->stock_reserved)->toBe(0)
        // The committed Store Wallet value came back exactly once.
        ->and(storeWalletBalance($buyer)->minor)->toBe(300_000)
        ->and(StoreWalletTransaction::where('idempotency_key', StoreWalletCheckout::releasedKeyFor($order->id))->count())
        ->toBe(1)
        // The restored lines and the new one are one editable basket again.
        ->and(Cart::query()->forUser($buyer)->firstOrFail()->items)->toHaveCount(3);
});

it('refuses an automatic fold while a payment attempt is in flight', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();
    $other = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    $order = buyNowCheckout($buyer, $product->fresh());

    $payment = initializePayment($order);

    expect(fn () => app(AddToCart::class)->handle($buyer, $other->fresh(), 1))
        ->toThrow(InvalidCheckout::class, 'being processed');

    // The order, the attempt and the stock all stand exactly as they were.
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        ->and($payment->fresh()->status)->toBe(OrderPaymentStatus::Pending)
        ->and($product->fresh()->stock_reserved)->toBe(1)
        ->and($other->fresh()->stock_reserved)->toBe(0)
        ->and(Cart::query()->forUser($buyer)->count())->toBe(0);
});

it('abandons the in-flight attempt and folds, when the customer asks to move back', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->withStock(5)->create();

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300_000);

    $order = buyNowCheckout($buyer, $product->fresh());
    $payment = initializePayment($order);

    $folded = app(FoldShopPurchaseToCart::class)->handle($buyer, abandonAttempts: true);

    expect($folded?->id)->toBe($order->id)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($payment->fresh()->status)->toBe(OrderPaymentStatus::Abandoned)
        ->and($payment->fresh()->failure_reason)->toContain('moved back')
        ->and($product->fresh()->stock_reserved)->toBe(0)
        ->and(storeWalletBalance($buyer)->minor)->toBe(300_000)
        ->and(Cart::query()->forUser($buyer)->firstOrFail()->items)->toHaveCount(1);
});

it('folds and re-places atomically, with net reservations and one payable order', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();
    $third = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $first->fresh(), 1);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);
    $old = app(PlaceCartOrder::class)->handle($buyer);

    // Keep shopping: folding first, then one consolidated order supersedes.
    app(AddToCart::class)->handle($buyer, $third->fresh(), 2);
    $new = app(PlaceCartOrder::class)->handle($buyer);

    expect($old->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($new->status)->toBe(OrderStatus::PendingPayment)
        ->and($new->items)->toHaveCount(3)
        ->and($first->fresh()->stock_reserved)->toBe(1)
        ->and($second->fresh()->stock_reserved)->toBe(1)
        ->and($third->fresh()->stock_reserved)->toBe(2)
        // One open catalogue order, and the basket has become it.
        ->and(Order::query()->payableShopOrder($buyer->id)->count())->toBe(1)
        ->and(Cart::query()->forUser($buyer)->count())->toBe(0);
});

it('expires a pending order it finds past its window, restoring the lines', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->withStock(5)->create();
    $other = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300_000);

    $order = buyNowCheckout($buyer, $product->fresh());

    $this->travel(2)->hours();
    app(AddToCart::class)->handle($buyer, $other->fresh(), 1);

    // Not cancelled: the fold deferred to the lifecycle, which records the
    // expiry as the reason the checkout closed, and restored the lines.
    expect($order->fresh()->status)->toBe(OrderStatus::PaymentExpired)
        ->and($product->fresh()->stock_reserved)->toBe(0)
        ->and(storeWalletBalance($buyer)->minor)->toBe(300_000)
        ->and(StoreWalletTransaction::where('idempotency_key', StoreWalletCheckout::releasedKeyFor($order->id))->count())
        ->toBe(1)
        ->and(Cart::query()->forUser($buyer)->firstOrFail()->items)->toHaveCount(2);
});

it('restores the lines when the provider reports the charge failed', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300_000);

    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);
    $order = app(PlaceCartOrder::class)->handle($buyer);

    app(OrderLifecycle::class)->markPaymentFailed($order->fresh(), 'The provider declined.');

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentFailed)
        ->and($first->fresh()->stock_reserved)->toBe(0)
        ->and($second->fresh()->stock_reserved)->toBe(0)
        ->and(storeWalletBalance($buyer)->minor)->toBe(300_000)
        ->and(StoreWalletTransaction::where('idempotency_key', StoreWalletCheckout::releasedKeyFor($order->id))->count())
        ->toBe(1)
        ->and(Cart::query()->forUser($buyer)->firstOrFail()->items)->toHaveCount(2);
});

it('records a payment verified after the fold, and blocks rather than repeating it', function (): void {
    $product = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();

    $buyer = userWithRole('customer');
    $order = buyNowCheckout($buyer, $product->fresh());
    $payment = initializePayment($order);

    app(FoldShopPurchaseToCart::class)->handle($buyer, abandonAttempts: true);

    // The provider confirms the customer did pay, after the order had already
    // been folded back into the cart.
    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'success',
        'amount' => $payment->amount_minor,
        'currency' => $payment->currency,
        'id' => random_int(1, PHP_INT_MAX),
        'channel' => 'mobile_money',
        'paid_at' => now()->toIso8601String(),
    ]);

    $result = app(FulfillOrderPayment::class)->handle($payment->fresh());

    // The money is recorded, the order keeps the truth of what happened, and
    // nothing silently reiterates a sale or re-opens the checkout.
    expect($payment->fresh()->status)->toBe(OrderPaymentStatus::Success)
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->fresh()->isFulfilmentBlocked())->toBeTrue()
        ->and($result['became_paid'])->toBeFalse()
        ->and($product->fresh()->stock_reserved)->toBe(0);
});

it('never folds nor restores an auction-linked order', function (): void {
    $auction = liveAuction();
    $buyer = userWithRole('customer');

    $order = buyNowCheckout($buyer, $auction->product->fresh(), $auction->fresh());

    expect(app(FoldShopPurchaseToCart::class)->handle($buyer))->toBeNull()
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment)
        // Expiry of the auction checkout gives the unit back to the auction's
        // own reservation, and never turns it into an editable basket line.
        ->and(Cart::query()->forUser($buyer)->count())->toBe(0);

    $this->travel(2)->hours();
    app(OrderLifecycle::class)->expire($order->fresh());

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentExpired)
        ->and(Cart::query()->forUser($buyer)->count())->toBe(0);
});

it('is a no-op when nothing is owed', function (): void {
    $buyer = userWithRole('customer');

    expect(app(FoldShopPurchaseToCart::class)->handle($buyer))->toBeNull()
        ->and(Order::query()->where('user_id', $buyer->id)->count())->toBe(0)
        ->and(Cart::query()->forUser($buyer)->count())->toBe(0);
});
