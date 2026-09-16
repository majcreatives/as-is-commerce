<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AddToCart;
use App\Domain\Orders\Actions\PlaceCartOrder;
use App\Models\Product;

/*
 * The checkout page shows the whole order a cart was placed as: every line,
 * with the quantities and totals frozen onto it, and the Store Wallet portion
 * that reduces the bill. Nothing on the page is a claim the server does not
 * back up.
 */

it('renders the full basket after a multi-line placement', function (): void {
    $first = Product::factory()->active()->pricedAt(100_000)->withStock(5)->create();
    $second = Product::factory()->active()->pricedAt(200_000)->withStock(5)->create();

    $buyer = userWithRole('customer');
    fundStoreWallet($buyer, 300_000);

    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);
    $order = app(PlaceCartOrder::class)->handle($buyer);

    $this->actingAs($buyer)
        ->get(route('checkout.show', $order))
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee('2 items')
        ->assertSee($first->name)
        ->assertSee($second->name)
        ->assertSee('2 &times;', false)
        ->assertSee('Buy Now price')
        ->assertSee('Applied from your Store Wallet')
        ->assertSee('Total to pay')
        ->assertDontSee('Highest Bid')
        ->assertDontSee('Place bid')
        ->assertDontSee('Auction Settlement Amount');
});

it('explains what is held until the deadline', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();
    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $product->fresh(), 1);
    $order = app(PlaceCartOrder::class)->handle($buyer);

    $this->actingAs($buyer)
        ->get(route('checkout.show', $order))
        ->assertOk()
        ->assertSee('Your items are held for you until then.');
});
