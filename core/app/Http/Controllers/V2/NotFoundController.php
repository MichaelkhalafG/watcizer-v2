<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2;

use App\Domain\Seo\NotFoundLog;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use App\Support\Coerce;
use App\Support\StorefrontServerKey;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `POST /api/v2/{storefront}/not-found` — a shop's server reports a page it answered 404 for (SEO Phase 1, 2026-11-14;
 * {@see NotFoundLog}). ONLY the shop's server: the request must carry the storefront server key (a secret in the two
 * servers' environments), so a browser cannot fill the dashboard's list. 204 either way once the key matches; 403
 * without it. Under /api/v2/, so the API host's allow-list (runbook §4.1.1) is unchanged.
 *
 * Body: `path` (the address, query dropped here), `referer` (optional), `user_agent` (optional; decides bot or not).
 */
final class NotFoundController extends Controller
{
    public function store(Request $request, StorefrontContext $ctx, NotFoundLog $log): Response
    {
        if (! StorefrontServerKey::matches($request)) {
            return response()->noContent(403);
        }
        $path = $request->input('path');
        if (is_string($path) && strlen($path) <= 2000) {
            $referer = $request->input('referer');
            $log->record(
                $ctx->id(),
                $path,
                is_string($referer) && strlen($referer) <= 2000 ? $referer : null,
                NotFoundLog::isBot(mb_substr(Coerce::str($request->input('user_agent')), 0, 512)),
            );
        }

        return response()->noContent();
    }
}
