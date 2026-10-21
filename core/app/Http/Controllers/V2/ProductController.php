<?php

namespace App\Http\Controllers\V2;

use App\Http\Controllers\Controller;
use App\Storefront\ProductDetail;
use App\Storefront\ProductListing;
use App\Storefront\StorefrontContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProductController extends Controller
{
    private const JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function index(Request $request, ProductListing $listing): JsonResponse
    {
        $input = $request->validate([
            // comma-separated since 2026-10-22: several subtrees are a union, which is what a multi-select
            // sub-type filter means. One path behaves exactly as it always did.
            'category' => 'nullable|string|max:500',
            'brand' => 'nullable|string|max:500',    // slugs and/or numeric ids, comma-separated (ids since 2026-10-17)
            'gender' => 'nullable|string|max:100',   // ids and/or English names, comma-separated (names since 2026-10-17)
            // `color` filters a colour in ANY role and is kept for callers that already send it; `dial_color`
            // and `band_color` are the two role-scoped sections the sidebars send (2026-10-23). A key that is
            // not validated here is DROPPED, which is how the watch-spec filters silently matched everything
            // until they were listed — so a new filter key is a change in two files, never one.
            'color' => 'nullable|string|max:200',
            'dial_color' => 'nullable|string|max:200',
            'band_color' => 'nullable|string|max:200',
            'material' => 'nullable|string|max:200',
            'grade' => 'nullable|string|max:200',
            // the three watch-spec sections (2026-10-22, ProductListing::WATCH_SPEC_FACETS) — ids,
            // comma-separated, exactly as `grade` and `material` are
            'movement' => 'nullable|string|max:200',
            'shape' => 'nullable|string|max:200',
            'display_type' => 'nullable|string|max:200',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            'in_stock' => 'nullable|in:0,1,true,false',
            'on_sale' => 'nullable|in:0,1,true,false',   // 2026-10-18: a valid sale only (ProductListing::applyFilters)
            'min_discount' => 'nullable|integer|min:1|max:95',   // 2026-10-19: a valid sale of at least N % (ProductListing::applyFilters)
            'facets' => 'nullable|in:0,1,true,false',   // 2026-10-17: per-result facet counts + price bounds, opt-in (ProductListing::facets)
            'q' => 'nullable|string|max:120',
            'sort' => 'nullable|in:'.implode(',', ProductListing::SORTS),
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:'.config()->integer('storefront.listing.max_per_page'),
            'locale' => 'nullable|string|max:5',
        ]);

        /** @var array<string, mixed> $input */
        $page = $listing->run($input, '/'.$request->path());
        if ($page === null) {
            throw new NotFoundHttpException('Category not found');
        }

        return response()->json($page, 200, [], self::JSON);
    }

    public function show(string $slug, ProductDetail $detail, StorefrontContext $ctx): JsonResponse
    {
        $payload = $detail->bySlug($slug);
        if ($payload === null) {
            throw new NotFoundHttpException('Product not found');
        }

        return response()->json($payload + ['locale' => $ctx->locale], 200, [], self::JSON);
    }
}
