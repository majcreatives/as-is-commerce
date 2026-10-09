<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * A visitor's own product-view history, kept in their session.
 *
 * SERVER AUTHORITATIVE BUT PERSONAL. Recording happens inside the product
 * page's own mount, so the history reflects pages this visitor was actually
 * served, never something the browser merely claimed. It lives in the
 * session -- never in a shared table -- so one customer's browsing is never
 * another's and nothing is persisted once the session ends.
 *
 * BOUNDED. The history stores a fixed number of ids and the strip renders a
 * fixed slice of them, so a long browsing session cannot grow the payload or
 * the query.
 *
 * INVISIBLE ITEMS DROP OUT. The strip resolves ids only against publicly
 * visible products, so a product published and later archived leaves the
 * strip even though its id still sits in the session awaiting the next prune.
 */
class RecentlyViewed
{
    /** How many product ids the session history may hold. */
    public const MAX = 8;

    /** How many products the public strip may show. */
    public const STRIP_LIMIT = 4;

    private const KEY = 'recently_viewed.product_ids';

    /**
     * Record that a visitor has opened a product's page.
     */
    public function remember(Product $product): void
    {
        $ids = array_values(array_filter(
            $this->ids(),
            fn (int $id): bool => $id !== $product->id,
        ));

        array_unshift($ids, $product->id);

        session()->put(self::KEY, array_slice($ids, 0, self::MAX));
    }

    /**
     * The stored ids, newest first.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $ids = session()->get(self::KEY, []);

        return is_array($ids)
            ? array_values(array_map('intval', $ids))
            : [];
    }

    /**
     * Products from the history, for a strip of cards.
     *
     * Resolved only against publicly visible products and returned in the
     * stored order, so an archived or deleted product never surfaces. The
     * currently open product is excluded; this strip is a section of that
     * product's own page.
     *
     * @return EloquentCollection<int, Product>
     */
    public function recent(int $limit = self::STRIP_LIMIT, ?int $excludeId = null): EloquentCollection
    {
        $ids = $this->ids();

        if ($ids === []) {
            return new EloquentCollection;
        }

        if ($excludeId !== null) {
            $ids = array_values(array_filter($ids, fn (int $id): bool => $id !== $excludeId));
        }

        if ($ids === []) {
            return new EloquentCollection;
        }

        $found = Product::query()
            ->publiclyVisible()
            ->with(['brand', 'images'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $products = [];

        foreach ($ids as $id) {
            $product = $found->get($id);

            if ($product === null) {
                continue;
            }

            $products[] = $product;

            if (count($products) === $limit) {
                break;
            }
        }

        return new EloquentCollection($products);
    }
}
