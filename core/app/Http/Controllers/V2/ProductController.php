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
            'category' => 'nullable|string|max:500',
            'brand' => 'nullable|string|max:500',
            'gender' => 'nullable|string|max:100',
            'color' => 'nullable|string|max:200',
            'material' => 'nullable|string|max:200',
            'grade' => 'nullable|string|max:200',
            'price_min' => 'nullable|numeric|min:0',
            'price_max' => 'nullable|numeric|min:0',
            'in_stock' => 'nullable|in:0,1,true,false',
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
