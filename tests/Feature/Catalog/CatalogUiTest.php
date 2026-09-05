<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\InventoryService;
use App\Enums\InventoryTransactionType;
use App\Enums\ProductCondition;
use App\Enums\ProductStatus;
use App\Livewire\Admin\Catalog\InventoryManager;
use App\Livewire\Admin\Catalog\ProductManager;
use App\Livewire\Admin\Catalog\TaxonomyManager;
use App\Livewire\Catalog\ProductCatalog;
use App\Livewire\Catalog\ProductDetail;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Database\Seeders\PermissionSeeder;
use Livewire\Livewire;

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
    $this->customer = userWithRole('customer');
    $this->category = Category::factory()->create(['name' => 'Phones', 'slug' => 'phones']);
    $this->brand = Brand::factory()->create(['name' => 'Northline', 'slug' => 'northline']);
});

// ------------------------------------------------------------ Public access

it('lets anyone browse the catalog without signing in', function (): void {
    $this->get('/products')->assertOk();
});

it('shows an active product in the catalog', function (): void {
    Product::factory()->active()->withStock(3)->create(['name' => 'Northline N7']);

    $this->get('/products')->assertOk()->assertSee('Northline N7');
});

/*
 * The important half: a product that is not meant to be public must not
 * appear, however it is reached.
 */
it('hides products that are not publicly visible', function (ProductStatus $status): void {
    Product::factory()->status($status)->create(['name' => 'Hidden Product']);

    $this->get('/products')->assertOk()->assertDontSee('Hidden Product');
})->with([
    'draft' => ProductStatus::Draft,
    'inactive' => ProductStatus::Inactive,
    'archived' => ProductStatus::Archived,
]);

it('404s a product detail page that is not publicly visible', function (ProductStatus $status): void {
    $product = Product::factory()->status($status)->create();

    $this->get("/products/{$product->slug}")->assertNotFound();
})->with([
    'draft' => ProductStatus::Draft,
    'inactive' => ProductStatus::Inactive,
    'archived' => ProductStatus::Archived,
]);

it('shows a product detail page', function (): void {
    $product = Product::factory()->active()->withStock(4)->create([
        'name' => 'Northline N7 Smartphone',
        'sku' => 'PHN-NL-0001',
        'category_id' => $this->category->id,
        'brand_id' => $this->brand->id,
        'buy_now_price_minor' => 550_000,
    ]);

    $this->get("/products/{$product->slug}")
        ->assertOk()
        ->assertSee('Northline N7 Smartphone')
        ->assertSee('PHN-NL-0001')
        ->assertSee('Northline')
        ->assertSee('Phones')
        ->assertSee('New')
        // The product's own price, in GHS.
        ->assertSee('5,500.00')
        ->assertSee('4 available');
});

it('shows an out-of-stock product without claiming it is available', function (): void {
    $product = Product::factory()->status(ProductStatus::OutOfStock)->create(['name' => 'Sold Out Item']);

    $this->get("/products/{$product->slug}")
        ->assertOk()
        ->assertSee('Sold Out Item')
        // One phrase for this state across the whole marketplace, so a card
        // and a product page never disagree about the same product.
        ->assertSee('Currently unavailable')
        // And no purchase control anywhere on it.
        ->assertDontSee('Buy now')
        ->assertDontSee('Sign in to buy');
});

/*
 * Nothing on a product page may invent an auction, a bid or a discount. None
 * of those exist yet, and a nonfunctional control is worse than none.
 */
it('shows no auction, bid or credit-discount figures', function (): void {
    $product = Product::factory()->active()->withStock(2)->create();

    $this->get("/products/{$product->slug}")
        ->assertOk()
        ->assertDontSee('Highest Bid')
        ->assertDontSee('Auction price')
        ->assertDontSee('credits off')
        ->assertDontSee('Place bid');
});

/*
 * Checkout has existed since the orders stage; this page finally offers it.
 * A guest is sent to sign in rather than to a control that would fail.
 */
it('offers a buy now control on a purchasable product', function (): void {
    $product = Product::factory()->active()->withStock(2)->create();

    $this->get("/products/{$product->slug}")
        ->assertOk()
        ->assertSee('Sign in to buy')
        ->assertDontSee('Add to cart');

    Livewire::actingAs(userWithRole('customer'))
        ->test(ProductDetail::class, ['slug' => $product->slug])
        ->assertSee('Buy now');
});

/*
 * And none at all on something that cannot be bought. A button that always
 * fails is worse than no button.
 */
it('offers no purchase control on an unavailable product', function (): void {
    $product = Product::factory()->status(ProductStatus::OutOfStock)->create();

    Livewire::actingAs(userWithRole('customer'))
        ->test(ProductDetail::class, ['slug' => $product->slug])
        ->assertSee('not available to buy right now')
        ->assertDontSee('Sign in to buy');
});

// --------------------------------------------------------------- Filtering

it('filters by category', function (): void {
    $other = Category::factory()->create(['slug' => 'laptops']);

    Product::factory()->active()->create(['name' => 'A Phone', 'category_id' => $this->category->id]);
    Product::factory()->active()->create(['name' => 'A Laptop', 'category_id' => $other->id]);

    Livewire::test(ProductCatalog::class)
        ->set('category', 'phones')
        ->assertSee('A Phone')
        ->assertDontSee('A Laptop');
});

it('filters by brand', function (): void {
    $other = Brand::factory()->create(['slug' => 'volta']);

    Product::factory()->active()->create(['name' => 'Northline Item', 'brand_id' => $this->brand->id]);
    Product::factory()->active()->create(['name' => 'Volta Item', 'brand_id' => $other->id]);

    Livewire::test(ProductCatalog::class)
        ->set('brand', 'northline')
        ->assertSee('Northline Item')
        ->assertDontSee('Volta Item');
});

it('filters by condition', function (): void {
    Product::factory()->active()->condition(ProductCondition::New)->create(['name' => 'Brand New Item']);
    Product::factory()->active()->condition(ProductCondition::Used)->create(['name' => 'Second Hand Item']);

    Livewire::test(ProductCatalog::class)
        ->set('condition', 'used')
        ->assertSee('Second Hand Item')
        ->assertDontSee('Brand New Item');
});

it('filters by availability', function (): void {
    Product::factory()->active()->withStock(5)->create(['name' => 'Available Item']);
    Product::factory()->status(ProductStatus::OutOfStock)->create(['name' => 'Unavailable Item']);

    // "Available" means obtainable, which also covers a product whose unit a
    // live auction is holding -- available stock alone would hide those.
    Livewire::test(ProductCatalog::class)
        ->set('availableOnly', true)
        ->assertSee('Available Item')
        ->assertDontSee('Unavailable Item');
});

it('searches by name and SKU', function (): void {
    Product::factory()->active()->create(['name' => 'Findable Widget', 'sku' => 'FIND-001']);
    Product::factory()->active()->create(['name' => 'Other Thing', 'sku' => 'OTHR-002']);

    Livewire::test(ProductCatalog::class)
        ->set('search', 'Findable')
        ->assertSee('Findable Widget')
        ->assertDontSee('Other Thing');

    Livewire::test(ProductCatalog::class)
        ->set('search', 'OTHR-002')
        ->assertSee('Other Thing')
        ->assertDontSee('Findable Widget');
});

it('never surfaces a hidden product through a filter', function (): void {
    Product::factory()->draft()->create(['name' => 'Draft Phone', 'category_id' => $this->category->id]);

    Livewire::test(ProductCatalog::class)
        ->set('category', 'phones')
        ->assertDontSee('Draft Phone');
});

it('sorts by price', function (): void {
    Product::factory()->active()->pricedAt(100_000)->create(['name' => 'Cheaper Item']);
    Product::factory()->active()->pricedAt(900_000)->create(['name' => 'Dearer Item']);

    // Asserted on the rendered output rather than a component property: the
    // products are produced by a method, and what matters is the order a
    // customer actually sees.
    Livewire::test(ProductCatalog::class)
        ->set('sort', 'price_asc')
        ->assertSeeInOrder(['Cheaper Item', 'Dearer Item']);

    Livewire::test(ProductCatalog::class)
        ->set('sort', 'price_desc')
        ->assertSeeInOrder(['Dearer Item', 'Cheaper Item']);
});

it('clears filters', function (): void {
    Livewire::test(ProductCatalog::class)
        ->set('search', 'something')
        ->set('condition', 'used')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('condition', '');
});

// ----------------------------------------------------------- Authorization

it('grants a customer none of the catalog permissions', function (string $permission): void {
    expect(userWithRole('customer')->can($permission))->toBeFalse();
})->with(array_values(array_filter(
    PermissionSeeder::PERMISSIONS,
    fn (string $p): bool => str_starts_with($p, 'products.')
        || str_starts_with($p, 'categories.')
        || str_starts_with($p, 'brands.')
        || str_starts_with($p, 'inventory.'),
)));

it('forbids a customer from the catalog admin routes', function (string $path): void {
    $this->actingAs($this->customer)->get($path)->assertForbidden();
})->with(['/admin/products', '/admin/taxonomy', '/admin/inventory']);

it('redirects a guest from the catalog admin routes', function (string $path): void {
    $this->get($path)->assertRedirect(route('login'));
})->with(['/admin/products', '/admin/taxonomy', '/admin/inventory']);

it('allows an admin into the catalog admin routes', function (string $path): void {
    $this->actingAs($this->admin)->get($path)->assertOk();
})->with(['/admin/products', '/admin/taxonomy', '/admin/inventory']);

it('forbids a customer from the admin components', function (string $component): void {
    Livewire::actingAs($this->customer)->test($component)->assertForbidden();
})->with([ProductManager::class, TaxonomyManager::class, InventoryManager::class]);

// ---------------------------------------------------------- Admin products

it('creates a product from the admin screen as a draft', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('create')
        ->set('name', 'Admin Created Phone')
        ->set('category_id', $this->category->id)
        ->set('brand_id', $this->brand->id)
        ->set('condition', 'refurbished')
        ->set('price', '1850.00')
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::firstWhere('name', 'Admin Created Phone');

    expect($product)->not->toBeNull()
        ->and($product->status)->toBe(ProductStatus::Draft)
        ->and($product->condition)->toBe(ProductCondition::Refurbished)
        // Entered as "1850.00", stored as whole pesewas.
        ->and($product->buy_now_price_minor)->toBe(185_000);
});

it('rejects a price containing a currency symbol', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('create')
        ->set('name', 'Bad Price')
        ->set('category_id', $this->category->id)
        ->set('price', 'GHS 5500')
        ->call('save')
        ->assertHasErrors('price');

    expect(Product::count())->toBe(0);
});

it('rejects a duplicate SKU from the admin screen', function (): void {
    Product::factory()->create(['sku' => 'TAKEN-001']);

    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('create')
        ->set('name', 'Duplicate')
        ->set('sku', 'TAKEN-001')
        ->set('category_id', $this->category->id)
        ->set('price', '100.00')
        ->call('save')
        ->assertHasErrors('sku');
});

it('activates a product from the admin screen', function (): void {
    $product = Product::factory()->draft()->withStock(3)->create();

    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('changeStatus', $product->id, 'active');

    expect($product->fresh()->status)->toBe(ProductStatus::Active);
});

it('reports a refused transition rather than failing silently', function (): void {
    $product = Product::factory()->archived()->create();

    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('changeStatus', $product->id, 'active')
        ->assertHasErrors('lifecycle');

    expect($product->fresh()->status)->toBe(ProductStatus::Archived);
});

it('refuses to open the editor for an archived product', function (): void {
    $product = Product::factory()->archived()->create();

    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('edit', $product->id)
        ->assertForbidden();
});

// -------------------------------------------------------- Admin inventory

it('records a stock movement from the admin screen', function (): void {
    $product = Product::factory()->active()->create();

    Livewire::actingAs($this->admin)
        ->test(InventoryManager::class)
        ->call('startAdjustment', $product->id)
        ->set('movementType', 'restock')
        ->set('quantity', 12)
        ->set('reason', 'Delivery received from supplier.')
        ->call('postMovement')
        ->assertHasNoErrors();

    expect($product->fresh()->stock_on_hand)->toBe(12);
});

it('requires a reason for a stock movement', function (): void {
    $product = Product::factory()->active()->create();

    Livewire::actingAs($this->admin)
        ->test(InventoryManager::class)
        ->call('startAdjustment', $product->id)
        ->set('movementType', 'restock')
        ->set('quantity', 5)
        ->set('reason', '')
        ->call('postMovement')
        ->assertHasErrors('reason');

    expect($product->fresh()->stock_on_hand)->toBe(0);
});

it('reports a refused movement rather than failing silently', function (): void {
    $product = Product::factory()->active()->create();
    app(InventoryService::class)->initialStock($product, 2);

    Livewire::actingAs($this->admin)
        ->test(InventoryManager::class)
        ->call('startAdjustment', $product->id)
        ->set('movementType', 'manual_adjustment')
        ->set('quantity', -50)
        ->set('reason', 'More than we hold.')
        ->call('postMovement')
        ->assertHasErrors('quantity');

    expect($product->fresh()->stock_on_hand)->toBe(2);
});

/*
 * Sales, reservations and releases are consequences of a customer action.
 * Letting staff post one by hand would put stock out of step with the orders
 * it is meant to reflect.
 */
it('offers only the movement types staff may post by hand', function (): void {
    $product = Product::factory()->active()->create();

    Livewire::actingAs($this->admin)
        ->test(InventoryManager::class)
        ->call('startAdjustment', $product->id)
        ->assertSee('Restock')
        ->assertSee('Manual adjustment')
        ->assertDontSee('<option value="sale"', escape: false)
        ->assertDontSee('<option value="reservation"', escape: false)
        ->assertDontSee('<option value="release"', escape: false);

    expect(InventoryTransactionType::Sale->isManuallyPostable())->toBeFalse()
        ->and(InventoryTransactionType::Reservation->isManuallyPostable())->toBeFalse()
        ->and(InventoryTransactionType::Release->isManuallyPostable())->toBeFalse()
        ->and(InventoryTransactionType::Restock->isManuallyPostable())->toBeTrue();
});

// --------------------------------------------------------- Admin taxonomy

it('creates a category', function (): void {
    Livewire::actingAs($this->admin)
        ->test(TaxonomyManager::class)
        ->call('create')
        ->set('name', 'Gaming Laptops')
        ->set('sort_order', 5)
        ->call('save')
        ->assertHasNoErrors();

    expect(Category::firstWhere('slug', 'gaming-laptops'))->not->toBeNull();
});

it('creates a nested category', function (): void {
    Livewire::actingAs($this->admin)
        ->test(TaxonomyManager::class)
        ->call('create')
        ->set('name', 'Tablets')
        ->set('parent_id', $this->category->id)
        ->call('save')
        ->assertHasNoErrors();

    $child = Category::firstWhere('slug', 'tablets');

    expect($child->parent_id)->toBe($this->category->id)
        ->and($child->ancestry()->pluck('name')->all())->toBe(['Phones', 'Tablets']);
});

it('refuses to make a category its own parent', function (): void {
    Livewire::actingAs($this->admin)
        ->test(TaxonomyManager::class)
        ->call('edit', $this->category->id)
        ->set('parent_id', $this->category->id)
        ->call('save')
        ->assertHasErrors('parent_id');
});

it('refuses to place a category inside its own descendant', function (): void {
    $child = Category::factory()->childOf($this->category)->create();

    Livewire::actingAs($this->admin)
        ->test(TaxonomyManager::class)
        ->call('edit', $this->category->id)
        ->set('parent_id', $child->id)
        ->call('save')
        ->assertHasErrors('parent_id');
});

it('creates a brand', function (): void {
    Livewire::actingAs($this->admin)
        ->test(TaxonomyManager::class)
        ->call('switchTab', 'brands')
        ->call('create')
        ->set('name', 'Volta Devices')
        ->call('save')
        ->assertHasNoErrors();

    expect(Brand::firstWhere('slug', 'volta-devices'))->not->toBeNull();
});

it('rejects a duplicate brand name', function (): void {
    Livewire::actingAs($this->admin)
        ->test(TaxonomyManager::class)
        ->call('switchTab', 'brands')
        ->call('create')
        ->set('name', 'Northline')
        ->call('save')
        ->assertHasErrors('name');
});
