<?php

declare(strict_types=1);

use App\Livewire\Admin\Catalog\ProductManager;
use App\Models\Auction;
use App\Models\Cart;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/*
 * Product deletion through the admin screen.
 *
 * The component is a thin control surface over ProductService::delete(): the
 * refusal logic and the guarantees -- an auction, order or stock history or a
 * customer's cart means `archive`, not `delete` -- live in the service and are
 * tested there. What this file pins is the surface around it: the action is
 * gated on `products.delete` on its own, a successful delete is permanent, and
 * the readable answers reach the administrator without a crash.
 */

beforeEach(function (): void {
    seedPermissions();
    seedSettings();

    $this->admin = userWithRole('admin');
    $this->category = Category::factory()->create();
    $this->product = Product::factory()->draft()->create([
        'name' => 'Deletable Product',
        'category_id' => $this->category->id,
    ]);
});

it('deletes a product with its gallery files and rows through the screen', function (): void {
    Storage::fake('public');

    $images = ProductImage::factory()->count(2)->create(['product_id' => $this->product->id]);

    foreach ($images as $image) {
        Storage::disk('public')->put($image->image_path, 'x');
    }

    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('delete', $this->product->id)
        ->assertHasNoErrors();

    expect(Product::whereKey($this->product->id)->doesntExist())->toBeTrue()
        ->and(ProductImage::where('product_id', $this->product->id)->doesntExist())->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('refuses a product an auction references, with a readable answer', function (): void {
    Auction::factory()->forProduct($this->product)->create();

    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('delete', $this->product->id)
        ->assertHasErrors('lifecycle');

    expect(Product::whereKey($this->product->id)->exists())->toBeTrue();
});

it('refuses a product sitting in a customer cart', function (): void {
    Cart::create(['user_id' => User::factory()->create()->id])
        ->items()
        ->create(['product_id' => $this->product->id, 'quantity' => 1]);

    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('delete', $this->product->id)
        ->assertHasErrors('lifecycle');

    expect(Product::whereKey($this->product->id)->exists())->toBeTrue();
});

it('refuses a product an order line references', function (): void {
    // Order lines are written once at checkout and are append-only.
    $product = stockedProduct();
    buyNowCheckout(bidder(0), $product);

    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->call('delete', $product->id)
        ->assertHasErrors('lifecycle');

    expect(Product::whereKey($product->id)->exists())->toBeTrue();
});

it('refuses a product delete without the products.delete permission', function (): void {
    Role::findByName('admin')->revokePermissionTo('products.delete');

    Livewire::actingAs($this->admin->fresh())
        ->test(ProductManager::class)
        ->call('delete', $this->product->id)
        ->assertForbidden();

    expect(Product::whereKey($this->product->id)->exists())->toBeTrue();
});
