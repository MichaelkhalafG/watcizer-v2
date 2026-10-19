<?php

namespace App\Http\Middleware;

use App\Storefront\CacheTags;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * HTTP caching for the v2 GETs (CLEAN_CORE_STUDY §5.2, first row): the Cache-Control of
 * `storefront.http_cache.control`, a strong ETag (sha1 of the body), `Cache-Tag` for purge-by-tag, and
 * 304 on a matching If-None-Match. The storefront is in the path, so the URL is the whole cache key.
 *
 * The Cache-Control is PRIVATE since 2026-10-19: the CDN in front of the API hosts keeps one copy per URL
 * whatever the request's Origin, so a shared-cache directive handed browsers the CORS header of whoever
 * filled the copy (config/storefront.php, tests/Feature/V2/NoSharedCacheV2Test.php).
 */
class HttpCacheHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200) {
            $response->headers->set('Cache-Control', 'no-store, private');

            return $response;
        }

        $content = $response->getContent();
        $etag = '"'.sha1($content === false ? '' : $content).'"';

        $response->headers->set('Cache-Control', config()->string('storefront.http_cache.control'));
        $response->headers->set('ETag', $etag);
        if (app()->bound(CacheTags::class)) {
            $tags = app(CacheTags::class)->header();
            if ($tags !== '') {
                $response->headers->set('Cache-Tag', $tags);
            }
        }

        $ifNoneMatch = $request->headers->get('If-None-Match');
        if ($ifNoneMatch !== null && in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true)) {
            $response->setStatusCode(304);
            $response->setContent('');
        }

        return $response;
    }
}
