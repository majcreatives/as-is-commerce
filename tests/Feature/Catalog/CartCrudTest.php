<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Catalog\Actions\UpdateCartLine;
use App\Domain\Catalog\Exceptions\InvalidCart;
use App\Domain\Orders\Exceptions\InvalidCheckout;
use App\Enums\ProductStatus;
use App\Models\Cart;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;

/*
 * Editing a cart is intent and nothing more. Every assertion here pairs the
 * cart's own change with proof that no ledger was written and no stock moved:
 * nothing in these actions may set aside a unit, create an order, or touch a
 * credit or Store Wallet balance.
 */

it('adds a product to a customer cart as a line', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();
    $customer = userWithRole('customer');

    app(AddToCart::class)->handle($customer, $product->fresh(), 2);

    $cart = Cart::query()->forUser($customer)->first();

    expect($cart)->not->toBeNull()
        ->and($cart->items)->toHaveCount(1)
        ->and($cart->items->first()->product_id)->toBe($product->id)
        ->and($cart->items->first()->quantity)->toBe(2);
});

it('accumulates quantity on one line when the same product is added again', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();
    $customer = userWithRole('customer');

    app(AddToCart::class)->handle($customer, $product->fresh(), 2);
    app(AddToCart::class)->handle($customer, $product->fresh(), 3);

    $line = Cart::query()->forUser($customer)->firstOrFail()->items->first();

    expect($line->quantity)->toBe(5);
});

it('caps a cart line at the stock the ledger actually has', function (): void {
    $product = Product::factory()->active()->withStock(3)->create();
    $customer = userWithRole('customer');

    app(AddToCart::class)->handle($customer, $product->fresh(), 3);

    expect(fn () => app(AddToCart::class)->handle($customer, $product->fresh(), 1))
        ->toThrow(InvalidCart::class, 'only has 3 available');

    expect(Cart::query()->forUser($customer)->firstOrFail()->items->first()->quantity)->toBe(3);
});

it('rejects a quantity below one', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();
    $customer = userWithRole('customer');

    expect(fn () => app(AddToCart::class)->handle($customer, $product->fresh(), 0))
        ->toThrow(InvalidCart::class, 'at least one unit');
});

it('refuses a product that is not purchasable', function (): void {
    $product = Product::factory()->status(ProductStatus::OutOfStock)->create();
    $customer = userWithRole('customer');

    expect(fn () => app(AddToCart::class)->handle($customer, $product->fresh(), 1))
        ->toThrow(InvalidCheckout::class, 'not available to buy');

    expect(Cart::query()->forUser($customer)->exists())->toBeFalse();
});

it('refuses a product that an active auction is selling', function (): void {
    // A second unit stays available, so the refusal is the auction-first rule
    // and not "out of stock".
    $auction = liveAuction(stock: 2);
    $customer = userWithRole('customer');

    expect(fn () => app(AddToCart::class)->handle($customer, $auction->product->fresh(), 1))
        ->toThrow(InvalidCart::class, 'auction');

    expect(Cart::query()->forUser($customer)->exists())->toBeFalse();
});

it('writes no ledger and reserves no stock when the cart changes', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();
    $customer = userWithRole('customer');

    $transactionsBefore = InventoryTransaction::count();

    app(AddToCart::class)->handle($customer, $product->fresh(), 1);
    $line = Cart::query()->forUser($customer)->firstOrFail()->items->firstOrFail();
    app(UpdateCartLine::class)->handle($customer, $line, 4);

    $product = $product->fresh();

    expect($product->stock_on_hand)->toBe(5)
        ->and($product->stock_reserved)->toBe(0)
        ->and(InventoryTransaction::count())->toBe($transactionsBefore)
        // Scoped to this customer: transaction-isolated and truncation-based
        // suites share one process, so a global count is not this test's data.
        ->and(Order::query()->where('user_id', $customer->id)->count())->toBe(0)
        ->and(creditWalletFor($customer)->transactions()->count())->toBe(0);
});

it('removes a cart line when its quantity is set to zero', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();
    $customer = userWithRole('customer');

    app(AddToCart::class)->handle($customer, $first->fresh(), 1);
    app(AddToCart::class)->handle($customer, $second->fresh(), 1);

    $line = Cart::query()->forUser($customer)->firstOrFail()->items()->where('product_id', $first->id)->firstOrFail();
    app(UpdateCartLine::class)->handle($customer, $line, 0);

    $cart = Cart::query()->forUser($customer)->firstOrFail();

    expect($cart->items)->toHaveCount(1)
        ->and($cart->items->first()->product_id)->toBe($second->id);
});

it('allows shrinking a line even when the product has since run out', function (): void {
    $product = Product::factory()->active()->withStock(1)->create();
    $customer = userWithRole('customer');

    app(AddToCart::class)->handle($customer, $product->fresh(), 1);

    // Another customer buys the last unit while it sits in the basket.
    buyNowCheckout(userWithRole('customer'), $product->fresh());
    expect($product->fresh()->availableStock())->toBe(0);

    $line = Cart::query()->forUser($customer)->firstOrFail()->items->firstOrFail();
    app(UpdateCartLine::class)->handle($customer, $line, 0);

    expect(Cart::query()->forUser($customer)->firstOrFail()->items)->toHaveCount(0);
});

it('refuses to grow a line past the available stock', function (): void {
    $product = Product::factory()->active()->withStock(2)->create();
    $customer = userWithRole('customer');

    app(AddToCart::class)->handle($customer, $product->fresh(), 1);

    $line = Cart::query()->forUser($customer)->firstOrFail()->items->firstOrFail();

    expect(fn () => app(UpdateCartLine::class)->handle($customer, $line, 3))
        ->toThrow(InvalidCart::class, 'only has 2 available');
});

it('refuses to touch another customer cart line', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();
    $owner = userWithRole('customer');
    $intruder = userWithRole('customer');

    app(AddToCart::class)->handle($owner, $product->fresh(), 1);

    $line = Cart::query()->forUser($owner)->firstOrFail()->items->firstOrFail();

    expect(fn () => app(UpdateCartLine::class)->handle($intruder, $line, 5))
        ->toThrow(InvalidCart::class, 'does not belong to you');

    expect($line->fresh()->quantity)->toBe(1);
});

it('keeps one cart per customer', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();
    $customer = userWithRole('customer');

    app(AddToCart::class)->handle($customer, $first->fresh(), 1);
    app(AddToCart::class)->handle($customer, $second->fresh(), 2);

    expect(Cart::query()->forUser($customer)->count())->toBe(1)
        ->and(Cart::query()->forUser($customer)->firstOrFail()->items)->toHaveCount(2);
});
