<?php

namespace App\Http\Controllers\Feeds;

use App\Feeds\GoogleFeed;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /feeds/google/{storefront}/{token}.tsv — SERVES the file `feeds:google` wrote (2026-11-11,
 * D-9), never builds one. The Meta feed controller's rules exactly: the token compared in constant
 * time; an unconfigured storefront, a wrong token and a missing file are one plain 404 with
 * `X-Robots-Tag: noindex`; HTTP's own conditional rules for 304.
 */
final class GoogleFeedController extends Controller
{
    public function show(Request $request, string $storefront, string $token): Response
    {
        $cfg = GoogleFeed::config($storefront);
        if ($cfg === null || ! hash_equals($cfg['token'], $token)) {
            return self::notFound();
        }
        $path = GoogleFeed::path($storefront);
        if (! is_file($path)) {
            return self::notFound();
        }

        $headers = [
            'ETag' => '"'.sha1_file($path).'"',
            'Last-Modified' => gmdate('D, d M Y H:i:s', (int) filemtime($path)).' GMT',
            'Cache-Control' => 'no-cache',
            'X-Robots-Tag' => 'noindex',
        ];
        $notModified = new Response('', 200, $headers);
        if ($notModified->isNotModified($request)) {
            return $notModified;
        }

        return new BinaryFileResponse($path, 200, $headers + ['Content-Type' => 'text/tab-separated-values; charset=UTF-8']);
    }

    private static function notFound(): Response
    {
        return response('Not found', 404, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
    }
}
