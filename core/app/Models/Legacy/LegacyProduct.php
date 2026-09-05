<?php

namespace App\Models\Legacy;

/**
 * Legacy `products` (60 columns, see SCHEMA_DRIFT_REPORT §2.3). Read-only; the transform
 * selects explicit column lists from it (AGENTS.md §2.9) — never rely on $fillable here.
 *
 * @property int $id
 * @property string $wa_code
 * @property int $active
 * @property int $stock
 * @property int|null $market_stock
 */
class LegacyProduct extends LegacyModel
{
    protected $table = 'products';

    /** @var list<string> */
    protected $guarded = ['*'];
}
