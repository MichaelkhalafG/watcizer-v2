<?php

namespace App\Http\Controllers\Compat;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;

/**
 * Reverse proxy for every legacy `/api/*` path the core does not own yet (auth, offers, blogs,
 * wishlist, ratings, cart, orders, addresses, payment callback — CLEAN_CORE_STUDY §3.3 "proxy"
 * rows). Method, query string, headers and body pass through unchanged; `X-Forwarded-*` carry
 * the client address so the legacy host can rate-limit and log per client (its TrustProxies
 * must trust the core host for that — switch-night item).
 */
class ProxyController extends Controller
{
    /** Response headers copied back verbatim (the rest is hop-by-hop or host-specific). */
    private const PASS_HEADERS = [
        'content-type', 'cache-control', 'etag', 'expires', 'last-modified', 'location', 'vary',
        'set-cookie', 'www-authenticate', 'retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining',
    ];

    /** Request headers never forwarded. */
    private const DROP_HEADERS = ['host', 'content-length', 'connection', 'expect', 'transfer-encoding', 'accept-encoding'];

    public function __invoke(Request $request, string $path): Response
    {
        $base = rtrim(config()->string('compat.legacy_base'), '/');
        $url = $base.'/api/'.ltrim($path, '/');
        $query = $request->getQueryString();
        if ($query !== null && $query !== '') {
            $url .= '?'.$query;
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            if (in_array(strtolower($name), self::DROP_HEADERS, true)) {
                continue;
            }
            $headers[$name] = implode(', ', array_filter($values, 'is_string'));
        }
        $forwardedFor = $request->headers->get('X-Forwarded-For');
        $headers['X-Forwarded-For'] = trim(($forwardedFor !== null ? $forwardedFor.', ' : '').($request->ip() ?? ''), ', ');
        $headers['X-Forwarded-Proto'] = $request->getScheme();
        $headers['X-Forwarded-Host'] = $request->getHttpHost();

        $pending = Http::withHeaders($headers)
            ->withOptions(['http_errors' => false, 'allow_redirects' => false])
            ->timeout(config()->integer('compat.proxy_timeout'));

        $body = $request->getContent();
        if ($body !== '') {
            $pending = $pending->withBody($body, $request->headers->get('Content-Type') ?? 'application/octet-stream');
        }

        try {
            $upstream = $pending->send($request->getMethod(), $url);
        } catch (ConnectionException) {
            return new Response(json_encode(['message' => 'Legacy upstream unavailable'], JSON_THROW_ON_ERROR), 502, ['Content-Type' => 'application/json']);
        }

        $response = new Response($upstream->body(), $upstream->status());
        foreach ($upstream->headers() as $name => $values) {
            if (! in_array(strtolower($name), self::PASS_HEADERS, true) || ! is_array($values)) {
                continue;
            }
            $response->headers->set($name, array_values(array_filter($values, 'is_string')), strtolower($name) !== 'set-cookie');
        }
        $response->headers->set('X-Proxied-By', 'core');

        return $response;
    }
}
