<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2;

use App\Feeds\PublicFeed;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use App\Support\Coerce;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/v2/{storefront}/feed` — the shop's PUBLIC product feed (2026-11-17): the file `feeds:public` wrote, served
 * as it is (never built on a request). 404 until the first run. Each shop serves it at /feeds/products.json and links it
 * from /llms.txt. Under /api/v2/, so the API host's allow-list (runbook §4.1.1) is unchanged.
 */
final class FeedController extends Controller
{
    public function show(StorefrontContext $ctx): Response
    {
        $code = Coerce::str(DB::table('storefronts')->where('id', $ctx->id())->value('code'));
        $file = $code === '' ? '' : PublicFeed::path($code);
        if ($file === '' || ! is_file($file)) {
            throw new NotFoundHttpException('No feed yet.');
        }

        // a plain response, not a file response: the v2 group's http.cache middleware reads a Response
        return response((string) file_get_contents($file), 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'public, max-age=600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
