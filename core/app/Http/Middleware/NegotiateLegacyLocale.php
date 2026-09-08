<?php

namespace App\Http\Middleware;

use App\Compat\LegacyLocaleNegotiator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the application locale the way the legacy host does — negotiated from Accept-Language
 * on every request (mcamara/laravel-localization, ported in LegacyLocaleNegotiator).
 *
 * Applied ONLY to the compat endpoints whose legacy cache holds Eloquent models and therefore
 * still localises the appended attributes at serialisation time on every request:
 * `catalog/meta` (Cache::remember of model collections) and `show_shipping_city`. `all_product`
 * caches `->toArray()` and is locale-blind on the legacy host → pinned to EN instead (D-13).
 * Verified against the running legacy app with a file cache on 2026-09-08 (flag F-18).
 */
class NegotiateLegacyLocale
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<string, string> $supported */
        $supported = config()->array('compat.locales');
        $locale = LegacyLocaleNegotiator::negotiate($request->header('Accept-Language'), $supported, config()->string('compat.default_locale'));
        app()->setLocale($locale);

        return $next($request);
    }
}
