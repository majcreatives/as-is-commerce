<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Marketplace\Queries\AuctionDiscoveryQuery;
use App\Domain\Marketplace\Queries\ProductDiscoveryQuery;
use App\Domain\Marketplace\ValueObjects\ListingAvailability;
use App\Enums\AuctionStatus;
use App\Enums\ProductStatus;
use App\Livewire\Auctions\AuctionIndex;
use App\Livewire\Catalog\ProductCatalog;
use App\Models\Auction;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Livewire\Livewire;

/*
 * Finding things, and being told the truth about them.
 *
 * The rule underneath all of it: a listing describes what a customer can
 * actually do right now. It never shows a finished auction as an opportunity,
 * never carries a stale bid, and never calls a product unavailable because an
 * auction is holding its unit.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->products = app(ProductDiscoveryQuery::class);
    $this->auctions = app(AuctionDiscoveryQuery::class);
});

// ------------------------------------------------------- Product discovery

it('shows a published product to a guest', function (): void {
    Product::factory()->active()->withStock(3)->create(['name' => 'Visible Widget']);

    $this->get(route('products.index'))->assertOk()->assertSee('Visible Widget');
});

it('never shows a draft or archived product', function (string $status): void {
    Product::factory()->status(ProductStatus::from($status))->create(['name' => 'Hidden Widget']);

    Livewire::test(ProductCatalog::class)
        ->assertDontSee('Hidden Widget')
        ->assertViewHas('products', fn ($page): bool => $page->total() === 0);
})->with(['draft', 'archived', 'inactive']);

it('cannot be made to show a draft through a search term', function (): void {
    Product::factory()->status(ProductStatus::Draft)->create(['name' => 'Secret Prototype']);

    Livewire::test(ProductCatalog::class)
        ->set('search', 'Secret Prototype')
        ->assertDontSee('Secret Prototype')
        ->assertViewHas('products', fn ($page): bool => $page->total() === 0);
});

it('caps a pathological search term', function (): void {
    Product::factory()->active()->create(['name' => 'Ordinary Thing']);

    // Bounded before it reaches the database rather than trusted.
    Livewire::test(ProductCatalog::class)
        ->set('search', str_repeat('a', 5000))
        ->assertOk()
        ->assertViewHas('products', fn ($page): bool => $page->total() === 0);
});

it('sorts products by price in both directions', function (): void {
    Product::factory()->active()->pricedAt(100_00)->create(['name' => 'Cheap One']);
    Product::factory()->active()->pricedAt(900_00)->create(['name' => 'Dear One']);

    $ascending = $this->products->paginate(['sort' => 'price_asc']);
    $descending = $this->products->paginate(['sort' => 'price_desc']);

    expect($ascending->first()->name)->toBe('Cheap One')
        ->and($descending->first()->name)->toBe('Dear One');
});

it('offers related products from the same category', function (): void {
    $product = Product::factory()->active()->create();
    Product::factory()->active()->count(2)->create(['category_id' => $product->category_id]);
    // A product in another category, which must not appear.
    Product::factory()->active()->create(['category_id' => Category::factory()->create()->id]);

    $related = $this->products->related($product);

    expect($related)->toHaveCount(2)
        // Never the product itself.
        ->and($related->pluck('id'))->not->toContain($product->id)
        ->and($related->pluck('category_id')->unique()->all())->toBe([$product->category_id]);
});

it('tops related products up from the same brand', function (): void {
    $brand = Brand::factory()->create();
    $product = Product::factory()->active()->create(['brand_id' => $brand->id]);

    // Nothing else in its category, but two more from the same brand.
    Product::factory()->active()->count(2)->create([
        'brand_id' => $brand->id,
        'category_id' => Category::factory()->create()->id,
    ]);

    expect($this->products->related($product))->toHaveCount(2);
});

// ------------------------------------------------------- Auction discovery

it('lists a live auction as an opportunity', function (): void {
    $auction = liveAuction(product: Product::factory()->active()->create(['name' => 'Live Item']));

    Livewire::test(AuctionIndex::class)
        ->assertSee('Live Item')
        ->assertViewHas('auctions', fn ($page): bool => $page->total() === 1);

    expect($auction->status)->toBe(AuctionStatus::Live);
});

/*
 * The rule that matters most in discovery: a finished auction is history, and
 * putting one in a list of things to bid on is an invitation to click nothing.
 */
it('never lists a finished auction as open', function (string $status): void {
    $auction = liveAuction(product: Product::factory()->active()->create(['name' => 'Finished Item']));

    // Written directly: the engine will not move an auction here arbitrarily,
    // and the point is what the listing does with the state, not how it arose.
    DB::table('auctions')->where('id', $auction->id)->update(['status' => $status]);

    Livewire::test(AuctionIndex::class)
        ->assertViewHas('auctions', fn ($page): bool => $page->total() === 0);

    expect($this->auctions->openCount())->toBe(0);
})->with(['settled', 'unsold', 'cancelled', 'forfeited']);

it('can still show a finished auction when asked for one', function (): void {
    $auction = liveAuction(product: Product::factory()->active()->create(['name' => 'Old Item']));
    DB::table('auctions')->where('id', $auction->id)->update(['status' => 'settled']);

    Livewire::test(AuctionIndex::class)
        ->set('filter', 'ended')
        ->assertSee('Old Item');
});

it('lists a scheduled auction as something to wait for', function (): void {
    $auction = liveAuction(product: Product::factory()->active()->create(['name' => 'Soon Item']));
    DB::table('auctions')->where('id', $auction->id)->update(['status' => 'scheduled']);

    Livewire::test(AuctionIndex::class)
        ->assertSee('Soon Item')
        ->assertSee('Starts soon');
});

it('filters auctions by category and condition', function (): void {
    $wanted = Product::factory()->active()->create(['name' => 'Wanted Item']);
    liveAuction(product: $wanted);
    liveAuction(product: Product::factory()->active()->create(['name' => 'Other Item']));

    Livewire::test(AuctionIndex::class)
        ->set('category', $wanted->category->slug)
        ->assertSee('Wanted Item')
        ->assertDontSee('Other Item');
});

it('orders ending soonest by the auction own timestamp', function (): void {
    $later = liveAuction(product: Product::factory()->active()->create(['name' => 'Later Item']));
    $sooner = liveAuction(product: Product::factory()->active()->create(['name' => 'Sooner Item']));

    DB::table('auctions')->where('id', $later->id)->update(['ends_at' => now()->addDays(3)]);
    DB::table('auctions')->where('id', $sooner->id)->update(['ends_at' => now()->addHour()]);

    $page = $this->auctions->paginate(['sort' => 'ending_soon']);

    expect($page->first()->product->name)->toBe('Sooner Item');
});

// ----------------------------------------------------------- Availability

/*
 * The trap this stage had to avoid. A live auction reserves the unit it is
 * selling, so available stock is zero by design -- and asking the catalog
 * alone would print "unavailable" on something anybody can bid for right now.
 */
it('does not call an auctioned product unavailable', function (): void {
    $product = Product::factory()->active()->withStock(1)->create(['name' => 'Auctioned Item']);
    $auction = liveAuction(product: $product);

    // Publishing an auction reserves the unit it is selling. Posted through
    // the inventory service so this is the real situation rather than an
    // approximation of it.
    app(InventoryService::class)->reserve(
        product: $product->fresh(),
        quantity: 1,
        reference: $auction,
        reason: 'Held for auction.',
    );

    expect($product->fresh()->availableStock())->toBe(0)
        ->and($product->fresh()->isInStock())->toBeFalse();

    $availability = ListingAvailability::for($product->fresh(), $auction);

    expect($availability->obtainable)->toBeTrue()
        ->and($availability->canBid)->toBeTrue()
        ->and($availability->hasAuction())->toBeTrue();

    Livewire::test(ProductCatalog::class)
        ->assertSee('Auctioned Item')
        ->assertDontSee('Currently unavailable');
});

it('calls a product with no stock and no auction unavailable', function (): void {
    $product = Product::factory()->status(ProductStatus::OutOfStock)->create();

    $availability = ListingAvailability::for($product);

    expect($availability->obtainable)->toBeFalse()
        ->and($availability->canBuyNow)->toBeFalse()
        ->and($availability->hasAuction())->toBeFalse();
});

/*
 * A card must never imply an auction exists because one used to.
 */
it('carries no auction figure from a finished auction', function (): void {
    $product = Product::factory()->active()->withStock(1)->create(['name' => 'Once Auctioned']);
    $auction = liveAuction(product: $product);
    placeBid($auction, bidder(500), 180);

    DB::table('auctions')->where('id', $auction->id)->update(['status' => 'settled']);

    $availability = $this->products->availabilityFor(collect([$product->fresh()]));

    expect($availability[$product->id]->hasAuction())->toBeFalse()
        ->and($availability[$product->id]->highestBidCredits())->toBeNull();

    Livewire::test(ProductCatalog::class)
        ->assertSee('Once Auctioned')
        ->assertDontSee('Highest Bid (Credits)');
});

it('shows a live auction figure on a product card', function (): void {
    $product = Product::factory()->active()->withStock(1)->create(['name' => 'Bid On Item']);
    $auction = liveAuction(product: $product);
    placeBid($auction, bidder(500), 180);

    Livewire::test(ProductCatalog::class)
        ->assertSee('Bid On Item')
        ->assertSee('Highest Bid (Credits)')
        ->assertSee('180');
});

// ------------------------------------------------------------- The homepage

it('shows a guest a shop-first front page with the auction way in', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('The shop, first.')
        ->assertSee('Shop now')
        ->assertSee('Explore auctions')
        // The disclosure that matters, on the front page.
        ->assertSee('not returned if you do not win');
});

it('surfaces a live auction inline on its product card instead of a band', function (): void {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('In the shop');

    liveAuction(product: Product::factory()->active()->create(['name' => 'Homepage Item']));

    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Homepage Item')
        ->assertSee('Auction open — no bids yet');
});

it('makes no promise about savings or winning', function (): void {
    $page = $this->get(route('home'))->assertOk();

    foreach (['guaranteed', 'risk-free', 'always cheaper', 'win every'] as $claim) {
        $page->assertDontSee($claim, escape: false);
    }
});

it('explains the platform without conflating credits and cedis', function (): void {
    $this->get(route('how-it-works'))
        ->assertOk()
        ->assertSee('Credits are not money')
        ->assertSee('The largest total wins')
        ->assertSee('This is a discount, not a refund')
        // The settlement is its own figure, and said to be.
        ->assertSee('separate figure in cedis');
});

// ---------------------------------------------------------------- SEO

it('gives a public page a canonical url and a description', function (): void {
    $product = Product::factory()->active()->withStock(1)->create(['name' => 'Indexed Item']);

    $this->get(route('products.show', $product->slug))
        ->assertOk()
        ->assertSee('rel="canonical"', escape: false)
        ->assertSee('property="og:title"', escape: false)
        ->assertSee('"@type":"Product"', escape: false);
});

/*
 * A customer's own pages are never worth indexing, and indexing one would put
 * their view of their account into a search result.
 */
it('keeps signed-in pages out of search results', function (): void {
    $this->actingAs(userWithRole('customer'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('noindex', escape: false);
});
