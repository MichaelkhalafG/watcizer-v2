<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2;

use App\Domain\Reviews\GuestReviews;
use App\Domain\Reviews\Reviews;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use App\Support\Coerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * VERIFIED REVIEWS on the storefront (SEO Phase 5, 2026-11-14; {@see Reviews}).
 *
 *  GET  /api/v2/{storefront}/reviews/request/{token}  — the order's products behind a review link of THIS shop (404 for
 *       an unknown, expired or other shop's link). Never cached: the "already reviewed" marks change as the form is used.
 *  POST /api/v2/{storefront}/reviews  (multipart) — token, product_id, rating 1–5, body, name, locale, photos[] (up to
 *       three images): saved PENDING for the team's approval. 201 saved; 409 that product already has a review from
 *       this order; 404 the link is not valid here; 422 the form is wrong. Throttled per address (`rating`).
 * Under /api/v2/, so the API host's allow-list (runbook §4.1.1) is unchanged.
 *
 * SINCE 2026-11-17 (Michael's decisions): a review is LIVE at once (201 {status: live}) unless a link or a phone number
 * holds it for the team (201 {status: held}); both answers carry the shop's Google review buttons (`google`).
 *  POST /api/v2/{storefront}/reviews/guest — from a product page, no login: product_id, name, email, rating, body,
 *       locale, turnstile (the invisible widget's token), device (the browser's random id). 201 live | held; 409 this
 *       e-mail already reviewed this product; 429 too many; 422 the form is wrong or the check failed; 404 not on this
 *       shop. The same answer whether or not the e-mail has an account ({@see GuestReviews}).
 *  GET  /api/v2/{storefront}/review-link/{key}?count=1 — a branch's in-store address: its Google review link, the scan
 *       counted (count=0 for a link preview). 404 for no such branch.
 */
final class ReviewController extends Controller
{
    public function request(StorefrontContext $ctx, string $token): JsonResponse
    {
        $data = strlen($token) === 40 ? Reviews::forToken($ctx->id(), $token) : null;

        return $data === null
            ? response()->json(['message' => 'This review link is not valid or has expired.'], 404)
            : response()->json(['data' => ['products' => $data['products'], 'google' => Reviews::googleLinks($ctx->id())]], 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request, StorefrontContext $ctx): JsonResponse
    {
        $data = Coerce::arr($request->validate([
            'token' => ['required', 'string', 'size:40'],
            'product_id' => ['required', 'integer', 'min:1'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'min:'.Coerce::int(config('reviews.body_min', 10)), 'max:'.Coerce::int(config('reviews.body_max', 2000))],
            'name' => ['required', 'string', 'max:60'],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'photos' => ['nullable', 'array', 'max:'.Coerce::int(config('reviews.max_photos', 3))],
            'photos.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.Coerce::int(config('reviews.photo_max_kb', 8192))],
        ]));
        $photos = array_values(array_filter(Coerce::arr($request->file('photos') ?? []), fn ($f): bool => $f instanceof UploadedFile));
        $result = Reviews::submit($ctx->id(), Coerce::str($data['token']), Coerce::int($data['product_id']), Coerce::int($data['rating']),
            Coerce::str($data['body']), Coerce::str($data['name']), Coerce::str($data['locale'] ?? 'ar'), $photos);

        return match ($result) {
            'saved' => response()->json(['status' => 'live', 'google' => Reviews::googleLinks($ctx->id())], 201, [], JSON_UNESCAPED_UNICODE),
            'held' => response()->json(['status' => 'held', 'google' => Reviews::googleLinks($ctx->id())], 201, [], JSON_UNESCAPED_UNICODE),
            'already' => response()->json(['message' => 'This product already has your review.'], 409),
            default => response()->json(['message' => 'This review link is not valid or has expired.'], 404),
        };
    }

    /** A review from a product page, without logging in (2026-11-17). */
    public function guest(Request $request, StorefrontContext $ctx): JsonResponse
    {
        $data = Coerce::arr($request->validate([
            'product_id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'min:2', 'max:60'],
            'email' => ['required', 'string', 'email:rfc', 'max:191'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'min:'.Coerce::int(config('reviews.body_min', 10)), 'max:'.Coerce::int(config('reviews.body_max', 2000))],
            'locale' => ['nullable', 'string', 'in:ar,en'],
            'turnstile' => ['nullable', 'string', 'max:4096'],
            'device' => ['nullable', 'string', 'max:64'],
        ]));
        $result = GuestReviews::submit($ctx->id(), Coerce::int($data['product_id']), Coerce::str($data['name']), Coerce::str($data['email']),
            Coerce::int($data['rating']), Coerce::str($data['body']), Coerce::str($data['locale'] ?? 'ar'), Coerce::str($data['turnstile'] ?? ''),
            Coerce::str($data['device'] ?? ''), (string) $request->ip());

        return match ($result) {
            'live', 'held' => response()->json(['status' => $result, 'google' => Reviews::googleLinks($ctx->id())], 201, [], JSON_UNESCAPED_UNICODE),
            'already' => response()->json(['message' => 'There is already a review from this e-mail for this product.'], 409),
            'limited' => response()->json(['message' => 'Too many reviews for now. Please try again later.'], 429),
            'verification' => response()->json(['message' => 'We could not check the form. Please try again.', 'errors' => ['turnstile' => ['failed']]], 422),
            default => response()->json(['message' => 'This product is not on this shop.'], 404),
        };
    }

    /** A branch's in-store review address: its Google review link, the scan counted unless count=0 (2026-11-17). */
    public function link(Request $request, StorefrontContext $ctx, string $key): JsonResponse
    {
        $url = Reviews::scan($ctx->id(), $key, $request->query('count', '1') !== '0');

        return $url === null
            ? response()->json(['message' => 'No such branch.'], 404, ['Cache-Control' => 'no-store'])
            : response()->json(['data' => ['url' => $url]], 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_SLASHES);
    }

    /**
     * One page of a product's approved reviews (2026-11-18: the Reviews tab's "Show more" on the product page). Only for a
     * product this shop shows; page 1 is in the product's own payload, so the tab asks for page 2 onwards. Read only.
     */
    public function forProduct(Request $request, StorefrontContext $ctx, int $product): JsonResponse
    {
        $shown = DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $ctx->id())->where('sp.product_id', $product)->where('sp.is_visible', true)
            ->where('p.is_active', true)->whereNull('p.deleted_at')->exists();
        if (! $shown) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(
            ['data' => Reviews::approvedPage($ctx->id(), $product, Coerce::int($request->query('page', '2')))],
            200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}
