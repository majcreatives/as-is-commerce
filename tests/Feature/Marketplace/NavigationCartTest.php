<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Models\Product;
use Carbon\Carbon;

/*
 * The header cart is the cart, not the order. The badge sums the quantity
 * across the customer's cart lines -- intent, nothing reserved. A pending
 * checkout is pointed to separately, so an abandoned buy is never lost.
 */

it('shows the cart link and no badge to a signed-in customer with an empty cart', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('View your cart')
        ->assertDontSee('id="cart-count"');
});

it('badges the cart with the total quantity in it', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 5);

    $customer = userWithRole('customer');
    app(AddToCart::class)->handle($customer, $product->fresh(), 2);

    $response = $this->actingAs($customer)->get(route('dashboard'));
    $response->assertOk();

    expect($response->getContent())
        ->toMatch('/id="cart-count"[\s\S]*?>\s*2\s*<\/span>/');
});

it('badges the cart with the total quantity across lines', function (): void {
    $first = Product::factory()->active()->create();
    $second = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($first, 5);
    app(InventoryService::class)->initialStock($second, 5);

    $customer = userWithRole('customer');
    app(AddToCart::class)->handle($customer, $first->fresh(), 1);
    app(AddToCart::class)->handle($customer, $second->fresh(), 3);

    $response = $this->actingAs($customer)->get(route('dashboard'));
    $response->assertOk();

    expect($response->getContent())
        ->toMatch('/id="cart-count"[\s\S]*?>\s*4\s*<\/span>/');
});

it('links the cart icon to the cart page', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('href="'.route('cart.show').'"', false);
});

it('shows a return-to-checkout prompt while a checkout is still owed', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    buyNowCheckout($customer, $product->fresh());

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Return to checkout');
});

it('links the return-to-checkout prompt to the pending order', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    $order = buyNowCheckout($customer, $product->fresh());

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('href="'.route('checkout.show', $order).'"', false);
});

it('hides the return-to-checkout prompt when nothing is owed', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Return to checkout');
});

it('hides the return-to-checkout prompt once the checkout is no longer payable', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    $order = buyNowCheckout($customer, $product->fresh());

    Carbon::setTestNow($order->payment_due_at);
    app(OrderLifecycle::class)->expire($order->fresh());

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Return to checkout');

    Carbon::setTestNow();
});
