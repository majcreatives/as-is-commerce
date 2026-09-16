<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Actions\PlaceCartOrder;
use App\Enums\ProductStatus;
use App\Livewire\Account\Dashboard;
use App\Livewire\Auctions\AuctionRoom;
use App\Livewire\Catalog\ProductDetail;
use App\Livewire\Delivery\OrderTracking;
use App\Livewire\Orders\OrderDetail;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Livewire\Livewire;

/*
 * What a customer can reach, and what they cannot.
 *
 * The marketplace is the largest public surface on the platform, so every one
 * of these calls a method directly rather than looking for a button: a control
 * that is not rendered is not a rule, and somebody circumventing the interface
 * would do exactly this.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);
});

// ------------------------------------------------------- Guests and gates

it('lets a guest browse without signing in', function (string $route): void {
    $this->get(route($route))->assertOk();
})->with(['home', 'products.index', 'auctions.index', 'how-it-works']);

it('sends a guest to sign in for anything of their own', function (string $route): void {
    $this->get(route($route))->assertRedirect(route('login'));
})->with(['dashboard', 'orders.index', 'notifications.index', 'credits.packages', 'addresses.index']);

it('lets a guest read an auction but not bid on it', function (): void {
    $auction = liveAuction();

    $this->get(route('auctions.show', $auction))
        ->assertOk()
        ->assertSee('Sign in');

    // And the action itself refuses, not merely the button.
    Livewire::test(AuctionRoom::class, ['auction' => $auction])
        ->set('amount', '150')
        ->call('review')
        ->assertForbidden();

    expect(Bid::count())->toBe(0);
});

it('refuses a guest adding to a cart', function (): void {
    $product = Product::factory()->active()->withStock(1)->create();

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->call('addToCart')
        ->assertForbidden();

    expect(Order::count())->toBe(0)
        ->and(Cart::count())->toBe(0);
});

// -------------------------------------------------------- Private data

it('does not show one customer another customer order', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());

    $this->actingAs(userWithRole('customer'))
        ->get(route('orders.show', $order))
        ->assertNotFound();
});

it('does not show one customer another customer tracking', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());

    $this->actingAs(userWithRole('customer'))
        ->get(route('orders.tracking', $order))
        ->assertNotFound();
});

it('does not show one customer another customer checkout', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product->fresh());

    $this->actingAs(userWithRole('customer'))
        ->get(route('checkout.show', $order))
        ->assertNotFound();
});

it('shows a customer only their own dashboard figures', function (): void {
    $auction = liveAuction();
    $spender = bidder(1_000);
    placeBid($auction, $spender, 400);

    // A different customer, with nothing.
    Livewire::actingAs(bidder(0))
        ->test(Dashboard::class)
        ->assertSee('Available credits')
        // Not the other customer's committed credits.
        ->assertDontSee('400');
});

/*
 * A bidder never learns another bidder's identity, anywhere on the platform.
 */
it('never names a bidder to another bidder', function (): void {
    $auction = liveAuction();
    $me = bidder(1_000);
    $rival = bidder(1_000);
    $rival->update(['name' => 'Kwame Mensah', 'email' => 'kwame@example.test']);

    placeBid($auction, $me, 100);
    placeBid($auction->fresh(), $rival, 300);

    Livewire::actingAs($me)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertDontSee('Kwame')
        ->assertDontSee('kwame@example.test')
        ->assertDontSee($rival->phone);
});

it('never names a bidder to a guest', function (): void {
    $auction = liveAuction();
    $rival = bidder(1_000);
    $rival->update(['name' => 'Ama Boateng']);

    placeBid($auction, $rival, 300);

    $this->get(route('auctions.show', $auction))
        ->assertOk()
        ->assertDontSee('Ama Boateng')
        ->assertDontSee($rival->phone);
});

// ---------------------------------------------- Nothing to manipulate

/*
 * The presentation layer holds no price and no discount. There is nothing on
 * these components a request could set that would change what anybody pays.
 */
it('exposes no price or discount a browser could set', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();

    $forbidden = ['price', 'amount_minor', 'total', 'discount', 'discountMinor', 'payable'];

    foreach ($forbidden as $property) {
        expect(property_exists(ProductDetail::class, $property))
            ->toBeFalse("ProductDetail must not expose a {$property} property.");
        expect(property_exists(AuctionRoom::class, $property))
            ->toBeFalse("AuctionRoom must not expose a {$property} property.");
    }

    // The bid form carries a credit count and an idempotency key, and nothing
    // that could be read as money.
    expect(property_exists(AuctionRoom::class, 'amount'))->toBeTrue();
});

it('ignores a fabricated price sent to a checkout', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 2);

    $buyer = bidder();

    Livewire::actingAs($buyer)
        ->test(ProductDetail::class, ['slug' => $product->slug])
        // The only input the browser may choose is the quantity--there is no
        // price anywhere on the component for a request to steer--and the
        // order still prices itself from the product.
        ->set('quantity', 2)
        ->call('addToCart');

    expect(Cart::query()->forUser($buyer)->firstOrFail()->items->first()->quantity)->toBe(2);

    $order = app(PlaceCartOrder::class)->handle($buyer);

    expect($order->subtotal_minor)->toBe(1_100_000)
        ->and($order->item()->unit_price_minor)->toBe(550_000);
});

it('gives a customer no way to change an auction from the marketplace', function (): void {
    foreach (['close', 'cancel', 'setStatus', 'declareWinner', 'extend'] as $method) {
        expect(method_exists(AuctionRoom::class, $method))
            ->toBeFalse("AuctionRoom must not expose {$method}.");
    }
});

it('gives a customer no way to change an order or a delivery', function (): void {
    foreach (['markPaid', 'advance', 'fulfil', 'refund', 'moveDelivery'] as $method) {
        expect(method_exists(OrderDetail::class, $method))
            ->toBeFalse("A customer's order page must not expose {$method}.");
        expect(method_exists(OrderTracking::class, $method))
            ->toBeFalse("A customer's tracking page must not expose {$method}.");
    }
});

// ------------------------------------------------------- Hidden records

it('404s a draft product rather than admitting it exists', function (): void {
    $product = Product::factory()->status(ProductStatus::Draft)->create();

    $this->get(route('products.show', $product->slug))->assertNotFound();
});

it('404s a draft auction rather than admitting it exists', function (): void {
    $auction = Auction::factory()->create();

    $this->get(route('auctions.show', $auction))->assertNotFound();
});
