<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\ProductService;
use App\Domain\Credit\ValueObjects\CreditAmount;
use App\Domain\Marketplace\RecentlyViewed;
use App\Enums\ProductStatus;
use App\Livewire\Catalog\ProductDetail;
use App\Models\Product;
use Livewire\Livewire;

it('remembers a product when its page is opened', function (): void {
    $product = stockedProduct();

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])->assertOk();

    expect(app(RecentlyViewed::class)->ids())->toBe([$product->id]);
});

it('shows the products a visitor has opened, newest first, on a later page', function (): void {
    $first = stockedProduct();
    $second = stockedProduct();

    Livewire::test(ProductDetail::class, ['slug' => $first->slug])->assertOk();
    Livewire::test(ProductDetail::class, ['slug' => $second->slug])->assertOk();

    // A third page offers the strip. The page it is on is never in it.
    $third = stockedProduct();

    Livewire::test(ProductDetail::class, ['slug' => $third->slug])
        ->assertOk()
        ->assertSee('Recently viewed')
        ->assertSee($first->name)
        ->assertSee($second->name)
        ->assertViewHas('recentlyViewed', function ($recently) use ($first, $second): bool {
            return collect($recently)->pluck('id')->all() === [$second->id, $first->id];
        });
});

it('moves a revisited product to the front without duplicating it', function (): void {
    $first = stockedProduct();
    $second = stockedProduct();

    Livewire::test(ProductDetail::class, ['slug' => $first->slug])->assertOk();
    Livewire::test(ProductDetail::class, ['slug' => $second->slug])->assertOk();
    Livewire::test(ProductDetail::class, ['slug' => $first->slug])->assertOk();

    $third = stockedProduct();

    Livewire::test(ProductDetail::class, ['slug' => $third->slug])
        ->assertViewHas('recentlyViewed', function ($recently) use ($first, $second): bool {
            return collect($recently)->pluck('id')->all() === [$first->id, $second->id];
        });
});

it('bounds the stored history and the strip', function (): void {
    $products = [];

    foreach (range(1, 9) as $index) {
        $products[$index] = stockedProduct();

        Livewire::test(ProductDetail::class, ['slug' => $products[$index]->slug])->assertOk();
    }

    // Nine visits fit in eight; the oldest has been pruned.
    $ids = app(RecentlyViewed::class)->ids();

    expect($ids)->toHaveCount(RecentlyViewed::MAX)
        ->and($ids[0])->toBe($products[9]->id)
        ->and($ids)->not->toContain($products[1]->id);

    // And a tenth page shows only the four newest.
    Livewire::test(ProductDetail::class, ['slug' => $products[9]->slug])
        ->assertViewHas('recentlyViewed', function ($recently) use ($products): bool {
            return collect($recently)->pluck('id')->all() === [
                $products[8]->id,
                $products[7]->id,
                $products[6]->id,
                $products[5]->id,
            ];
        });
});

it('never surfaces a product that is no longer publicly visible', function (): void {
    $product = stockedProduct();

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])->assertOk();

    // The history still holds the id, but the catalog no longer agrees it is
    // seeable -- the strip must resolve that rather than trust the session.
    app(ProductService::class)->transitionTo($product, ProductStatus::Archived);

    $current = stockedProduct();

    Livewire::test(ProductDetail::class, ['slug' => $current->slug])
        ->assertViewHas('recentlyViewed', fn ($recently): bool => $recently->isNotEmpty() === false)
        ->assertDontSee('Recently viewed');
});

it('keeps the history per session rather than anywhere shared', function (): void {
    $product = stockedProduct();

    Livewire::test(ProductDetail::class, ['slug' => $product->slug])->assertOk();

    expect(session('recently_viewed.product_ids'))->toContain($product->id);

    // A second visitor carries no history, so opening a page offers no strip.
    session()->forget('recently_viewed.product_ids');

    Livewire::test(ProductDetail::class, ['slug' => stockedProduct()->slug])
        ->assertViewHas('recentlyViewed', fn ($recently): bool => $recently->isEmpty())
        ->assertDontSee('Recently viewed');
});

it('shows an honest auction figure on a recently viewed card', function (): void {
    // A unit an auction is holding: zero available stock by design, but live
    // and bid on. Its card must say so, never "Currently unavailable".
    $auctionProduct = Product::factory()->active()->withStock(1)->create(['name' => 'Strip Auctioned']);
    $auction = liveAuction(product: $auctionProduct);
    placeBid($auction, bidder(500 * CreditAmount::SUBCREDITS_PER_CREDIT), 180 * CreditAmount::SUBCREDITS_PER_CREDIT);

    Livewire::test(ProductDetail::class, ['slug' => $auctionProduct->slug])->assertOk();

    $current = stockedProduct();

    Livewire::test(ProductDetail::class, ['slug' => $current->slug])
        ->assertOk()
        ->assertSee('Recently viewed')
        ->assertSee('Strip Auctioned')
        ->assertSee('Highest Bid (Credits)')
        ->assertDontSee('Currently unavailable');
});

it('does not record a draft when the page could not open', function (): void {
    $draft = Product::factory()->create(['status' => ProductStatus::Draft]);

    $this->get(route('products.show', $draft->slug))->assertNotFound();

    expect(app(RecentlyViewed::class)->ids())->toBe([]);
});
