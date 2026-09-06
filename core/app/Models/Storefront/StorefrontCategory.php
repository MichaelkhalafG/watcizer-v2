<?php

namespace App\Models\Storefront;

use App\Models\Catalog\Product;
use Astrotomic\Translatable\Contracts\Translatable as TranslatableContract;
use Astrotomic\Translatable\Translatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;

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

    /**
     * The permanent menu-visibility rule (CLEAN_CORE_STUDY §3.3, decided 2026-09-06): a node is
     * listed iff it is active, flagged for the menu, AND it or any descendant (materialised
     * `path` prefix) holds at least one product that is visible on this storefront, active
     * and not soft-deleted. Nothing stamps this; a zero-product node hides itself and comes
     * back the moment a visible product is placed in it. Every v2/compat category listing
     * MUST go through this scope; the dashboard (an admin view) does not.
     *
     * @param  Builder<StorefrontCategory>  $query
     * @return Builder<StorefrontCategory>
     */
    public function scopeVisibleInMenu(Builder $query, int $storefrontId): Builder
    {
        return $query
            ->where('storefront_categories.storefront_id', $storefrontId)
            ->where('storefront_categories.is_active', true)
            ->where('storefront_categories.show_in_menu', true)
            ->whereExists(function (QueryBuilder $q) use ($storefrontId): void {
                $q->selectRaw('1')
                    ->from('storefront_category_product as scp')
                    ->join('storefront_categories as node', 'node.id', '=', 'scp.storefront_category_id')
                    ->join('storefront_product as sp', function (JoinClause $j) use ($storefrontId): void {
                        $j->on('sp.product_id', '=', 'scp.product_id')->where('sp.storefront_id', '=', $storefrontId);
                    })
                    ->join('catalog_products as cp', 'cp.id', '=', 'sp.product_id')
                    ->where('scp.storefront_id', $storefrontId)
                    ->where('sp.is_visible', true)
                    ->where('cp.is_active', true)
                    ->whereNull('cp.deleted_at')
                    ->whereRaw("node.path LIKE CONCAT(storefront_categories.path, '%')");
            });
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
