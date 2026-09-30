<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Marketplace\Queries\SitemapQuery;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

/**
 * GET /sitemap.xml
 *
 * A map of the public surface, for crawlers. It decides nothing and computes no
 * business rule: every URL and every visibility decision belongs to
 * {@see SitemapQuery}, and the view only lays out what that returns.
 *
 * The rendered document is reused for {@see SitemapQuery::TTL_SECONDS} because a
 * crawler sweep is not a browsing session, and the answer changes only when the
 * catalog does. The cache holds plain strings, so a stale entry can never carry
 * a model with it.
 */
class SitemapController extends Controller
{
    private const CACHE_KEY = 'sitemap:public_urls';

    public function __invoke(SitemapQuery $sitemap): Response
    {
        $document = Cache::remember(
            self::CACHE_KEY,
            SitemapQuery::TTL_SECONDS,
            static fn (): array => $sitemap->build(),
        );

        return response(
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".view('sitemap', $document)->render(),
            Response::HTTP_OK,
            ['Content-Type' => 'application/xml; charset=UTF-8'],
        );
    }
}
