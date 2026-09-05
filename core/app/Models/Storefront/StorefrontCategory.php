<?php

namespace App\Models\Storefront;

use App\Models\Catalog\Product;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `storefront_categories` — per-storefront tree with a materialised `path` ("/12/57/").
 * `path`/`depth` are maintained by the category service's moveTo(), never by hand (R2-22).
 */
class StorefrontCategory extends Model implements TranslatableContract
{
    use Translatable;

    protected $table = 'storefront_categories';

    /** @var list<string> */
    public array $translatedAttributes = ['name', 'description', 'meta_title', 'meta_description'];

    /** @var list<string> */
    protected $fillable = [
        'storefront_id', 'parent_id', 'depth', 'path', 'slug', 'image_path', 'icon', 'is_active', 'show_in_menu',
        'sort_order', 'legacy_source', 'legacy_id', 'legacy_parent_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'depth' => 'integer',
            'is_active' => 'boolean',
            'show_in_menu' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class, 'storefront_id');
    }

    /** @return BelongsTo<StorefrontCategory, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(StorefrontCategory::class, 'parent_id');
    }

    /** @return HasMany<StorefrontCategory, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(StorefrontCategory::class, 'parent_id')->orderBy('sort_order');
    }

    /** @return HasMany<StorefrontCategoryProduct, $this> */
    public function placements(): HasMany
    {
        return $this->hasMany(StorefrontCategoryProduct::class, 'storefront_category_id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'storefront_category_product', 'storefront_category_id', 'product_id')
            ->withPivot(['storefront_id', 'sort_order', 'is_primary'])
            ->withTimestamps();
    }
}
