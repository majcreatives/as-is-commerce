<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Models\Product;
use Carbon\Carbon;

/*
 * The header cart is the one affordance for everything still owed. The badge
 * sums the quantity across the customer's basket (intent, nothing reserved)
 * plus the payable Shop checkout's items (already set aside). A separate
 * "Return to checkout" prompt does not exist: the cart page surfaces an owed
 * order when there is one.
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

it('folds an owed checkout into the cart badge when the basket is empty', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    buyNowCheckout($customer, $product->fresh());

    $response = $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Return to checkout');

    expect($response->getContent())
        ->toMatch('/id="cart-count"[\s\S]*?>\s*1\s*<\/span>/');
});

it('sums the basket and an owed checkout in one badge', function (): void {
    $first = Product::factory()->active()->create();
    $second = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($first, 5);
    app(InventoryService::class)->initialStock($second, 1);

    $customer = userWithRole('customer');
    app(AddToCart::class)->handle($customer, $first->fresh(), 2);
    buyNowCheckout($customer, $second->fresh());

    $response = $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Return to checkout');

    expect($response->getContent())
        ->toMatch('/id="cart-count"[\s\S]*?>\s*3\s*<\/span>/');
});

it('shows no badge when nothing is owed and the basket is empty', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('id="cart-count"')
        ->assertDontSee('Return to checkout');
});

it('does not fold an expired checkout into the badge', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    $order = buyNowCheckout($customer, $product->fresh());

    Carbon::setTestNow($order->payment_due_at);
    app(OrderLifecycle::class)->expire($order->fresh());

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('id="cart-count"');

    Carbon::setTestNow();
});
