<?php

namespace App\Models\Inventory;

use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariant;
use App\Models\Storefront\Storefront;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * `inventory_movements` — the append-only stock ledger (D4). Rows are created ONLY by
 * App\Services\InventoryService; `quantity_after` is the bucket balance after the delta.
 */
class InventoryMovement extends Model
{
    public const UPDATED_AT = null;

    public const BUCKETS = ['express', 'market'];

    public const REASONS = ['order', 'order_cancel', 'payment_failed', 'restock', 'manual', 'import', 'adjustment', 'erp_sync', 'transform'];

    protected $table = 'inventory_movements';

    /** @var list<string> */
    protected $fillable = [
        'product_id', 'variant_id', 'bucket', 'quantity_delta', 'quantity_after', 'reason',
        'reference_type', 'reference_id', 'actor_type', 'actor_id', 'storefront_id', 'external_ref', 'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity_delta' => 'integer',
            'quantity_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }

    /** @return BelongsTo<Storefront, $this> */
    public function storefront(): BelongsTo
    {
        return $this->belongsTo(Storefront::class, 'storefront_id');
    }

    /** @return MorphTo<Model, $this> */
    public function reference(): MorphTo
    {
        return $this->morphTo('reference', 'reference_type', 'reference_id');
    }
}
