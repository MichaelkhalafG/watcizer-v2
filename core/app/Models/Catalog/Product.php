<?php

namespace App\Models\Catalog;

use App\Models\Inventory\InventoryMovement;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontCategoryProduct;
use App\Models\Storefront\StorefrontProduct;
use App\Models\User;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * `catalog_products` — the single product record shared by every storefront (D3).
 *
 * Stock columns are denormalised mirrors of the `inventory_movements` ledger and are
 * written ONLY by App\Services\InventoryService (D4). Never mass-assign them.
 */
class Product extends Model implements TranslatableContract
{
    use SoftDeletes;
    use Translatable;

    public const FAMILIES = ['watch', 'fashion', 'bag', 'wallet', 'perfume', 'electronics', 'other'];

    protected $table = 'catalog_products';

    /** @var list<string> */
    public array $translatedAttributes = [
        'title', 'short_description', 'long_description', 'model_name', 'country', 'stone',
        'meta_title', 'meta_description',
    ];

    /** @var list<string> */
    protected $fillable = [
        'family', 'brand_id', 'grade_id', 'wa_code', 'sku', 'model_number', 'hs_code',
        'purchase_price', 'selling_price', 'sale_price', 'currency', 'low_stock_threshold',
        'warranty_years', 'is_active', 'search_keywords', 'specs', 'created_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'sale_price' => 'decimal:2',
            'stock_express' => 'integer',
            'stock_market' => 'integer',
            'in_stock' => 'boolean',
            'is_active' => 'boolean',
            'rating_avg' => 'decimal:2',
            'rating_count' => 'integer',
            'specs' => 'array',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /** @return BelongsTo<Grade, $this> */
    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasOne<ProductWatchSpecs, $this> */
    public function watchSpecs(): HasOne
    {
        return $this->hasOne(ProductWatchSpecs::class, 'product_id');
    }

    /** @return HasMany<ProductImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id')->orderByDesc('is_cover')->orderBy('sort');
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id');
    }

    /** @return BelongsToMany<Feature, $this> */
    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'catalog_product_feature', 'product_id', 'feature_id');
    }

    /** @return BelongsToMany<Gender, $this> */
    public function genders(): BelongsToMany
    {
        return $this->belongsToMany(Gender::class, 'catalog_product_gender', 'product_id', 'gender_id');
    }

    /** @return BelongsToMany<Color, $this> */
    public function colors(): BelongsToMany
    {
        return $this->belongsToMany(Color::class, 'catalog_product_color', 'product_id', 'color_id')->withPivot('role');
    }

    /** @return HasMany<StorefrontProduct, $this> */
    public function storefrontProducts(): HasMany
    {
        return $this->hasMany(StorefrontProduct::class, 'product_id');
    }

    /** @return BelongsToMany<Storefront, $this> */
    public function storefronts(): BelongsToMany
    {
        return $this->belongsToMany(Storefront::class, 'storefront_product', 'product_id', 'storefront_id')
            ->withPivot(['is_visible', 'is_featured', 'sort_order', 'slug', 'effective_price', 'effective_sale_price', 'published_at'])
            ->withTimestamps();
    }

    /** @return HasMany<StorefrontCategoryProduct, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(StorefrontCategoryProduct::class, 'product_id');
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'product_id');
    }
}
