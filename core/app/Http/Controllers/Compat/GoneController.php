<?php

namespace App\Http\Controllers\Compat;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Legacy paths the storefront never calls (CLEAN_CORE_STUDY §3.3, last row): not reimplemented,
 * answered with 410 Gone so a stray consumer fails loudly instead of reading frozen legacy data.
 */
class GoneController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['message' => 'Gone: this legacy endpoint is retired; use /api/v2/{storefront}/… (CLEAN_CORE_STUDY §3.3).'], 410);
    }
}
