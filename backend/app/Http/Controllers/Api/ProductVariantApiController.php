<?php
namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\NewColor;
use App\Models\NewSize;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class ProductVariantApiController extends Controller
{
    // ── GET /api/products/{id}/variants ──────────────────────
    public function index(Product $product)
    {
        $cacheKey = "product_variants_{$product->id}";

        $variants = Cache::remember($cacheKey, now()->addMinutes(10), function () use ($product) {
            return $product->variants()
                ->with(['color', 'size'])
                ->where('is_active', true)
                ->get()
                ->map(fn($v) => $this->formatVariant($v));
        });

        return response()->json($variants);
    }

    // ── GET /api/colors ──────────────────────────────────────
    public function colors()
    {
        $colors = Cache::remember('api_new_colors', now()->addMinutes(30), function () {
            return NewColor::where('is_active', true)
                ->orderBy('name_en')
                ->get()
                ->map(fn($c) => [
                    'id'      => $c->id,
                    'name_en' => $c->name_en,
                    'name_ar' => $c->name_ar,
                    'hex'     => $c->hex,
                ]);
        });

        return response()->json($colors);
    }

    // ── GET /api/sizes ───────────────────────────────────────
    public function sizes()
    {
        $sizes = Cache::remember('api_new_sizes', now()->addMinutes(30), function () {
            return NewSize::where('is_active', true)
                ->orderBy('type')
                ->orderBy('name_en')
                ->get()
                ->map(fn($s) => [
                    'id'      => $s->id,
                    'name_en' => $s->name_en,
                    'name_ar' => $s->name_ar,
                    'type'    => $s->type,
                ]);
        });

        return response()->json($sizes);
    }

    // ── GET /api/products/{id}/variants/summary ───────────────
    public function summary(Product $product)
    {
        $variants = $product->variants()
            ->with(['color', 'size'])
            ->where('is_active', true)
            ->get();

        $colors = $variants->filter(fn($v) => $v->color)
            ->map(fn($v) => [
                'id'      => $v->color->id,
                'name_en' => $v->color->name_en,
                'name_ar' => $v->color->name_ar,
                'hex'     => $v->color->hex,
            ])->unique('id')->values();

        $sizes = $variants->filter(fn($v) => $v->size)
            ->map(fn($v) => [
                'id'      => $v->size->id,
                'name_en' => $v->size->name_en,
                'name_ar' => $v->size->name_ar,
                'type'    => $v->size->type,
            ])->unique('id')->values();

        return response()->json([
            'product_id'     => $product->id,
            'total_variants' => $variants->count(),
            'total_stock'    => $variants->sum('stock'),
            'colors'         => $colors,
            'sizes'          => $sizes,
            'variants'       => $variants->map(fn($v) => $this->formatVariant($v)),
        ]);
    }

    // ── Format helper ─────────────────────────────────────────
    private function formatVariant(ProductVariant $v): array
    {
        return [
            'id'             => $v->id,
            'product_id'     => $v->product_id,
            'color'          => $v->color ? [
                'id'      => $v->color->id,
                'name_en' => $v->color->name_en,
                'name_ar' => $v->color->name_ar,
                'hex'     => $v->color->hex,
            ] : null,
            'size'           => $v->size ? [
                'id'      => $v->size->id,
                'name_en' => $v->size->name_en,
                'name_ar' => $v->size->name_ar,
                'type'    => $v->size->type,
            ] : null,
            'stock'          => $v->stock,
            'sku'            => $v->sku,
            'price_modifier' => (float) $v->price_modifier,
            'is_active'      => $v->is_active,
            'in_stock'       => $v->stock > 0,
            'label_en'       => $v->getLabel('en'),
            'label_ar'       => $v->getLabel('ar'),
        ];
    }
}