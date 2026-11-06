<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\StorefrontCampaigns;
use App\Storefront\StorefrontContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v2/{storefront}/campaigns — the storefront's LIVE timed campaigns (the twelve updates, Part 2,
 * 2026-11-05): Hot Deals and Flash Sale, each an object or null, with the server's clock.
 *
 * Not under `http.cache`, for the reason the offers and the payment methods are not: its ten minutes plus
 * an hour of stale would keep an ended sale on the page, and a countdown is the one thing on the site that
 * is wrong by the second. `private, max-age=0` — the CDN in front of the API hosts keeps one copy per URL,
 * and this answer is only true at the instant it is given.
 */
class CampaignController extends Controller
{
    public function index(StorefrontContext $ctx, StorefrontCampaigns $campaigns): JsonResponse
    {
        return response()->json(
            ['data' => $campaigns->build(), 'locale' => $ctx->locale],
            200,
            ['Cache-Control' => 'private, max-age=0'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
