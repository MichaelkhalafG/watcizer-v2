<?php

namespace App\Http\Controllers\Api;

use App\Models\Brand;
use App\Models\Color;
use App\Models\Grade;
use App\Models\Gender;
use App\Models\Feature;
use App\Models\SubType;
use App\Models\Category;
use App\Models\BannerHome;
use App\Models\ShippingCity;
use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class CatalogMetaController extends Controller
{
    /**
     * All lookup tables in one cached call (replaces ~11 separate requests).
     */
    public function index(Request $request)
    {
        // Admin-gated cache bust. No-op for public / non-admin callers
        // (this route runs CheckApi only, so auth('api') is null unless a
        // valid JWT is supplied; non-admin types never trigger the forget).
        if ($request->boolean('bust') && optional(auth('api')->user())->type === 'admin') {
            Cache::forget('catalog_meta');
        }

        $assetBase = rtrim((string) config('services.asset_base'), '/');
        $img = fn (?string $folder, ?string $file) => $file
            ? $assetBase . '/Uploads_Images/' . $folder . '/' . $file
            : null;

        // {id, name_en, name_ar} mapper for a translatable lookup collection
        $named = fn ($collection, string $attr) => $collection->map(fn ($m) => [
            'id'      => $m->id,
            'name_en' => optional($m->translate('en'))->{$attr},
            'name_ar' => optional($m->translate('ar'))->{$attr},
        ])->values()->all();

        $data = Cache::remember('catalog_meta', 3600, function () use ($img, $named) {
            // Same Color table backs both dial & band swatches (as in the legacy frontend)
            $colors = Color::with('translations')->get();

            return [
                'brands' => Brand::with('translations')->get()->map(fn ($b) => [
                    'id'       => $b->id,
                    'name_en'  => optional($b->translate('en'))->brand_name,
                    'name_ar'  => optional($b->translate('ar'))->brand_name,
                    'logo_url' => $img('Brand', $b->image),
                ])->values()->all(),

                'categories' => $named(
                    Category::with('translations')
                        ->whereNull('parent_id')
                        ->where('is_active', true)
                        ->orderBy('sort_order')
                        ->get(),
                    'name'
                ),

                'sub_types' => SubType::with('translations')->get()->map(fn ($s) => [
                    'id'          => $s->id,
                    'name_en'     => optional($s->translate('en'))->sub_type_name,
                    'name_ar'     => optional($s->translate('ar'))->sub_type_name,
                    'category_id' => $s->category_id, // no FK column on sub_types → null
                ])->values()->all(),

                'genders'     => $named(Gender::with('translations')->get(), 'gender_name'),
                'grades'      => $named(Grade::with('translations')->get(), 'grade_name'),
                'dial_colors' => $named($colors, 'color_name'),
                'band_colors' => $named($colors, 'color_name'),
                'features'    => $named(Feature::with('translations')->get(), 'feature_name'),

                'banners' => BannerHome::all()->map(fn ($b) => [
                    'id'        => $b->id,
                    'image_url' => $img('Banner_home', $b->image),
                    'link'      => $b->offer_id, // linked offer (no dedicated link column)
                    'order'     => null,         // no order/sort column on banner_homes
                ])->values()->all(),

                'shipping_cities' => ShippingCity::with('translations')->get()->map(fn ($c) => [
                    'id'            => $c->id,
                    'name_en'       => optional($c->translate('en'))->city_name,
                    'name_ar'       => optional($c->translate('ar'))->city_name,
                    'shipping_cost' => $c->shipping_cost !== null ? (float) $c->shipping_cost : null,
                ])->values()->all(),
            ];
        });

        return response()->json($data);
    }
}
