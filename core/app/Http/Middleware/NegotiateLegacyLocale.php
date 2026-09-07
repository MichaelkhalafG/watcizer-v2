<?php

namespace App\Http\Middleware;

use App\Compat\LegacyLocaleNegotiator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the application locale the way the legacy host does for every request (see
 * LegacyLocaleNegotiator). Compat routes only; v2 routes never read Accept-Language.
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
