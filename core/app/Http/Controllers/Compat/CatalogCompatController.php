<?php

namespace App\Http\Controllers\Compat;

use App\Compat\CompatServices;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The legacy read endpoints the Watchizer storefront calls today (CLEAN_CORE_STUDY §3.3 "move"
 * rows), answered from the clean tables with the legacy JSON, byte for byte.
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
        return response()->json($this->compat->catalog->allProduct(app()->getLocale()));
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
        $payload = ctype_digit($id) ? $this->compat->detail->byId((int) $id) : null;
        if ($payload === null) {
            // The legacy findOrFail() message, verbatim (the storefront only reads the status).
            throw new NotFoundHttpException("No query results for model [App\\Models\\Product] {$id}");
        }

        return response()->json($payload);
    }

    public function showByName(string $name): JsonResponse
    {
        $payload = $this->compat->detail->byName($name);
        if ($payload === null) {
            throw new NotFoundHttpException('No query results for model [App\\Models\\Product].');
        }

        return response()->json($payload);
    }
}
