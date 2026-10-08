<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Queries;

use App\Models\Auction;
use App\Models\BlogCategory;
use App\Models\Post;
use App\Models\Product;
use App\Models\Tag;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Assembling the public URL set behind /sitemap.xml.
 *
 * READ ONLY, and authoritative about nothing. A sitemap is a claim made to a
 * crawler about which URLs exist and are worth reading. Every entry here is
 * derived from the same visibility rules the pages themselves resolve through,
 * so this cannot advertise a URL that 404s, and it cannot advertise a page the
 * public is not allowed to see.
 *
 * WHAT IS IN IT. The standing pages reachable without an account, the catalog,
 * and the auctions a customer can still act on. Both catalog and auction lists
 * are bounded, because a sitemap is crawl budget spent deliberately: an
 * unbounded file stops being a map and becomes a dump that no engine will read
 * to the end.
 *
 * WHAT IS NOT IN IT, AND WHY. Finished auctions. `publiclyVisible` still admits
 * settled, unsold, cancelled and forfeited, because those pages render and a
 * customer holding a link may still want the outcome -- that is a reason to
 * serve the page, not a reason to advertise it. Those URLs accumulate without
 * limit, and a month-old settled auction is not a page a crawler should be
 * invited to spend a visit on. So the map uses
 * {@see AuctionDiscoveryQuery::openStatuses()} -- the same three states the
 * auction hall itself calls an opportunity. An auction arrives when it is
 * scheduled and leaves when it stops being something a customer can join.
 *
 * `lastmod` is the row's own `updated_at`, which is a fact. Priority and change
 * frequency are absent on purpose: both are advisory fields the major engines
 * ignore outright, and a number written into a sitemap to look decisive is
 * decoration. Nothing is invented here that the database does not already know.
 */
class SitemapQuery
{
    /**
     * Per-section caps. Comfortably inside the protocol's 50,000-URL ceiling,
     * and far above what this catalog will hold for some time. Reaching either
     * one is a signal to split this into a sitemap index, not to raise the cap.
     */
    public const MAX_PRODUCTS = 5000;

    public const MAX_AUCTIONS = 5000;

    public const MAX_POSTS = 5000;

    /**
     * How long a rendered map is reused, in seconds.
     *
     * Crawlers are not rate-limited visitors, and this is two indexed queries
     * that only change when the catalog does. A short reuse keeps a crawler
     * sweep off the database while bounding how long a newly published listing
     * can stay unlisted.
     */
    public const TTL_SECONDS = 3600;

    /**
     * The whole map, in the shape the view renders without deciding anything.
     *
     * Static pages carry no `lastmod`. A `Route::view` has no `updated_at`, and
     * a date written by hand would be a fiction the moment the copy changed.
     *
     * @return array{
     *     static: list<string>,
     *     products: list<array{loc: string, lastmod: string}>,
     *     auctions: list<array{loc: string, lastmod: string}>,
     *     posts: list<array{loc: string, lastmod: string}>,
     *     blogCategories: list<array{loc: string, lastmod: string}>,
     *     blogTags: list<array{loc: string, lastmod: string}>,
     *     notes: list<string>
     * }
     */
    public function build(): array
    {
        [$products, $productsCapped] = $this->productEntries(self::MAX_PRODUCTS);
        [$auctions, $auctionsCapped] = $this->auctionEntries(self::MAX_AUCTIONS);
        [$posts, $postsCapped] = $this->postEntries(self::MAX_POSTS);

        return [
            'static' => $this->staticPages(),
            'products' => $products,
            'auctions' => $auctions,
            'posts' => $posts,
            'blogCategories' => $this->categoryEntries(),
            'blogTags' => $this->tagEntries(),
            'notes' => array_values(array_filter([
                $productsCapped ? 'Catalog is truncated at '.self::MAX_PRODUCTS.' listings. Split this into a sitemap index before raising the cap.' : null,
                $auctionsCapped ? 'Auctions are truncated at '.self::MAX_AUCTIONS.' listings. Split this into a sitemap index before raising the cap.' : null,
                $postsCapped ? 'Blog posts are truncated at '.self::MAX_POSTS.' listings. Split this into a sitemap index before raising the cap.' : null,
            ])),
        ];
    }

    /**
     * The standing public pages, in the order a reader meets them.
     *
     * Named by route rather than by path, so a URL that moves in one place moves
     * here too. Every name must resolve to a page with no `noindex` header --
     * advertising a page that asks not to be indexed is the one way this file
     * could work against itself.
     *
     * /partners and /success-stories are here even when empty. They are standing
     * pages that return 200 and say plainly that nothing has been published, so
     * there is nothing to 404 and no secret on them -- unlike an individual
     * record, which this file never advertises because there is no per-record
     * URL to advertise.
     *
     * @return list<string>
     */
    public function staticPages(): array
    {
        return [
            'home',
            'auctions.index',
            'products.index',
            'how-it-works',
            'about',
            'contact',
            'faqs',
            'partners.index',
            'success-stories.index',
            'privacy',
            'terms',
            'cookies',
            'blog.index',
        ];
    }

    /**
     * Catalog URLs, most recently changed first.
     *
     * Active and out-of-stock listings alike. Both render, and a listing that
     * is temporarily out of stock is still a page somebody searches for by name;
     * `publiclyVisible` already excludes the drafts and archived rows whose
     * pages would 404.
     *
     * One column beyond the key, because the map needs to know when the page
     * last changed and has no other way to learn it. The description and image
     * columns stay behind -- a sitemap is URLs, not page bodies.
     *
     * @return array{0: list<array{loc: string, lastmod: string}>, 1: bool}
     */
    public function productEntries(int $limit): array
    {
        $rows = Product::query()
            ->publiclyVisible()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get(['slug', 'updated_at']);

        return $this->toEntries(
            $rows->take($limit),
            fn (Product $product): string => route('products.show', $product->slug),
            $rows->count() > $limit,
        );
    }

    /**
     * Auction URLs a customer can still join, most recently changed first.
     *
     * `publiclyVisible` first for the same reason as the catalog, then the
     * open-status filter, so a draft cannot be reached through this path at all.
     *
     * @return array{0: list<array{loc: string, lastmod: string}>, 1: bool}
     */
    public function auctionEntries(int $limit): array
    {
        $rows = Auction::query()
            ->publiclyVisible()
            ->whereIn('status', AuctionDiscoveryQuery::openStatuses())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get(['id', 'updated_at']);

        return $this->toEntries(
            $rows->take($limit),
            fn (Auction $auction): string => route('auctions.show', $auction->id),
            $rows->count() > $limit,
        );
    }

    /**
     * Blog post URLs, most recently changed first.
     *
     * Only published posts. A draft, or a post awaiting a publish date, has no
     * page the public can reach -- the detail route itself 404s on those rows,
     * so advertising them here would be a lie.
     *
     * @return array{0: list<array{loc: string, lastmod: string}>, 1: bool}
     */
    public function postEntries(int $limit): array
    {
        $rows = Post::query()
            ->published()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit + 1)
            ->get(['slug', 'updated_at']);

        return $this->toEntries(
            $rows->take($limit),
            fn (Post $post): string => route('blog.show', $post->slug),
            $rows->count() > $limit,
        );
    }

    /**
     * Blog category pages that are actually pages.
     *
     * Only an active category with at least one published post earns a URL:
     * the booked-but-empty category dir `/blog/category/{slug}` converts to
     * nothing a crawler should visit, and an archived category 404s outright.
     * `lastmod` is the newest post's `updated_at` -- the category page changes
     * when its newest post changes, not when the category row did.
     *
     * @return list<array{loc: string, lastmod: string}>
     */
    public function categoryEntries(): array
    {
        return BlogCategory::query()
            ->active()
            ->whereHas('posts', fn ($query): Builder => $query
                // The same two clauses as Post::scopePublished -- a paged
                // category is a page only if something published shows on it.
                ->where('active', true)
                ->whereNotNull('published_at'))
            ->withMax('posts', 'updated_at')
            ->orderBy('name')
            ->get()
            ->map(fn (BlogCategory $category): array => [
                'loc' => route('blog.category', $category),
                'lastmod' => Carbon::parse($category->posts_max_updated_at)->toAtomString(),
            ])
            ->all();
    }

    /**
     * Blog tag pages that are actually pages.
     *
     * The same rule as {@see self::categoryEntries()}: active and holding at
     * least one published post. A tag with no published posts has that empty
     * page for whoever lands on it by hand; it does not spend a crawl visit.
     *
     * @return list<array{loc: string, lastmod: string}>
     */
    public function tagEntries(): array
    {
        return Tag::query()
            ->active()
            ->whereHas('posts', fn ($query): Builder => $query
                // The same two clauses as Post::scopePublished -- a tag page is
                // a page only if something published shows on it.
                ->where('active', true)
                ->whereNotNull('published_at'))
            ->withMax('posts', 'updated_at')
            ->orderBy('name')
            ->get()
            ->map(fn (Tag $tag): array => [
                'loc' => route('blog.tag', $tag),
                'lastmod' => Carbon::parse($tag->posts_max_updated_at)->toAtomString(),
            ])
            ->all();
    }

    /**
     * Pair each row with its absolute URL, and say whether the cap was hit.
     *
     * The cap is detected by asking for one row more than is wanted rather than
     * by comparing the result to the limit, so a section holding exactly the
     * limit is not mistaken for a truncated one.
     *
     * Generic over the row type because the two sections hold different models
     * and `Collection` is invariant in its value type -- a single-model
     * signature would reject one of the two call sites.
     *
     * @template T of Product|Auction|Post
     *
     * @param  Collection<int, T>  $rows
     * @param  callable(T): string  $locator
     * @return array{0: list<array{loc: string, lastmod: string}>, 1: bool}
     */
    private function toEntries(Collection $rows, callable $locator, bool $capped): array
    {
        return [
            $rows
                ->map(fn (Product|Auction|Post $row): array => [
                    'loc' => $locator($row),
                    'lastmod' => $row->updated_at->toAtomString(),
                ])
                ->values()
                ->all(),
            $capped,
        ];
    }
}
