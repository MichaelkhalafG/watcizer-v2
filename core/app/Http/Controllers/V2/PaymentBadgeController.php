<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\PaymentBadges;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v2/{storefront}/payment-badges — the badges the storefront may show in its footer, on the cart
 * and at checkout (the twelve updates, Part 7, 2026-11-05): one per payment method the checkout OFFERS on
 * this storefront, in the checkout's order, each with the picture the team uploaded or `image: null`.
 *
 * `image_path` (the stored file name) stays on the server: the storefront needs the address, not the column.
 *
 * Beside `payment-methods`, outside `http.cache`, for that endpoint's reason: a method switched off in the
 * dashboard must stop being advertised within a minute, not after ten minutes plus an hour of stale. The
 * browser may keep it for that one minute — it is on every page, in the footer.
 */
class PaymentBadgeController extends Controller
{
    public function index(StorefrontContext $ctx, PaymentBadges $badges): JsonResponse
    {
        $data = array_map(
            fn (array $badge): array => ['method' => $badge['method'], 'label' => $badge['label'], 'image' => $badge['image']],
            $badges->forStorefront($ctx->id()),
        );

        return response()->json(['data' => $data], 200, ['Cache-Control' => 'private, max-age=60'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
