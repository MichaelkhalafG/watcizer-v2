<?php

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place in this application that builds a raw SQL fragment out of a column name and a
 * number.
 *
 * Relative updates (`stock_express = stock_express + (-2)`, `quantity = quantity + 3`) cannot be
 * expressed with a binding in an UPDATE's SET list, so they have to become text. Concentrating
 * that here means there is exactly one construction site to audit instead of one per caller, and
 * it can be made safe by construction: the column is checked against a whitelist and the delta is
 * an `int`, so no caller-supplied string ever reaches the SQL — which is also why the single
 * PHPStan `literal-string` exemption in the codebase lives here and nowhere else.
 */
final class Sql
{
    /** Every column this helper is allowed to name. Nothing outside the list can be built. */
    public const COLUMNS = ['stock_express', 'stock_market', 'stock', 'quantity'];

    /** `` `column` + (delta) `` — a relative change. */
    public static function delta(string $column, int $delta): Expression
    {
        /** @phpstan-ignore argument.type */
        return DB::raw('`'.self::column($column).'` + ('.$delta.')');
    }

    /**
     * `` ((`a` + (delta)) > 0 OR `b` > 0) `` — the denormalised `in_stock` flag recomputed from
     * the bucket that is moving and the one that is not, in the same statement that moves it.
     */
    public static function eitherBucketPositive(string $moving, string $other, int $delta): Expression
    {
        /** @phpstan-ignore argument.type */
        return DB::raw('((`'.self::column($moving).'` + ('.$delta.')) > 0 OR `'.self::column($other).'` > 0)');
    }

    /**
     * `EXISTS (an ACTIVE variant of this product with stock in either bucket)` — the value of
     * `catalog_products.in_stock` for a product that HAS variants (wave 3.5).
     *
     * A correlated subquery rather than a maintained counter, because it is evaluated in the same
     * statement that moves the variant and must see that movement. It reads at most a handful of
     * rows through `cpv_product_active_idx`.
     */
    public static function anyActiveVariantInStock(): Expression
    {
        return DB::raw(
            '(EXISTS (SELECT 1 FROM `catalog_product_variants` `v` '.
            'WHERE `v`.`product_id` = `catalog_products`.`id` AND `v`.`is_active` = 1 '.
            'AND (`v`.`stock_express` > 0 OR `v`.`stock_market` > 0)))'
        );
    }

    /**
     * `catalog_products.in_stock` re-derived at whichever level owns the product, in ONE statement:
     * from the ACTIVE variants when the product has variants, from its own two buckets when it has
     * none.
     *
     * The CASE is not decoration. `anyActiveVariantInStock()` alone returns 0 for a product with no
     * variants, so using it unconditionally marked every plain watch out of stock — 🟠-2 of the
     * 2026-09-10 review, found in `recomputeInStock()`. Doing the level test in PHP and choosing an
     * expression would work too, but it would read the variant table in one statement and write in
     * another, so a variant inserted between the two would flip the flag the wrong way; one
     * statement cannot be interleaved with itself.
     */
    public static function inStockAtEitherLevel(): Expression
    {
        return DB::raw(
            '(CASE WHEN EXISTS (SELECT 1 FROM `catalog_product_variants` `hv` '.
            'WHERE `hv`.`product_id` = `catalog_products`.`id`) '.
            'THEN (EXISTS (SELECT 1 FROM `catalog_product_variants` `v` '.
            'WHERE `v`.`product_id` = `catalog_products`.`id` AND `v`.`is_active` = 1 '.
            'AND (`v`.`stock_express` > 0 OR `v`.`stock_market` > 0))) '.
            'ELSE (`catalog_products`.`stock_express` > 0 OR `catalog_products`.`stock_market` > 0) END)'
        );
    }

    private static function column(string $column): string
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("Sql helper refuses the column [{$column}]; add it to Sql::COLUMNS if it belongs there.");
        }

        return $column;
    }
}
