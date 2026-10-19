<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\StorefrontCart;
use App\Support\LegacyJwt;
use App\Support\Val;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * The v2 cart of the storefront in the path (B7 step S1, round 5, 2026-10-18) — {@see StorefrontCart}.
 *
 *   GET    /api/v2/{storefront}/cart                the cart, re-priced and re-checked now
 *   POST   /api/v2/{storefront}/cart/items          {product_id, variant_id?, bucket?, quantity} — sets that line
 *   PATCH  /api/v2/{storefront}/cart/items/{item}   {quantity}
 *   DELETE /api/v2/{storefront}/cart/items/{item}
 *
 * The shopper is found the way compat finds them (`CompatGuestCart`): a valid bearer token names a signed-in
 * customer; otherwise the `X-Guest-Token` header names a guest cart, and the first add mints one (a UUID, echoed
 * back on the response). An invalid or expired token falls through to the guest — as on compat, so an expired
 * session keeps its cart. Nothing here is cached, by anybody: `private, max-age=0` (one shopper's cart must never
 * be served to another — the reason compat's cart routes sit in their own group).
 *
 * Refusals are 422 with a stable code (`error`) the storefront can word in either language; a line id that is not
 * in THIS shopper's cart on THIS storefront is a 404.
 */
class CartController extends Controller
{
    public const HEADER = 'X-Guest-Token';

    public function show(Request $request, StorefrontCart $carts): JsonResponse
    {
        [$userId, $token] = $this->identity($request);

        return $this->respond($carts->payload($carts->find($userId, $token)), $userId === null ? $token : null);
    }

    public function store(Request $request, StorefrontCart $carts): JsonResponse
    {
        $data = Validator::make($request->all(), [
            'product_id' => 'required|integer|min:1',
            'variant_id' => 'nullable|integer|min:1',
            'bucket' => 'nullable|in:'.implode(',', StorefrontCart::BUCKETS),
            'quantity' => 'required|integer|min:1|max:'.StorefrontCart::MAX_QUANTITY,
        ])->validate();
        $productId = Val::int($data, 'product_id');
        $variantId = Val::nint($data, 'variant_id');
        $bucket = Val::nstr($data, 'bucket');
        $quantity = Val::int($data, 'quantity');

        [$userId, $token] = $this->identity($request);
        $check = $carts->check($productId, $variantId, $bucket, $quantity);
        if ($check['refusal'] !== null) {
            return $this->refuse($check['refusal'], $userId === null ? $token : null);
        }
        $token ??= (string) Str::uuid();
        $cart = $carts->resolve($userId, $token);
        $refusal = $carts->set($cart, $productId, $variantId, $check['bucket'], $quantity);
        if ($refusal !== null) {
            return $this->refuse($refusal, $userId === null ? $token : null);
        }

        return $this->respond($carts->payload($cart), $userId === null ? $token : null);
    }

    public function update(Request $request, StorefrontCart $carts, int $item): JsonResponse
    {
        $data = Validator::make($request->all(), ['quantity' => 'required|integer|min:1|max:'.StorefrontCart::MAX_QUANTITY])->validate();
        [$userId, $token] = $this->identity($request);
        $cart = $carts->find($userId, $token);
        if ($cart === null) {
            return $this->refuse('not_found', $userId === null ? $token : null);
        }
        $refusal = $carts->update($cart, $item, Val::int($data, 'quantity'));
        if ($refusal !== null) {
            return $this->refuse($refusal, $userId === null ? $token : null);
        }

        return $this->respond($carts->payload($cart), $userId === null ? $token : null);
    }

    public function destroy(Request $request, StorefrontCart $carts, int $item): JsonResponse
    {
        [$userId, $token] = $this->identity($request);
        $cart = $carts->find($userId, $token);
        if ($cart === null || ! $carts->remove($cart, $item)) {
            return $this->refuse('not_found', $userId === null ? $token : null);
        }

        return $this->respond($carts->payload($cart), $userId === null ? $token : null);
    }

    /** @return array{0: ?int, 1: ?string} the signed-in customer's id, or the guest token (either may be absent) */
    private function identity(Request $request): array
    {
        $userId = LegacyJwt::userId($request);
        if ($userId !== null) {
            return [$userId, null];
        }
        $token = $request->header(self::HEADER);
        // A guest token is a UUID the server minted; anything else is not one of ours and is not looked up.
        $token = is_string($token) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $token) === 1 ? $token : null;

        return [null, $token];
    }

    /** @param  array<string, mixed>  $payload */
    private function respond(array $payload, ?string $guestToken): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, max-age=0, no-store'];
        if ($guestToken !== null) {
            $headers[self::HEADER] = $guestToken;
        }

        return response()->json(['data' => $payload], 200, $headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function refuse(string $code, ?string $guestToken): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, max-age=0, no-store'];
        if ($guestToken !== null) {
            $headers[self::HEADER] = $guestToken;
        }

        return response()->json(['error' => $code], $code === 'not_found' ? 404 : 422, $headers);
    }

}
