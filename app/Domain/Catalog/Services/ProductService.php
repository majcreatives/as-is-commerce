<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\InvalidProductTransition;
use App\Enums\ProductStatus;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creating products and moving them through their lifecycle.
 *
 * Status is not fillable and is changed only here, through a guarded
 * transition, so a crafted request cannot publish a draft or resurrect an
 * archived listing.
 *
 * Stock is not this service's business. It belongs to
 * {@see InventoryService}, which is the only thing permitted to write it.
 */
class ProductService
{
    /**
     * Create a product as a draft.
     *
     * Always a draft: a product should be reviewed before it appears in a
     * public catalog, and creating one live by saving a form is the kind of
     * accident worth designing out.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?User $actor = null): Product
    {
        return DB::transaction(function () use ($attributes, $actor): Product {
            $product = new Product;

            // Filtered rather than passed straight through: a payload carrying
            // `status` or a stock column is ignored, not honoured.
            $product->fill(Arr::only($attributes, $product->getFillable()));

            $product->sku = $this->resolveSku($attributes['sku'] ?? null, (string) $attributes['name']);
            $product->slug = $this->uniqueSlug($attributes['slug'] ?? $attributes['name']);
            $product->status = ProductStatus::Draft;

            // Set explicitly rather than left to the database default, so the
            // model handed back reports its real stock instead of null. A
            // caller reading zero and a caller reading null behave very
            // differently, and only one of them is correct.
            $product->stock_on_hand = 0;
            $product->stock_reserved = 0;
            $product->created_by = $actor?->id;
            $product->updated_by = $actor?->id;
            $product->save();

            return $product;
        });
    }

    /**
     * Update a product's descriptive and pricing fields.
     *
     * Archived products are frozen: their listing is the record of what was
     * sold under it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Product $product, array $attributes, ?User $actor = null): Product
    {
        if (! $product->status->isEditable()) {
            throw InvalidProductTransition::notEditable($product->status);
        }

        return DB::transaction(function () use ($product, $attributes, $actor): Product {
            $product->fill(Arr::only($attributes, $product->getFillable()));

            if (isset($attributes['slug']) && $attributes['slug'] !== $product->getOriginal('slug')) {
                $product->slug = $this->uniqueSlug((string) $attributes['slug'], $product->id);
            }

            $product->updated_by = $actor?->id;
            $product->save();

            return $product;
        });
    }

    /**
     * Move a product to a new status, if the lifecycle permits it.
     */
    public function transitionTo(Product $product, ProductStatus $target, ?User $actor = null): Product
    {
        if ($product->status === $target) {
            return $product;
        }

        if (! $product->status->canTransitionTo($target)) {
            throw InvalidProductTransition::between($product->status, $target);
        }

        return DB::transaction(function () use ($product, $target, $actor): Product {
            // Publishing something with nothing to sell would put a buy
            // button on an empty shelf, so it lands as out of stock instead.
            $resolved = $target === ProductStatus::Active && $product->availableStock() <= 0
                ? ProductStatus::OutOfStock
                : $target;

            $product->status = $resolved;

            if ($resolved !== ProductStatus::Draft && $product->published_at === null) {
                $product->published_at = now();
            }

            $product->updated_by = $actor?->id;
            $product->save();

            activity('product')
                ->performedOn($product)
                ->causedBy($actor)
                ->withProperties([
                    'sku' => $product->sku,
                    'status' => $resolved->value,
                    'requested' => $target->value,
                ])
                ->log('status_changed');

            return $product;
        });
    }

    /**
     * A URL-safe slug that no other product holds.
     *
     * Two products legitimately share a name -- the same phone in different
     * conditions -- so a collision is normal rather than an error, and is
     * resolved by suffixing. A name that slugs to nothing at all still yields
     * a usable slug, so a product URL cannot break on an unusual title.
     */
    public function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source);

        if ($base === '') {
            $base = 'product';
        }

        $base = Str::limit($base, 180, '');
        $slug = $base;
        $suffix = 2;

        while ($this->slugTaken($slug, $ignoreId)) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    private function slugTaken(string $slug, ?int $ignoreId): bool
    {
        return Product::query()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();
    }

    /**
     * Use the SKU given, or derive a readable one.
     *
     * Human-readable on purpose: operations staff quote SKUs aloud and write
     * them on boxes, and a database id is neither stable across environments
     * nor meaningful on a shelf.
     */
    private function resolveSku(?string $given, string $name): string
    {
        if (is_string($given) && trim($given) !== '') {
            return strtoupper(trim($given));
        }

        $prefix = Str::of($name)->slug()->upper()->replace('-', '')->limit(8, '')->value();

        if ($prefix === '') {
            $prefix = 'PROD';
        }

        do {
            $sku = $prefix.'-'.strtoupper(Str::random(6));
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }
}
