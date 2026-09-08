<?php

namespace App\Http\Controllers\Compat;

use App\Compat\CompatServices;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The legacy read endpoints the Watchizer storefront calls today (CLEAN_CORE_STUDY §3.3 "move"
 * rows), answered from the clean tables with the legacy JSON, byte for byte.
 *
 * Appended translated attributes: `all_product` is pinned to `compat.pinned_locale` (EN) — the
 * legacy host caches its `->toArray()` and is locale-blind (D-13); `catalog/meta` and
 * `show_shipping_city` follow the negotiated request locale — the legacy host caches Eloquent
 * models there and serialises them per request (flag F-18). Verified live 2026-09-08.
 */
class CatalogCompatController extends Controller
{
    public function __construct(private readonly CompatServices $compat) {}

    public function meta(): JsonResponse
    {
        return response()->json($this->compat->meta->build(app()->getLocale()));
    }

    public function allProduct(): JsonResponse
    {
        return response()->json($this->compat->catalog->allProduct($this->locale()));
    }

    public function allProductImage(): JsonResponse
    {
        return response()->json($this->compat->catalog->allProductImage());
    }

    public function allProductRating(): JsonResponse
    {
        return response()->json($this->compat->catalog->allProductRating());
    }

    public function shippingCities(): JsonResponse
    {
        return response()->json($this->compat->catalog->shippingCities(app()->getLocale()));
    }

    public function show(string $id): JsonResponse
    {
        $productId = self::legacyId($id);
        $payload = $productId === null ? null : $this->compat->detail->byId($productId);
        if ($payload === null) {
            // Generic body on purpose (review 🟡-11): no model class names, no ids echoed back.
            throw new NotFoundHttpException('Not Found');
        }

        return response()->json($payload);
    }

    public function showByName(string $name): JsonResponse
    {
        $payload = $this->compat->detail->byName($name);
        if ($payload === null) {
            throw new NotFoundHttpException('Not Found');
        }

        return response()->json($payload);
    }

    /**
     * The legacy `findOrFail($id)` compares the raw path segment with MySQL's loose string→number
     * coercion: `4.0`, `04`, ` 4` and `4abc` all match product 4, `4.5` and `abc` match nothing
     * (review 🟠-4). Reproduced with PHP's numeric-prefix cast; only positive integers resolve.
     */
    public static function legacyId(string $raw): ?int
    {
        $n = (float) $raw;
        if ($n <= 0 || $n !== floor($n) || $n > PHP_INT_MAX) {
            return null;
        }

        return (int) $n;
    }

    private function locale(): string
    {
        return config()->string('compat.pinned_locale');
    }
}
