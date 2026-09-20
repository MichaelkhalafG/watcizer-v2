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
    public const COLUMNS = [
        'stock_express', 'stock_market', 'stock', 'quantity',
        // The other three columns the LOW STOCK rule names (B5, 2026-09-20). They are here
        // for the same reason the stock columns are: `column()` refuses anything not on this
        // list, so a rule naming a column it has not declared fails loudly in this file
        // rather than reaching the database as text nobody vetted.
        'low_stock_threshold', 'is_active', 'in_stock',
    ];

    /** Every JSON column a lookup reference may be counted inside (wave 4D). */
    public const JSON_COLUMNS = ['specs'];

    /**
     * `JSON_EXTRACT(`column`, ?) = ?` — for counting a lookup id stored inside a JSON spec column.
     *
     * The column is whitelisted here exactly as a stock column is; the PATH and the id stay
     * BINDINGS at the call site, so nothing caller-supplied becomes SQL text. Used by the lookup
     * delete guard, which would otherwise not see a material that lives only in a product's
     * `specs` and would let it be deleted out from under 166 handbags.
     */
    public static function jsonExtract(string $column): Expression
    {
        if (! in_array($column, self::JSON_COLUMNS, true)) {
            throw new InvalidArgumentException("Sql::jsonExtract() may not name the column [{$column}].");
        }

        // Two placeholders: the JSON PATH and the value, both bound by the caller. `DB::raw()`
        // takes any string, so unlike `delta()` this needs no exemption — the safety is the
        // whitelist two lines above, which is the same safety `delta()` relies on.
        return DB::raw('JSON_EXTRACT(`'.$column.'`, ?) = ?');
    }

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

    /**
     * "LOW STOCK" — the whole rule, in one place, for every screen that asks.
     *
     * ── Why this now owns four clauses and not one (B5, 2026-09-20) ─────────────────
     *
     * It used to return the arithmetic alone, and the consequence was that three screens each
     * wrapped it in whatever else they thought "low" meant — or did not wrap it at all:
     *
     *   • products list      arithmetic only                              7,524
     *   • inventory view     `is_active` + `in_stock` + arithmetic        4,946
     *   • inventory BADGE    arithmetic, computed in PHP                  fired on all 2,578
     *                                                                     out-of-stock rows
     *
     * Two screens used the same Arabic words and disagreed by 2,578, and the badge contradicted
     * the filter beside it on ONE screen: every out-of-stock row was badged «منخفض» while
     * clicking «مخزون منخفض» returned none of them.
     *
     * A helper that owns a FRAGMENT cannot prevent that: every caller still has to remember the
     * rest, and two callers out of three did not. So the fragment is gone and this returns the
     * whole test. There is nothing left for a caller to add, and therefore nothing to forget.
     *
     * ── The four clauses, each earned ───────────────────────────────────────────────
     *
     *   `is_active = 1`            an inactive product is not selling; an alert to reorder it is
     *                              noise about a decision somebody already made.
     *   `in_stock = 1`             OUT of stock is a different state with its own view and its own
     *                              count. Low means "nearly gone", not "gone" — and this is the
     *                              clause the badge was missing, which is why it fired on all 2,578.
     *   `low_stock_threshold > 0`  the product form says, in as many words, *leave it at zero if you
     *                              do not want an alert for this product*. Measured 2026-09-20: NO
     *                              product currently sits at 0, so this clause changes no number
     *                              today. It is here because the form makes a promise and the rule
     *                              should keep it directly, not by the accident of `in_stock` also
     *                              excluding those rows.
     *   the arithmetic             both buckets together against the product's OWN threshold, which
     *                              is a column because a watch and a keychain do not run low at the
     *                              same count.
     *
     * ── Why this is also what the BADGE reads ───────────────────────────────────────
     *
     * The badge was computed in PHP from two of the row's columns, which made it a fourth
     * definition that no change to this file could reach. {@see self::lowStockSelect()} returns the
     * same expression as a SELECT, so the row carries the answer this function gave — not a
     * re-derivation that agrees with it today.
     *
     * The `literal-string` return type is what lets `whereRaw()` accept it at PHPStan level 10:
     * the value is a constant in the source, not something assembled from input. `$alias` is a
     * `match` over two literals rather than concatenation for the same reason — concatenating an
     * argument would destroy that type, and with it the reason this helper is safe. A third alias
     * means a third arm, in this file, which is the audit trail the class exists to provide.
     *
     * @return literal-string
     */
    public static function lowStock(string $alias = ''): string
    {
        foreach (['stock_express', 'stock_market', 'low_stock_threshold', 'is_active', 'in_stock'] as $column) {
            self::column($column);
        }

        return match ($alias) {
            '' => '(`is_active` = 1 AND `in_stock` = 1 AND `low_stock_threshold` > 0'
                .' AND (`stock_express` + `stock_market`) <= `low_stock_threshold`)',
            'p' => '(`p`.`is_active` = 1 AND `p`.`in_stock` = 1 AND `p`.`low_stock_threshold` > 0'
                .' AND (`p`.`stock_express` + `p`.`stock_market`) <= `p`.`low_stock_threshold`)',
            default => throw new InvalidArgumentException("Sql::lowStock() has no arm for the alias [{$alias}]."),
        };
    }

    /**
     * The same rule, as a SELECT — so a row can carry its own answer.
     *
     * This exists so the inventory BADGE is not a second opinion. It was `($express + $market) <=
     * $threshold` in PHP, two columns re-compared after the query had already decided something
     * else, and it disagreed with the filter on the same screen for every out-of-stock row.
     *
     * Reading it off the query means the badge cannot drift from the filter, because there is no
     * second expression to drift. MariaDB returns 1/0, which the caller casts.
     *
     * @return literal-string
     */
    public static function lowStockSelect(string $alias = ''): string
    {
        return match ($alias) {
            '' => self::lowStock().' AS `is_low`',
            'p' => self::lowStock('p').' AS `is_low`',
            default => throw new InvalidArgumentException("Sql::lowStockSelect() has no arm for the alias [{$alias}]."),
        };
    }

    private static function column(string $column): string
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("Sql helper refuses the column [{$column}]; add it to Sql::COLUMNS if it belongs there.");
        }

        return $column;
    }
}
