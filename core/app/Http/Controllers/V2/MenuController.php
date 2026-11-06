<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use App\Storefront\StorefrontMenu;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/v2/{storefront}/menu — the mega menu's data (the twelve updates, Part 4, 2026-11-05): the
 * top-level categories with their sub-categories as picture tiles, popular brands and a featured product;
 * the same per gender; the brands; and whether anything is on sale. See {@see StorefrontMenu}.
 *
 * Under `http.cache` like the tree it is built from: it changes when the catalogue does, not by the minute.
 */
class MenuController extends Controller
{
    public function show(StorefrontContext $ctx, StorefrontMenu $menu): JsonResponse
    {
        return response()->json(['data' => $menu->build(), 'locale' => $ctx->locale], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
