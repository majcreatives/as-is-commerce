<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Queries;

use App\Domain\Marketplace\ValueObjects\ListingAvailability;
use App\Enums\AuctionStatus;
use App\Enums\ProductStatus;
use App\Models\Auction;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Finding products, for the shop.
 *
 * READ ONLY. Nothing here writes, and nothing here decides: it assembles what
 * a listing page needs to describe products honestly. Every purchase path
 * re-checks its own rules against locked rows when it actually runs.
 *
 * EVERY QUERY STARTS FROM `publiclyVisible`. A draft, inactive or archived
 * product cannot appear through a filter, a search term or a crafted query
 * string, because visibility is decided by the scope rather than by
 * remembering to add a status check at each call site.
 *
 * SEARCH IS BOUNDED. The term is trimmed and length-capped before it reaches
 * a LIKE, so a pathological query cannot be used to make the database work
 * arbitrarily hard. MySQL's own capabilities, deliberately: a search engine is
 * infrastructure this platform does not need to answer "do you have a Nokia".
 */
class ProductDiscoveryQuery
{
    /** Longer than any real product name anybody types. */
    public const MAX_SEARCH_LENGTH = 80;

    public const PER_PAGE = 12;

    /**
     * @param  array{search?: string, category?: string, brand?: string, condition?: string, availableOnly?: bool, sort?: string}  $filters
     * @return LengthAwarePaginator<int, Product>
     */
    public function paginate(array $filters, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->filtered($filters)->paginate($perPage);
    }

    /**
     * A few products worth looking at next.
     *
     * Same category first, then same brand -- deterministic, explicable, and
     * based on data the catalog already holds. Not a recommendation engine:
     * nothing here observes what anybody has looked at, and two customers see
     * the same list.
     *
     * @return EloquentCollection<int, Product>
     */
    public function related(Product $product, int $limit = 4): EloquentCollection
    {
        $sameCategory = Product::query()
            ->publiclyVisible()
            ->with('brand')
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->id)
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        if ($sameCategory->count() >= $limit || $product->brand_id === null) {
            return $sameCategory;
        }

        // Top up from the same brand rather than showing three products where
        // there is room for four.
        $sameBrand = Product::query()
            ->publiclyVisible()
            ->with('brand')
            ->where('brand_id', $product->brand_id)
            ->whereKeyNot($product->id)
            ->whereNotIn('id', $sameCategory->modelKeys())
            ->orderByDesc('published_at')
            ->limit($limit - $sameCategory->count())
            ->get();

        return $sameCategory->concat($sameBrand);
    }

    /**
     * A handful of products for the homepage.
     *
     * @return EloquentCollection<int, Product>
     */
    public function featured(int $limit = 8): EloquentCollection
    {
        return Product::query()
            ->publiclyVisible()
            ->with(['brand', 'category'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * What each of these products can currently be done with.
     *
     * One query for the whole page rather than one per card, and the auctions
     * are filtered to currently relevant ones -- so a finished auction can
     * never put a stale highest bid on a card, and a product whose unit an
     * auction is holding is not described as out of stock.
     *
     * @param  Collection<int, Product>  $products
     * @return array<int, ListingAvailability> Keyed by product id.
     */
    public function availabilityFor(Collection $products): array
    {
        if ($products->isEmpty()) {
            return [];
        }

        $auctions = Auction::query()
            ->whereIn('product_id', $products->pluck('id')->all())
            ->whereIn('status', [
                AuctionStatus::Live,
                AuctionStatus::Closing,
                AuctionStatus::Scheduled,
            ])
            // Newest first, so a product with more than one relevant auction
            // is described by the most recent rather than by chance.
            ->orderByDesc('id')
            ->get()
            ->keyBy('product_id');

        $availability = [];

        foreach ($products as $product) {
            $availability[$product->id] = ListingAvailability::for(
                $product,
                $auctions->get($product->id),
            );
        }

        return $availability;
    }

    /**
     * The currently relevant auction for one product, if there is one.
     */
    public function activeAuctionFor(Product $product): ?Auction
    {
        return Auction::query()
            ->where('product_id', $product->id)
            ->whereIn('status', [
                AuctionStatus::Live,
                AuctionStatus::Closing,
                AuctionStatus::Scheduled,
            ])
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Categories that actually hold something a customer can see.
     *
     * Offering a filter that returns nothing is worse than not offering it.
     *
     * @return Collection<int, Category>
     */
    public function categories(): Collection
    {
        return Category::query()
            ->active()
            // The status list rather than the scope: static analysis cannot
            // resolve the related model through whereHas, and both read the
            // same definition, so the visibility rule still lives in one place.
            ->whereHas('products', fn (Builder $q) => $q->whereIn(
                'status',
                ProductStatus::publiclyVisibleCases(),
            ))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return Collection<int, Brand>
     */
    public function brands(): Collection
    {
        return Brand::query()
            ->active()
            ->whereHas('products', fn (Builder $q) => $q->whereIn(
                'status',
                ProductStatus::publiclyVisibleCases(),
            ))
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{search?: string, category?: string, brand?: string, condition?: string, availableOnly?: bool, sort?: string}  $filters
     * @return Builder<Product>
     */
    private function filtered(array $filters): Builder
    {
        $term = $this->searchTerm($filters['search'] ?? '');

        return Product::query()
            ->publiclyVisible()
            ->with(['brand', 'category'])
            ->when($term !== '', fn (Builder $q) => $q->where(function (Builder $inner) use ($term): void {
                $inner->where('name', 'like', "%{$term}%")
                    ->orWhere('short_description', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhereHas('brand', fn (Builder $b) => $b->where('name', 'like', "%{$term}%"))
                    ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', "%{$term}%"));
            }))
            ->when(($filters['category'] ?? '') !== '', fn (Builder $q) => $q->whereHas(
                'category',
                fn (Builder $c) => $c->where('slug', $filters['category']),
            ))
            ->when(($filters['brand'] ?? '') !== '', fn (Builder $q) => $q->whereHas(
                'brand',
                fn (Builder $b) => $b->where('slug', $filters['brand']),
            ))
            ->when(($filters['condition'] ?? '') !== '', fn (Builder $q) => $q->where(
                'condition',
                $filters['condition'],
            ))
            // "Available" means something a customer could obtain: on the
            // shelf, or held by an auction they can still take part in.
            // Available stock alone would hide every auctioned product.
            ->when($filters['availableOnly'] ?? false, fn (Builder $q) => $q->where(
                fn (Builder $inner) => $inner
                    ->whereRaw('stock_on_hand - stock_reserved > 0')
                    ->orWhereHas('auctions', fn (Builder $a) => $a->whereIn('status', [
                        AuctionStatus::Live,
                        AuctionStatus::Closing,
                        AuctionStatus::Scheduled,
                    ])),
            ))
            ->tap(fn (Builder $q) => $this->sort($q, $filters['sort'] ?? 'newest'));
    }

    /**
     * @param  Builder<Product>  $query
     */
    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('buy_now_price_minor'),
            'price_desc' => $query->orderByDesc('buy_now_price_minor'),
            'name' => $query->orderBy('name'),
            default => $query->orderByDesc('published_at')->orderByDesc('id'),
        };
    }

    /**
     * Trimmed and capped before it reaches the database.
     */
    private function searchTerm(string $raw): string
    {
        return mb_substr(trim($raw), 0, self::MAX_SEARCH_LENGTH);
    }
}
