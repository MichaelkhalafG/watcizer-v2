<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of the legacy `CheckApiMiddleware`: the storefront sends the public key in `Api-Code`;
 * anything else answers the legacy body `{"error":"Unauthorized"}` with 401 (byte-identical).
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

        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
