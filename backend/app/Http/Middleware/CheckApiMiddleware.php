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

        if ($request->header('Api-Code') == $apiPassword) {
            return $next($request);
        } else {
            return response(['error' => 'Unauthorized'] , 401);
        }
    }
}
