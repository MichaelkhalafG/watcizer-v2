<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of the legacy `CheckApiMiddleware`: the storefront sends the public key in `Api-Code`;
 * anything else answers the legacy body `{"error":"Unauthorized"}` with 401 (byte-identical).
 *
 * A GET or HEAD may also carry it as `?api_code=` (2026-09-27). A custom request header makes the
 * browser send a CORS preflight before every cross-origin request, and the catalogue reads the
 * storefront repeats per filter tap (`catalog/listing`, `catalog/cards`) paid a full extra round
 * trip for it. The key is PUBLIC — it ships in the storefront's JavaScript bundle — so the query
 * string costs no secrecy; it does show in access logs. Writes still need the header.
 */
class CheckApiCode
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config()->string('compat.api_key');

        if ($expected !== '' && $request->header('Api-Code') === $expected) {
            return $next($request);
        }
        if ($expected !== '' && in_array($request->method(), ['GET', 'HEAD'], true) && $request->query('api_code') === $expected) {
            return $next($request);
        }

        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
