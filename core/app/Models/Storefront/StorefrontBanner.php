<?php

namespace App\Models\Storefront;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `storefront_banners` — replaces the legacy banner_homes/sides/bottoms per storefront;
 * `placement` is an application constant (home | side | bottom …), `type_show` mob | pc.
 */
class StorefrontBanner extends Model
{
    protected $table = 'storefront_banners';

    /** @var list<string> */
    protected $fillable = [
        'storefront_id', 'placement', 'image_path', 'type_show', 'link_url', 'product_id', 'storefront_category_id',
        'sort_order', 'is_active', 'starts_at', 'ends_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class, 'storefront_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<StorefrontCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(StorefrontCategory::class, 'storefront_category_id');
    }
}
