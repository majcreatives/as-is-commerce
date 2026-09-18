<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Catalog;

use App\Domain\Catalog\Services\ProductMediaService;
use App\Models\Product;
use App\Models\ProductImage;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * A product's image gallery.
 *
 * The only screen that manages a product's pictures. Uploads, reordering,
 * the featured image and removal all happen here, through
 * {@see ProductMediaService}, which owns the disk and the rows -- so a
 * reorder or removal can never be a second implementation of the gallery's
 * rules.
 *
 * ARCHIVED PRODUCTS ARE FROZEN, exactly as their catalog fields are: once a
 * listing is the record of what was sold under it, its images stop changing.
 * Holding `products.view` shows the gallery on nothing; reaching this screen
 * is itself `products.update`.
 */
#[Layout('components.layouts.app')]
#[Title('Product images')]
class ProductMediaManager extends Component
{
    use WithFileUploads;

    public Product $product;

    /**
     * The files selected in the upload control.
     *
     * @var array<int, TemporaryUploadedFile>
     */
    public $upload = [];

    public function mount(Product $product): void
    {
        $this->authorize('products.update');

        abort_unless($product->status->isEditable(), 403, 'An archived product cannot be edited.');

        $this->product = $product->load('images');
    }

    /**
     * @return Collection<int, ProductImage>
     */
    public function images(): Collection
    {
        return $this->product->images;
    }

    public function uploadImages(ProductMediaService $media): void
    {
        $this->authorize('products.update');

        $validated = $this->validate([
            'upload' => ['required', 'array', 'min:1', 'max:'.ProductMediaService::MAX_IMAGES],
            'upload.*' => ['required', 'file', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp,gif'],
        ], [], ['upload.*' => 'image']);

        $attached = 0;

        foreach ($validated['upload'] as $file) {
            try {
                $media->attach($this->product, $file, auth()->user());
                $attached++;
            } catch (DomainException $e) {
                // The service's refusal, worded for an administrator.
                $this->addError('upload', $e->getMessage());
            }
        }

        $this->refreshGallery();

        $this->upload = [];

        session()->flash('status', $attached === 0
            ? 'No images were added.'
            : "{$attached} image".($attached === 1 ? '' : 's').' added.');
    }

    public function setFeatured(int $imageId, ProductMediaService $media): void
    {
        $this->authorize('products.update');

        try {
            $media->setFeatured($this->product, $imageId, auth()->user());
        } catch (DomainException $e) {
            $this->addError('gallery', $e->getMessage());

            return;
        }

        $this->refreshGallery();
        session()->flash('status', 'Featured image updated.');
    }

    public function moveUp(int $imageId, ProductMediaService $media): void
    {
        $this->move($imageId, -1, $media);
    }

    public function moveDown(int $imageId, ProductMediaService $media): void
    {
        $this->move($imageId, 1, $media);
    }

    public function remove(int $imageId, ProductMediaService $media): void
    {
        $this->authorize('products.update');

        try {
            $media->remove($this->product, $imageId, auth()->user());
        } catch (DomainException $e) {
            $this->addError('gallery', $e->getMessage());

            return;
        }

        $this->refreshGallery();
        session()->flash('status', 'Image removed.');
    }

    public function render(): View
    {
        return view('livewire.admin.catalog.product-media-manager', [
            'images' => $this->images(),
        ]);
    }

    /**
     * Move an image one step earlier or later in the gallery.
     */
    private function move(int $imageId, int $direction, ProductMediaService $media): void
    {
        $this->authorize('products.update');

        $ordered = $this->product->images->pluck('id')->all();
        $index = array_search($imageId, $ordered, true);

        if ($index === false) {
            return;
        }

        $target = $index + $direction;

        if ($target < 0 || $target >= count($ordered)) {
            return;
        }

        $ordered[$index] = $ordered[$target];
        $ordered[$target] = $imageId;

        try {
            $media->reorder($this->product, $ordered, auth()->user());
        } catch (DomainException $e) {
            $this->addError('gallery', $e->getMessage());

            return;
        }

        $this->refreshGallery();
        session()->flash('status', 'Image order updated.');
    }

    private function refreshGallery(): void
    {
        $this->product->unsetRelation('images');
        $this->product->load('images');
    }
}
