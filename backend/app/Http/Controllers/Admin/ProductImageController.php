<?php
namespace App\Http\Controllers\Admin;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\ImageService;
use Illuminate\Support\Facades\Cache;

class ProductImageController extends Controller
{
    // ── OLD methods ──────────────────────────────────────
    // Image management is now per-product (see manageImages / product.images.*),
    // and the product_image.index view expects a single $product + $images that
    // this global entry point cannot provide. Redirect to the product list where
    // each product exposes its own gallery-management screen.
    public function index() { return redirect()->route('product.index'); }
    public function create() { return redirect()->route('product.index'); }
    public function store(Request $request) { return back(); }
    public function edit(ProductImage $product_image) { return back(); }
    public function update(Request $request, ProductImage $product_image) { return back(); }
    public function destroy(ProductImage $product_image)
    {
        $path = public_path('Uploads_Images/Product_image/' . $product_image->image);
        if (file_exists($path)) unlink($path);
        $product_image->delete();
        Cache::forget('AllProductImage');
        return back()->with('success', trans('messages.delete'));
    }

    // ── NEW: Gallery management ──────────────────────────
    public function manageImages(Product $product)
    {
        $images = $product->product_image()->ordered()->get();
        return view('Dashboard.product_image.manage', compact('product', 'images'));
    }

    public function uploadImages(Request $request, Product $product)
    {
        $request->validate([
            'images'   => 'required|array|min:1|max:20',
            'images.*' => 'required|image|mimes:jpg,jpeg,png,webp,gif|max:5120',
        ]);

        $imageService = new ImageService;
        $sortStart    = $product->product_image()->max('sort') + 1;

        foreach ($request->file('images') as $index => $file) {
            // Product gallery images: max 1200x1200, WebP q85.
            $newName = $imageService->process($file, 'Product_image', [
                'max_width'  => 1200,
                'max_height' => 1200,
                'quality'    => 85,
            ]);
            ProductImage::create([
                'product_id' => $product->id,
                'image'      => $newName,
                'is_cover'   => false,
                'sort'       => $sortStart + $index,
            ]);
        }

        Cache::forget('AllProductImage');
        return back()->with('success', trans('messages.add'));
    }

    public function setCover(ProductImage $image)
    {
        ProductImage::where('product_id', $image->product_id)->update(['is_cover' => false]);
        $image->update(['is_cover' => true]);
        Cache::forget('AllProductImage');
        return back()->with('success', 'Cover updated.');
    }

    public function sort(Request $request)
    {
        $request->validate(['order' => 'required|array', 'order.*' => 'integer']);
        foreach ($request->order as $sort => $id) {
            ProductImage::where('id', $id)->update(['sort' => $sort]);
        }
        return response()->json(['success' => true]);
    }

    public function destroyImage(ProductImage $image)
    {
        $path = public_path('Uploads_Images/Product_image/' . $image->image);
        if (file_exists($path)) unlink($path);
        $image->delete();
        Cache::forget('AllProductImage');
        return back()->with('success', trans('messages.delete'));
    }
}