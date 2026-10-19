<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\StorefrontCheckout;
use App\Support\Coerce;
use App\Support\LegacyJwt;
use App\Support\Val;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/v2/{storefront}/orders` — the v2 checkout of the storefront in the path (B7 step S2, round 6,
 * 2026-10-18); the work is {@see StorefrontCheckout}. The shopper is found as the v2 cart finds them (a valid bearer
 * token, else the `X-Guest-Token` the cart minted), and the order is made from THAT shopper's cart on THIS storefront.
 *
 * Body: `total` (what the shopper was shown — the guard), `payment_method` (cash | card | paymob | whatsapp) and
 * `payment_method_id` (a v2 method id, optional for cash), and either `address_id` — which must be the buyer's own
 * (the account's, or one this guest session created; a foreign id and a missing one answer the same) — or a new
 * address (`address_line`, `shipping_city_id`, `phone`); `note`, `guest_name`, `guest_phone`, `guest_email`.
 *
 * Never cached. Throttled on its own limiter (`v2-order`, the same numbers as compat's `add-order`), so Brand
 * Fashion's orders never spend Watchizer's per-IP allowance.
 */
class OrderController extends Controller
{
    public function store(Request $request, StorefrontCheckout $checkout): JsonResponse
    {
        $userId = LegacyJwt::userId($request);
        $token = $request->header(CartController::HEADER);
        $guestToken = $userId === null && is_string($token) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token) === 1 ? $token : null;
        if ($userId === null && $guestToken === null) {
            return $this->respond(['error' => 'cart_empty'], 422);
        }

        try {
            $data = Validator::make($request->all(), [
                'address_id' => ['required_without:address_line', 'nullable', 'integer',
                    Rule::exists('addresses', 'id')->where(function (Builder $query) use ($userId, $guestToken): void {
                        if ($userId !== null) {
                            $query->where('user_id', $userId);
                        } else {
                            $query->whereNull('user_id')->where('guest_token', $guestToken);
                        }
                    }),
                ],
                'address_line' => 'required_without:address_id|nullable|string|min:3|max:500',
                'shipping_city_id' => 'required_without:address_id|nullable|integer|exists:shipping_cities,id',
                'phone' => 'required_without:address_id|nullable|string|min:7|max:20',
                'total' => 'required|numeric|min:0',
                'payment_method' => 'required|in:cash,card,paymob,whatsapp',
                'payment_method_id' => 'nullable',
                'note' => 'nullable|string|max:1000',
                'guest_name' => 'nullable|string|max:255',
                'guest_phone' => 'nullable|string|max:20',
                'guest_email' => 'nullable|email|max:255',
            ])->validate();
        } catch (ValidationException $e) {
            return $this->respond(['error' => 'invalid', 'fields' => array_keys($e->errors())], 422);
        }

        // The fields the checkout reads, typed one by one (never the raw body).
        $fields = [
            'payment_method' => Val::str($data, 'payment_method'),
            'payment_method_id' => Coerce::nstr($request->input('payment_method_id')),
            'total' => Val::str($data, 'total'),
            'address_line' => Val::nstr($data, 'address_line'),
            'shipping_city_id' => Val::nint($data, 'shipping_city_id'),
            'phone' => Val::nstr($data, 'phone'),
            'note' => Val::nstr($data, 'note'),
            'guest_name' => Val::nstr($data, 'guest_name'),
            'guest_phone' => Val::nstr($data, 'guest_phone'),
            'guest_email' => Val::nstr($data, 'guest_email'),
        ];
        $result = $checkout->place($request, $userId, $guestToken, Val::nint($data, 'address_id'), $fields);

        return $this->respond($result['body'], $result['status']);
    }

    /** @param  array<string, mixed>  $body */
    private function respond(array $body, int $status): JsonResponse
    {
        return response()->json($body, $status, ['Cache-Control' => 'private, max-age=0, no-store'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
