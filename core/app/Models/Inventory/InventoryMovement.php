<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

/**
 * One row of the append-only stock ledger (`inventory_movements`, study §4.2).
 *
 * The table has `created_at` and no `updated_at` — a movement is a fact about a moment and is
 * never edited, so Eloquent's timestamp pair is switched off and `created_at` is written
 * explicitly by the service. A correction is a NEW row with the opposite delta, never an UPDATE
 * of an old one (milestone audit finding against the step-20 baseline).
 *
 * @property int $id
 * @property int $product_id
 * @property int|null $variant_id
 * @property string $bucket
 * @property int $quantity_delta
 * @property int $quantity_after
 * @property string $reason
 * @property string|null $reference_type
 * @property int|null $reference_id
 * @property int|null $reference_line_id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property int|null $storefront_id
 * @property string|null $external_ref
 * @property string|null $note
 * @property string $created_at
 */
final class InventoryMovement extends Model
{
    public $timestamps = false;

    protected $table = 'inventory_movements';

    /** @var list<string> */
    protected $fillable = [
        'product_id', 'variant_id', 'bucket', 'quantity_delta', 'quantity_after', 'reason',
        'reference_type', 'reference_id', 'reference_line_id', 'actor_type', 'actor_id',
        'storefront_id', 'external_ref', 'note', 'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'variant_id' => 'integer',
            'quantity_delta' => 'integer',
            'quantity_after' => 'integer',
            'reference_id' => 'integer',
            'reference_line_id' => 'integer',
            'actor_id' => 'integer',
            'storefront_id' => 'integer',
        ];
    }
}
