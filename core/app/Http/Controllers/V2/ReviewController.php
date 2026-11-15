<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2;

use App\Domain\Reviews\Reviews;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use App\Support\Coerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * VERIFIED REVIEWS on the storefront (SEO Phase 5, 2026-11-14; {@see Reviews}).
 *
 *  GET  /api/v2/{storefront}/reviews/request/{token}  — the order's products behind a review link of THIS shop (404 for
 *       an unknown, expired or other shop's link). Never cached: the "already reviewed" marks change as the form is used.
 *  POST /api/v2/{storefront}/reviews  (multipart) — token, product_id, rating 1–5, body, name, locale, photos[] (up to
 *       three images): saved PENDING for the team's approval. 201 saved; 409 that product already has a review from
 *       this order; 404 the link is not valid here; 422 the form is wrong. Throttled per address (`rating`).
 * Under /api/v2/, so the API host's allow-list (runbook §4.1.1) is unchanged.
 */
final class ReviewController extends Controller
{
    public function request(StorefrontContext $ctx, string $token): JsonResponse
    {
        $data = strlen($token) === 40 ? Reviews::forToken($ctx->id(), $token) : null;

        return $data === null
            ? response()->json(['message' => 'This review link is not valid or has expired.'], 404)
            : response()->json(['data' => ['products' => $data['products']]], 200, ['Cache-Control' => 'no-store'], JSON_UNESCAPED_UNICODE);
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
            'saved' => response()->json(['status' => 'pending'], 201),
            'already' => response()->json(['message' => 'This product already has your review.'], 409),
            default => response()->json(['message' => 'This review link is not valid or has expired.'], 404),
        };
    }
}
