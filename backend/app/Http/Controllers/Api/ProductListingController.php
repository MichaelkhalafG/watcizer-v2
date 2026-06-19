<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProductListResource;
use App\Http\Resources\ProductResource;

class ProductListingController extends Controller
{
    /**
     * Paginated, filterable, server-shaped product listing.
     * Backed by the indexes added in P1-5.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'brand_id'    => 'nullable|integer',
            'category_id' => 'nullable|integer',
            'sub_type_id' => 'nullable|integer',
            'gender_id'   => 'nullable|integer',
            'grade_id'    => 'nullable|integer',
            'price_min'   => 'nullable|numeric',
            'price_max'   => 'nullable|numeric',
            'search'      => 'nullable|string|max:255',
            'sort'        => 'nullable|in:price_asc,price_desc,newest,rating',
            'per_page'    => 'nullable|integer|min:1|max:96',
            'page'        => 'nullable|integer|min:1',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 24);
        $sort    = $validated['sort'] ?? 'newest';

        $query = Product::query()
            ->with([
                'translations',
                'brand.translations',
                'mainCategory.translations',
                'sub_type.translations',
                'grade.translations',
                'gender.translations',
            ])
            ->withAvg('product_rating', 'rating')
            ->withCount('product_rating');

        // ── Filters (applied only when the param is present & non-null) ──────
        if (! empty($validated['brand_id'])) {
            $query->where('brand_id', $validated['brand_id']);
        }
        if (! empty($validated['category_id'])) {
            $query->where('main_category_id', $validated['category_id']);
        }
        if (! empty($validated['sub_type_id'])) {
            $query->where('sub_type_id', $validated['sub_type_id']);
        }
        if (! empty($validated['grade_id'])) {
            $query->where('grade_id', $validated['grade_id']);
        }
        if (! empty($validated['gender_id'])) {
            $genderId = $validated['gender_id'];
            $query->whereHas('gender', fn ($q) => $q->where('genders.id', $genderId));
        }
        if (isset($validated['price_min'])) {
            $query->where('sale_price_after_discount', '>=', $validated['price_min']);
        }
        if (isset($validated['price_max'])) {
            $query->where('sale_price_after_discount', '<=', $validated['price_max']);
        }
        if (! empty($validated['search'])) {
            $search = $validated['search'];
            // product_title is translatable (both en & ar rows live in product_translations)
            $query->whereHas('translations', function ($q) use ($search) {
                $q->where('product_title', 'LIKE', "%{$search}%");
            });
        }

        // ── Sort ─────────────────────────────────────────────────────────────
        switch ($sort) {
            case 'price_asc':
                $query->orderBy('sale_price_after_discount', 'asc');
                break;
            case 'price_desc':
                $query->orderBy('sale_price_after_discount', 'desc');
                break;
            case 'rating':
                $query->orderByDesc('product_rating_avg_rating');
                break;
            case 'newest':
            default:
                $query->orderBy('created_at', 'desc');
                break;
        }

        $products = $query->paginate($perPage);

        return ProductListResource::collection($products)
            ->response()
            ->getData(true);
    }

    /**
     * Single fully-shaped product (PDP) + related products — by id.
     */
    public function show(Request $request, $id)
    {
        $product = Product::with($this->detailRelations())->findOrFail($id);

        return $this->productDetailResponse($product);
    }

    /**
     * Same as show(), but resolved by the English product_title (the value
     * the SPA carries in /product/:name). Used by the PDP route.
     */
    public function showByName(Request $request, $name)
    {
        $product = Product::with($this->detailRelations())
            ->whereHas('translations', fn ($q) => $q->where('locale', 'en')
                                                     ->where('product_title', $name))
            ->firstOrFail();

        return $this->productDetailResponse($product);
    }

    /**
     * Eager-loads needed to fully shape a single product.
     * Full rating set — ProductResource limits to 10 for display and computes
     * count/average over the whole collection.
     */
    private function detailRelations(): array
    {
        return [
            'translations',
            'brand.translations',
            'mainCategory.translations',
            'sub_type.translations',
            'gender.translations',
            'grade.translations',
            'dialColor.translations',
            'bandColor.translations',
            'feature.translations',
            'productImages',
            'product_rating.user',
        ];
    }

    /**
     * Build the { product, related[] } payload for a loaded product.
     */
    private function productDetailResponse(Product $product)
    {
        $related = Product::with([
                'translations',
                'brand.translations',
                'mainCategory.translations',
                'sub_type.translations',
                'grade.translations',
                'gender.translations',
            ])
            ->withAvg('product_rating', 'rating')
            ->withCount('product_rating')
            ->where('id', '!=', $product->id)
            ->where(fn ($q) => $q->where('sub_type_id', $product->sub_type_id)
                                 ->orWhere('brand_id', $product->brand_id))
            ->where(fn ($q) => $q->where('stock', '>', 0)->orWhere('market_stock', '>', 0))
            ->limit(6)
            ->get();

        return response()->json([
            'product' => new ProductResource($product),
            'related' => ProductListResource::collection($related),
        ]);
    }
}
