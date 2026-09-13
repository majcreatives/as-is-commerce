<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Models\Product;
use Carbon\Carbon;

it('shows a cart to a customer with a payable checkout', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    buyNowCheckout($customer, $product->fresh());

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Return to your checkout');
});

it('hides the cart for a customer with no checkout', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Return to your checkout');
});

it('hides the cart once the checkout is no longer payable', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    $order = buyNowCheckout($customer, $product->fresh());

    Carbon::setTestNow($order->payment_due_at);
    app(OrderLifecycle::class)->expire($order->fresh());

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Return to your checkout');

    Carbon::setTestNow();
});

it('links the cart to the correct checkout', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    $order = buyNowCheckout($customer, $product->fresh());

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('checkout.show', $order->order_number));
});
