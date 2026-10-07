<?php

declare(strict_types=1);

use App\Enums\AuctionStatus;
use App\Enums\ProductStatus;
use App\Models\Auction;
use App\Models\Post;
use App\Models\Product;

/*
 * /sitemap.xml
 *
 * A sitemap is a promise to a crawler: every URL in it exists, is public, and is
 * worth a visit. The failure modes are quiet in both directions. Listing a page
 * that 404s spends a crawl on nothing and erodes trust in the whole domain.
 * Omitting a page that is live and indexable means it is found slowly, or never.
 *
 * The decision this file exists to pin is which auctions belong in the map.
 * `publiclyVisible` admits settled, unsold, cancelled and forfeited, because
 * those pages render and a customer holding a link may still want the outcome.
 * A sitemap is a different question. Those URLs accumulate without limit, and a
 * month-old settled auction is not a page a crawler should be invited to spend a
 * visit on, so the map uses the auction hall's own definition of an opportunity
 * -- scheduled, live, closing -- and lets an auction enter and leave as it
 * progresses.
 *
 * The strongest test here is the last one: every URL the sitemap claims is
 * fetched back and has to answer for itself.
 */

/**
 * The parsed `<loc>` values, so assertions are about URLs and not about markup.
 *
 * @return list<string>
 */
function sitemapLocations(string $xml): array
{
    $previous = libxml_use_internal_errors(true);
    $parsed = simplexml_load_string($xml);
    libxml_use_internal_errors($previous);

    expect($parsed)->not->toBeFalse('the sitemap is not well-formed XML');

    $locations = array_map('strval', $parsed->xpath('//*[local-name()="loc"]') ?? []);

    return $locations;
}

it('answers with a well formed xml document', function (): void {
    $response = test()->get('/sitemap.xml');

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

    $body = $response->getContent();

    expect($body)->toStartWith('<?xml version="1.0" encoding="UTF-8"?>')
        ->and($body)->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');

    $previous = libxml_use_internal_errors(true);
    expect(simplexml_load_string($body))->not->toBeFalse('the sitemap does not parse as XML');
    libxml_use_internal_errors($previous);
});

it('does not ask not to be indexed', function (): void {
    // A sitemap that told a crawler to skip it would be a contradiction. The
    // noindex header is meant for pages a stranger must not see, and this file
    // contains only URLs of pages they may.
    test()->get('/sitemap.xml')->assertHeaderMissing('X-Robots-Tag');
});

it('lists the standing public pages', function (string $name, string $path): void {
    expect(sitemapLocations(test()->get('/sitemap.xml')->getContent()))
        ->toContain(route($name));
})->with([
    ['home', '/'],
    ['auctions.index', '/auctions'],
    ['products.index', '/products'],
    ['how-it-works', '/how-it-works'],
    ['about', '/about'],
    ['contact', '/contact'],
    ['faqs', '/faqs'],
    ['partners.index', '/partners'],
    ['blog.index', '/blog'],
    ['success-stories.index', '/success-stories'],
    ['privacy', '/privacy'],
    ['terms', '/terms'],
    ['cookies', '/cookies'],
]);

it('lists a product the public can see', function (ProductStatus $status): void {
    $product = Product::factory()->status($status)->create();

    expect(sitemapLocations(test()->get('/sitemap.xml')->getContent()))
        ->toContain(route('products.show', $product->slug));
})->with([
    'active' => [ProductStatus::Active],
    // Out of stock still renders and is still a page somebody searches for by
    // name. Leaving it out would mean a listing disappears from search the
    // moment it sells out, which is the opposite of what should happen.
    'out of stock' => [ProductStatus::OutOfStock],
]);

it('omits a product the public cannot see', function (ProductStatus $status): void {
    $product = Product::factory()->status($status)->create();

    expect(sitemapLocations(test()->get('/sitemap.xml')->getContent()))
        ->not->toContain(route('products.show', $product->slug));
})->with([
    'draft' => [ProductStatus::Draft],
    'archived' => [ProductStatus::Archived],
]);

it('lists an auction a customer can still join', function (string $state): void {
    $auction = Auction::factory()->{$state}()->create();

    expect(sitemapLocations(test()->get('/sitemap.xml')->getContent()))
        ->toContain(route('auctions.show', $auction->id));
})->with(['live', 'closing', 'scheduled']);

it('omits an auction that has finished', function (AuctionStatus $status): void {
    $auction = Auction::factory()->create(['status' => $status]);

    // The page still renders -- the outcome is worth reading if you hold the
    // link -- but it is no longer something a customer can act on, and left in
    // the map these accumulate forever and dilute everything still open.
    expect(sitemapLocations(test()->get('/sitemap.xml')->getContent()))
        ->not->toContain(route('auctions.show', $auction->id));
})->with([
    'settled' => [AuctionStatus::Settled],
    'unsold' => [AuctionStatus::Unsold],
    'cancelled' => [AuctionStatus::Cancelled],
    'forfeited' => [AuctionStatus::Forfeited],
]);

it('omits a draft auction', function (): void {
    $auction = Auction::factory()->create(['status' => AuctionStatus::Draft]);

    expect(sitemapLocations(test()->get('/sitemap.xml')->getContent()))
        ->not->toContain(route('auctions.show', $auction->id));
});

it('lists a published post by slug', function (): void {
    $post = Post::factory()->published()->create();

    expect(sitemapLocations(test()->get('/sitemap.xml')->getContent()))
        ->toContain(route('blog.show', $post->slug));
});

it('omits a post that is not public yet', function (string $state): void {
    $post = Post::factory()->{$state}()->create();

    // The detail route 404s for these rows, so advertising them here would be a
    // lie. A dash in an archive travels exactly as far as the page behind it.
    expect(sitemapLocations(test()->get('/sitemap.xml')->getContent()))
        ->not->toContain(route('blog.show', $post->slug));
})->with([
    'inactive but dated' => ['inactive'],
    'active but undated' => ['undated'],
]);

it('never lists a private path', function (string $path): void {
    // Not one mechanism but two: robots.txt keeps crawlers off, and the route
    // sends noindex. A sitemap that advertised either would be handing a
    // customer's account view to a search result.
    $body = test()->get('/sitemap.xml')->getContent();

    expect($body)->not->toContain($path);
})->with([
    '/admin',
    '/dashboard',
    '/orders',
    '/wallet',
    '/credits',
    '/cart',
    '/checkout',
    '/login',
    '/register',
    '/referrals',
    '/health',
]);

it('stamps catalog entries with a real lastmod', function (): void {
    $product = Product::factory()->active()->create(['updated_at' => '2026-01-02 03:04:05']);

    $response = test()->get('/sitemap.xml');
    $body = $response->getContent();

    expect($body)->toContain('<lastmod>2026-01-02T03:04:05+00:00</lastmod>');
});

it('reuses the rendered map rather than rebuilding it per request', function (): void {
    // A crawler sweep is not a browsing session. The map is rebuilt at most once
    // per TTL, so a run of requests over one catalog does not become a run of
    // identical database queries.
    Product::factory()->active()->create();

    $first = test()->get('/sitemap.xml')->getContent();
    $locationsInFirst = count(sitemapLocations($first));

    expect($locationsInFirst)->toBeGreaterThan(0);

    // A listing created after the first render is absent until the TTL expires.
    // That is the trade being made deliberately, and this pins it.
    $late = Product::factory()->active()->create();
    $second = test()->get('/sitemap.xml')->getContent();

    expect(sitemapLocations($second))->toHaveCount($locationsInFirst)
        ->not->toContain(route('products.show', $late->slug));
});

it('lists only urls that actually resolve', function (): void {
    Product::factory()->count(2)->active()->create();
    Auction::factory()->live()->create();
    Auction::factory()->scheduled()->create();
    Post::factory()->published()->create();

    $locations = sitemapLocations(test()->get('/sitemap.xml')->getContent());

    expect($locations)->not->toBeEmpty();

    foreach ($locations as $location) {
        // Every claim in the map is held to account. This is the test that fails
        // if a URL is advertised before the page behind it can serve it.
        test()->get($location)->assertOk();
    }
});
