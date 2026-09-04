<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;

/*
 * The sweep that stops an abandoned checkout holding stock forever.
 *
 * Nothing here depends on a page being open or a browser reporting anything.
 * The command reads a stored deadline in server time and acts.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);
});

it('releases the unit an abandoned checkout was holding', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());

    expect($product->fresh()->availableStock())->toBe(0);

    $this->artisan('orders:expire-checkouts')->assertSuccessful();
    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);

    $this->travel(2)->hours();

    $this->artisan('orders:expire-checkouts')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::PaymentExpired)
        ->and($product->fresh()->availableStock())->toBe(1);
});

it('never expires a checkout that was paid', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());
    payOrder($order);

    $this->travel(2)->hours();
    $this->artisan('orders:expire-checkouts')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('expires each checkout once when run repeatedly', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());

    $this->travel(2)->hours();

    foreach (range(1, 3) as $ignored) {
        $this->artisan('orders:expire-checkouts')->assertSuccessful();
    }

    // One release, not three: available stock is 1, not 3.
    expect($product->fresh()->availableStock())->toBe(1)
        ->and($order->fresh()->transitions()
            ->where('to_status', OrderStatus::PaymentExpired)->count())->toBe(1);
});

it('lets the released unit be bought by somebody else', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    buyNowCheckout(bidder(), $product->fresh());

    $this->travel(2)->hours();
    $this->artisan('orders:expire-checkouts');

    $second = buyNowCheckout(bidder(), $product->fresh());
    payOrder($second);

    expect($product->fresh()->stock_on_hand)->toBe(0)
        ->and($second->fresh()->status)->toBe(OrderStatus::Paid);
});

it('honours the limit it is given', function (): void {
    $orders = collect(range(1, 3))->map(function (): Order {
        $product = Product::factory()->active()->create();
        app(InventoryService::class)->initialStock($product, 1);

        return buyNowCheckout(bidder(), $product->fresh());
    });

    $this->travel(2)->hours();

    $this->artisan('orders:expire-checkouts', ['--limit' => 1])->assertSuccessful();

    expect(Order::where('status', OrderStatus::PaymentExpired)->count())->toBe(1)
        ->and($orders)->toHaveCount(3);
});

it('does nothing at all when there are no checkouts', function (): void {
    $this->artisan('orders:expire-checkouts')
        ->expectsOutputToContain('Expired 0 checkout(s).')
        ->assertSuccessful();
});
