<?php

namespace App\Models\Catalog;

use App\Models\Inventory\InventoryMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `catalog_product_variants` — not exposed on any storefront until the variants phase
 * (CLEAN_CORE_STUDY §2.4, R2-20). Stock columns are ledger mirrors (InventoryService only).
 */
class ProductVariant extends Model
{
    protected $table = 'catalog_product_variants';

    /** @var list<string> */
    protected $fillable = ['product_id', 'sku', 'label', 'color_id', 'size_id', 'price_delta', 'is_active', 'sort'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'stock_express' => 'integer',
            'stock_market' => 'integer',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<Color, $this> */
    public function color(): BelongsTo
    {
        return $this->belongsTo(Color::class, 'color_id');
    }

    /** @return BelongsTo<Size, $this> */
    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class, 'size_id');
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'variant_id');
    }
}
