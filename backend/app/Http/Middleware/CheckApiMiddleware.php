<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckApiMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $apiPassword = config('services.public_api_key');

        // Fail CLOSED on an empty key (security audit 2026-09-23, Finding 4). With `==`, an unset
        // key (null) matched a request with no Api-Code header (null), and '' matched it too —
        // every CheckApi route opened to a header-less caller. hash_equals is hygiene on top.
        // Mirrors core's CheckApiCode. Minimal on purpose: this app is retired after the cutover.
        if (is_string($apiPassword) && $apiPassword !== ''
            && hash_equals($apiPassword, (string) $request->header('Api-Code'))) {
            return $next($request);
        } else {
            return response(['error' => 'Unauthorized'] , 401);
        }
    }
}
