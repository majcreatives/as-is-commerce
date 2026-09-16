<?php

declare(strict_types=1);

use App\Domain\Auction\Services\BuyNowPricer;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Orders\Actions\PlaceCartOrder;
use App\Livewire\Account\Dashboard;
use App\Livewire\Auctions\AuctionRoom;
use App\Livewire\Catalog\ProductDetail;
use App\Models\AuctionRuleset;
use App\Models\Bid;
use App\Models\Cart;
use App\Models\Order;
use App\Models\Product;
use Livewire\Livewire;

/*
 * Getting from looking to buying, and being told the truth on the way.
 *
 * The rules under all of it: credits are never money, a bid confirmation says
 * what it costs, and every figure on every screen comes from the domain rather
 * than from arithmetic in a template.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    config(['paystack.secret_key' => 'sk_test_orders']);
});

// -------------------------------------------------------------- Buy Now

it('lets a customer start a checkout from a product page', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    app(InventoryService::class)->initialStock($product, 1);

    $buyer = bidder();

    Livewire::actingAs($buyer)
        ->test(ProductDetail::class, ['slug' => $product->slug])
        ->call('addToCart')
        ->assertRedirect(route('cart.show'));

    // The product page fills the basket; placing it freezes the order the way
    // the domain does. The browser sent a slug and a page click, nothing else.
    $order = app(PlaceCartOrder::class)->handle($buyer);

    expect($order)->not->toBeNull()
        ->and(Cart::query()->forUser($buyer)->exists())->toBeFalse()
        ->and($order->total_minor)->toBeGreaterThan(0)
        ->and($order->subtotal_minor)->toBe(550_000);
});

it('sends a guest to sign in rather than to a checkout', function (): void {
    $product = Product::factory()->active()->withStock(1)->create();

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('Sign in to buy');

    expect(Order::count())->toBe(0);
});

/*
 * An auction owns the Buy Now path for the unit it is holding: its price
 * carries the bidder's credit discount, and completing it ends the auction.
 * Opening a second, discount-free checkout on the same unit would be wrong in
 * both directions.
 */
it('routes a product with a live auction to the auction', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    Livewire::actingAs(bidder())
        ->test(ProductDetail::class, ['slug' => $product->slug])
        ->assertSee('View the auction')
        // The auction owns the Buy Now path for the unit it is holding, so the
        // product page sends the customer to the auction instead of the cart.
        ->call('addToCart')
        ->assertRedirect(route('auctions.show', $auction));

    expect(Order::count())->toBe(0)
        ->and(Cart::count())->toBe(0);
});

it('shows no purchase control when nothing can be bought', function (): void {
    $product = Product::factory()->active()->create();

    // Active, but nothing on the shelf and no auction holding anything.
    expect($product->availableStock())->toBe(0);

    Livewire::actingAs(bidder())
        ->test(ProductDetail::class, ['slug' => $product->slug])
        ->assertSee('not available to buy right now')
        ->assertDontSee('Sign in to buy');
});

// ------------------------------------------------- The Buy Now discount

it('shows a bidder their own discount, from the domain', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);
    $bidder = customerWithPurchasedCredits(1_000, 100_000);

    placeBid($auction, $bidder, 150);

    $quote = app(BuyNowPricer::class)->quote($auction->fresh(), $bidder);

    // The discount is valued from the lot the credits were bought at: here
    // GH₵1.00 each, so 150 consumed credits reduce the price by GH₵150.
    expect($quote->eligibleCredits)->toBe(150)
        ->and($quote->discount->minor)->toBe(15_000)
        ->and($quote->payable->minor)->toBe(535_000);

    Livewire::actingAs($bidder)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('5,350.00')
        ->assertSee('150');
});

/*
 * The distinction the whole discount rests on. Credits sitting unused in a
 * wallet have bought nothing and reduce nothing.
 */
it('gives no discount for unused wallet credits', function (): void {
    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    // A thousand credits, none of them bid.
    $rich = bidder(1_000);

    $quote = app(BuyNowPricer::class)->quote($auction, $rich);

    expect($quote->eligibleCredits)->toBe(0)
        ->and($quote->discount->minor)->toBe(0)
        ->and($quote->payable->minor)->toBe(550_000);
});

it('gives no discount for credits spent on a different auction', function (): void {
    $bidder = bidder(1_000);

    $elsewhere = liveAuction(product: Product::factory()->active()->create());
    placeBid($elsewhere, $bidder, 200);

    $product = Product::factory()->active()->pricedAt(550_000)->create();
    $auction = liveAuction(product: $product);

    $quote = app(BuyNowPricer::class)->quote($auction, $bidder->fresh());

    expect($quote->eligibleCredits)->toBe(0)
        ->and($quote->payable->minor)->toBe(550_000);
});

// ----------------------------------------------------------- Bidding UX

it('asks a bidder to confirm before consuming credits', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);

    $component = Livewire::actingAs($bidder)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->set('amount', '150')
        ->call('review');

    $component->assertSet('confirming', true)
        ->assertSee('You are about to bid')
        ->assertSee('will be consumed immediately')
        ->assertSee('not be returned if you lose');

    // Nothing has happened yet: reviewing is not bidding.
    expect(Bid::count())->toBe(0)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(1_000);

    $component->call('bid');

    expect(Bid::count())->toBe(1)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(850);
});

it('lets a bidder back out of the confirmation', function (): void {
    $auction = liveAuction();
    $bidder = bidder(1_000);

    Livewire::actingAs($bidder)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->set('amount', '150')
        ->call('review')
        ->call('cancelBid')
        ->assertSet('confirming', false);

    expect(Bid::count())->toBe(0)
        ->and(creditWalletFor($bidder)->fresh()->balance)->toBe(1_000);
});

it('refuses a bid that is not a whole number of credits', function (): void {
    $auction = liveAuction();

    Livewire::actingAs(bidder(1_000))
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->set('amount', '150.50')
        ->call('review')
        ->assertHasErrors('amount')
        ->assertSet('confirming', false);

    expect(Bid::count())->toBe(0);
});

it('tells a bidder plainly when they cannot afford it', function (): void {
    $auction = liveAuction();
    $poor = bidder(50);

    Livewire::actingAs($poor)
        ->test(AuctionRoom::class, ['auction' => $auction])
        ->set('amount', '500')
        ->call('review')
        ->call('bid')
        ->assertHasErrors('amount');

    expect(Bid::count())->toBe(0)
        ->and(creditWalletFor($poor)->fresh()->balance)->toBe(50);
});

/*
 * The concurrency case a page must handle: somebody else bid while the
 * confirmation was open. The domain refuses it, the confirmation closes, and
 * the page re-reads authoritative state rather than retrying blindly.
 */
it('closes the confirmation when a bid is refused', function (): void {
    $ruleset = AuctionRuleset::factory()->active()->withoutThrottle()->create([
        'minimum_bid_increment_credits' => 10,
    ]);

    $auction = liveAuction(ruleset: $ruleset);
    $first = bidder(1_000);
    $second = bidder(1_000);

    placeBid($auction, $first, 100);

    $component = Livewire::actingAs($second)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->set('amount', '105')
        ->call('review');

    // Somebody else bids higher while the confirmation is open.
    placeBid($auction->fresh(), $first, 300);

    $component->call('bid')
        ->assertHasErrors('amount')
        ->assertSet('confirming', false);

    expect(creditWalletFor($second)->fresh()->balance)->toBe(1_000);
});

it('shows a bidder that they have been outbid, without naming anybody', function (): void {
    $auction = liveAuction();
    $me = bidder(1_000);
    $rival = bidder(1_000);
    $rival->update(['name' => 'Kwame Mensah']);

    placeBid($auction, $me, 100);
    placeBid($auction->fresh(), $rival, 300);

    Livewire::actingAs($me)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('You have been outbid')
        ->assertSee('300')
        // Never who did it.
        ->assertDontSee('Kwame')
        ->assertDontSee($rival->phone);
});

it('tells the standing highest bidder that they lead', function (): void {
    $auction = liveAuction();
    $me = bidder(1_000);

    placeBid($auction, $me, 100);

    Livewire::actingAs($me)
        ->test(AuctionRoom::class, ['auction' => $auction->fresh()])
        ->assertSee('You hold the highest bid');
});

// ------------------------------------------------------------ Dashboard

it('shows a customer their two credit figures separately', function (): void {
    $auction = liveAuction();
    $customer = bidder(1_000);

    placeBid($auction, $customer, 150);

    Livewire::actingAs($customer)
        ->test(Dashboard::class)
        ->assertSee('Available credits')
        ->assertSee('850')
        ->assertSee('Credits committed')
        ->assertSee('150')
        // And says plainly that the committed ones are gone.
        ->assertSee('Not returned, whether you won or lost');
});

it('never adds a customer available and committed credits together', function (): void {
    $auction = liveAuction();
    $customer = bidder(1_000);

    placeBid($auction, $customer, 150);

    Livewire::actingAs($customer)
        ->test(Dashboard::class)
        // 850 available and 150 committed. A combined 1,000 would be a figure
        // that is half spendable and half spent, and means nothing.
        ->assertDontSee('1,000');
});

it('shows a customer the auctions they are bidding in', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Watched Item']);
    $auction = liveAuction(product: $product);
    $customer = bidder(1_000);

    placeBid($auction, $customer, 150);

    Livewire::actingAs($customer)
        ->test(Dashboard::class)
        ->assertSee('Watched Item')
        ->assertSee('You lead');
});

it('does not show a customer somebody else auctions', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Not Mine']);
    $auction = liveAuction(product: $product);

    placeBid($auction, bidder(1_000), 150);

    Livewire::actingAs(bidder())
        ->test(Dashboard::class)
        ->assertDontSee('Not Mine')
        ->assertSee('bid on anything yet');
});
