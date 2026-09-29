<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;

/*
 * Indexability, and the accessibility scaffolding the public pages rely on.
 *
 * Two separate concerns, tested together because they are both "what a stranger
 * arriving from outside gets to see".
 *
 * INDEXABILITY. A customer's account page, a bid history and the entire admin
 * surface are not search results. Getting this wrong leaks a customer's own view
 * of their account into a search listing, and points a crawler at pages that can
 * only ever redirect it to a sign-in screen.
 *
 * The subtle part, and the reason these tests exist: whether a page may be
 * indexed is a property of the ROUTE, not a property of whoever is looking at
 * it. The version that shipped first emitted `noindex` only when the viewer was
 * authenticated, which is backwards twice -- a crawler is never authenticated so
 * the tag never reached the party it was written for, and a signed-in customer
 * opening a public page had that public page marked noindex for them. These tests
 * pin the behaviour to the route so that regression cannot come back quietly.
 */

/** Public pages that must stay discoverable. */
const PUBLIC_PAGES = ['/', '/auctions', '/products', '/about', '/how-it-works', '/contact', '/faqs', '/privacy', '/terms', '/cookies'];

/** Pages a crawler must be kept out of, reachable only when signed in. */
const PRIVATE_PATHS = ['/admin', '/dashboard', '/orders', '/wallet', '/credits', '/cart', '/addresses', '/referrals', '/notifications'];

/**
 * The document head, so assertions do not match body copy.
 */
function documentHead(string $html): string
{
    $start = strpos($html, '<head>');
    $end = strpos($html, '</head>');

    return $start === false || $end === false ? $html : substr($html, $start, $end - $start);
}

it('keeps public pages indexable', function (string $path): void {
    $response = test()->get($path);

    $response->assertOk();
    $response->assertHeaderMissing('X-Robots-Tag');
})->with(PUBLIC_PAGES);

it('sends an unauthenticated crawler from a private page to a page that says do not index me', function (string $path): void {
    // The header cannot ride on this redirect: 'auth' throws
    // AuthenticationException, the redirect is built by the exception handler
    // after the middleware has unwound, and there is no response left to tag.
    // So the guarantee is the chain, and all three links are asserted below:
    // the private path is not fetched (robots.txt), and a crawl that arrives
    // anyway lands on a noindex page.
    $response = test()->get($path);

    $response->assertRedirect(route('login'));

    $rules = array_map('trim', explode("\n", File::get(public_path('robots.txt'))));
    expect(in_array('Disallow: '.$path, $rules, true))
        ->toBeTrue("robots.txt does not keep crawlers off {$path}");

    test()->get('/login')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
})->with(PRIVATE_PATHS);

it('keeps crawlers out of an account page once signed in', function (string $path): void {
    // A real response, so the header does apply here.
    $this->actingAs(User::factory()->create())
        ->get($path)
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
})->with(PRIVATE_PATHS);

it('keeps crawlers out of the sign-in pages', function (string $path): void {
    $response = test()->get($path);

    $response->assertOk();
    $response->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $response->assertSee('noindex', escape: false);
})->with(['/login', '/register', '/forgot-password']);

it('marks a signed-in view of a public page as still indexable', function (): void {
    // The regression this replaces. Indexability belongs to the route, so
    // looking at the shop while signed in must not change how it is indexed.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/products');

    $response->assertOk();
    $response->assertHeaderMissing('X-Robots-Tag');
});

it('marks an admin page noindex even for an administrator', function (): void {
    // An admin viewing /admin is still a crawler-shaped request, and the route
    // has not changed, so it must not become indexable because of who is logged in.
    $this->actingAs(userWithRole('super_admin'))
        ->get('/admin')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('does not mark a public page noindex just because the layout changed', function (): void {
    // Guards the inverse failure: the fix must not creep onto public pages.
    $html = documentHead(test()->get('/')->assertOk()->getContent());

    expect($html)->not->toContain('noindex');
});

it('tells crawlers about the private paths in robots.txt', function (): void {
    $robots = File::get(public_path('robots.txt'));

    expect($robots)->toContain('User-agent: *');

    foreach (PRIVATE_PATHS as $path) {
        expect($robots)->toContain('Disallow: '.$path);
    }
});

it('does not block public pages in robots.txt', function (): void {
    // Exact-line comparison. A substring test would flag 'Disallow: /admin' as
    // a rule against '/', which is the opposite of what it means.
    $rules = array_map('trim', explode("\n", File::get(public_path('robots.txt'))));

    foreach (PUBLIC_PAGES as $path) {
        $rule = 'Disallow: '.($path === '/' ? '/' : $path);

        expect($rules)->not->toContain($rule, "robots.txt blocks the public page {$path}");
    }
});

/*
 * The shop and the auction are the two halves of the business. If they share a
 * description, a search engine is told they are the same page, and the page a
 * bidder actually wanted loses its snippet to the homepage's.
 */
it('gives every indexable page its own description', function (): void {
    $descriptions = [];

    foreach (PUBLIC_PAGES as $path) {
        $response = test()->get($path)->assertOk();
        $matched = preg_match('/<meta name="description" content="([^"]*)"/i', documentHead($response->getContent()), $m);

        expect($matched)->toBe(1, "{$path} has no meta description");
        $descriptions[$path] = $m[1];
    }

    $duplicated = array_diff_assoc($descriptions, array_unique($descriptions));

    expect($duplicated)->toBe([], 'these pages share a description: '.implode(', ', array_keys($duplicated)));
});

it('gives the shop and the auctions their own title', function (string $path, string $expected): void {
    $html = documentHead(test()->get($path)->assertOk()->getContent());
    preg_match('/<title>(.*?)<\/title>/is', $html, $m);

    expect($m[1] ?? '')->toContain($expected);
})->with([
    ['/products', 'Shop'],
    ['/auctions', 'Auctions'],
]);

it('names the brand in an inner page title', function (string $path): void {
    $html = documentHead(test()->get($path)->assertOk()->getContent());

    expect($html)->toContain(config('app.name'));
})->with(['/products', '/auctions', '/about', '/faqs', '/privacy', '/terms']);

it('does not repeat the brand when the page already names it', function (): void {
    // The home page title is a sentence that already contains the brand, so the
    // layout must not append it a second time. Asserted on the <title> only:
    // og:site_name and og:title legitimately repeat the brand.
    $html = documentHead(test()->get('/')->assertOk()->getContent());
    preg_match('/<title>(.*?)<\/title>/is', $html, $m);

    expect($m[1] ?? '')->toContain(config('app.name'));
    expect(substr_count($m[1] ?? '', config('app.name')))->toBe(1);
});

/*
 * Accessibility scaffolding.
 *
 * The landing, sign-in and registration layouts all have to offer a <main>
 * landmark and a skip link. Each of those three layouts was missing one or both
 * at some point, and nothing about the failure is visible in a screenshot.
 */
it('offers a main landmark and a skip link on every layout', function (string $path): void {
    $html = test()->get($path)->assertOk()->getContent();

    expect(str_contains($html, '<main id="main"'))->toBeTrue("{$path} has no <main> landmark to skip to");
    expect(str_contains($html, 'Skip to content'))->toBeTrue("{$path} has no skip link");
    expect(str_contains($html, 'href="#main"'))->toBeTrue("{$path} skip link does not point at the main landmark");
})->with(['/', '/login', '/register']);

it('gives every public page exactly one first-level heading', function (string $path): void {
    $html = test()->get($path)->assertOk()->getContent();

    expect(substr_count(strtolower($html), '<h1'))->toBe(1, "{$path} does not have exactly one h1");
})->with(PUBLIC_PAGES);

it('never skips a heading level', function (string $path): void {
    preg_match_all('/<h([1-6])\b/i', test()->get($path)->assertOk()->getContent(), $m);

    $levels = array_map('intval', $m[1]);
    $previous = null;

    foreach ($levels as $level) {
        if ($previous !== null) {
            expect($level)
                ->not->toBeGreaterThan($previous + 1, "{$path} jumps from h{$previous} to h{$level}");
        }
        $previous = $level;
    }
})->with(PUBLIC_PAGES);
