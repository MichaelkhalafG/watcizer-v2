<?php

namespace App\Http\Middleware;

use App\Models\Storefront\Storefront;
use App\Storefront\CacheTags;
use App\Storefront\CategoryTree;
use App\Storefront\Lookups;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Storefront context for `/api/v2/{storefront}/…` (v1 study §3.8.1): the path segment is the
 * storefront code (cached 10 min), unknown or inactive → 404. The request locale comes from
 * `?locale=` only — never from a cookie or Accept-Language (study §6.2 rule 5) — and must be one
 * of the storefront's locales, else the storefront default (ar). Responses carry BOTH locales;
 * the request locale drives search and the echoed `meta.locale`.
 */
class ResolveStorefront
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->route('storefront');
        $code = is_string($code) ? $code : '';

        $ttl = config()->integer('storefront.ttl.storefront');
        // The cache holds the raw attribute row, never the model: `cache.serializable_classes` is off.
        $attributes = Cache::get("sf:code:{$code}");
        if (! is_array($attributes)) {
            $attributes = Storefront::query()->where('code', $code)->first()?->getAttributes() ?? [];
            Cache::put("sf:code:{$code}", $attributes, $ttl);
        }
        if ($attributes === []) {
            throw new NotFoundHttpException('Storefront not found');
        }
        $row = [];
        foreach ($attributes as $k => $v) {
            $row[(string) $k] = $v;
        }
        /** @var Storefront $storefront */
        $storefront = (new Storefront)->newFromBuilder($row);
        if (! $storefront->is_active) {
            throw new NotFoundHttpException('Storefront not found');
        }

        $tags = new CacheTags;
        $tags->storefront((int) $storefront->id);
        $context = new StorefrontContext($storefront, self::locale($request, $storefront), $tags);

        app()->instance(StorefrontContext::class, $context);
        app()->instance(CacheTags::class, $tags);
        // Per-request memo holders: fresh instances every request so nothing leaks between requests.
        $cache = app(StorefrontCache::class);
        app()->instance(Lookups::class, new Lookups($cache, $context));
        app()->instance(CategoryTree::class, new CategoryTree($cache, $context));
        app()->setLocale($context->locale);

        $request->route()?->forgetParameter('storefront');

        return $next($request);
    }

    private static function locale(Request $request, Storefront $storefront): string
    {
        $wanted = $request->query('locale');
        $wanted = is_string($wanted) ? strtolower($wanted) : null;
        $locales = $storefront->getAttribute('locales');
        if ($wanted !== null && is_array($locales) && in_array($wanted, $locales, true)) {
            return $wanted;
        }
        $default = $storefront->getAttribute('default_locale');

        return is_string($default) && $default !== '' ? $default : 'ar';
    }
}
