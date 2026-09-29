<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks a response as not worth indexing.
 *
 * Whether a page may be indexed is a property of the route, not a property of
 * whoever happens to be looking at it. The previous approach emitted a `noindex`
 * meta tag only when the viewer was authenticated, which was backwards twice
 * over: a crawler is never authenticated, so the tag never reached the only
 * party it was written for, and a signed-in customer opening a public page had
 * that public page marked noindex for them.
 *
 * This is a response header rather than a meta tag so it holds for JSON and
 * file responses too, and so it cannot be quietly lost by a layout change.
 */
class NoIndex
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // `false` rather than `replace`, so a route that has a more specific
        // opinion about indexing keeps it.
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow', false);

        return $response;
    }
}
