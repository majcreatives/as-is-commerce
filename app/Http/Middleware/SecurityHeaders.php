<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Content Security Policy, and the response headers that belong with it.
 *
 * The policy is an allow-list. Nothing loads on this site unless it is named
 * here, so a script tag that reaches the markup by any route has nowhere to go
 * and is refused rather than executed.
 *
 * How the nonce reaches the tags. A per-request nonce is written onto Vite,
 * which is a container singleton, and from there the framework and Livewire
 * both pick it up for their own output: Vite puts it on the asset tags, and
 * Livewire puts it on the script it injects, on its generated style elements,
 * and on window.livewireScriptConfig. It is published to views as well, for
 * the one inline script this application writes itself (the JSON-LD block).
 *
 * Setting the nonce rather than passing it by hand is deliberate. A nonce that
 * only some tags carry is a nonce that fails open at the first tag somebody
 * forgets, and the resulting breakage looks like a layout bug rather than a
 * policy bug.
 *
 * @see \config\livewire.php 'csp_safe', which swaps Livewire for the build
 *      that nonces its injected markup instead of the build that does not.
 */
class SecurityHeaders
{
    /**
     * How long a browser is told to keep using HTTPS. Only sent over HTTPS,
     * since a browser ignores it on a plaintext response and caching it from
     * one would strand visitors who later reach the site any other way.
     *
     * includeSubDomains and preload are deliberately absent. Preloading
     * commits a domain to HTTPS-only in a way that cannot be undone quickly,
     * and every subdomain is not this application's to decide. Those belong
     * with the production domain, when it is known, as a deliberate step.
     */
    private const HSTS_SECONDS = 31_536_000;

    /**
     * Features the browser should not offer on any page of this site. Nothing
     * here records, scans, or geolocates, so each is refused outright.
     *
     * @var array<int, string>
     */
    private const DENIED_FEATURES = [
        'camera',
        'microphone',
        'geolocation',
        'payment',
        'usb',
        'magnetometer',
        'accelerometer',
        'gyroscope',
    ];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Before the view renders, not after. The nonce has to be in place
        // while the layout and Livewire write their tags, because by the time
        // $next() returns the markup is already built and any tag that missed
        // it is refused by the browser with no way to tell that from a
        // template bug.
        $nonce = Str::random(40);

        // Resolved from the container rather than through the facade: Vite's
        // facade exposes cspNonce() but not useCspNonce(), and Vite is a
        // registered singleton, so this is the same instance Livewire reads
        // its nonce from afterwards.
        app(Vite::class)->useCspNonce($nonce);
        View::share('cspNonce', $nonce);

        $response = $next($request);

        $response->headers->set('Content-Security-Policy', $this->policy($nonce));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set(
            'Permissions-Policy',
            $this->permissionsPolicy(),
        );

        if ($request->isSecure()) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age='.self::HSTS_SECONDS,
            );
        }

        return $response;
    }

    /**
     * The policy itself.
     *
     * Every directive here is a consequence of something the application
     * actually does. Where a source is absent it is a refusal, so the list is
     * short because the site is small, not because anything was overlooked.
     */
    private function policy(string $nonce): string
    {
        return implode('; ', [
            // Last resort. Nothing is fetched from anywhere else.
            "default-src 'self'",

            // Stops an injected <base> from re-pointing every relative URL on
            // the page at somewhere the attacker controls.
            "base-uri 'self'",

            // <object> and <embed> have no use here and have historically been
            // a way to get a scripting context past a policy.
            "object-src 'none'",

            // Nothing legitimately frames this site, and framing is how a page
            // gets a click it did not ask for. X-Frame-Options above covers
            // the browsers that predate this directive.
            "frame-ancestors 'none'",

            // Forms post to this site. Payment leaves by a redirect, not a form
            // submission, so Paystack does not belong here.
            "form-action 'self'",

            // The part that does the work. A nonce and 'self' only: no
            // 'unsafe-inline' and no 'unsafe-eval', so an injected inline
            // handler and a string passed to eval() are both refused.
            "script-src 'self' 'nonce-{$nonce}'",

            // Stylesheets come from the compiled asset and from Livewire, which
            // nonces its generated ones. No 'unsafe-inline' here either. The
            // one inline style attribute this application had was replaced with
            // x-cloak rather than allowed, which is why this can stay strict.
            "style-src 'self' 'nonce-{$nonce}'",

            // Tailwind and the icon set ship as files.
            "img-src 'self' data: blob:",

            "font-src 'self' data:",

            // Livewire's requests are all same-origin.
            "connect-src 'self'",

            "media-src 'self'",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ]);
    }

    /**
     * The Permissions-Policy value.
     */
    private function permissionsPolicy(): string
    {
        $directives = array_map(
            static fn (string $feature): string => $feature.'=()',
            self::DENIED_FEATURES,
        );

        return implode(', ', $directives);
    }
}
