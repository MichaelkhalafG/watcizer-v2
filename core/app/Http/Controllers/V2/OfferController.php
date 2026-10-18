<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use App\Storefront\StorefrontOffers;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v2/{storefront}/offers` — the offers (the dashboard's promotions) this storefront is running now
 * ({@see StorefrontOffers}; round 5, 2026-10-18). An empty `data` is an honest answer: no offer is running.
 *
 * Not under `http.cache`, and private like `payment-methods`: an offer switched off in the dashboard must leave
 * the page at once, and the API host's CDN keeps one copy per URL whatever `Vary: Origin` says (routes/api.php).
 */
class OfferController extends Controller
{
    public function index(StorefrontContext $ctx, StorefrontOffers $offers): JsonResponse
    {
        return response()->json(
            ['data' => $offers->running($ctx->id(), now()), 'locale' => $ctx->locale],
            200,
            ['Cache-Control' => 'private, max-age=0'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
