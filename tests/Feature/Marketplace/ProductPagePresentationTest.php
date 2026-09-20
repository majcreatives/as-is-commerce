<?php

declare(strict_types=1);

use App\Domain\Auction\Actions\CloseAuction;
use App\Domain\Auction\Actions\CreateAuction;
use App\Domain\Auction\Services\AuctionLifecycle;
use App\Domain\Catalog\Services\InventoryService;
use App\Domain\Shared\Money\Money;
use App\Enums\ProductCondition;
use App\Livewire\Catalog\ProductDetail;
use App\Models\AuctionRuleset;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Livewire\Livewire;

/*
 * What a product page leads with, and where its metadata goes.
 *
 * Two rules are being held at once. An auction that currently holds the unit
 * owns both ways of acquiring the product, so it leads the page -- but only an
 * auction that actually exists and is currently relevant, never the eligibility
 * flag, which says nothing about whether one is running. And a credit figure
 * stays a count wherever it appears: no currency symbol, and never the words
 * "auction price".
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();
});

// --------------------------------------------------- The auction leads

it('leads with the auction when one is holding the product', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Nokia Handset']);
    $auction = liveAuction(product: $product);

    placeBid($auction, bidder(500), 150);

    $rendered = Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        ->assertSee('Highest Bid (Credits)')
        ->assertSee('150 credits')
        ->assertSee('Bid on this product')
        // The disclosure a bidder most needs, before they leave for the room.
        ->assertSee('consumed straight away')
        ->html();

    // Leads: the auction block is above the outright price, not below it.
    expect(strpos($rendered, 'Highest Bid (Credits)'))
        ->toBeLessThan(strpos($rendered, 'Buy Now price'));
});

it('never prints a credit figure as money', function (): void {
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);

    placeBid($auction, bidder(500), 150);

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        ->assertSee('150 credits')
        // The two ways this goes wrong: a currency symbol in front of a count,
        // and calling the bid a price.
        ->assertDontSee('GH₵ 150')
        ->assertDontSee('GH₵150')
        ->assertDontSee('auction price', false)
        ->assertDontSee('Auction price', false);
});

it('says no bids yet rather than showing a zero', function (): void {
    $product = Product::factory()->active()->create();
    liveAuction(product: $product);

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        ->assertSee('No bids yet')
        ->assertSee('Highest Bid (Credits)');
});

it('shows a scheduled auction with its start time and no bid figure', function (): void {
    // A scheduled auction holds the unit but has taken no bids and has no
    // clock running yet. A countdown here would be counting down to nothing,
    // and a highest bid would be inventing one.
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 1);

    $auction = app(CreateAuction::class)->handle(
        $product->fresh(),
        AuctionRuleset::factory()->active()->withoutThrottle()->create(),
        Money::fromMinor(10_000),
    );

    app(AuctionLifecycle::class)->schedule($auction, now()->addDay());

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        ->assertSee('Opens')
        ->assertSee('Highest Bid (Credits)')
        ->assertSee('No bids yet')
        // Not open for bidding yet, so the action is to look rather than bid.
        ->assertSee('View the auction')
        ->assertDontSee('Closes in');
});

it('does not lead with an auction that has finished', function (): void {
    // A closed auction says nothing about present availability, and its
    // highest bid must never reach the page.
    $product = Product::factory()->active()->create();
    $auction = liveAuction(product: $product);

    placeBid($auction, bidder(500), 150);
    app(CloseAuction::class)->handle($auction, force: true);

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        ->assertDontSee('Highest Bid (Credits)')
        ->assertDontSee('150 credits')
        ->assertDontSee('Bid on this product');
});

it('does not lead with an auction merely because the product is eligible for one', function (): void {
    // The eligibility flag means an administrator MAY put this product into
    // the auction channel. It is not an auction.
    $product = Product::factory()->active()->create(['auction_eligible' => true]);
    app(InventoryService::class)->initialStock($product, 3);

    Livewire::actingAs(bidder(0))
        ->test(ProductDetail::class, ['slug' => $product->fresh()->slug])
        ->assertOk()
        ->assertDontSee('Highest Bid (Credits)')
        ->assertDontSee('Bid on this product')
        ->assertSee('Add to cart');
});

it('keeps the plain shop page unchanged when no auction is involved', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 3);

    Livewire::actingAs(bidder(0))
        ->test(ProductDetail::class, ['slug' => $product->fresh()->slug])
        ->assertOk()
        ->assertSee('Buy Now price')
        ->assertSee('Add to cart')
        ->assertDontSee('Highest Bid (Credits)');
});

it('sends the outright purchase to the auction rather than the cart', function (): void {
    // Two checkouts on one unit would be wrong in both directions, and the
    // auction's price is the one carrying the bidder's credit discount.
    $product = Product::factory()->active()->create();
    liveAuction(product: $product);

    Livewire::actingAs(bidder(0))
        ->test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        ->assertSee('Buy it outright on the auction page')
        ->assertDontSee('Add to cart');
});

// ------------------------------------------------------ Metadata links

it('links the brand, category and condition to a filtered listing', function (): void {
    $category = Category::factory()->create(['name' => 'Phones', 'slug' => 'phones']);
    $brand = Brand::factory()->create(['name' => 'Nokia', 'slug' => 'nokia']);

    $product = Product::factory()->active()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'condition' => ProductCondition::Refurbished,
    ]);

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        ->assertSee(route('products.index', ['brand' => 'nokia']), false)
        ->assertSee(route('products.index', ['category' => 'phones']), false)
        ->assertSee(route('products.index', ['condition' => 'refurbished']), false);
});

it('finds the product through each of those filters', function (): void {
    // The links are only worth having if they land on a listing that contains
    // the product they came from.
    $category = Category::factory()->create(['slug' => 'phones']);
    $brand = Brand::factory()->create(['slug' => 'nokia']);

    $product = Product::factory()->active()->create([
        'name' => 'Nokia Handset',
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'condition' => ProductCondition::Refurbished,
    ]);

    $this->get(route('products.index', ['brand' => 'nokia']))->assertSee('Nokia Handset');
    $this->get(route('products.index', ['category' => 'phones']))->assertSee('Nokia Handset');
    $this->get(route('products.index', ['condition' => 'refurbished']))->assertSee('Nokia Handset');
});

// ------------------------------------------------------- The image viewer

it('makes the featured image open a viewer that is reachable by keyboard', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Nokia Handset']);
    $product->images()->create(['image_path' => 'products/1/a.jpg', 'position' => 0]);

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        // A button, not a bare click handler on an image.
        ->assertSee('View larger image of Nokia Handset')
        ->assertSee('role="dialog"', false)
        ->assertSee('aria-modal="true"', false)
        ->assertSee('Close image viewer');
});

it('describes every image and counts them', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Nokia Handset']);
    $product->images()->create(['image_path' => 'products/1/a.jpg', 'position' => 0]);
    $product->images()->create(['image_path' => 'products/1/b.jpg', 'position' => 1]);
    $product->images()->create(['image_path' => 'products/1/c.jpg', 'position' => 2]);

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        ->assertSee('Nokia Handset — image 1 of 3')
        ->assertSee('Nokia Handset — image 3 of 3')
        ->assertSee('Show image 2 of 3')
        ->assertSee('Previous image')
        ->assertSee('Next image');
});

it('offers no viewer controls for a product with no images', function (): void {
    $product = Product::factory()->active()->create(['image_path' => null]);

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])
        ->assertOk()
        ->assertSee('No image available')
        ->assertDontSee('Close image viewer')
        ->assertDontSee('Next image');
});
