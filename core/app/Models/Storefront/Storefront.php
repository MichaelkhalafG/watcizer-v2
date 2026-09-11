<?php

namespace App\Models\Storefront;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `storefronts` — a sales channel (Watchizer = id 1, Brand Fashion, Nile Fashion). Never "brand" (D5).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $domain
 * @property list<string> $locales
 * @property string $default_locale
 * @property string $currency
 * @property bool $is_active
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Storefront extends Model
{
    public const WATCHIZER_ID = 1;

    /**
     * Brand Fashion — the second storefront, launching before the mid-October 2026 season
     * (AGENTS §1). The id is EXPLICIT and deterministic for the same reason Watchizer's is
     * (study §2.9.3): rehearsal, local and production must agree on it, because
     * `storefront_categories.path`, every `storefront_product` row and every URL a team member
     * bookmarks carry it.
     */
    public const BRAND_FASHION_ID = 2;

    /**
     * The active storefronts, lowest id first — the list every per-storefront write iterates.
     *
     * Read from the DATABASE, never from a constant: a storefront can be deactivated from the
     * settings screen, and a transform that kept writing placements for a switched-off channel
     * would be inventing rows nobody asked for.
     *
     * @return list<int>
     */
    public static function activeIds(): array
    {
        $out = [];
        foreach (self::query()->where('is_active', true)->orderBy('id')->pluck('id') as $id) {
            if (is_numeric($id)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

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
