<?php

namespace App\Models\Storefront;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `storefront_product` — per-storefront placement of one product: visibility, featured,
 * sort, slug, gated price overrides (D3/D4). `effective_*` are service-maintained.
 */
class StorefrontProduct extends Model
{
    protected $table = 'storefront_product';

    /** @var list<string> */
    protected $fillable = [
        'storefront_id', 'product_id', 'is_visible', 'is_featured', 'sort_order', 'slug',
        'price_override', 'sale_price_override', 'effective_price', 'effective_sale_price', 'published_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
            'price_override' => 'decimal:2',
            'sale_price_override' => 'decimal:2',
            'effective_price' => 'decimal:2',
            'effective_sale_price' => 'decimal:2',
            'published_at' => 'datetime',
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
}
