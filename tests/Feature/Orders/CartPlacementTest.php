<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Orders\Actions\FulfillOrderPayment;
use App\Domain\Orders\Actions\PlaceCartOrder;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Domain\Orders\Exceptions\PaymentNotAcceptable;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\Cart;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\OrderTransition;
use App\Models\Product;

/*
 * Placement is where a cart becomes commerce, and it is the only place. The
 * whole leap happens in one transaction: lines validated and priced, stock
 * reserved, a Store Wallet portion committed, the order written with every
 * figure frozen. Any line that cannot be satisfied takes the whole placement
 * down with it -- no order, no reservation, no wallet movement, cart intact.
 */

beforeEach(function (): void {
    seedSettings();
});

it('places a multi-line, multi-quantity cart as one order', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $second = Product::factory()->active()->pricedAt(200_000)->withStock(5)->create();
    $third = Product::factory()->active()->pricedAt(50_000)->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 3);
    app(AddToCart::class)->handle($buyer, $third->fresh(), 1);

    $order = app(PlaceCartOrder::class)->handle($buyer);

    expect($order->source)->toBe(OrderSource::BuyNow)
        ->and($order->auction_id)->toBeNull()
        ->and($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->holds_reservation)->toBeTrue()
        ->and($order->items)->toHaveCount(3)
        ->and($order->subtotal_minor)->toBe(850_000) // 2×100k + 3×200k + 50k
        ->and($order->discount_minor)->toBe(0)
        ->and($order->total_minor)->toBe(850_000)
        ->and($order->payable_minor)->toBe(850_000)
        ->and($order->payment_due_at)->not->toBeNull()
        ->and(OrderTransition::where('order_id', $order->id)->where('reason', 'Cart order placed.')->exists())->toBeTrue();

    $byProduct = $order->items->keyBy('product_id');

    expect($byProduct[$first->id]->quantity)->toBe(2)
        ->and($byProduct[$first->id]->unit_price_minor)->toBe(100_000)
        ->and($byProduct[$first->id]->line_total_minor)->toBe(200_000)
        ->and($byProduct[$second->id]->quantity)->toBe(3)
        ->and($byProduct[$second->id]->line_total_minor)->toBe(600_000)
        ->and($byProduct[$third->id]->quantity)->toBe(1)
        ->and($byProduct[$third->id]->line_total_minor)->toBe(50_000);
});

it('freezes the pricing snapshot onto the order', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);

    $order = app(PlaceCartOrder::class)->handle($buyer);

    // The snapshot is the same value object the checkout page shows, frozen.
    expect($order->pricing_snapshot['subtotal_minor'])->toBe(200_000)
        ->and($order->pricing_snapshot['payable_minor'])->toBe(200_000);
});

it('reserves every line and not one unit more', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $second = Product::factory()->active()->pricedAt(200_000)->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 3);

    app(PlaceCartOrder::class)->handle($buyer);

    expect($first->fresh()->stock_reserved)->toBe(2)
        ->and($first->fresh()->availableStock())->toBe(3)
        ->and($second->fresh()->stock_reserved)->toBe(3)
        ->and($second->fresh()->availableStock())->toBe(2);
});

it('deletes the cart once the order exists', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();
    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $product->fresh(), 1);
    app(PlaceCartOrder::class)->handle($buyer);

    expect(Cart::query()->forUser($buyer)->count())->toBe(0);
});

it('applies one Store Wallet portion across all lines and keeps the payable positive', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $second = Product::factory()->active()->pricedAt(200_000)->withStock(5)->create();

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300_000); // GH₵3,000, less than the bill

    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);

    $order = app(PlaceCartOrder::class)->handle($buyer);

    expect($order->store_wallet_applied_minor)->toBe(300_000)
        ->and($order->payable_minor)->toBe(100_000) // 400k − 300k, still owed
        ->and(storeWalletBalance($buyer)->minor)->toBe(0);
});

it('leaves nothing, no order and no reservation, when any one line is short', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(2)->create();
    $second = Product::factory()->active()->withStock(1)->create();

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 200_000);
    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);

    // Another customer legitimately takes the last of the second product
    // while the first sits in the buyer's basket.
    buyNowCheckout(userWithRole('customer'), $second->fresh());

    // Refused up front -- validation or, if the shortage only appears under
    // the row lock, during reservation. Either way the whole placement rolls
    // back to nothing.
    expect(fn () => app(PlaceCartOrder::class)->handle($buyer))
        ->toThrow(InvalidCheckout::class);

    // All-or-nothing: the first line's reservation was taken and rolled back
    // with the whole placement.
    expect(Order::query()->where('user_id', $buyer->id)->count())->toBe(0)
        ->and($first->fresh()->stock_reserved)->toBe(0)
        ->and($second->fresh()->stock_reserved)->toBe(1)
        ->and(storeWalletBalance($buyer)->minor)->toBe(200_000)
        // The cart is still there to edit and retry.
        ->and(Cart::query()->forUser($buyer)->firstOrFail()->items)->toHaveCount(2);
});

it('folds an earlier placement into the cart and re-places as one order', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();
    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $first->fresh(), 1);
    $firstOrder = app(PlaceCartOrder::class)->handle($buyer);

    // The customer may keep shopping after placing: adding folds the pending
    // order back into the basket (release → restore), and the next placement
    // becomes the single order covering both products.
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);
    $order = app(PlaceCartOrder::class)->handle($buyer);

    expect($firstOrder->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->items)->toHaveCount(2)
        ->and($first->fresh()->stock_reserved)->toBe(1)
        ->and($second->fresh()->stock_reserved)->toBe(1)
        ->and(Order::query()->payableShopOrder($buyer->id)->count())->toBe(1)
        ->and(Cart::query()->forUser($buyer)->count())->toBe(0);
});

it('allows a new placement once the earlier checkout has been paid', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $second = Product::factory()->active()->pricedAt(200_000)->withStock(5)->create();
    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $first->fresh(), 1);
    payOrder(app(PlaceCartOrder::class)->handle($buyer));

    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);
    $order = app(PlaceCartOrder::class)->handle($buyer);

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($order->items->first()->product_id)->toBe($second->id);
});

it('allows a new placement once the earlier checkout has expired', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();
    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $first->fresh(), 1);
    $firstOrder = app(PlaceCartOrder::class)->handle($buyer);

    $this->travel(2)->hours();
    app(OrderLifecycle::class)->expire($firstOrder->fresh());

    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);
    $order = app(PlaceCartOrder::class)->handle($buyer);

    expect($order->status)->toBe(OrderStatus::PendingPayment)
        ->and($second->fresh()->stock_reserved)->toBe(1);
});

it('keeps an auction-linked Buy Now alongside a pending catalogue order', function (): void {
    $auction = liveAuction();
    $catalogueProduct = Product::factory()->active()->withStock(5)->create();

    $buyer = bidder();
    buyNowCheckout($buyer, $catalogueProduct->fresh());

    // The auction rail owns its own unit and stays available: the catalogue
    // order is a separate obligation.
    $auctionOrder = buyNowCheckout($buyer, $auction->product->fresh(), $auction->fresh());

    expect($auctionOrder->source)->toBe(OrderSource::BuyNow)
        ->and($auctionOrder->auction_id)->toBe($auction->id)
        ->and($auctionOrder->holds_reservation)->toBeFalse();
});

it('releases every reservation when the multi-line order expires', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 3);

    $order = app(PlaceCartOrder::class)->handle($buyer);

    $this->travel(2)->hours();
    app(OrderLifecycle::class)->expire($order->fresh());

    expect($order->fresh()->holds_reservation)->toBeFalse()
        ->and($first->fresh()->stock_reserved)->toBe(0)
        ->and($first->fresh()->availableStock())->toBe(5)
        ->and($second->fresh()->stock_reserved)->toBe(0)
        ->and($second->fresh()->availableStock())->toBe(5);
});

it('returns the Store Wallet value once, and only once, when the multi-line order expires', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $second = Product::factory()->active()->pricedAt(200_000)->withStock(5)->create();

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300_000);

    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);
    $order = app(PlaceCartOrder::class)->handle($buyer);

    $this->travel(2)->hours();
    app(OrderLifecycle::class)->expire($order->fresh());
    app(OrderLifecycle::class)->expire($order->fresh());

    expect(storeWalletBalance($buyer)->minor)->toBe(300_000);
});

it('releases every reservation when the multi-line order is cancelled', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);

    $order = app(PlaceCartOrder::class)->handle($buyer);
    app(OrderLifecycle::class)->cancel($order->fresh(), 'Changed my mind.');

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($first->fresh()->stock_reserved)->toBe(0)
        ->and($second->fresh()->stock_reserved)->toBe(0);
});

it('keeps the full reservation while a payment fails to verify', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);

    $order = app(PlaceCartOrder::class)->handle($buyer);
    $payment = initializePayment($order);

    fakePaystackVerify([
        'reference' => $payment->provider_reference,
        'status' => 'failed',
        'amount' => $order->payable_minor,
        'currency' => $order->currency,
        'id' => random_int(1, PHP_INT_MAX),
    ]);

    expect(fn () => app(FulfillOrderPayment::class)->handle($payment->fresh()))
        ->toThrow(PaymentNotAcceptable::class);

    // A failed verification does not free stock: the order is still owed and
    // the money was never taken, so the reservation still stands until the
    // checkout closes.
    expect($first->fresh()->stock_reserved)->toBe(2)
        ->and($second->fresh()->stock_reserved)->toBe(1)
        ->and($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('verifies the multi-line payable and hands off fulfilment order-level', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $second = Product::factory()->active()->pricedAt(200_000)->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);

    $order = app(PlaceCartOrder::class)->handle($buyer);

    $payment = initializePayment($order);
    expect($payment->amount_minor)->toBe(400_000);

    payOrder($order, $payment);

    $order = $order->fresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->holds_reservation)->toBeFalse()
        // The reservations became sales: on-hand drops, reserved goes to zero.
        ->and($first->fresh()->stock_on_hand)->toBe(3)
        ->and($first->fresh()->stock_reserved)->toBe(0)
        ->and($second->fresh()->stock_on_hand)->toBe(4)
        ->and($second->fresh()->stock_reserved)->toBe(0)
        ->and(Delivery::query()->where('order_id', $order->id)->exists())->toBeTrue();
});
