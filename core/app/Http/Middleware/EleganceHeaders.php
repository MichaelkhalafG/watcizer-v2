<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Elegance page's response headers (2026-11-21) - on its two routes only (GET / and GET /ar), never global, so the
 * dashboard, the API and every other response keep exactly the headers they have.
 *
 * The page loads nothing but its own stylesheet, fonts and images: no script may run at all (default-src 'none'), it
 * may not be framed, it sends no referrer beyond the origin to other sites, and asks for no camera, microphone or
 * location. No HSTS: the host does not send it today (Michael's brief: only if it already does). PHP's own
 * X-Powered-By is removed from these responses. Cacheable for an hour: the page is the same for everyone and carries
 * no cookie.
 */
final class EleganceHeaders
{
    public const CSP = "default-src 'none'; style-src 'self'; img-src 'self'; font-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Content-Security-Policy', self::CSP);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Cache-Control', 'public, max-age=3600');
        $response->headers->remove('X-Powered-By');
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
