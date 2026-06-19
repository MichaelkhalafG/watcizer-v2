<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    /**
     * Full, sanitized product representation (PDP / single-product responses).
     * Exposes ONLY the whitelisted fields below — never internal columns
     * (purchase_price, wa_code, hs_code, sku_unique, created_by, updated_by,
     * low_stock_threshold) or pivot internals, and never reviewer email/phone.
     */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        $assetBase = rtrim((string) config('services.asset_base'), '/');
        $url = fn (?string $folder, ?string $file) => $file
            ? $assetBase . '/Uploads_Images/' . $folder . '/' . $file
            : null;

        // ── Images: main image + ordered gallery ─────────────────────────────
        $images = [];
        if ($p->image) {
            $images[] = $url('Product', $p->image);
        }
        foreach ($p->productImages as $img) {
            if ($img->image) {
                $images[] = $url('Product_image', $img->image);
            }
        }

        // ── Translatable mini-shape helpers for related models ───────────────
        $named = fn ($model, string $attr) => $model ? [
            'id'      => $model->id,
            'name_en' => optional($model->translate('en'))->{$attr},
            'name_ar' => optional($model->translate('ar'))->{$attr},
        ] : null;

        $manyNamed = fn ($collection, string $attr) => $collection
            ->map(fn ($m) => [
                'id'      => $m->id,
                'name_en' => optional($m->translate('en'))->{$attr},
                'name_ar' => optional($m->translate('ar'))->{$attr},
            ])->values();

        // ── Ratings (10 most recent, name only — no email/phone) ─────────────
        $ratings = $p->product_rating
            ->sortByDesc('created_at')
            ->take(10)
            ->map(function ($r) {
                $u = $r->user;
                $name = $u ? trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) : '';
                return [
                    'user_name'  => $name !== '' ? $name : 'Anonymous',
                    'rating'     => (int) $r->rating,
                    'comment'    => $r->comment,
                    'created_at' => optional($r->created_at)->toIso8601String(),
                ];
            })->values();

        $ratingsCount = $p->product_rating->count();

        return [
            'id'             => $p->id,
            'name_en'        => optional($p->translate('en'))->product_title,
            'name_ar'        => optional($p->translate('ar'))->product_title,
            'slug'           => $p->seo_slug ?: (string) $p->id,
            'description_en' => optional($p->translate('en'))->long_description,
            'description_ar' => optional($p->translate('ar'))->long_description,
            'price'          => $p->selling_price !== null ? (float) $p->selling_price : null,
            'sale_price'     => $p->sale_price_after_discount !== null ? (float) $p->sale_price_after_discount : null,
            'images'         => $images,
            'brand'          => $p->brand ? [
                'id'       => $p->brand->id,
                'name_en'  => optional($p->brand->translate('en'))->brand_name,
                'name_ar'  => optional($p->brand->translate('ar'))->brand_name,
                'logo_url' => $url('Brand', $p->brand->image),
            ] : null,
            'main_category'  => $named($p->mainCategory, 'name'),
            'sub_type'       => $named($p->sub_type, 'sub_type_name'),
            // gender is many-to-many → array of objects
            'gender'         => $manyNamed($p->gender, 'gender_name'),
            'grade'          => $named($p->grade, 'grade_name'),
            'dial_colors'    => $manyNamed($p->dialColor, 'color_name'),
            'band_colors'    => $manyNamed($p->bandColor, 'color_name'),
            'features'       => $manyNamed($p->feature, 'feature_name'),
            'in_stock'       => (bool) (((int) $p->stock) > 0 || ((int) $p->market_stock) > 0),
            'stock_quantity' => (int) (($p->stock ?? 0) + ($p->market_stock ?? 0)),
            'average_rating' => $ratingsCount ? round((float) $p->product_rating->avg('rating'), 1) : null,
            'ratings_count'  => $ratingsCount,
            'ratings'        => $ratings,

            // ── Legacy compatibility aliases (P1-C1) ─────────────────────────
            'product_title'             => optional($p->translate('en'))->product_title,
            'product_title_ar'          => optional($p->translate('ar'))->product_title,
            'selling_price'             => $p->selling_price !== null ? (float) $p->selling_price : null,
            'sale_price_after_discount' => $p->sale_price_after_discount !== null ? (float) $p->sale_price_after_discount : null,
            'short_description'         => optional($p->translate('en'))->short_description,
            'short_description_ar'      => optional($p->translate('ar'))->short_description,
            'long_description'          => optional($p->translate('en'))->long_description,
            'long_description_ar'       => optional($p->translate('ar'))->long_description,
            'image'                     => $url('Product', $p->image),
            'stock'                     => (int) ($p->stock ?? 0),
            'market_stock'              => (int) ($p->market_stock ?? 0),
            'brand_name'                => optional($p->brand?->translate('en'))->brand_name,
            'brand_name_ar'             => optional($p->brand?->translate('ar'))->brand_name,
            'sub_type_name'             => optional($p->sub_type?->translate('en'))->sub_type_name,
            'sub_type_name_ar'          => optional($p->sub_type?->translate('ar'))->sub_type_name,
            'dial_color'                => $p->dialColor->map(fn ($c) => [
                'id'            => $c->id,
                'color_id'      => $c->id,
                'name'          => optional($c->translate('en'))->color_name,
                'name_ar'       => optional($c->translate('ar'))->color_name,
                'color_name_en' => optional($c->translate('en'))->color_name,
                'color_name_ar' => optional($c->translate('ar'))->color_name,
                'color_value'   => $c->color_value ?? null,
            ])->values(),
            'band_color'                => $p->bandColor->map(fn ($c) => [
                'id'            => $c->id,
                'color_id'      => $c->id,
                'name'          => optional($c->translate('en'))->color_name,
                'name_ar'       => optional($c->translate('ar'))->color_name,
                'color_name_en' => optional($c->translate('en'))->color_name,
                'color_name_ar' => optional($c->translate('ar'))->color_name,
                'color_value'   => $c->color_value ?? null,
            ])->values(),
            'feature'                   => $p->feature->map(fn ($f) => [
                'id'      => $f->id,
                'name'    => optional($f->translate('en'))->feature_name,
                'name_ar' => optional($f->translate('ar'))->feature_name,
            ])->values(),

            // ── Spec fields (confirmed in PART A) ────────────────────────────
            // direct columns:
            'water_resistance' => $p->water_resistance,
            'case_thickness'   => $p->case_thickness,
            'band_length'      => $p->band_length,
            'model_number'     => $p->model_number,
            // FK relations → translated lookup names (en + ar):
            'watch_movement'    => optional($p->movement_type?->translate('en'))->movement_type_name,
            'watch_movement_ar' => optional($p->movement_type?->translate('ar'))->movement_type_name,
            'case_shape'        => optional($p->shape?->translate('en'))->shape_name,
            'case_shape_ar'     => optional($p->shape?->translate('ar'))->shape_name,
            'band_material'     => optional($p->band_material?->translate('en'))->material_name,
            'band_material_ar'  => optional($p->band_material?->translate('ar'))->material_name,
            'glass_material'    => optional($p->dial_glass_material?->translate('en'))->material_name,
            'glass_material_ar' => optional($p->dial_glass_material?->translate('ar'))->material_name,
            'case_material'     => optional($p->dial_case_material?->translate('en'))->material_name,
            'case_material_ar'  => optional($p->dial_case_material?->translate('ar'))->material_name,

            // ── Full-parity fields for ProductDisplay (P1-R1) ────────────────
            // Category type (Watches/Fashion top level)
            'category_type'      => optional($p->category_type?->translate('en'))->category_type_name,
            'category_type_ar'   => optional($p->category_type?->translate('ar'))->category_type_name,
            'category_type_name' => optional($p->category_type?->translate('en'))->category_type_name, // EN — drives the isfashion check

            // Watch dimensions (direct columns)
            'band_width'   => $p->band_width,
            'watch_height' => $p->watch_height,
            'watch_width'  => $p->watch_width,
            'watch_length' => $p->watch_length,

            // Size-type names (FK relations → SizeType.size_type_name)
            'water_resistance_size_type' => optional($p->waterResistanceSizeType?->translate('en'))->size_type_name,
            'case_size_type'             => optional($p->caseSizeType?->translate('en'))->size_type_name,
            'band_size_type'             => optional($p->bandSizeType?->translate('en'))->size_type_name,
            'band_width_size_type'       => optional($p->bandWidthSizeType?->translate('en'))->size_type_name,
            'case_thickness_size_type'   => optional($p->caseThicknessSizeType?->translate('en'))->size_type_name,
            'watch_height_size_type'     => optional($p->watchHeightSizeType?->translate('en'))->size_type_name,
            'watch_width_size_type'      => optional($p->watchWidthSizeType?->translate('en'))->size_type_name,
            'watch_length_size_type'     => optional($p->watchLengthSizeType?->translate('en'))->size_type_name,

            // Materials under the names ProductDisplay reads
            'dial_glass_material' => optional($p->dial_glass_material?->translate('en'))->material_name,
            'dial_case_material'  => optional($p->dial_case_material?->translate('en'))->material_name,

            // Closure / display type (relations: closure_type / display_type)
            'band_closure'        => optional($p->closure_type?->translate('en'))->closure_type_name,
            'band_closure_ar'     => optional($p->closure_type?->translate('ar'))->closure_type_name,
            'dial_display_type'   => optional($p->display_type?->translate('en'))->display_type_name,
            'dial_display_type_ar'=> optional($p->display_type?->translate('ar'))->display_type_name,

            // Country / stone (translatable attributes on the product itself)
            'country'    => optional($p->translate('en'))->country,
            'country_ar' => optional($p->translate('ar'))->country,
            'stone'      => optional($p->translate('en'))->stone,
            'stone_ar'   => optional($p->translate('ar'))->stone,

            // Brand / grade / sub_type as strings (legacy aliases alongside the objects)
            'brand_string'    => optional($p->brand?->translate('en'))->brand_name,
            'grade_string'    => optional($p->grade?->translate('en'))->grade_name,
            'sub_type_string' => optional($p->sub_type?->translate('en'))->sub_type_name,

            // Gender / features as joined strings (ProductDisplay .join()s them)
            'gender_string'  => $p->gender->map(fn ($g) => optional($g->translate('en'))->gender_name)->filter()->join(', '),
            'feature_string' => $p->feature->map(fn ($f) => optional($f->translate('en'))->feature_name)->filter()->join(', '),
        ];
    }
}
