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

    private static function column(string $column): string
    {
        if (! in_array($column, self::COLUMNS, true)) {
            throw new InvalidArgumentException("Sql helper refuses the column [{$column}]; add it to Sql::COLUMNS if it belongs there.");
        }

        return $column;
    }
}
