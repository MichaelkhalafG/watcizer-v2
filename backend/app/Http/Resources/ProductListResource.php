<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductListResource extends JsonResource
{
    /**
     * Server-shaped product card for the listing endpoint.
     * Exposes ONLY the whitelisted fields below — no internal columns
     * (purchase_price, wa_code, hs_code, sku_unique, created_by,
     * updated_by, low_stock_threshold) are ever included.
     */
    public function toArray(Request $request): array
    {
        $p = $this->resource;

        $assetBase = rtrim((string) config('services.asset_base'), '/');

        // Provided by withAvg()/withCount() in the controller; fall back to the
        // loaded relation if for some reason they are absent.
        $avg = $p->product_rating_avg_rating
            ?? ($p->relationLoaded('product_rating') ? $p->product_rating->avg('rating') : null);
        $count = $p->product_rating_count
            ?? ($p->relationLoaded('product_rating') ? $p->product_rating->count() : 0);

        return [
            'id'               => $p->id,
            'name_en'          => optional($p->translate('en'))->product_title,
            'name_ar'          => optional($p->translate('ar'))->product_title,
            'slug'             => $p->seo_slug ?: (string) $p->id,
            'price'            => $p->selling_price !== null ? (float) $p->selling_price : null,
            'sale_price'       => $p->sale_price_after_discount !== null ? (float) $p->sale_price_after_discount : null,
            'main_image_url'   => $p->image ? $assetBase . '/Uploads_Images/Product/' . $p->image : null,
            'brand_name_en'    => optional(optional($p->brand)->translate('en'))->brand_name,
            'brand_name_ar'    => optional(optional($p->brand)->translate('ar'))->brand_name,
            'category_name_en' => optional(optional($p->mainCategory)->translate('en'))->name,
            'category_name_ar' => optional(optional($p->mainCategory)->translate('ar'))->name,
            'grade_name_en'    => optional(optional($p->grade)->translate('en'))->grade_name,
            'grade_name_ar'    => optional(optional($p->grade)->translate('ar'))->grade_name,
            // gender is many-to-many → arrays (a product can be Men / Women / Unisex)
            'gender_name_en'   => $p->gender->map(fn ($g) => optional($g->translate('en'))->gender_name)->filter()->values(),
            'gender_name_ar'   => $p->gender->map(fn ($g) => optional($g->translate('ar'))->gender_name)->filter()->values(),
            'in_stock'         => (bool) (((int) $p->stock) > 0 || ((int) $p->market_stock) > 0),
            'average_rating'   => $avg !== null ? round((float) $avg, 2) : null,
            'ratings_count'    => (int) $count,

            // ── Legacy compatibility aliases (P1-C1) — let existing frontend
            //    consumers read the new endpoint with their current field names.
            'product_title'             => optional($p->translate('en'))->product_title,
            'product_title_ar'          => optional($p->translate('ar'))->product_title,
            'selling_price'             => $p->selling_price !== null ? (float) $p->selling_price : null,
            'sale_price_after_discount' => $p->sale_price_after_discount !== null ? (float) $p->sale_price_after_discount : null,
            'short_description'         => optional($p->translate('en'))->short_description,
            'short_description_ar'      => optional($p->translate('ar'))->short_description,
            'image'                     => $p->image ? $assetBase . '/Uploads_Images/Product/' . $p->image : null,
            'stock'                     => (int) ($p->stock ?? 0),
            'market_stock'              => (int) ($p->market_stock ?? 0),
            'active'                    => (int) ($p->active ?? 0),
            'brand_name'                => optional($p->brand?->translate('en'))->brand_name,
            'brand_name_ar'             => optional($p->brand?->translate('ar'))->brand_name,
            'sub_type_name'             => optional($p->sub_type?->translate('en'))->sub_type_name,
            'sub_type_name_ar'          => optional($p->sub_type?->translate('ar'))->sub_type_name,
        ];
    }
}
