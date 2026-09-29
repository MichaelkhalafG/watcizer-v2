<?php

namespace App\Http\Controllers\V2;

use App\Domain\Payment\CheckoutMethods;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v2/{storefront}/payment-methods` — what the checkout may offer (batch 1, 2026-09-26).
 *
 * Only rows a shopper can actually pay with (CheckoutMethods), both labels, and each row's order
 * limits so the storefront can show a method as unavailable-with-a-reason instead of letting the
 * order be refused. Cash on delivery is NOT driven by this list: the storefront always offers it.
 *
 * Cached for 60 seconds, with no stale window, instead of v2's usual ten minutes plus an hour of
 * stale: switching a method off in the dashboard (Apple Pay is switched exactly this way) has to
 * reach the checkout within a minute, and `add_order` refuses a switched-off method regardless.
 * The browser keeps nothing (`max-age=0`): after a refusal the checkout refetches the list, and a
 * browser-cached copy would show the shopper the method they were just refused.
 *
 * And no SHARED cache either (2026-09-29): this was `public, s-maxage=60`, and Hostinger's CDN on
 * the API host keeps one copy per URL regardless of `Vary: Origin` — a copy filled by a request with
 * no or another Origin carries the wrong CORS header, and the checkout's method list then fails in
 * the browser (see routes/api.php, the compat cache groups). `add_order` still refuses a switched-off
 * method whatever any list said.
 */
class PaymentMethodController extends Controller
{
    public function index(StorefrontContext $ctx, CheckoutMethods $methods): JsonResponse
    {
        return response()->json(
            ['data' => $methods->offered($ctx->id())],
            200,
            ['Cache-Control' => 'private, max-age=0'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
