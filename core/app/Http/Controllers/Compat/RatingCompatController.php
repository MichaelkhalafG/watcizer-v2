<?php

namespace App\Http\Controllers\Compat;

use App\Compat\CompatServices;
use App\Domain\Catalog\ProductRatings;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CompatAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/add_product_rating  {product_id, rating 1–5, comment?}  — behind `compat.auth` (B1, 2026-10-01).
 *
 * The storefront's review form has posted here since before the cutover; with the legacy proxy
 * retired it fell through to it and failed. The customer is the bearer token's; a `user_id` the
 * form also sends is ignored. The legacy answer `{success, message}` is kept for the form. 404 for a
 * product this shop does not show, 422 for a bad rating, 401 without a token. Throttled per IP
 * (`throttle:rating`). On the API host's allow-list.
 */
final class RatingCompatController extends Controller
{
    public function __construct(private readonly CompatServices $compat, private readonly ProductRatings $ratings) {}

    public function add(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['nullable', 'string', 'max:'.ProductRatings::COMMENT_MAX],
        ]);

        $status = $this->ratings->rate(
            $this->compat->storefrontId,
            $request->integer('product_id'),
            CompatAuth::id($request),
            $request->integer('rating'),
            $request->filled('comment') ? $request->string('comment')->toString() : null,
        );
        if ($status === 'not_found') {
            return response()->json(['success' => false, 'message' => 'Product not found'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Rating added successfully', 'status' => $status]);
    }
}
