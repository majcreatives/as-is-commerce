<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Services\OrderLifecycle;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

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

it('badges the cart with the number of items', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    buyNowCheckout($customer, $product->fresh());

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('You have 1 item to check out');
});

it('badges the cart with the total quantity across lines', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $customer = userWithRole('customer');
    $order = buyNowCheckout($customer, $product->fresh());

    // A second line mimics a checkout holding more than one item. The badge
    // is a sum of line quantities, not a row count.
    $first = $order->item();
    DB::table('order_items')->insert([
        'order_id' => $order->id,
        'product_id' => $first->product_id,
        'product_name_snapshot' => $first->product_name_snapshot,
        'sku_snapshot' => $first->sku_snapshot,
        'quantity' => 3,
        'unit_price_minor' => $first->unit_price_minor,
        'discount_minor' => 0,
        'line_total_minor' => $first->unit_price_minor * 3,
        'created_at' => now(),
    ]);

    $this->actingAs($customer)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('You have 4 items to check out');
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
