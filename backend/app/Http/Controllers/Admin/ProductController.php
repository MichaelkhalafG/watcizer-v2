<?php

namespace App\Http\Controllers\Admin;

use App\Models\User;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Grade;
use App\Models\Offer;
use App\Models\Shape;
use App\Models\Gender;
use App\Models\Feature;
use App\Models\Product;
use App\Models\SubType;
use App\Models\Category;
use App\Models\Material;
use App\Models\SizeType;
use App\Models\BannerSide;
use App\Models\ClosureType;
use App\Models\DisplayType;
use App\Models\BannerBottom;
use App\Models\CategoryType;
use App\Models\MovementType;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use App\Exports\ProductExport;
use App\Imports\ProductImport;
use App\Http\Controllers\Controller;
use Intervention\Image\ImageManager;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\ProductRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Maatwebsite\Excel\Validators\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        if ($request->has('clear_filter')) {
            session()->forget('filter_quantity');
            return redirect()->route('product.index');
        }

        $query    = Product::query();
        $quantity = $request->has('quantity') ? $request->quantity : session('filter_quantity');
        session(['filter_quantity' => $quantity]);

        if ($quantity !== null) {
            $query->where(function ($query) use ($quantity) {
                $query->where('stock', '=', $quantity)->orWhere('market_stock', '=', $quantity);
            });
        }

        $product = $query->get();
        foreach ($product as $item) {
            $item->created_by_first_name = User::where('id', $item->created_by)->value('first_name');
            $item->created_by_last_name  = User::where('id', $item->created_by)->value('last_name');
        }

        return view('Dashboard.product.index', compact('product', 'quantity'));
    }

    public function create()
    {
        $data = [
            'main_categories' => Category::where('is_active', true)->whereNull('parent_id')->where('level', 1)->orderBy('sort_order')->get(),
            'category_type'   => CategoryType::all(['id']),
            'brand'           => Brand::all(['id']),
            'grade'           => Grade::all(['id']),
            'color'           => Color::all(['id', 'color_value']),
            'closure_type'    => ClosureType::all(['id']),
            'display_type'    => DisplayType::all(['id']),
            'size_type'       => SizeType::all(['id']),
            'shape'           => Shape::all(['id']),
            'material'        => Material::all(['id']),
            'movement_type'   => MovementType::all(['id']),
            'feature'         => Feature::all(['id']),
            'gender'          => Gender::all(['id']),
            'sub_type'        => SubType::all(['id']),
        ];
        return view('Dashboard.product.create', $data);
    }

    public function store(ProductRequest $request)
    {
        $product = new Product;

        // ── 3-level category ─────────────────────────────────
        $product->main_category_id = $request->input('main_category_id');

        $subIds = array_filter((array) $request->input('sub_category_id', []));
        $product->sub_category_id = count($subIds) >= 1 ? $subIds[0] : null;

        $typeIds = array_filter((array) $request->input('product_type_id', []));
        $product->product_type_id = count($typeIds) >= 1 ? $typeIds[0] : null;

        // ── Core fields ───────────────────────────────────────
        $product->translateOrNew('ar')->product_title     = $request['product_title']['ar'];
        $product->translateOrNew('en')->product_title     = $request['product_title']['en'];
        $product->category_type_id                        = $request['category_type_id'];
        $product->brand_id                                = $request['brand_id'];
        $product->sku_unique                              = $request['sku_unique'];
        $product->purchase_price                          = $request['purchase_price'];
        $product->selling_price                           = $request['selling_price'];
        $product->sale_price_after_discount               = $request['sale_price_after_discount'];
        $product->percentage_discount                     = $request['percentage_discount'];
        $product->grade_id                                = $request['grade_id'];
        $product->sub_type_id                             = $request['sub_type_id'];
        $product->band_closure_id                         = $request['band_closure_id'];
        $product->dial_display_type_id                    = $request['dial_display_type_id'];
        $product->case_size                               = $request['case_size'];
        $product->case_size_type_id                       = $request['case_size_type_id'];
        $product->translateOrNew('ar')->short_description = $request['short_description']['ar'];
        $product->translateOrNew('en')->short_description = $request['short_description']['en'];
        $product->case_shape_id                           = $request['case_shape_id'];
        $product->band_material_id                        = $request['band_material_id'];
        $product->watch_movement_id                       = $request['watch_movement_id'];
        $product->stock                                   = $request['stock'];
        $product->market_stock                            = $request['market_stock'];
        $product->band_length                             = $request['band_length'];
        $product->band_size_type_id                       = $request['band_size_type_id'];
        $product->water_resistance                        = $request['water_resistance'];
        $product->water_resistance_size_type_id           = $request['water_resistance_size_type_id'];
        $product->band_width                              = $request['band_width'];
        $product->band_width_size_type_id                 = $request['band_width_size_type_id'];
        $product->case_thickness                          = $request['case_thickness'];
        $product->case_thickness_size_type_id             = $request['case_thickness_size_type_id'];
        $product->translateOrNew('ar')->long_description  = $request['long_description']['ar'];
        $product->translateOrNew('en')->long_description  = $request['long_description']['en'];
        $product->dial_case_material_id                   = $request['dial_case_material_id'];
        $product->dial_glass_material_id                  = $request['dial_glass_material_id'];
        $product->watch_height                            = $request['watch_height'];
        $product->watch_height_size_type_id               = $request['watch_height_size_type_id'];
        $product->watch_width                             = $request['watch_width'];
        $product->watch_width_size_type_id                = $request['watch_width_size_type_id'];
        $product->translateOrNew('ar')->model_name        = $request['model_name']['ar'] ?? null;
        $product->translateOrNew('en')->model_name        = $request['model_name']['en'] ?? null;
        $product->model_number                            = $request['model_number'];
        $product->watch_length                            = $request['watch_length'];
        $product->watch_length_size_type_id               = $request['watch_length_size_type_id'];
        $product->warranty_years                          = $request['warranty_years'];
        $product->interchangeable_dial                    = $request['interchangeable_dial'];
        $product->interchangeable_strap                   = $request['interchangeable_strap'];
        $product->active                                  = $request['active'];
        $product->watch_box                               = $request['watch_box'];
        $product->wa_code                                 = $request['wa_code'];
        $product->search_keywords                         = $request['search_keywords'];
        $product->translateOrNew('ar')->country           = $request['country']['ar'] ?? null;
        $product->translateOrNew('en')->country           = $request['country']['en'] ?? null;
        $product->translateOrNew('ar')->stone             = $request['stone']['ar'] ?? null;
        $product->translateOrNew('en')->stone             = $request['stone']['en'] ?? null;
        $product->created_by                              = auth()->user()->id;

        // ── Main image ────────────────────────────────────────
        $image   = $request->file('image');
        $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
        $manager = new ImageManager(new Driver());
        $img     = $manager->read($image);
        $img->toWebp()->save(public_path('/Uploads_Images/Product/' . $NewName));
        $product->image = $NewName;

        $product->save();

        // ── Sync relations ────────────────────────────────────
        $product->feature()->sync($request->input('feature_id', []));
        $product->gender()->sync($request->input('gender_id', []));
        $product->bandColor()->sync($request->input('band_color_id', []));
        $product->dialColor()->sync($request->input('dial_color_id', []));

        // ── Gallery images (Base64) ───────────────────────────
        if ($request->has('gallery_base64')) {
            $galleryManager = new ImageManager(new Driver());
            foreach ($request->input('gallery_base64', []) as $sort => $base64) {
                try {
                    $galleryName = time() . '_g' . $sort . '_' . uniqid() . '.webp';
                    $galleryManager->read($base64)->toWebp()
                        ->save(public_path('/Uploads_Images/Product_image/' . $galleryName));
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image'      => $galleryName,
                        'is_cover'   => $sort === 0,
                        'sort'       => $sort,
                    ]);
                } catch (\Exception $e) {
                    \Log::error('Gallery store error: ' . $e->getMessage());
                }
            }
        }

        Cache::forget('AllProduct');
        Cache::forget('AllProductImage');

        return redirect(route('product.index'))->with('success', trans('messages.add'));
    }

    public function show(Product $product)
    {
        return view('Dashboard.product.show', compact('product'));
    }

    public function edit(Product $product)
    {
        if (auth()->user()->id != $product->created_by || auth()->user()->type == 'SuperAdmin') {
            $this->authorize('AnyAction');
        }

        $product->load(['productImages', 'dialColor', 'bandColor', 'feature', 'gender']);

        $data = [
            'product'       => $product,
            'category'      => Category::all(['id']),
            'category_type' => CategoryType::all(['id']),
            'brand'         => Brand::all(['id']),
            'grade'         => Grade::all(['id']),
            'color'         => Color::all(['id', 'color_value']),
            'closure_type'  => ClosureType::all(['id']),
            'display_type'  => DisplayType::all(['id']),
            'size_type'     => SizeType::all(['id']),
            'shape'         => Shape::all(['id']),
            'material'      => Material::all(['id']),
            'movement_type' => MovementType::all(['id']),
            'feature'       => Feature::all(['id']),
            'gender'        => Gender::all(['id']),
            'sub_type'      => SubType::all(['id']),
        ];

        return view('Dashboard.product.edit', $data);
    }

    public function update(ProductRequest $request, Product $product)
    {
        if (auth()->user()->id != $product->created_by || auth()->user()->type == 'SuperAdmin') {
            $this->authorize('AnyAction');
        }

        $product->main_category_id = $request->input('main_category_id');

        $subIds = array_filter((array) $request->input('sub_category_id', []));
        $product->sub_category_id = count($subIds) >= 1 ? $subIds[0] : null;

        $typeIds = array_filter((array) $request->input('product_type_id', []));
        $product->product_type_id = count($typeIds) >= 1 ? $typeIds[0] : null;

        $product->translateOrNew('ar')->product_title     = $request['product_title']['ar'];
        $product->translateOrNew('en')->product_title     = $request['product_title']['en'];
        $product->category_type_id                        = $request['category_type_id'];
        $product->brand_id                                = $request['brand_id'];
        $product->sku_unique                              = $request['sku_unique'];
        $product->purchase_price                          = $request['purchase_price'];
        $product->selling_price                           = $request['selling_price'];
        $product->sale_price_after_discount               = $request['sale_price_after_discount'];
        $product->percentage_discount                     = $request['percentage_discount'];
        $product->grade_id                                = $request['grade_id'];
        $product->sub_type_id                             = $request['sub_type_id'];
        $product->band_closure_id                         = $request['band_closure_id'];
        $product->dial_display_type_id                    = $request['dial_display_type_id'];
        $product->case_size                               = $request['case_size'];
        $product->case_size_type_id                       = $request['case_size_type_id'];
        $product->translateOrNew('ar')->short_description = $request['short_description']['ar'];
        $product->translateOrNew('en')->short_description = $request['short_description']['en'];
        $product->case_shape_id                           = $request['case_shape_id'];
        $product->band_material_id                        = $request['band_material_id'];
        $product->watch_movement_id                       = $request['watch_movement_id'];
        $product->stock                                   = $request['stock'];
        $product->market_stock                            = $request['market_stock'];
        $product->band_length                             = $request['band_length'];
        $product->band_size_type_id                       = $request['band_size_type_id'];
        $product->water_resistance                        = $request['water_resistance'];
        $product->water_resistance_size_type_id           = $request['water_resistance_size_type_id'];
        $product->band_width                              = $request['band_width'];
        $product->band_width_size_type_id                 = $request['band_width_size_type_id'];
        $product->case_thickness                          = $request['case_thickness'];
        $product->case_thickness_size_type_id             = $request['case_thickness_size_type_id'];
        $product->translateOrNew('ar')->long_description  = $request['long_description']['ar'];
        $product->translateOrNew('en')->long_description  = $request['long_description']['en'];
        $product->dial_case_material_id                   = $request['dial_case_material_id'];
        $product->dial_glass_material_id                  = $request['dial_glass_material_id'];
        $product->watch_height                            = $request['watch_height'];
        $product->watch_height_size_type_id               = $request['watch_height_size_type_id'];
        $product->watch_width                             = $request['watch_width'];
        $product->watch_width_size_type_id                = $request['watch_width_size_type_id'];
        $product->translateOrNew('ar')->model_name        = $request['model_name']['ar'] ?? null;
        $product->translateOrNew('en')->model_name        = $request['model_name']['en'] ?? null;
        $product->model_number                            = $request['model_number'];
        $product->watch_length                            = $request['watch_length'];
        $product->watch_length_size_type_id               = $request['watch_length_size_type_id'];
        $product->warranty_years                          = $request['warranty_years'];
        $product->interchangeable_dial                    = $request['interchangeable_dial'];
        $product->interchangeable_strap                   = $request['interchangeable_strap'];
        $product->active                                  = $request['active'];
        $product->watch_box                               = $request['watch_box'];
        $product->wa_code                                 = $request['wa_code'];
        $product->search_keywords                         = $request['search_keywords'];
        $product->translateOrNew('ar')->country           = $request['country']['ar'] ?? null;
        $product->translateOrNew('en')->country           = $request['country']['en'] ?? null;
        $product->translateOrNew('ar')->stone             = $request['stone']['ar'] ?? null;
        $product->translateOrNew('en')->stone             = $request['stone']['en'] ?? null;
        $product->updated_by                              = auth()->user()->id;

        // ── Main image ────────────────────────────────────────
        if ($image = $request->file('image')) {
            if ($product->image && file_exists(public_path('Uploads_Images/Product/' . $product->image))) {
                unlink(public_path('Uploads_Images/Product/' . $product->image));
            }
            $NewName = time() . '_' . date('Y-m-d_') . uniqid() . '.webp';
            $manager = new ImageManager(new Driver());
            $img     = $manager->read($image);
            $img->toWebp()->save(public_path('/Uploads_Images/Product/' . $NewName));
            $product->image = $NewName;
        } else {
            unset($product['image']);
        }

        $product->save();

        // ── Sync relations ────────────────────────────────────
        $product->feature()->sync($request->input('feature_id', []));
        $product->gender()->sync($request->input('gender_id', []));
        $product->bandColor()->sync($request->input('band_color_id', []));
        $product->dialColor()->sync($request->input('dial_color_id', []));

        // ── Gallery images (Base64) during edit ───────────────
        if ($request->has('gallery_base64')) {
            $currentCount   = $product->productImages()->count();
            $galleryManager = new ImageManager(new Driver());
            foreach ($request->input('gallery_base64', []) as $sort => $base64) {
                if ($currentCount >= 10) break;
                try {
                    $galleryName = time() . '_g' . $sort . '_' . uniqid() . '.webp';
                    $galleryManager->read($base64)->toWebp()
                        ->save(public_path('/Uploads_Images/Product_image/' . $galleryName));
                    ProductImage::create([
                        'product_id' => $product->id,
                        'image'      => $galleryName,
                        'is_cover'   => 0,
                        'sort'       => $currentCount + $sort,
                    ]);
                    $currentCount++;
                } catch (\Exception $e) {
                    \Log::error('Gallery update error: ' . $e->getMessage());
                }
            }
        }

        Cache::forget('AllProduct');
        Cache::forget('AllProductImage');

        return redirect(route('product.index'))->with('success', trans('messages.edit'));
    }

    public function destroy(Product $product)
    {
        $product_count = $product->withCount('order_items')->findOrFail($product->id);
        if ($product_count->order_items_count > 0) {
            return back()->with('error', trans('messages.undelete_order'));
        }

        $image = ProductImage::where('product_id', $product->id)->get();
        foreach ($image as $img) {
            if (file_exists(public_path('Uploads_Images/Product_image/' . $img->image))) {
                unlink(public_path('Uploads_Images/Product_image/' . $img->image));
            }
        }

        if ($product->image && file_exists(public_path('Uploads_Images/Product/' . $product->image))) {
            unlink(public_path('Uploads_Images/Product/' . $product->image));
        }

        $offers = Offer::where('main_product_id', $product->id)->get();
        foreach ($offers as $offer) {
            $offer->delete();
        }
        $product->product_image()->delete();
        $product->product_rating()->delete();
        $product->delete();

        Cache::forget('AllProduct');
        Cache::forget('AllProductImage');

        return back()->with('success', trans('messages.delete'));
    }

    public function export()
    {
        return Excel::download(new ProductExport, 'Product.xlsx');
    }

    public function import(Request $request)
    {
        try {
            $request->validate([
                'import' => 'required|mimes:csv,xlsx|max:30720',
            ]);

            $file     = $request->file('import');
            $filePath = $file->storeAs('temp', uniqid() . '.' . $file->getClientOriginalExtension());

            Excel::import(new ProductImport(storage_path('app/' . $filePath)), storage_path('app/' . $filePath));

            Storage::delete($filePath);
            Cache::forget('AllProduct');

            return back()->with('success', trans('messages.import_mes'));

        } catch (ValidationException $e) {
            $failures      = $e->failures();
            $errorMessages = [];
            foreach ($failures as $failure) {
                $errorMessages[] = "Row {$failure->row()} : " . implode(', ', $failure->errors());
            }
            return back()->with('validationErrors', $errorMessages);
        }
    }
}