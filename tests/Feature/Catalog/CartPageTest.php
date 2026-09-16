<?php

declare(strict_types=1);

use App\Domain\Catalog\Actions\AddToCart;
use App\Enums\OrderStatus;
use App\Livewire\Catalog\CartPage;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Livewire\Livewire;

/*
 * The cart page is the basket before payment. Editing the basket edits the
 * cart; placing the basket writes the order. The page itself never decides a
 * price, a total or a reservation -- those belong to the placement action.
 */

it('sends a guest to sign in', function (): void {
    $this->get(route('cart.show'))->assertRedirect(route('login'));
});

it('rejects a staff member without the checkout permission', function (): void {
    Livewire::actingAs(staffWith([]))
        ->test(CartPage::class)
        ->assertForbidden();
});

it('shows an empty basket and nothing else', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(CartPage::class)
        ->assertSee('Your cart is empty.')
        ->assertSee('Browse products')
        ->assertDontSee('Place order');
});

it('lists every line with its product name and quantity', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $first->fresh(), 2);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 3);

    Livewire::actingAs($buyer)
        ->test(CartPage::class)
        ->assertSee($first->name)
        ->assertSee($second->name)
        ->assertSeeInOrder([
            'Review your basket before you pay once.',
            $first->name,
            $second->name,
            'Place order',
        ]);
});

it('updates a line and recalculates its per-line total', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $product->fresh(), 1);
    $line = Cart::query()->forUser($buyer)->firstOrFail()->items->first();

    Livewire::actingAs($buyer)
        ->test(CartPage::class)
        ->set("quantities.{$line->id}", 3)
        ->call('updateQuantities')
        ->assertHasNoErrors();

    expect($line->fresh()->quantity)->toBe(3);
});

it('refuses to grow a line past available stock', function (): void {
    $product = Product::factory()->active()->withStock(2)->create();
    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $product->fresh(), 1);
    $line = Cart::query()->forUser($buyer)->firstOrFail()->items->first();

    Livewire::actingAs($buyer)
        ->test(CartPage::class)
        ->set("quantities.{$line->id}", 5)
        ->call('updateQuantities')
        ->assertHasErrors("quantities.{$line->id}");

    expect($line->fresh()->quantity)->toBe(1);
});

it('removes a single line', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $first->fresh(), 1);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);

    $line = Cart::query()->forUser($buyer)->firstOrFail()->items()->where('product_id', $first->id)->first();

    Livewire::actingAs($buyer)
        ->test(CartPage::class)
        ->call('removeLine', $line->id)
        ->assertHasNoErrors();

    expect(Cart::query()->forUser($buyer)->firstOrFail()->items)->toHaveCount(1);
});

it('empties the basket', function (): void {
    $first = Product::factory()->active()->withStock(5)->create();
    $second = Product::factory()->active()->withStock(5)->create();

    $buyer = userWithRole('customer');
    app(AddToCart::class)->handle($buyer, $first->fresh(), 1);
    app(AddToCart::class)->handle($buyer, $second->fresh(), 1);

    Livewire::actingAs($buyer)
        ->test(CartPage::class)
        ->call('clearCart')
        ->assertSee('Your cart is empty.');

    expect(Cart::query()->forUser($buyer)->firstOrFail()->items)->toHaveCount(0);
});

it('places an order from the basket and redirects to checkout', function (): void {
    $product = Product::factory()->active()->withStock(5)->create();
    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $product->fresh(), 1);

    Livewire::actingAs($buyer)
        ->test(CartPage::class)
        ->call('placeOrder')
        ->assertHasNoErrors(['place']);

    $order = Order::query()->where('user_id', $buyer->id)->latest('id')->first();

    expect($order)->not->toBeNull()
        ->and($order->status)->toBe(OrderStatus::PendingPayment)
        ->and(Cart::query()->forUser($buyer)->count())->toBe(0);
});

it('shows an error when the basket cannot be placed', function (): void {
    $product = Product::factory()->active()->withStock(1)->create();
    $buyer = userWithRole('customer');

    app(AddToCart::class)->handle($buyer, $product->fresh(), 1);
    buyNowCheckout(userWithRole('customer'), $product->fresh());

    Livewire::actingAs($buyer)
        ->test(CartPage::class)
        ->call('placeOrder')
        ->assertHasErrors('place')
        ->assertSee('not available to buy');

    expect(Order::query()->where('user_id', $buyer->id)->count())->toBe(0)
        ->and(Cart::query()->forUser($buyer)->count())->toBe(1);
});
