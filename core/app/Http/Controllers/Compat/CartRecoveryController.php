<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compat;

use App\Compat\CompatServices;
use App\Domain\Orders\OrderRecovery;
use App\Support\Coerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/cart/recover {token}` — what the storefront's /cart/recover page needs to bring back a
 * card order that expired unpaid (2026-10-05). See `OrderRecovery` for the token and, above all, for
 * EXACTLY what a valid token exposes; this controller adds nothing to that.
 *
 * Answers: 200 with the payload; 410 `expired`; 409 `reordered` (the same shopper placed an order
 * since, and it went through — `OrderRecovery::reorderedSince`); 404 `invalid` (forged, malformed,
 * another shop's order, or an order that is no longer the cancelled card order the link was made
 * for). Never
 * cached, throttled per IP. It writes nothing: the storefront rebuilds the cart through the ordinary
 * add-to-cart route, which re-checks stock and price like any other add.
 */
final class CartRecoveryController
{
    public function __construct(private readonly CompatServices $compat) {}

    public function __invoke(Request $request): JsonResponse
    {
        $verdict = OrderRecovery::verify(Coerce::str($request->input('token')));
        if (isset($verdict['refused'])) {
            return self::refuse($verdict['refused']);
        }
        $payload = OrderRecovery::payload($verdict['order'], $this->compat->cart, $this->compat->storefrontId);
        if ($payload === null) {
            return self::refuse('invalid');
        }
        // After the order is known to be this shop's cancelled card order: the shopper ordered again
        // since, and it went through — say so instead of rebuilding a cart they already bought.
        if (OrderRecovery::reorderedSince($verdict['order'])) {
            return self::refuse('reordered');
        }

        return response()->json(['recovery' => $payload])->header('Cache-Control', 'private, no-store');
    }

    private static function refuse(string $reason): JsonResponse
    {
        $status = match ($reason) {
            'expired' => 410,
            'reordered' => 409,
            default => 404,
        };

        return response()->json(['refused' => $reason], $status)->header('Cache-Control', 'private, no-store');
    }
}
