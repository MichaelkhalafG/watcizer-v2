<?php

namespace App\Http\Controllers\V2;

use App\Compat\CompatAccount;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use App\Support\LegacyJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /api/v2/{storefront}/me/orders` — the signed-in customer's orders ON THIS STOREFRONT (the night run of
 * 2026-10-19, audit item a1).
 *
 * Compat's `me/orders` — Watchizer's live account page — lists every order the user has on every shop: `users` are
 * shared across storefronts and its query has no storefront condition, so a customer of both shops would see both
 * shops' orders on either. Compat is left exactly as it is; this read is the storefront-scoped one Brand Fashion's
 * account page uses. It takes compat's own builder, unedited, for the shape the account page already draws, and
 * keeps the orders whose `storefront_id` is the path's. An order with NO `storefront_id` (written before the column
 * existed, or by a path that still leaves it null — backlog: orders.storefront_id NOT NULL) belongs to the default
 * storefront, the only shop that existed when it was written, so it is listed there and nowhere else.
 *
 * Private and never cached, like the cart: one customer's orders are never served to another.
 */
class AccountController extends Controller
{
    public function orders(Request $request, StorefrontContext $ctx, CompatAccount $account): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, max-age=0, no-store'];
        $userId = LegacyJwt::userId($request);
        if ($userId === null) {
            return response()->json(['error' => 'unauthenticated'], 401, $headers);
        }
        $storefrontId = $ctx->id();
        $isDefault = $storefrontId === config()->integer('compat.storefront_id');
        $orders = array_values(array_filter(
            $account->orders($userId, $ctx->locale),
            fn (array $order): bool => ($order['storefront_id'] ?? null) === $storefrontId
                || (($order['storefront_id'] ?? null) === null && $isDefault),
        ));

        return response()->json(['data' => $orders], 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
