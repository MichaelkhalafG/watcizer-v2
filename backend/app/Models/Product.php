<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Astrotomic\Translatable\Translatable;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;

class Product extends Model implements TranslatableContract
{
    use HasFactory, Translatable;

    public $translatedAttributes = [
        'product_title',
        'model_name',
        'country',
        'stone',
        'long_description',
        'short_description',
    ];

    protected $fillable = [
        // ── Legacy fields ──────────────────────────────
        'category_type_id',
        'sub_type_id',
        'brand_id',
        'grade_id',
        'band_closure_id',
        'dial_display_type_id',
        'case_size',
        'case_size_type_id',
        'case_shape_id',
        'band_material_id',
        'watch_movement_id',
        'band_length',
        'band_size_type_id',
        'water_resistance',
        'water_resistance_size_type_id',
        'band_width',
        'band_width_size_type_id',
        'case_thickness',
        'case_thickness_size_type_id',
        'dial_case_material_id',
        'dial_glass_material_id',
        'watch_height',
        'watch_height_size_type_id',
        'watch_width',
        'watch_width_size_type_id',
        'watch_length',
        'watch_length_size_type_id',
        'sku_unique',
        'model_number',
        'image',
        'warranty_years',
        'interchangeable_dial',
        'interchangeable_strap',
        'hs_code',
        'average_rate',
        'purchase_price',
        'selling_price',
        'sale_price_after_discount',
        'percentage_discount',
        'stock',
        'market_stock',
        'search_keywords',
        'active',
        'watch_box',
        'wa_code',
        'created_by',
        'updated_by',

        // ── NEW: 3-level category system ───────────────
        'main_category_id',
        'sub_category_id',
        'product_type_id',

        // ── NEW: SEO fields ────────────────────────────
        'seo_title',
        'seo_slug',
        'seo_meta_description',

        // ── NEW: Stock management ──────────────────────
        'low_stock_threshold',

        // ── NEW: Extra attributes (bag/wallet/etc) ─────
        'extra_attributes',
    ];

    protected $hidden = [
        'purchase_price',
        'wa_code',
        'hs_code',
        'sku_unique',
        'created_by',
        'updated_by',
        'low_stock_threshold',
    ];

    // ── Legacy relations ───────────────────────────────────

    public function category()
    {
        return $this->hasOne(Category::class, 'id', 'category_id');
    }

    public function brand()
    {
        return $this->hasOne(Brand::class, 'id', 'brand_id');
    }

    public function category_type()
    {
        return $this->hasOne(CategoryType::class, 'id', 'category_type_id');
    }

    public function closure_type()
    {
        return $this->hasOne(ClosureType::class, 'id', 'band_closure_id');
    }

    public function color($table)
    {
        return $this->belongsToMany(Color::class, $table);
    }

    public function dialColor()
    {
        return $this->color('color_dial_product');
    }

    public function bandColor()
    {
        return $this->color('color_band_product');
    }

    public function display_type()
    {
        return $this->hasOne(DisplayType::class, 'id', 'dial_display_type_id');
    }

    public function feature()
    {
        return $this->belongsToMany(Feature::class);
    }

    public function gender()
    {
        return $this->belongsToMany(Gender::class);
    }

    public function grade()
    {
        return $this->hasOne(Grade::class, 'id', 'grade_id');
    }

    public function material($foreignKey)
    {
        return $this->hasOne(Material::class, 'id', $foreignKey);
    }

    public function bandMaterial()       { return $this->material('band_material_id'); }
    public function dialCaseMaterial()   { return $this->material('dial_case_material_id'); }
    public function dialGlassMaterial()  { return $this->material('dial_glass_material_id'); }

    // ── NEW: aliases matching API controller names ─────────
    public function band_material()      { return $this->material('band_material_id'); }
    public function dial_case_material() { return $this->material('dial_case_material_id'); }
    public function dial_glass_material(){ return $this->material('dial_glass_material_id'); }

    public function movement_type()
    {
        return $this->hasOne(MovementType::class, 'id', 'watch_movement_id');
    }

    public function shape()
    {
        return $this->hasOne(Shape::class, 'id', 'case_shape_id');
    }

    public function size_type($foreignKey)
    {
        return $this->hasOne(SizeType::class, 'id', $foreignKey);
    }

    public function caseSizeType()            { return $this->size_type('case_size_type_id'); }
    public function bandSizeType()            { return $this->size_type('band_size_type_id'); }
    public function waterResistanceSizeType() { return $this->size_type('water_resistance_size_type_id'); }
    public function bandWidthSizeType()       { return $this->size_type('band_width_size_type_id'); }
    public function caseThicknessSizeType()   { return $this->size_type('case_thickness_size_type_id'); }
    public function watchHeightSizeType()     { return $this->size_type('watch_height_size_type_id'); }
    public function watchWidthSizeType()      { return $this->size_type('watch_width_size_type_id'); }
    public function watchLengthSizeType()     { return $this->size_type('watch_length_size_type_id'); }

    // ── NEW: snake_case aliases for API with() calls ────────
    public function case_size_type()            { return $this->size_type('case_size_type_id'); }
    public function band_size_type()            { return $this->size_type('band_size_type_id'); }
    public function water_resistance_size_type(){ return $this->size_type('water_resistance_size_type_id'); }
    public function band_width_size_type()      { return $this->size_type('band_width_size_type_id'); }
    public function case_thickness_size_type()  { return $this->size_type('case_thickness_size_type_id'); }
    public function watch_height_size_type()    { return $this->size_type('watch_height_size_type_id'); }
    public function watch_width_size_type()     { return $this->size_type('watch_width_size_type_id'); }
    public function watch_length_size_type()    { return $this->size_type('watch_length_size_type_id'); }

    public function sub_type()
    {
        return $this->hasOne(SubType::class, 'id', 'sub_type_id');
    }

    // ── Product Images ─────────────────────────────────────
    // ✅ original name kept
    public function product_image()
    {
        return $this->hasMany(ProductImage::class);
    }

    // ✅ NEW alias — used in edit.blade.php and API
    public function productImages()
    {
        return $this->hasMany(ProductImage::class)->orderBy('sort');
    }

    public function product_rating()
    {
        return $this->hasMany(ProductRating::class);
    }

    public function order_items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // ── NEW: 3-level category relations ────────────────────
    // These use the NEW categories table (from CategorySeeder)
    public function mainCategory()
    {
        return $this->belongsTo(Category::class, 'main_category_id');
    }

    public function subCategory()
    {
        return $this->belongsTo(Category::class, 'sub_category_id');
    }

    public function productType()
    {
        return $this->belongsTo(Category::class, 'product_type_id');
    }
    // ── NEW: Product Variants ──────────────────────────────
public function variants()
{
    return $this->hasMany(ProductVariant::class)->with(['color', 'size']);
}

public function activeVariants()
{
    return $this->hasMany(ProductVariant::class)->where('is_active', true);
}

public function inStockVariants()
{
    return $this->hasMany(ProductVariant::class)->where('stock', '>', 0);
}
}