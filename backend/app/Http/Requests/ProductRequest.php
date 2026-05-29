<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            // ── Basic ─────────────────────────────────────────
            'product_title.en'              => 'required|string|min:2|max:255',
            'product_title.ar'              => 'required|string|min:2|max:255',

            // ── Category (NEW 3-level system) ─────────────────
            // category_type_id مش required دلوقتي — الـ new system بيستخدم main_category_id
            'category_type_id'              => 'nullable|integer|exists:category_types,id',
            'main_category_id'              => 'nullable|integer|exists:categories,id',
            'sub_category_id'               => 'nullable|array',
            'sub_category_id.*'             => 'nullable|integer|exists:categories,id',
            'product_type_id'               => 'nullable|array',
            'product_type_id.*'             => 'nullable|integer|exists:categories,id',

            // ── sub_type_id — optional (مش required) ──────────
            'sub_type_id'                   => 'nullable|integer|exists:sub_types,id',

            // ── Gender — array (multi-select) ─────────────────
            'gender_id'                     => 'required|array|min:1',
            'gender_id.*'                   => 'integer|exists:genders,id',

            // ── Brand & Pricing ───────────────────────────────
            'brand_id'                      => 'required|integer|exists:brands,id',
            'purchase_price'                => 'required|numeric|min:0',
            'selling_price'                 => 'required|numeric|min:0',
            'sale_price_after_discount'     => 'nullable|numeric|min:0',
            'percentage_discount'           => 'nullable|numeric|min:0|max:100',

            // ── Stock ─────────────────────────────────────────
            'stock'                         => 'required|numeric|min:0',
            'market_stock'                  => 'nullable|numeric|min:0',
            'low_stock_threshold'           => 'nullable|numeric|min:0',

            // ── Grade & SKU ───────────────────────────────────
            'grade_id'                      => 'nullable|integer|exists:grades,id',
            'sku_unique'                    => 'nullable|string|min:2|max:255',

            // ── Watch Attributes ──────────────────────────────
            'dial_color_id'                 => 'nullable|array',
            'dial_color_id.*'               => 'integer|exists:colors,id',
            'band_color_id'                 => 'nullable|array',
            'band_color_id.*'               => 'integer|exists:colors,id',
            'band_closure_id'               => 'nullable|integer|exists:closure_types,id',
            'dial_display_type_id'          => 'nullable|integer|exists:display_types,id',
            'case_size'                     => 'nullable|numeric|min:0',
            'case_size_type_id'             => 'nullable|integer|exists:size_types,id',
            'case_shape_id'                 => 'nullable|integer|exists:shapes,id',
            'band_material_id'              => 'nullable|integer|exists:materials,id',
            'watch_movement_id'             => 'nullable|integer|exists:movement_types,id',
            'band_length'                   => 'nullable|numeric|min:0',
            'band_size_type_id'             => 'nullable|integer|exists:size_types,id',
            'water_resistance'              => 'nullable|numeric|min:0',
            'water_resistance_size_type_id' => 'nullable|integer|exists:size_types,id',
            'band_width'                    => 'nullable|numeric|min:0',
            'band_width_size_type_id'       => 'nullable|integer|exists:size_types,id',
            'case_thickness'                => 'nullable|numeric|min:0',
            'case_thickness_size_type_id'   => 'nullable|integer|exists:size_types,id',
            'dial_case_material_id'         => 'nullable|integer|exists:materials,id',
            'dial_glass_material_id'        => 'nullable|integer|exists:materials,id',
            'watch_height'                  => 'nullable|numeric|min:0',
            'watch_height_size_type_id'     => 'nullable|integer|exists:size_types,id',
            'watch_width'                   => 'nullable|numeric|min:0',
            'watch_width_size_type_id'      => 'nullable|integer|exists:size_types,id',
            'watch_length'                  => 'nullable|numeric|min:0',
            'watch_length_size_type_id'     => 'nullable|integer|exists:size_types,id',
            'model_name.ar'                 => 'nullable|string|max:255',
            'model_name.en'                 => 'nullable|string|max:255',
            'model_number'                  => 'nullable|string|max:255',
            'warranty_years'                => 'nullable|string|max:255',
            'interchangeable_dial'          => 'nullable|boolean',
            'interchangeable_strap'         => 'nullable|boolean',
            'watch_box'                     => 'nullable|boolean',
            'country.ar'                    => 'nullable|string|max:255',
            'country.en'                    => 'nullable|string|max:255',
            'stone.ar'                      => 'nullable|string|max:255',
            'stone.en'                      => 'nullable|string|max:255',

            // ── Features ──────────────────────────────────────
            'feature_id'                    => 'nullable|array',
            'feature_id.*'                  => 'integer|exists:features,id',

            // ── Descriptions ──────────────────────────────────
            'short_description.ar'          => 'required|string',
            'short_description.en'          => 'required|string',
            'long_description.ar'           => 'required|string',
            'long_description.en'           => 'required|string',

            // ── Settings ──────────────────────────────────────
            'active'                        => 'required|boolean',
            'search_keywords'               => 'nullable|string',

            // ── SEO (new fields) ──────────────────────────────
            'seo_title'                     => 'nullable|string|max:60',
            'seo_slug'                      => 'nullable|string|max:255',
            'seo_meta_description'          => 'nullable|string|max:160',

            // ── Extra attributes (bag/wallet/perfume/electronics) ─
            'bag_strap_type'                => 'nullable|string|max:255',
            'bag_compartments'              => 'nullable|numeric|min:0',
            'laptop_compartment'            => 'nullable|boolean',
            'waterproof'                    => 'nullable|boolean',
            'wallet_card_slots'             => 'nullable|numeric|min:0',
            'rfid_protection'               => 'nullable|boolean',
            'coin_pocket'                   => 'nullable|boolean',
            'perfume_volume'                => 'nullable|numeric|min:0',
            'perfume_type'                  => 'nullable|string|max:50',
            'perfume_scent'                 => 'nullable|string|max:255',
            'elec_battery_capacity'         => 'nullable|numeric|min:0',
            'elec_connectivity'             => 'nullable|string|max:255',

            // ── Variants ──────────────────────────────────────
            'variants'                      => 'nullable|array',
            'variants.*.name'               => 'nullable|string|max:255',
            'variants.*.price'              => 'nullable|numeric|min:0',
            'variants.*.stock'              => 'nullable|numeric|min:0',
            'variants.*.sku'                => 'nullable|string|max:255',
            'variants.*.image'              => 'nullable|image|mimes:png,jpg,webp,gif|max:5120',

            // ── Gallery images ────────────────────────────────
            'gallery_images'                => 'nullable|array|max:10',
            'gallery_images.*'              => 'image|mimes:png,jpg,webp,gif|max:5120',
        ];

        // ── Image + WA Code rules تختلف بين create و update ──
        if ($this->isMethod('post')) {
            $rules['image']   = 'required|image|mimes:png,jpg,webp,gif|max:5120';
            $rules['wa_code'] = 'required|unique:products,wa_code|string|min:2|max:255';
        } elseif ($this->isMethod('put') || $this->isMethod('patch')) {
            $rules['image']   = 'nullable|image|mimes:png,jpg,webp,gif|max:5120';
            $rules['wa_code'] = [
                'required', 'string', 'min:2', 'max:255',
                Rule::unique('products', 'wa_code')->ignore($this->route('product')),
            ];
        }

        return $rules;
    }

    /**
     * Custom error messages بالعربي والإنجليزي
     */
    public function messages(): array
    {
        return [
            'product_title.en.required'  => 'Product title in English is required.',
            'product_title.ar.required'  => 'اسم المنتج بالعربي مطلوب.',
            'brand_id.required'          => 'Please select a brand.',
            'gender_id.required'         => 'Please select at least one gender.',
            'gender_id.min'              => 'Please select at least one gender.',
            'stock.required'             => 'Express stock is required.',
            'selling_price.required'     => 'Selling price is required.',
            'purchase_price.required'    => 'Purchase price is required.',
            'short_description.ar.required' => 'الوصف المختصر بالعربي مطلوب.',
            'short_description.en.required' => 'Short description in English is required.',
            'long_description.ar.required'  => 'الوصف التفصيلي بالعربي مطلوب.',
            'long_description.en.required'  => 'Long description in English is required.',
            'image.required'             => 'Main product image is required.',
            'wa_code.required'           => 'WA Code is required.',
            'wa_code.unique'             => 'This WA Code is already used by another product.',
            'active.required'            => 'Please set product status.',
        ];
    }
}