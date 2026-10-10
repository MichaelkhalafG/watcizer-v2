<?php

namespace App\Http\Controllers\Feeds;

use App\Feeds\MetaFeed;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /feeds/meta/{storefront}/{token}.csv — SERVES the file `feeds:meta` wrote. It never builds
 * one: a missing file is a 404 until the next scheduled run (2026-10-10).
 *
 * The token is the only credential (Meta's scheduled fetch cannot send our Api-Code header), compared
 * in constant time. Unconfigured storefront, wrong token, no file: one and the same 404, so the
 * answer never says which part was wrong — and never falls back to another storefront's feed.
 *
 * The 404 is plain text with `X-Robots-Tag: noindex`: that is how a probe tells core's 404 from the
 * host's (Apache's is an HTML page without the header) — the allow-list line is the one place this
 * feed can fail silently (runbook §4.1.1).
 */
final class MetaFeedController extends Controller
{
    public function show(Request $request, string $storefront, string $token): Response
    {
        $cfg = MetaFeed::config($storefront);
        if ($cfg === null || ! hash_equals($cfg['token'], $token)) {
            return self::notFound();
        }
        $path = MetaFeed::path($storefront);
        if (! is_file($path)) {
            return self::notFound();
        }

        $headers = [
            'ETag' => '"'.sha1_file($path).'"',
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime($path)).' GMT',
            'Cache-Control' => 'no-cache',
            'X-Robots-Tag' => 'noindex',
        ];
        // HTTP's own rules, not a string compare (2026-10-10): If-None-Match compared WEAKLY (a W/ form
        // matches — Hostinger's CDN weakens ETags it re-encodes), a list or `*`, and If-Modified-Since
        // when there is no If-None-Match. The hand-written exact compare answered 200 to both.
        $notModified = new Response('', 200, $headers);
        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        return new BinaryFileResponse($path, 200, $headers + ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function notFound(): Response
    {
        return response('Not found', 404, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }
}
