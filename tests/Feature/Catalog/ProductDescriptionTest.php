<?php

declare(strict_types=1);

use App\Livewire\Admin\Catalog\ProductManager;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Livewire\Livewire;

/*
 * The product description through the admin form.
 *
 * A description is rich text and it is rendered with `{!! !!}` on the detail
 * page, so the one path into the column is the same whitelist the blog editor
 * uses: whatever the browser sends, only the allowed markup survives.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
    $this->category = Category::factory()->create();
    $this->brand = Brand::factory()->create();
});

it('stores a product description through the rich-text whitelist', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('create')
        ->set('name', 'Described Product')
        ->set('category_id', $this->category->id)
        ->set('brand_id', $this->brand->id)
        ->set('price', '100.00')
        ->set('description', '<p>Trusted <strong>copy</strong>.</p><script>bad()</script>')
        ->call('save')
        ->assertHasNoErrors();

    $product = Product::firstWhere('name', 'Described Product');

    expect($product)->not->toBeNull()
        // The script tag never reaches the column.
        ->and($product->description)->not->toContain('script')
        ->and($product->description)->toContain('<strong>copy</strong>');
});

it('keeps an existing description when none is typed', function (): void {
    $product = Product::factory()->create([
        'name' => 'Keeps Description',
        'description' => '<p>Already written.</p>',
    ]);

    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('edit', $product->id)
        ->set('name', 'Renamed Product')
        ->call('save')
        ->assertHasNoErrors();

    expect($product->fresh()->description)->toBe('<p>Already written.</p>');
});
