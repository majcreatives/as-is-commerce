<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Queries;

use App\Domain\Marketplace\ValueObjects\ListingAvailability;
use App\Enums\AuctionStatus;
use App\Enums\BidStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductStatus;
use App\Models\Auction;
use App\Models\Bid;
use App\Models\Brand;
use App\Models\Category;
use App\Models\OrderItem;
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
            ->with(['brand', 'images'])
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
            ->with(['brand', 'images'])
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
            ->with(['brand', 'category', 'images'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Products with recent buying or bidding activity.
     *
     * "Trending" means what people have actually done: units on paid order
     * lines within the window, plus accepted bids placed on currently
     * relevant auctions within the window. Both are records -- nothing here
     * is invented -- and a product nobody has touched simply does not appear.
     * The window is the `homepage_trending_window_days` setting, 30 days when
     * unset. Where two products tie, the higher total leads and the higher
     * product id follows, so the same activity always surfaces the same list.
     *
     * READ ONLY, LIKE EVERYTHING HERE. Availability for the returned products
     * is decided separately by ListingAvailability, never by the presence of
     * old activity.
     *
     * @return EloquentCollection<int, Product>
     */
    public function trending(int $limit = 8): EloquentCollection
    {
        $windowDays = (int) settings()->getInt('homepage_trending_window_days', 30);
        $cutoff = now()->subDays(max(1, $windowDays));

        $scores = collect();

        // Units on paid orders, dated by when the money was verified. The paid
        // definition lives on the enum, read here rather than re-declared.
        $units = OrderItem::query()
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->whereIn('orders.status', array_map(
                fn (OrderStatus $status): string => $status->value,
                array_filter(OrderStatus::cases(), fn (OrderStatus $status): bool => $status->isPaid()),
            ))
            ->where('orders.paid_at', '>=', $cutoff)
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) AS units')
            ->groupBy('order_items.product_id')
            ->pluck('units', 'order_items.product_id');

        foreach ($units as $productId => $count) {
            $scores->put((int) $productId, (int) $count);
        }

        // Accepted bids on auctions a customer can still act on. The status
        // list is the same one ListingAvailability uses, so a finished auction
        // never leaves an echo here.
        $bids = Bid::query()
            ->join('auctions', 'auctions.id', '=', 'bids.auction_id')
            ->where('bids.status', BidStatus::Accepted)
            ->where('bids.created_at', '>=', $cutoff)
            ->whereIn('auctions.status', [
                AuctionStatus::Live,
                AuctionStatus::Closing,
                AuctionStatus::Scheduled,
            ])
            ->selectRaw('auctions.product_id, COUNT(bids.id) AS bid_count')
            ->groupBy('auctions.product_id')
            ->pluck('bid_count', 'auctions.product_id');

        foreach ($bids as $productId => $count) {
            $productId = (int) $productId;
            $scores->put($productId, $scores->get($productId, 0) + (int) $count);
        }

        if ($scores->isEmpty()) {
            return new EloquentCollection;
        }

        $entries = $scores
            ->map(fn (int $count, int $productId): array => [$count, $productId])
            ->values()
            ->all();

        // Total desc, then product id desc: a total order, so the sort does
        // not lean on PHP's sort stability to come out deterministic.
        uasort($entries, static fn (array $a, array $b): int => $b[0] <=> $a[0] ?: $b[1] <=> $a[1]);

        $ids = array_column(array_slice($entries, 0, $limit), 1);

        if ($ids === []) {
            return new EloquentCollection;
        }

        // FIELD() (MySQL/MariaDB) keeps the ranked order from PHP; bounded at
        // the page size, and anything that stopped being publicly visible
        // since the activity that ranked it simply drops out.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return Product::query()
            ->publiclyVisible()
            ->with(['brand', 'category', 'images'])
            ->whereIn('id', $ids)
            ->orderByRaw("FIELD(id, {$placeholders})", $ids)
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
            ->with(['brand', 'category', 'images'])
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
