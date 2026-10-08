<?php

declare(strict_types=1);

use App\Domain\Catalog\Exceptions\InvalidProductDelete;
use App\Domain\Catalog\Exceptions\InvalidProductTransition;
use App\Domain\Catalog\Services\ProductService;
use App\Domain\Shared\Money\Money;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Models\Auction;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

beforeEach(function (): void {
    seedRoles();

    $this->products = app(ProductService::class);
    $this->category = Category::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function productAttributes(array $overrides = []): array
{
    return array_merge([
        'name' => 'Northline N7 Smartphone',
        'category_id' => Category::factory()->create()->id,
        'condition' => ProductCondition::New,
        'buy_now_price_minor' => 550_000,
        'currency' => 'GHS',
    ], $overrides);
}

// ------------------------------------------------------------------ Create

it('creates a product as a draft', function (): void {
    $product = $this->products->create(productAttributes(['category_id' => $this->category->id]));

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->published_at)->toBeNull()
        ->and($product->stock_on_hand)->toBe(0)
        ->and($product->stock_reserved)->toBe(0);
});

it('never creates a product already active, even if asked to', function (): void {
    $product = $this->products->create(productAttributes([
        'category_id' => $this->category->id,
        'status' => 'active',
        'stock_on_hand' => 999,
    ]));

    expect($product->status)->toBe(ProductStatus::Draft)
        ->and($product->stock_on_hand)->toBe(0);
});

it('generates a readable SKU when none is supplied', function (): void {
    $product = $this->products->create(productAttributes(['category_id' => $this->category->id]));

    expect($product->sku)->not->toBeEmpty()
        ->and($product->sku)->toMatch('/^[A-Z0-9]+-[A-Z0-9]{6}$/');
});

it('keeps a SKU that was supplied', function (): void {
    $product = $this->products->create(productAttributes([
        'category_id' => $this->category->id,
        'sku' => 'PHN-NL-0001',
    ]));

    expect($product->sku)->toBe('PHN-NL-0001');
});

// -------------------------------------------------------------------- Slug

it('generates a slug from the name', function (): void {
    $product = $this->products->create(productAttributes([
        'category_id' => $this->category->id,
        'name' => 'Northline N7 Smartphone 128GB',
    ]));

    expect($product->slug)->toBe('northline-n7-smartphone-128gb');
});

/*
 * Two products legitimately share a name -- the same phone in different
 * conditions -- so a collision is normal and must not break either URL.
 */
it('suffixes a slug rather than colliding', function (): void {
    $first = $this->products->create(productAttributes(['category_id' => $this->category->id, 'name' => 'Same Name']));
    $second = $this->products->create(productAttributes(['category_id' => $this->category->id, 'name' => 'Same Name']));
    $third = $this->products->create(productAttributes(['category_id' => $this->category->id, 'name' => 'Same Name']));

    expect($first->slug)->toBe('same-name')
        ->and($second->slug)->toBe('same-name-2')
        ->and($third->slug)->toBe('same-name-3');
});

it('still produces a usable slug from a name that slugs to nothing', function (): void {
    $product = $this->products->create(productAttributes([
        'category_id' => $this->category->id,
        'name' => '???',
    ]));

    expect($product->slug)->not->toBeEmpty()
        ->and($product->slug)->toBe('product');
});

// ------------------------------------------------------------- Constraints

it('rejects a duplicate SKU', function (): void {
    Product::factory()->create(['sku' => 'DUPE-001']);

    expect(fn () => Product::factory()->create(['sku' => 'DUPE-001']))
        ->toThrow(QueryException::class);
});

it('rejects a duplicate slug', function (): void {
    Product::factory()->create(['slug' => 'dupe-slug']);

    expect(fn () => Product::factory()->create(['slug' => 'dupe-slug']))
        ->toThrow(QueryException::class);
});

it('rejects a zero or negative price at the database level', function (): void {
    expect(fn () => Product::factory()->create(['buy_now_price_minor' => 0]))
        ->toThrow(QueryException::class);
});

it('rejects an unknown status', function (): void {
    $product = Product::factory()->create();

    expect(fn () => DB::statement(
        'UPDATE products SET status = ? WHERE id = ?', ['nonsense', $product->id]
    ))->toThrow(QueryException::class);
});

it('rejects an unknown condition', function (): void {
    $product = Product::factory()->create();

    expect(fn () => DB::statement(
        'UPDATE products SET `condition` = ? WHERE id = ?', ['pristine', $product->id]
    ))->toThrow(QueryException::class);
});

it('refuses to delete a category that holds products', function (): void {
    $category = Category::factory()->create();
    Product::factory()->create(['category_id' => $category->id]);

    expect(fn () => $category->delete())->toThrow(QueryException::class);
});

it('refuses to delete a category that holds child categories', function (): void {
    $parent = Category::factory()->create();
    Category::factory()->childOf($parent)->create();

    expect(fn () => $parent->delete())->toThrow(QueryException::class);
});

it('keeps a product when its brand is removed', function (): void {
    $brand = Brand::factory()->create();
    $product = Product::factory()->create(['brand_id' => $brand->id]);

    $brand->delete();

    expect(Product::find($product->id))->not->toBeNull()
        ->and(Product::find($product->id)->brand_id)->toBeNull();
});

// ------------------------------------------------------------- Transitions

it('publishes a draft with stock as active', function (): void {
    $product = Product::factory()->withStock(5)->draft()->create();

    $this->products->transitionTo($product, ProductStatus::Active);

    expect($product->fresh()->status)->toBe(ProductStatus::Active)
        ->and($product->fresh()->published_at)->not->toBeNull();
});

/*
 * Publishing something with nothing to sell would put a buy button on an
 * empty shelf, so it lands as out of stock instead.
 */
it('publishes a draft with no stock as out of stock', function (): void {
    $product = Product::factory()->draft()->create();

    $this->products->transitionTo($product, ProductStatus::Active);

    expect($product->fresh()->status)->toBe(ProductStatus::OutOfStock);
});

it('refuses an illegal transition', function (): void {
    $product = Product::factory()->archived()->create();

    expect(fn (): Product => $this->products->transitionTo($product, ProductStatus::Active))
        ->toThrow(InvalidProductTransition::class);

    expect($product->fresh()->status)->toBe(ProductStatus::Archived);
});

it('refuses to edit an archived product', function (): void {
    $product = Product::factory()->archived()->create(['name' => 'Original']);

    expect(fn (): Product => $this->products->update($product, ['name' => 'Changed']))
        ->toThrow(InvalidProductTransition::class);

    expect($product->fresh()->name)->toBe('Original');
});

it('allows every transition the lifecycle declares', function (ProductStatus $from, ProductStatus $to): void {
    expect($from->canTransitionTo($to))->toBeTrue();
})->with([
    [ProductStatus::Draft, ProductStatus::Active],
    [ProductStatus::Draft, ProductStatus::Archived],
    [ProductStatus::Active, ProductStatus::Inactive],
    [ProductStatus::Active, ProductStatus::Archived],
    [ProductStatus::Inactive, ProductStatus::Active],
    [ProductStatus::OutOfStock, ProductStatus::Active],
]);

it('treats archived as terminal', function (): void {
    expect(ProductStatus::Archived->allowedTransitions())->toBe([]);
});

// -------------------------------------------------------------- Visibility

it('shows only publicly visible products in the catalog scope', function (): void {
    Product::factory()->active()->create(['name' => 'Visible Active']);
    Product::factory()->status(ProductStatus::OutOfStock)->create(['name' => 'Visible Out Of Stock']);
    Product::factory()->draft()->create(['name' => 'Hidden Draft']);
    Product::factory()->status(ProductStatus::Inactive)->create(['name' => 'Hidden Inactive']);
    Product::factory()->archived()->create(['name' => 'Hidden Archived']);

    $names = Product::query()->publiclyVisible()->pluck('name')->all();

    expect($names)->toHaveCount(2)
        ->and($names)->toContain('Visible Active')
        ->and($names)->toContain('Visible Out Of Stock');
});

it('separates being listed from being purchasable', function (): void {
    $outOfStock = Product::factory()->status(ProductStatus::OutOfStock)->create();

    expect($outOfStock->isPubliclyVisible())->toBeTrue()
        ->and($outOfStock->isPurchasable())->toBeFalse();
});

it('is not purchasable when everything on hand is reserved', function (): void {
    $product = Product::factory()->active()->withStock(3, 3)->create();

    expect($product->availableStock())->toBe(0)
        ->and($product->isPurchasable())->toBeFalse();
});

// -------------------------------------------------------------- Money shape

it('stores the Buy Now price as integer minor units', function (): void {
    $product = Product::factory()->pricedAt(Money::fromDecimalString('5500.00')->minor)->create();

    expect($product->buy_now_price_minor)->toBe(550_000)->toBeInt()
        ->and($product->buyNowPrice()->toDecimalString())->toBe('5500.00');

    // Straight from the database, so no cast could be hiding a float.
    $raw = DB::table('products')->where('id', $product->id)->first();
    expect($raw->buy_now_price_minor)->toEqual(550_000);
});

// -------------------------------------------------------------- Deleting
/*
 * Delete is the permanent end of a product's life, and it is refused whenever
 * the row carries history the platform must keep: an auction, an order line, a
 * stock movement, or a customer's cart. `archive` is the lifecycle that
 * retires such a product without destroying any of it. These tests pin the
 * refusals to the same references the database constraints protect, so a new
 * reviewer cannot "simplify" the service into a delete that orphans ledger or
 * user data.
 */

it('permanently deletes a product nothing references', function (): void {
    $product = $this->products->create(productAttributes(['category_id' => $this->category->id]));
    $productId = $product->id;

    $this->products->delete($product);

    expect(Product::whereKey($productId)->doesntExist())->toBeTrue();
});

it('refuses to delete a product an auction references', function (): void {
    $product = $this->products->create(productAttributes(['category_id' => $this->category->id]));
    $auction = Auction::factory()->forProduct($product)->create();

    expect(fn () => $this->products->delete($product))
        ->toThrow(InvalidProductDelete::class, 'Archive it instead.');

    expect(Product::whereKey($product->id)->exists())->toBeTrue()
        ->and(Auction::whereKey($auction->id)->exists())->toBeTrue();
});

it('refuses to delete a product an order line references', function (): void {
    // Through the real checkout: an order line is written at checkout and is
    // append-only (a database trigger refuses edits), so the reference this
    // test pins is exactly the one production produces.
    $product = stockedProduct();
    $order = buyNowCheckout(bidder(0), $product);

    expect(fn () => $this->products->delete($product))
        ->toThrow(InvalidProductDelete::class);

    expect(Product::whereKey($product->id)->exists())->toBeTrue()
        ->and(Order::whereKey($order->id)->exists())->toBeTrue();
});

it('refuses to delete a product sitting in a customer cart', function (): void {
    $product = $this->products->create(productAttributes(['category_id' => $this->category->id]));
    Cart::create(['user_id' => User::factory()->create()->id])
        ->items()
        ->create(['product_id' => $product->id, 'quantity' => 1]);

    expect(fn () => $this->products->delete($product))
        ->toThrow(InvalidProductDelete::class);

    expect(Product::whereKey($product->id)->exists())->toBeTrue();
});

it('refuses to delete a product with inventory history', function (): void {
    $product = $this->products->create(productAttributes(['category_id' => $this->category->id]));
    InventoryTransaction::factory()->create(['product_id' => $product->id]);

    expect(fn () => $this->products->delete($product))
        ->toThrow(InvalidProductDelete::class);

    expect(Product::whereKey($product->id)->exists())->toBeTrue();
});

it('removes a product with its gallery files and rows', function (): void {
    Storage::fake('public');

    $product = $this->products->create(productAttributes(['category_id' => $this->category->id]));
    $images = ProductImage::factory()->count(2)->create(['product_id' => $product->id]);

    foreach ($images as $image) {
        Storage::disk('public')->put($image->image_path, 'x');
    }

    $this->products->delete($product);

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(ProductImage::where('product_id', $product->id)->doesntExist())->toBeTrue()
        ->and(Product::whereKey($product->id)->doesntExist())->toBeTrue();
});

it('records a product deletion in the audit log', function (): void {
    $product = $this->products->create(productAttributes(['category_id' => $this->category->id]));
    $productId = $product->id;

    $this->products->delete($product);

    $entry = Activity::query()
        ->where('log_name', 'product')
        ->where('subject_id', $productId)
        ->where('description', 'like', '%deleted')
        ->first();

    expect($entry)->not->toBeNull();
});
