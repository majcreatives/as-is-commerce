<?php

declare(strict_types=1);

use App\Domain\Catalog\Services\ProductMediaService;
use App\Livewire\Admin\Catalog\ProductManager;
use App\Livewire\Admin\Catalog\ProductMediaManager;
use App\Livewire\Catalog\ProductDetail;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function (): void {
    Storage::fake('public');

    $this->admin = userWithRole('admin');
    $this->customer = userWithRole('customer');
    $this->product = Product::factory()->active()->withStock(2)->create();
});

/** @return array<int, UploadedFile> */
function galleryUploads(int $count): array
{
    return collect(range(1, $count))
        ->map(fn (int $i): UploadedFile => UploadedFile::fake()->image("photo-{$i}.png", 400, 400))
        ->all();
}

// ------------------------------------------------------------ Authorization

it('lets an admin open the media screen', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $this->product])
        ->assertOk();
});

it('forbids a customer from the media screen', function (): void {
    Livewire::actingAs($this->customer)
        ->test(ProductMediaManager::class, ['product' => $this->product])
        ->assertForbidden();
});

it('forbids a staff member without products.update', function (): void {
    Livewire::actingAs(staffWith(['products.view']))
        ->test(ProductMediaManager::class, ['product' => $this->product])
        ->assertForbidden();
});

it('forbids a guest from the media screen', function (): void {
    $this->get(route('admin.products.media', $this->product))->assertRedirect(route('login'));
});

it('refuses to open the media screen for an archived product', function (): void {
    $archived = Product::factory()->archived()->create();

    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $archived])
        ->assertForbidden();
});

// --------------------------------------------------------------- Uploading

it('uploads several images into the gallery', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $this->product->fresh()])
        ->set('upload', galleryUploads(2))
        ->call('uploadImages')
        ->assertHasNoErrors();

    $images = ProductImage::where('product_id', $this->product->id)->orderBy('position')->get();

    expect($images)->toHaveCount(2)
        // The first upload is the featured image; positions read 0, 1.
        ->and($images[0]->position)->toBe(0)
        ->and($images[1]->position)->toBe(1);

    foreach ($images as $image) {
        expect($image->image_path)->toStartWith("products/{$this->product->id}/")
            ->and(Storage::disk('public')->exists($image->image_path))->toBeTrue();
    }
});

it('rejects a non-image upload', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $this->product])
        ->set('upload', [UploadedFile::fake()->create('notes.txt', 20)])
        ->call('uploadImages')
        ->assertHasErrors('upload.0');

    expect(ProductImage::count())->toBe(0);
});

it('refuses more than the gallery limit in one batch', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $this->product])
        ->set('upload', galleryUploads(ProductMediaService::MAX_IMAGES + 1))
        ->call('uploadImages')
        ->assertHasErrors('upload');

    expect(ProductImage::count())->toBe(0);
});

it('refuses to exceed the gallery limit across uploads', function (): void {
    ProductImage::factory()->count(ProductMediaService::MAX_IMAGES)->create([
        'product_id' => $this->product->id,
    ]);

    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $this->product->fresh()])
        ->set('upload', galleryUploads(1))
        ->call('uploadImages')
        ->assertHasErrors('upload');

    expect(ProductImage::count())->toBe(ProductMediaService::MAX_IMAGES);
});

// ------------------------------------------------------------- Management

it('removes an image and its file', function (): void {
    $image = ProductImage::factory()->create([
        'product_id' => $this->product->id,
        'image_path' => "products/{$this->product->id}/remove-me.jpg",
        'position' => 0,
    ]);

    Storage::disk('public')->put($image->image_path, 'bytes');

    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $this->product->fresh()])
        ->call('remove', $image->id);

    expect(ProductImage::count())->toBe(0)
        ->and(Storage::disk('public')->exists($image->image_path))->toBeFalse();
});

it('cannot remove an image that belongs to another product', function (): void {
    $other = Product::factory()->create();
    $image = ProductImage::factory()->create([
        'product_id' => $other->id,
        'image_path' => "products/{$other->id}/foreign.jpg",
        'position' => 0,
    ]);

    expect(fn () => app(ProductMediaService::class)->remove($this->product, $image->id))
        ->toThrow(ModelNotFoundException::class);

    expect(ProductImage::count())->toBe(1);
});

it('makes an image featured by moving it to the front', function (): void {
    $images = ProductImage::factory()->count(3)->create(['product_id' => $this->product->id]);

    $target = $images->get(2);

    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $this->product->fresh()])
        ->call('setFeatured', $target->id);

    expect(ProductImage::whereKey($target->id)->value('position'))->toBe(0)
        ->and(ProductImage::whereKey($images[0]->id)->value('position'))->toBe(1)
        ->and(ProductImage::whereKey($images[1]->id)->value('position'))->toBe(2);
});

it('moves an image earlier in the gallery', function (): void {
    $a = ProductImage::factory()->create(['product_id' => $this->product->id, 'position' => 0]);
    $b = ProductImage::factory()->create(['product_id' => $this->product->id, 'position' => 1]);

    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $this->product->fresh()])
        ->call('moveUp', $b->id)
        ->call('moveDown', $a->id);

    expect(ProductImage::whereKey($b->id)->value('position'))->toBe(0)
        ->and(ProductImage::whereKey($a->id)->value('position'))->toBe(1);
});

it('ignores a move that would leave the gallery bounds', function (): void {
    $a = ProductImage::factory()->create(['product_id' => $this->product->id, 'position' => 0]);
    $b = ProductImage::factory()->create(['product_id' => $this->product->id, 'position' => 1]);

    // The featured image cannot move up already being first.
    Livewire::actingAs($this->admin)
        ->test(ProductMediaManager::class, ['product' => $this->product->fresh()])
        ->call('moveUp', $a->id)
        ->call('moveDown', $b->id);

    expect(ProductImage::whereKey($a->id)->value('position'))->toBe(0)
        ->and(ProductImage::whereKey($b->id)->value('position'))->toBe(1);
});

// --------------------------------------------------------- Public rendering

it('shows the gallery on the public product page', function (): void {
    ProductImage::factory()->create([
        'product_id' => $this->product->id,
        'image_path' => "products/{$this->product->id}/front.jpg",
        'position' => 0,
    ]);
    ProductImage::factory()->create([
        'product_id' => $this->product->id,
        'image_path' => "products/{$this->product->id}/side.jpg",
        'position' => 1,
    ]);

    $this->get(route('products.show', $this->product))
        ->assertOk()
        ->assertSee("/storage/products/{$this->product->id}/front.jpg")
        ->assertSee("/storage/products/{$this->product->id}/side.jpg");
});

it('falls back to the product image_path before a gallery exists', function (): void {
    $product = Product::factory()->active()->withStock(2)->create([
        'image_path' => 'https://cdn.example.com/legacy.jpg',
    ]);

    $this->get(route('products.show', $product))
        ->assertOk()
        ->assertSee('https://cdn.example.com/legacy.jpg');
});

it('uses the featured gallery image on catalog cards', function (): void {
    ProductImage::factory()->create([
        'product_id' => $this->product->id,
        'image_path' => "products/{$this->product->id}/front.jpg",
        'position' => 0,
    ]);

    $this->get(route('products.index'))
        ->assertOk()
        ->assertSee("/storage/products/{$this->product->id}/front.jpg");
});

it('opens product media from the product manager', function (): void {
    Livewire::actingAs($this->admin)
        ->test(ProductManager::class)
        ->assertSeeHtml(route('admin.products.media', $this->product));
});

// -------------------------------------------------------------- Lazy loads

it('does not trigger a query per card for product images', function (): void {
    ProductImage::factory()->count(3)->create(['product_id' => $this->product->id]);

    $this->withoutExceptionHandling()
        ->withMiddleware(['web'])
        ->get(route('products.index'))
        ->assertOk();
});

it('renders the featured gallery image in the product component', function (): void {
    ProductImage::factory()->create([
        'product_id' => $this->product->id,
        'image_path' => "products/{$this->product->id}/front.jpg",
        'position' => 0,
    ]);

    Livewire::actingAs($this->customer)
        ->test(ProductDetail::class, ['slug' => $this->product->slug])
        ->assertOk()
        ->assertSee("/storage/products/{$this->product->id}/front.jpg");
});
