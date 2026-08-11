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
use App\Services\ImageService;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;
use App\Http\Requests\ProductRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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

        // Eager-load the creator (belongsTo User) so the loop below never fires a
        // per-row User query (was 2 queries per product). Paginate to keep the
        // page light; withQueryString preserves the quantity filter across pages.
        // Server-side text search (?q=) — the client DataTable is disabled on this
        // paginated page, so search runs in the query across title + identifiers.
        $q = trim((string) $request->input('q'));
        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->whereHas('translations', function ($t) use ($q) {
                        $t->where('product_title', 'like', "%{$q}%");
                    })
                    ->orWhere('model_number', 'like', "%{$q}%")
                    ->orWhere('sku_unique', 'like', "%{$q}%")
                    ->orWhere('wa_code', 'like', "%{$q}%");
            });
        }

        $product = $query->with('creator')->paginate(50)->withQueryString();
        foreach ($product as $item) {
            $item->created_by_first_name = $item->creator?->first_name;
            $item->created_by_last_name  = $item->creator?->last_name;
        }

        return view('Dashboard.product.index', compact('product', 'quantity'));
    }

    public function create()
    {
        $data = [
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

        // ── Classification ────────────────────────────────────
        // The 3-level `categories` taxonomy (main/sub/product category) was
        // removed from the product form; the storefront navigates by
        // category_type_id + sub_type_id only. Those columns stay nullable in the
        // DB and are intentionally left unset here.

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
        // Stock is optional — default both tiers to 0 when left blank.
        $product->stock                                   = $request['stock'] ?? 0;
        $product->market_stock                            = $request['market_stock'] ?? 0;
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

        // ── SEO + stock (NEW fields) ───────────────────────────
        $product->seo_title                               = $request->input('seo_title');
        $product->seo_slug                                = $request->input('seo_slug');
        $product->seo_meta_description                    = $request->input('seo_meta_description');
        $product->low_stock_threshold                     = $request->input('low_stock_threshold', 5);

        // ── Main image (padded onto a 1200x1200 white square, WebP q85) ──────
        // Image processing is memory-heavy (Imagick) and can fail on very large
        // uploads on shared hosting. Catch it so data entry gets a clear,
        // recoverable message with their input preserved — NOT a raw 500 that
        // loses the whole product they just typed.
        try {
            $product->image = (new ImageService)->process($request->file('image'), 'Product', [
                'max_width'  => 1200,
                'max_height' => 1200,
                'quality'    => 85,
                'pad_square' => true,
            ]);
        } catch (\Throwable $e) {
            \Log::error('Product main image processing failed (store): ' . $e->getMessage());
            return back()->withInput()->withErrors([
                'image' => 'We could not process this image (it may be too large or an unsupported format). Please upload a JPG or PNG under ~4 MB and try again — the rest of your entries are kept.',
            ]);
        }

        // ── Gallery images (multipart file uploads — WAF-safe) ────────────────
        // Previously the gallery arrived as inline base64 strings in this same
        // POST (gallery_base64[]). That fat, data-URI-looking payload was what
        // Hostinger's ModSecurity rejected with a bare "Forbidden" page. Gallery
        // images now come as ordinary multipart files (gallery_images[], already
        // validated in ProductRequest) and go through the same ImageService as
        // the main image — no base64 anywhere.
        //
        // Image FILE processing is NOT covered by the DB transaction below, so
        // process every gallery file to a filename FIRST (outside the txn); the
        // DB rows are then written inside the transaction. A single failed image
        // is logged and skipped — it never aborts the whole save.
        $galleryRows = [];
        foreach ($request->file('gallery_images', []) as $sort => $file) {
            if ($sort >= 10) {
                break;
            }
            try {
                $galleryName = (new ImageService)->process($file, 'Product_image', [
                    'max_width'  => 1200,
                    'max_height' => 1200,
                    'quality'    => 85,
                    'pad_square' => true,
                ]);
                $galleryRows[] = [
                    'image'    => $galleryName,
                    'is_cover' => $sort === 0,
                    'sort'     => $sort,
                ];
            } catch (\Throwable $e) {
                \Log::error('Gallery store error: ' . $e->getMessage());
            }
        }

        // ── Persist everything atomically ─────────────────────
        // The model + translations + pivot syncs + gallery rows either all
        // commit or all roll back, so a mid-save failure never leaves a
        // half-written product.
        DB::transaction(function () use ($product, $request, $galleryRows) {
            $product->save();

            $product->feature()->sync($request->input('feature_id', []));
            $product->gender()->sync($request->input('gender_id', []));
            $product->bandColor()->sync($request->input('band_color_id', []));
            $product->dialColor()->sync($request->input('dial_color_id', []));

            foreach ($galleryRows as $row) {
                ProductImage::create([
                    'product_id' => $product->id,
                    'image'      => $row['image'],
                    'is_cover'   => $row['is_cover'],
                    'sort'       => $row['sort'],
                ]);
            }
        });

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
            'product'         => $product,
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

        // The 3-level `categories` taxonomy (main/sub/product category) was removed
        // from the product form. These columns stay nullable in the DB and are no
        // longer written here; existing values on already-saved products are left
        // untouched (the inputs are simply absent from the submitted form).

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
        // Stock is optional — default both tiers to 0 when left blank.
        $product->stock                                   = $request['stock'] ?? 0;
        $product->market_stock                            = $request['market_stock'] ?? 0;
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

        // ── SEO + stock (NEW fields) ───────────────────────────
        $product->seo_title                               = $request->input('seo_title');
        $product->seo_slug                                = $request->input('seo_slug');
        $product->seo_meta_description                    = $request->input('seo_meta_description');
        $product->low_stock_threshold                     = $request->input('low_stock_threshold', $product->low_stock_threshold ?? 5);

        // ── Main image ────────────────────────────────────────
        if ($image = $request->file('image')) {
            // Process the NEW image first; only delete the old file once that
            // succeeds. Catch failures so a too-large upload returns a clear,
            // recoverable message (input preserved) instead of a raw 500 — and
            // never leaves the product with its old image already deleted.
            try {
                $newImage = (new ImageService)->process($image, 'Product', [
                    'max_width'  => 1200,
                    'max_height' => 1200,
                    'quality'    => 85,
                    'pad_square' => true,
                ]);
            } catch (\Throwable $e) {
                \Log::error('Product main image processing failed (update): ' . $e->getMessage());
                return back()->withInput()->withErrors([
                    'image' => 'We could not process this image (it may be too large or an unsupported format). Please upload a JPG or PNG under ~4 MB and try again — the rest of your entries are kept.',
                ]);
            }
            if ($product->image && file_exists(public_path('Uploads_Images/Product/' . $product->image))) {
                @unlink(public_path('Uploads_Images/Product/' . $product->image));
            }
            $product->image = $newImage;
        } else {
            unset($product['image']);
        }

        // ── Persist fields + relations atomically ─────────────
        // Model + translations + pivot syncs commit or roll back together (the
        // new main image, if any, was already processed above — file writes are
        // outside the txn by design and are cleaned up on their own failure path).
        DB::transaction(function () use ($product, $request) {
            $product->save();

            $product->feature()->sync($request->input('feature_id', []));
            $product->gender()->sync($request->input('gender_id', []));
            $product->bandColor()->sync($request->input('band_color_id', []));
            $product->dialColor()->sync($request->input('dial_color_id', []));
        });

        // ── Gallery images during edit ────────────────────────────────────────
        // No gallery handling in the main update POST anymore. On the edit screen
        // newly-added gallery images are uploaded live, as ordinary multipart
        // files, to ProductImageController::uploadImages (product.images.store) —
        // and existing ones are deleted live too. That keeps this POST small
        // (fields only) so it never trips the WAF, and means a failed field save
        // never loses already-uploaded images.

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
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Real file-field validation (wrong type/size) — let the framework
            // render those errors instead of the generic message below.
            throw $e;
        } catch (\Throwable $e) {
            \Log::error('Excel import failed: ' . $e->getMessage());

            return back()->with('error', 'The file could not be imported — it may be too large or malformed. Please try a smaller CSV/XLSX and check the column format.');
        }
    }
}