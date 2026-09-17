<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Catalog\Services\InventoryService;
use App\Enums\OrderStatus;
use App\Livewire\Admin\Orders\OrderDetail as AdminOrderDetail;
use App\Livewire\Admin\Orders\OrderManager;
use App\Livewire\Auctions\AuctionRoom;
use App\Livewire\Checkout\CheckoutPage;
use App\Livewire\Delivery\OrderTracking;
use App\Livewire\Orders\OrderDetail;
use App\Livewire\Orders\OrderIndex;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/*
 * The interface: what it shows, what it refuses, and the words it uses.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);

    $this->admin = userWithRole('admin');
});

// -------------------------------------------------------------- Checkout

it('shows the components separately, not just a total', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = customerWithPurchasedCredits(1_000, 100_000);
    placeBid($auction, $buyer, 150);

    $order = buyNowCheckout($buyer, $product, $auction);

    Livewire::actingAs($buyer)
        ->test(CheckoutPage::class, ['order' => $order])
        ->assertOk()
        ->assertSee('Buy Now price')
        ->assertSee('GH₵ 5,500.00', escape: false)
        ->assertSee('Credit discount')
        ->assertSee('GH₵ 150.00', escape: false)
        ->assertSee('Total to pay')
        ->assertSee('GH₵ 5,350.00', escape: false);
});

it('shows the credits behind a discount as a count, never as money', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = customerWithPurchasedCredits(1_000, 100_000);
    placeBid($auction, $buyer, 150);

    Livewire::actingAs($buyer)
        ->test(CheckoutPage::class, ['order' => buyNowCheckout($buyer, $product, $auction)])
        // Kept short: the rendered markup wraps, so a long phrase would fail
        // on a line break rather than on anything that matters.
        ->assertSee('150 credits')
        ->assertSee('consumed bidding on this auction')
        // The credits are never written with a currency symbol.
        ->assertDontSee('GH₵ 150 credits', escape: false)
        ->assertSee('not refunded')
        ->assertSee('not returned to your wallet');
});

it('tells a winner what they owe and what they do not', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);
    placeBid($auction, $winner, 180);
    app(CloseAuction::class)->handle($auction, force: true);

    Livewire::actingAs($winner)
        ->test(CheckoutPage::class, ['order' => settlementCheckout($auction->fresh(), $winner)])
        ->assertSee('Auction Settlement Amount')
        ->assertSee('GH₵ 100.00', escape: false)
        ->assertSee('180 credits')
        ->assertSee('not charged again here')
        ->assertSee('not</strong> your bid converted into cedis', escape: false)
        // Never the product's price, and never the bid as money.
        ->assertDontSee('GH₵ 5,500.00', escape: false)
        ->assertDontSee('GH₵ 180.00', escape: false);
});

it('says a Buy Now checkout has not ended the auction', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder();

    Livewire::actingAs($buyer)
        ->test(CheckoutPage::class, ['order' => buyNowCheckout($buyer, $product, $auction)])
        ->assertSee('still running until your payment is confirmed');
});

it('never says an order is paid before it is', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);
    $buyer = bidder();

    Livewire::actingAs($buyer)
        ->test(CheckoutPage::class, ['order' => buyNowCheckout($buyer, $product)])
        ->assertSee('only once we have verified the payment')
        ->assertDontSee('Payment confirmed');
});

it('sends the customer away to the provider to pay', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);
    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product);

    fakeHttp([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/go',
                'access_code' => 'code',
            ],
        ]),
    ]);

    Livewire::actingAs($buyer)
        ->test(CheckoutPage::class, ['order' => $order])
        ->call('pay')
        ->assertRedirect('https://checkout.paystack.com/go');
});

it('submits no amount from the browser', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);
    $buyer = bidder();

    $component = Livewire::actingAs($buyer)
        ->test(CheckoutPage::class, ['order' => buyNowCheckout($buyer, $product)]);

    // The component holds an order and nothing else. There is no amount
    // property for a browser to tamper with.
    expect($component->get('order')->total_minor)->toBe(550_000)
        ->and(property_exists(CheckoutPage::class, 'amount'))->toBeFalse()
        ->and(property_exists(CheckoutPage::class, 'total'))->toBeFalse();
});

it('refuses to show one customer another customer checkout', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);

    Livewire::actingAs(bidder())
        ->test(CheckoutPage::class, ['order' => $order])
        ->assertNotFound();
});

it('cancels a checkout at the customer request', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);
    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product);

    Livewire::actingAs($buyer)
        ->test(CheckoutPage::class, ['order' => $order])
        ->call('cancel')
        ->assertRedirect(route('orders.index'));

    expect($order->fresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($product->fresh()->availableStock())->toBe(1);
});

// --------------------------------------------------------- Customer orders

it('lists a customer own orders and nobody else', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Mine']);
    app(InventoryService::class)->initialStock($product, 2);

    $mine = bidder();
    buyNowCheckout($mine, $product);

    $other = Product::factory()->active()->create(['name' => 'Theirs']);
    app(InventoryService::class)->initialStock($other, 1);
    buyNowCheckout(bidder(), $other);

    Livewire::actingAs($mine)
        ->test(OrderIndex::class)
        ->assertOk()
        ->assertSee('Mine')
        ->assertDontSee('Theirs');
});

it('refuses to show one customer another customer order', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $order = buyNowCheckout(bidder(), $product);

    Livewire::actingAs(bidder())
        ->test(OrderDetail::class, ['order' => $order])
        ->assertNotFound();
});

it('shows a customer what happened to a blocked order', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $slow = bidder();
    $quick = bidder();

    $slowOrder = buyNowCheckout($slow, $product, $auction);
    payOrder(buyNowCheckout($quick, $product->fresh(), $auction->fresh()));
    payOrder($slowOrder);

    Livewire::actingAs($slow)
        ->test(OrderDetail::class, ['order' => $slowOrder->fresh()])
        ->assertSee('could not complete this order')
        ->assertSee('will be in touch');
});

// ------------------------------------------------------------ Auction room

it('offers a Buy Now button that opens a checkout', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $buyer = bidder();

    Livewire::actingAs($buyer)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->assertSee('Buy now for')
        ->assertSee('does not end this auction')
        ->call('buyNow')
        ->assertRedirectContains('/checkout/');

    expect(Order::count())->toBe(1)
        // The auction is untouched by opening a checkout.
        ->and($auction->fresh()->status->acceptsBids())->toBeTrue();
});

it('offers the winner a settlement button', function (): void {
    $auction = liveAuction(settlementMinor: 10_000);
    $winner = bidder(500);
    placeBid($auction, $winner, 100);
    app(CloseAuction::class)->handle($auction, force: true);

    Livewire::actingAs($winner)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('You won this auction')
        ->assertSee('not your bid converted into GH', escape: false)
        ->call('settle')
        ->assertRedirectContains('/checkout/');
});

it('does not offer a settlement button to a loser', function (): void {
    $auction = liveAuction();
    $winner = bidder(500);
    $loser = bidder(500);
    placeBid($auction, $loser, 50);
    placeBid($auction, $winner, 200);
    app(CloseAuction::class)->handle($auction, force: true);

    Livewire::actingAs($loser)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertDontSee('Settle this auction')
        ->call('settle')
        ->assertHasErrors('checkout');
});

// ------------------------------------------------------------- Admin

it('lists every order for staff', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Admin Visible']);
    app(InventoryService::class)->initialStock($product, 1);
    buyNowCheckout(bidder(), $product);

    Livewire::actingAs($this->admin)
        ->test(OrderManager::class)
        ->assertOk()
        ->assertSee('Admin Visible');
});

it('flags orders that were paid but could not be completed', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $slowOrder = buyNowCheckout(bidder(), $product, $auction);
    payOrder(buyNowCheckout(bidder(), $product->fresh(), $auction->fresh()));
    payOrder($slowOrder);

    Livewire::actingAs($this->admin)
        ->test(OrderManager::class)
        ->assertSee('could not be completed')
        ->assertSee('needs attention');
});

it('shows an administrator the three figures without conflating them', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product, settlementMinor: 10_000);
    $winner = bidder(500);
    placeBid($auction, $winner, 180);
    app(CloseAuction::class)->handle($auction, force: true);

    $order = settlementCheckout($auction->fresh(), $winner);

    Livewire::actingAs($this->admin)
        ->test(AdminOrderDetail::class, ['order' => $order])
        ->assertOk()
        // The settlement, as money.
        ->assertSee('GH₵ 100.00', escape: false)
        // The bid, as a count of credits.
        ->assertSee('180 credits')
        // The product's price, labelled as its own thing.
        ->assertSee('Product Buy Now price')
        ->assertSee('GH₵ 5,500.00', escape: false)
        ->assertSee('neither is derived from the winning bid');
});

it('offers no control to mark an order paid', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);
    $order = buyNowCheckout(bidder(), $product);

    Livewire::actingAs($this->admin)
        ->test(AdminOrderDetail::class, ['order' => $order])
        ->assertDontSee('Mark as paid')
        // And the method refuses it even if called directly.
        ->call('advance', 'paid')
        ->assertHasErrors('lifecycle');

    expect($order->fresh()->status)->toBe(OrderStatus::PendingPayment);
});

it('lets an administrator move a paid order along', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);
    $order = buyNowCheckout(bidder(), $product);
    payOrder($order);

    Livewire::actingAs($this->admin)
        ->test(AdminOrderDetail::class, ['order' => $order->fresh()])
        ->call('advance', 'processing')
        ->assertHasNoErrors()
        ->call('advance', 'fulfilled')
        ->assertHasNoErrors();

    expect($order->fresh()->status)->toBe(OrderStatus::Fulfilled);
});

// --------------------------------------------------------- Authorization

it('forbids a customer from the admin order list', function (): void {
    Livewire::actingAs(userWithRole('customer'))
        ->test(OrderManager::class)
        ->assertForbidden();
});

it('keeps a customer out of the admin order routes', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('admin.orders.index'))
        ->assertForbidden();
});

/*
 * The lesson from the wallet screens, retested: an admin screen must not be
 * gated on a permission every customer holds.
 */
it('does not gate the admin order list on a permission customers hold', function (): void {
    $customer = userWithRole('customer');

    expect($customer->can('orders.view_own'))->toBeTrue()
        ->and($customer->can('checkout.create'))->toBeTrue()
        ->and($customer->can('orders.view'))->toBeFalse()
        ->and($customer->can('orders.manage'))->toBeFalse()
        ->and($customer->can('order_payments.view'))->toBeFalse();
});

it('lets an administrator reach the order screens', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);
    $order = buyNowCheckout(bidder(), $product);

    $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertOk();
    $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk();
});

it('requires a signed-in customer for checkout and orders', function (string $route): void {
    $this->get($route)->assertRedirect(route('login'));
})->with([
    'orders' => fn (): string => route('orders.index'),
]);

/*
 * Regression: a customer order's "View product" link, the tracking page and
 * the admin order screen all read relations (items.product, delivery,
 * auction.product) and must load them explicitly rather than lazily, because
 * the app runs with strict lazy-loading outside production. The staging
 * environment trips these renders with LazyLoadingViolationException; the
 * local request path happens to hydrate the relations before the blade runs,
 * which hides the bug. These HTTP tests exercise the full routes, and the
 * bare-model tests below hold the real ordering: with logical loading on, a
 * component handed a bare model must leave every relation its blade reads
 * loaded before it returns.
 */
it('renders a customer order page over HTTP with the product link loaded', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);
    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product);

    $this->actingAs($buyer)
        ->get(route('orders.show', $order))
        ->assertOk()
        ->assertSee('View product')
        ->assertSee($product->name);
});

it('renders an admin order page over HTTP for an auction buy-out', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    // A Buy Now checkout against a live auction: the order links an auction,
    // and the screen reads auction.product for the comparison price.
    $order = buyNowCheckout(bidder(), $product, $auction);

    $this->actingAs($this->admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk();
});

it('renders the tracking page over HTTP once a delivery exists', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);
    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product);
    payOrder($order);

    $order->refresh()->load('delivery');
    expect($order->delivery)->not->toBeNull();

    $this->actingAs($buyer)
        ->get(route('orders.tracking', $order))
        ->assertOk();
});

/*
 * The same bug, held by the shoulders: a component handed a bare model by
 * HTTP route binding must load every relation its blade reads while logical
 * loading is still on, rather than leaving the render to trip
 * LazyLoadingViolationException. Rendered here the way Livewire mounts it,
 * without the request-path hydration that can hide a missing load.
 */
it('loads the item product a customer order page links to', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);
    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product);

    $bare = $order->newQuery()->whereKey($order->getKey())->first();

    Auth::login($buyer);
    $component = new OrderDetail;
    $component->mount($bare);
    $component->render();

    expect($bare->relationLoaded('items'))->toBeTrue()
        ->and($bare->items->first()->relationLoaded('product'))->toBeTrue();
});

it('loads the relations an admin order screen reads', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $order = buyNowCheckout(bidder(), $product, $auction);
    payOrder($order);

    $bare = $order->newQuery()->whereKey($order->getKey())->first();

    Auth::login($this->admin);
    $component = new AdminOrderDetail;
    $component->mount($bare);

    expect($bare->relationLoaded('items'))->toBeTrue()
        ->and($bare->relationLoaded('delivery'))->toBeTrue()
        ->and($bare->relationLoaded('auction'))->toBeTrue()
        ->and($bare->auction->relationLoaded('product'))->toBeTrue();
});

it('loads the delivery an order tracking screen reads', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);
    $buyer = bidder();
    $order = buyNowCheckout($buyer, $product);
    payOrder($order);

    $bare = $order->newQuery()->whereKey($order->getKey())->first();

    Auth::login($buyer);
    $component = new OrderTracking;
    $component->mount($bare);

    expect($bare->relationLoaded('items'))->toBeTrue()
        ->and($bare->relationLoaded('delivery'))->toBeTrue()
        ->and($bare->delivery)->not->toBeNull();
});
