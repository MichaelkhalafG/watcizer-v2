<?php
namespace App\Http\Controllers\Admin;
use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Illuminate\Support\Facades\Cache;

class ProductImageController extends Controller
{
    // ── OLD methods (unchanged) ──────────────────────────
    public function index() { return view('Dashboard.product_image.index'); }
    public function create() { return view('Dashboard.product_image.create'); }
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

        $manager   = new ImageManager(new Driver());
        $sortStart = $product->product_image()->max('sort') + 1;

        foreach ($request->file('images') as $index => $file) {
            $newName = time() . '_' . $index . '_' . uniqid() . '.webp';
            $manager->read($file)->toWebp()->save(public_path('Uploads_Images/Product_image/' . $newName));
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