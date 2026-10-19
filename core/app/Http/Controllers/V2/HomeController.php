<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\StorefrontHome;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v2/{storefront}/home — the home page's rails (the dashboard's, with compat's picks) as v2 card
 * rows, the deals band, the category entries and the home banner (V2-READ-HALF step 1, 2026-10-17).
 */
class HomeController extends Controller
{
    public function show(StorefrontHome $home): JsonResponse
    {
        return response()->json($home->build(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
