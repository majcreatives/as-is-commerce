<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Orders\Actions\PlaceCartOrder;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;

/*
 * Two customers racing the same last units with their carts. Placement refuses
 * a line that cannot be satisfied -- during validation, or under the product
 * row lock during reservation -- and is all-or-nothing, so exactly one
 * customer may win the unit; the other's whole placement fails and they keep
 * their basket. Same shape as the inventory reservation race; the winner is
 * whatever arrival order the database decides.
 */

beforeEach(function (): void {
    seedSettings();
});

it('does not let two customers take the same last unit at the same time', function (): void {
    $product = Product::factory()->active()->pricedAt(100_000)->withStock(1)->create();

    $firstBuyer = userWithRole('customer');
    $secondBuyer = userWithRole('customer');

    app(AddToCart::class)->handle($firstBuyer, $product->fresh(), 1);
    app(AddToCart::class)->handle($secondBuyer, $product->fresh(), 1);

    $succeeded = 0;
    $refused = 0;

    // Each placement is an independent transaction, exactly as two requests
    // would be. The first to the row lock wins; the loser's placement rolls
    // back to nothing.
    foreach ([$firstBuyer, $secondBuyer] as $buyer) {
        try {
            app(PlaceCartOrder::class)->handle($buyer);
            $succeeded++;
        } catch (DomainException) {
            $refused++;
        }
    }

    expect($succeeded)->toBe(1)
        ->and($refused)->toBe(1);

    $stock = $product->fresh();

    expect($stock->stock_reserved)->toBe(1)
        ->and($stock->availableStock())->toBe(0)
        // The winner holds an order; the loser has no order and still their
        // basket to edit and retry.
        ->and(Order::count())->toBe(1)
        ->and(Cart::count())->toBe(1);
});

it('fails a whole multi-line placement when another race wins one of its lines', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(2)->create();
    $last = Product::factory()->active()->pricedAt(50_000)->withStock(1)->create();

    $winner = userWithRole('customer');
    $loser = userWithRole('customer');

    app(AddToCart::class)->handle($winner, $last->fresh(), 1);
    app(AddToCart::class)->handle($loser, $first->fresh(), 1);
    app(AddToCart::class)->handle($loser, $last->fresh(), 1);

    // The winner races away with the last unit first.
    app(PlaceCartOrder::class)->handle($winner);

    expect(fn () => app(PlaceCartOrder::class)->handle($loser))
        ->toThrow(DomainException::class);

    // The loser's order never exists; their first line's reservation was rolled
    // back with it; only the winner's reservation stands.
    expect(Order::where('user_id', $loser->id)->count())->toBe(0)
        ->and($first->fresh()->stock_reserved)->toBe(0)
        ->and($last->fresh()->stock_reserved)->toBe(1)
        ->and(Cart::query()->forUser($loser)->firstOrFail()->items)->toHaveCount(2);
});
