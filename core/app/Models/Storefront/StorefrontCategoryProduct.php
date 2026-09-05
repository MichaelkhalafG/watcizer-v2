<?php

namespace App\Models\Storefront;

use App\Models\Catalog\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `storefront_category_product` — a product placed in a category of one storefront;
 * at most one placement per storefront is `is_primary` (breadcrumb / canonical).
 */
class StorefrontCategoryProduct extends Model
{
    protected $table = 'storefront_category_product';

    /** @var list<string> */
    protected $fillable = ['storefront_id', 'storefront_category_id', 'product_id', 'sort_order', 'is_primary'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class, 'storefront_id');
    }

    /** @return BelongsTo<StorefrontCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(StorefrontCategory::class, 'storefront_category_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }
}
