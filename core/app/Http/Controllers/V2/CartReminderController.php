<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2;

use App\Domain\Notifications\CartReminders;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The two shop-side halves of the abandoned-cart e-mail (2026-11-24). Under /api/v2/, so the API host's allow-list is
 * unchanged; both throttled per address (`cart-reminder`), both private and uncached.
 *
 *  POST checkout/contact  {email}  with the guest cart's X-Guest-Token - the address a GUEST typed at the checkout, kept
 *                         on that cart so its reminder can reach them (D1: no opt-in step; every reminder has a one-click
 *                         unsubscribe). A signed-in shopper's cart uses the account's address and needs none. Writes one
 *                         row per cart (the latest address wins); answers 204 whether or not there was a cart, so it tells
 *                         a caller nothing about anyone's cart.
 *  POST cart/restore      {r, s}  the e-mail button's signed link (D4): the guest cart's token when the link is the
 *                         reminder's own, under 14 days old, on this shop, and still a guest cart; 404 otherwise.
 */
final class CartReminderController extends Controller
{
    public function contact(Request $request, StorefrontContext $ctx): JsonResponse
    {
        $email = mb_strtolower(trim(Coerce::str($request->input('email'))));
        $token = $request->header(CartController::HEADER);
        $headers = ['Cache-Control' => 'private, max-age=0, no-store'];
        if (mb_strlen($email) > 191 || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! is_string($token)
            || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token) !== 1) {
            return response()->json(null, 204, $headers);
        }
        $cartId = Coerce::nint(DB::table('carts')->where('storefront_id', $ctx->id())->where('guest_token', $token)->whereNull('user_id')->value('id'));
        if ($cartId !== null) {
            DB::table('core_checkout_contacts')->updateOrInsert(['cart_id' => $cartId], [
                'storefront_id' => $ctx->id(), 'email' => $email, 'updated_at' => now(),
            ] + (DB::table('core_checkout_contacts')->where('cart_id', $cartId)->exists() ? [] : ['created_at' => now()]));
        }

        return response()->json(null, 204, $headers);
    }

    public function restore(Request $request, StorefrontContext $ctx): JsonResponse
    {
        $token = CartReminders::restoreToken($ctx->id(), Coerce::int($request->input('r')), Coerce::str($request->input('s')));
        $headers = ['Cache-Control' => 'private, max-age=0, no-store'];
        if ($token === null) {
            return response()->json(['data' => null], 404, $headers);
        }
        $items = DB::table('carts as c')->join('cart_items as i', 'i.cart_id', '=', 'c.id')->where('c.guest_token', $token)->count();

        return response()->json(['data' => ['guest_token' => $token, 'items' => $items]], 200, $headers);
    }
}
