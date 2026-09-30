<?php

declare(strict_types=1);

use App\Models\Product;
use Illuminate\Support\Facades\Http;

/*
 * The Content Security Policy is the point of this file. Its value is entirely
 * in what it refuses: a script that arrives by any route, from a stored value,
 * a compromised dependency, or a mistake in a template, is stopped rather than
 * run. A test that only checked the header existed would pass just as happily
 * against a policy containing 'unsafe-inline', which is the version that
 * protects nothing, so the assertions below are about the absences.
 */

/**
 * Pull the nonce out of a policy string.
 */
function cspNonce(string $policy): ?string
{
    if (preg_match("/'nonce-([^']+)'/", $policy, $matches) === 1) {
        return $matches[1];
    }

    return null;
}

/**
 * Every opening tag of a given type that has no src/href to fetch, so the ones
 * the browser has to trust on the word of the policy.
 *
 * @return array<int, string>
 */
function tagsWithoutSource(string $html, string $element): array
{
    preg_match_all('/<'.$element.'\b[^>]*>/i', $html, $matches);

    return array_values(array_filter(
        $matches[0],
        fn (string $tag): bool => ! str_contains($tag, 'src='),
    ));
}

it('sends a content security policy', function (): void {
    $response = $this->get('/');

    $response->assertOk();

    expect($response->headers->get('Content-Security-Policy'))->toBeString()->not->toBeEmpty();
});

it('refuses to let the browser guess where content may come from', function (): void {
    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    // 'self' on the fallback, so a source omitted below is a refusal rather
    // than a wide door left open by the shorthand.
    expect($policy)->toContain("default-src 'self'");

    // Nothing here frames this site, and framing is how a page collects a
    // click it did not ask for.
    expect($policy)
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'");
});

it('runs no inline script and evaluates no string as code', function (): void {
    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    // The two tokens that would give back everything the policy takes away.
    // A nonce cannot be combined with either and still mean anything: the
    // browser ignores the nonce for the directive when 'unsafe-inline' is
    // present, and 'unsafe-eval' is not covered by a nonce at all.
    expect($policy)
        ->toContain("script-src 'self' 'nonce-")
        ->not->toContain("'unsafe-inline'")
        ->not->toContain("'unsafe-eval'");

    // Same reasoning for styles, which is why the single inline style
    // attribute in the codebase became x-cloak rather than an exception here.
    expect($policy)
        ->toContain("style-src 'self' 'nonce-")
        ->not->toContain("style-src 'unsafe-inline'");
});

it('has a nonce on the policy', function (): void {
    $nonce = cspNonce($this->get('/')->headers->get('Content-Security-Policy'));

    expect($nonce)->toBeString()->not->toBeEmpty();
});

it('issues a different nonce each time', function (): void {
    $first = cspNonce($this->get('/')->headers->get('Content-Security-Policy'));
    $second = cspNonce($this->get('/')->headers->get('Content-Security-Policy'));

    // A reused or predictable nonce is no protection at all: anything that can
    // run once can replay the value forever.
    expect($first)->not->toBe($second);
});

it('nonces the structured data it writes itself', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Kettle']);

    $response = $this->get(route('products.show', $product->slug));
    $response->assertOk();

    $nonce = cspNonce($response->headers->get('Content-Security-Policy'));

    // The nonce in the policy and the nonce on the tag have to be the same
    // value. If they diverge the browser refuses the block, and the symptom is
    // a missing logo in a search result rather than anything that names CSP.
    expect($response->getContent())
        ->toContain('<script type="application/ld+json" nonce="'.$nonce.'"');
});

it('leaves no inline script un-nonced', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Kettle']);

    $html = $this->get(route('products.show', $product->slug))->getContent();

    $inline = tagsWithoutSource($html, 'script');

    expect($inline)->not->toBeEmpty();

    foreach ($inline as $tag) {
        expect($tag)->toContain('nonce="');
    }
});

it('leaves no style element un-nonced', function (): void {
    $product = Product::factory()->active()->create(['name' => 'Kettle']);

    $html = $this->get(route('products.show', $product->slug))->getContent();

    // Worth asserting separately from scripts, because style-src carries the
    // same 'unsafe-inline'-free rule and Livewire emits its own <style>
    // elements -- including the progress bar, which is injected by JavaScript
    // at runtime rather than rendered by the layout. Those are only permitted
    // because the CSP build nonces them; on the default build this test fails,
    // which is the point of setting 'csp_safe'.
    $styles = tagsWithoutSource($html, 'style');

    expect($styles)->not->toBeEmpty();

    foreach ($styles as $tag) {
        expect($tag)->toContain('nonce="');
    }
});

it('nonces the asset tags the framework writes', function (): void {
    $response = $this->get('/');
    $response->assertOk();

    $html = $response->getContent();
    $nonce = cspNonce($response->headers->get('Content-Security-Policy'));

    // Vite takes its nonce from the same container singleton the middleware
    // sets. This asserts the wiring end to end, because the alternative --
    // passing the nonce by hand at each call site -- is the version that works
    // until somebody adds a layout and forgets. The policy nonce is read from
    // this same response rather than a second request, since each response
    // carries its own.
    expect($html)
        ->toContain('/build/assets/')
        ->toContain('nonce="'.$nonce.'"')
        ->toContain('<script type="module" src="http://localhost:8000/build/assets/')
        ->toContain('nonce="'.$nonce.'"');
});

it('tells the browser not to guess content types', function (): void {
    $response = $this->get('/');

    $response->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('keeps paths out of cross-origin referrers', function (): void {
    $this->get('/')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('refuses framing by the older header as well', function (): void {
    // frame-ancestors in the policy covers current browsers. X-Frame-Options is
    // still worth sending for the ones that predate it.
    $this->get('/')->assertHeader('X-Frame-Options', 'DENY');
});

it('refuses device features nothing on this site uses', function (): void {
    $policy = $this->get('/')->headers->get('Permissions-Policy');

    foreach (['camera', 'microphone', 'geolocation', 'payment'] as $feature) {
        expect($policy)->toContain($feature.'=()');
    }
});

it('does not demand https over a plaintext response', function (): void {
    // A browser ignores HSTS sent over plaintext, and caching it from one is
    // how a site ends up unreachable for anyone who cannot get https. The
    // header belongs only on a response that was already secure.
    $this->get('/')->assertHeaderMissing('Strict-Transport-Security');
});

it('demands https once the response is secure', function (): void {
    // The scheme has to be in the URL. Symfony's Request::create() rebuilds
    // $server['HTTPS'] from the scheme it parses, so a server variable of
    // HTTPS=on passed alongside an http:// URL is overwritten before the
    // request exists.
    $response = $this->get('https://localhost/');

    expect($response->headers->get('Strict-Transport-Security'))
        ->toBeString()
        ->toContain('max-age=');
});

it('leaves subdomains and preload out of the https demand', function (): void {
    $header = $this->get('https://localhost/')
        ->headers->get('Strict-Transport-Security');

    // Preloading commits a domain to https-only in a way that cannot be undone
    // quickly, and every subdomain is not this application's to decide. Both
    // belong with the production domain, as a deliberate step.
    expect($header)
        ->toBeString()
        ->not->toContain('includeSubDomains')
        ->not->toContain('preload');
});

it('covers the admin and the auth screens, not only the marketplace', function (): void {
    // The middleware is global precisely so this cannot drift: a header that
    // depends on remembering which routes were grouped is a header that
    // eventually gets left off the one route that needed it.
    foreach (['/login', '/register'] as $path) {
        $policy = $this->get($path)->headers->get('Content-Security-Policy');

        expect($policy)->toContain("frame-ancestors 'none'");
    }
});

it('does not interfere with the paystack webhook', function (): void {
    // The webhook is a POST from Paystack's servers, not a browser, and it is
    // unauthenticated by design. A global header middleware is exactly the kind
    // of thing that quietly breaks it, so it is worth a test that reaches the
    // route and gets as far as the signature check. An unsigned request is
    // refused with 401, which is the correct outcome and is not what is being
    // asserted here -- what is asserted is that the request arrived.
    fakeHttp(['*' => Http::response(['status' => true], 200)]);

    $this->post('/webhooks/paystack', ['event' => 'charge.success'], [
        'x-paystack-signature' => 'not-a-valid-signature',
    ])->assertStatus(401);
});
