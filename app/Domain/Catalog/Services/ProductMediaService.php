<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\InvalidProductMedia;
use App\Domain\Catalog\Exceptions\InvalidProductTransition;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Growing and ordering a product's image gallery.
 *
 * THE FIRST IMAGE IS THE FEATURED ONE. Cards and auctions show whichever image
 * holds position zero, so "set as featured" is a reorder, not a flag.
 * Positions are kept contiguous by the service after every change, and
 * ordering always reads position then id, so the front image stays
 * deterministic even mid-transaction.
 *
 * STORAGE. Files live on the public disk under products/{product_id}/ with a
 * uuid name and a whitelisted image extension. The path is stored relative to
 * the disk; {@see ProductImage::url()} turns it into the /storage/... URL a
 * page can render.
 *
 * ARCHIVED PRODUCTS ARE FROZEN. A gallery works on a listing that can still
 * change. An archived product's gallery is its record of what was sold, and
 * refuses to change just as the product's own fields do.
 */
class ProductMediaService
{
    /** How many images a product can hold. */
    public const MAX_IMAGES = 8;

    /** @var list<string> */
    public const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Store an uploaded image and add it to the gallery.
     */
    public function attach(Product $product, UploadedFile $file, ?User $actor = null): ProductImage
    {
        $this->guardEditable($product);

        return DB::transaction(function () use ($product, $file, $actor): ProductImage {
            if ($product->images()->count() >= self::MAX_IMAGES) {
                throw InvalidProductMedia::limitReached(self::MAX_IMAGES);
            }

            $path = $file->storeAs(
                "products/{$product->id}",
                Str::uuid()->toString().'.'.$this->allowedExtension($file),
                'public',
            );

            if ($path === false) {
                throw InvalidProductMedia::storeFailed();
            }

            $image = $product->images()->create([
                'image_path' => $path,
                // The gallery is kept contiguous (positions 0, 1, 2, ...), so
                // the next position is simply the current count.
                'position' => $product->images()->count(),
                'created_by' => $actor?->id,
            ]);

            activity('product')
                ->performedOn($product)
                ->causedBy($actor)
                ->withProperties(['image_id' => $image->id, 'path' => $path])
                ->log('image_attached');

            return $image->fresh();
        });
    }

    /**
     * Delete an image and its file, keeping the remaining gallery contiguous.
     */
    public function remove(Product $product, int $imageId, ?User $actor = null): void
    {
        $this->guardEditable($product);

        DB::transaction(function () use ($product, $imageId, $actor): void {
            $image = $product->images()->findOrFail($imageId);
            $path = $image->image_path;

            $image->delete();

            // Only ever delete something this service could have written. A
            // hand-edited image_path must not turn "remove this thumbnail"
            // into "delete some unrelated public file".
            if (Str::startsWith($path, 'products/')) {
                Storage::disk('public')->delete($path);
            }

            $this->renumber($product);

            activity('product')
                ->performedOn($product)
                ->causedBy($actor)
                ->withProperties(['image_id' => $imageId, 'path' => $path])
                ->log('image_removed');
        });
    }

    /**
     * Make an image the featured one by moving it to the front.
     */
    public function setFeatured(Product $product, int $imageId, ?User $actor = null): void
    {
        $this->guardEditable($product);

        $owned = $product->images()->orderBy('position')->orderBy('id')->pluck('id')->all();

        if (! in_array($imageId, $owned, true)) {
            throw InvalidProductMedia::invalidOrder();
        }

        $this->reorder($product, [$imageId, ...array_values(array_diff($owned, [$imageId]))], $actor);
    }

    /**
     * Rewrite the gallery to a given order of image ids.
     *
     * The first id becomes the featured image. The list must be exactly the
     * product's own images; anything else is refused rather than partially
     * applied, so a crafted payload cannot reorder another product's gallery.
     *
     * @param  list<int>  $orderedIds
     */
    public function reorder(Product $product, array $orderedIds, ?User $actor = null): void
    {
        $this->guardEditable($product);

        DB::transaction(function () use ($product, $orderedIds, $actor): void {
            $owned = $product->images()->pluck('id')->all();

            if (count($orderedIds) !== count($owned)
                || array_diff($orderedIds, $owned) !== []
                || array_diff($owned, $orderedIds) !== []) {
                throw InvalidProductMedia::invalidOrder();
            }

            $this->applyPositions($product, $orderedIds);

            activity('product')
                ->performedOn($product)
                ->causedBy($actor)
                ->withProperties(['order' => $orderedIds])
                ->log('images_reordered');
        });
    }

    /**
     * @param  list<int>  $orderedIds
     */
    private function applyPositions(Product $product, array $orderedIds): void
    {
        foreach ($orderedIds as $index => $id) {
            ProductImage::query()
                ->whereKey($id)
                ->where('product_id', $product->id)
                ->update(['position' => $index]);
        }
    }

    /**
     * Renumber a gallery so positions read 0, 1, 2, ... with no gaps.
     */
    private function renumber(Product $product): void
    {
        $ordered = $product->images()->orderBy('position')->orderBy('id')->pluck('id')->all();

        $this->applyPositions($product, $ordered);
    }

    private function guardEditable(Product $product): void
    {
        if (! $product->status->isEditable()) {
            throw InvalidProductTransition::notEditable($product->status);
        }
    }

    /**
     * The extension to store under, verifying both name and content.
     *
     * The component already passes image validation; this is the defence that
     * survives a bypassed component. Storing only a whitelisted image
     * extension (and only content whose MIME agrees) is what stops a uploaded
     * disguise from ever being served as something executable.
     */
    private function allowedExtension(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)
            || ! in_array((string) $file->getMimeType(), self::ALLOWED_MIME_TYPES, true)) {
            throw InvalidProductMedia::unsupportedType();
        }

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }
}
