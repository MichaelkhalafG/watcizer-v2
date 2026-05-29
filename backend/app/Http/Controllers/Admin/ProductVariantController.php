<?php
namespace App\Http\Controllers\Admin;

use App\Models\Product;
use App\Models\NewColor;
use App\Models\NewSize;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;

class ProductVariantController extends Controller
{
    public function index(Product $product)
    {
        $variants = $product->variants()->with(['color', 'size'])->get();
        return view('Dashboard.variants.index', compact('product', 'variants'));
    }

    public function create(Product $product)
    {
        $colors = NewColor::where('is_active', true)->get();
        $sizes  = NewSize::where('is_active', true)->get()->groupBy('type');
        return view('Dashboard.variants.create', compact('product', 'colors', 'sizes'));
    }

    public function store(Request $request, Product $product)
    {
        $request->validate([
            'variants'               => 'required|array|min:1',
            'variants.*.stock'       => 'required|integer|min:0',
            'variants.*.sku'         => 'nullable|string|unique:product_variants,sku',
            'variants.*.color_id'    => 'nullable|exists:new_colors,id',
            'variants.*.size_id'     => 'nullable|exists:new_sizes,id',
            'variants.*.price_modifier' => 'nullable|numeric',
        ]);

        foreach ($request->variants as $variantData) {
            ProductVariant::create([
                'product_id'     => $product->id,
                'color_id'       => $variantData['color_id'] ?? null,
                'size_id'        => $variantData['size_id'] ?? null,
                'stock'          => $variantData['stock'],
                'sku'            => $variantData['sku'] ?? null,
                'price_modifier' => $variantData['price_modifier'] ?? 0,
                'is_active'      => true,
            ]);
        }

        return redirect()->route('products.variants.index', $product)
            ->with('success', 'Variants added successfully');
    }

    // ── Auto-generate variants (Color × Size combinations) ──
    public function generate(Request $request, Product $product)
    {
        $request->validate([
            'color_ids' => 'required|array',
            'size_ids'  => 'required|array',
        ]);

        $colors = NewColor::whereIn('id', $request->color_ids)->get();
        $sizes  = NewSize::whereIn('id', $request->size_ids)->get();

        $created = 0;
        foreach ($colors as $color) {
            foreach ($sizes as $size) {
                // تجنب التكرار
                $exists = ProductVariant::where('product_id', $product->id)
                    ->where('color_id', $color->id)
                    ->where('size_id', $size->id)
                    ->exists();

                if (!$exists) {
                    ProductVariant::create([
                        'product_id' => $product->id,
                        'color_id'   => $color->id,
                        'size_id'    => $size->id,
                        'stock'      => 0,
                        'is_active'  => true,
                    ]);
                    $created++;
                }
            }
        }

        return response()->json([
            'success' => true,
            'created' => $created,
            'message' => "$created variants generated",
        ]);
    }

    public function update(Request $request, Product $product, ProductVariant $variant)
    {
        $request->validate([
            'stock'          => 'required|integer|min:0',
            'price_modifier' => 'nullable|numeric',
            'is_active'      => 'boolean',
        ]);

        $variant->update($request->only('stock', 'price_modifier', 'is_active', 'sku'));

        return back()->with('success', 'Variant updated');
    }

    public function destroy(Product $product, ProductVariant $variant)
    {
        $variant->delete();
        return back()->with('success', 'Variant deleted');
    }

    public function show(Product $product, ProductVariant $variant) {}

    public function edit(Product $product, ProductVariant $variant) {}
}