<?php

namespace App\Models\Storefront;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `storefronts` — a sales channel (Watchizer = id 1, Brand Fashion, Nile Fashion). Never "brand" (D5).
 */
class Storefront extends Model
{
    public const WATCHIZER_ID = 1;

    protected $table = 'storefronts';

    /** @var list<string> */
    protected $fillable = ['code', 'name', 'domain', 'locales', 'default_locale', 'currency', 'is_active', 'settings'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'locales' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /** @return HasMany<StorefrontProduct, $this> */
    public function storefrontProducts(): HasMany
    {
        return $this->hasMany(StorefrontProduct::class, 'storefront_id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'storefront_product', 'storefront_id', 'product_id')
            ->withPivot(['is_visible', 'is_featured', 'sort_order', 'slug', 'effective_price', 'effective_sale_price', 'published_at'])
            ->withTimestamps();
    }

    /** @return HasMany<StorefrontCategory, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(StorefrontCategory::class, 'storefront_id');
    }

    /** @return HasMany<StorefrontBanner, $this> */
    public function banners(): HasMany
    {
        return $this->hasMany(StorefrontBanner::class, 'storefront_id');
    }

    /** @return HasMany<StorefrontRedirect, $this> */
    public function redirects(): HasMany
    {
        return $this->hasMany(StorefrontRedirect::class, 'storefront_id');
    }
}
