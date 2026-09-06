<?php

namespace App\Transform;

use InvalidArgumentException;
use stdClass;

/**
 * Typed accessors for query-builder rows (stdClass). Every legacy value passes
 * through here so a wrong assumption about a column's shape fails loudly instead
 * of being silently coerced.
 */
final class Row
{
    private static function raw(stdClass $row, string $column): mixed
    {
        if (! property_exists($row, $column)) {
            throw new InvalidArgumentException("Row has no column [$column] — the explicit select list and the accessor disagree.");
        }

        return $row->{$column};
    }

    public static function int(stdClass $row, string $column): int
    {
        $v = self::raw($row, $column);
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '' && preg_match('/^-?\d+$/', $v) === 1) {
            return (int) $v;
        }
        if (is_float($v) && floor($v) === $v) {
            return (int) $v;
        }
        throw new InvalidArgumentException(sprintf('Column [%s] is not an integer (%s).', $column, var_export($v, true)));
    }

    public static function nint(stdClass $row, string $column): ?int
    {
        return self::raw($row, $column) === null ? null : self::int($row, $column);
    }

    public static function str(stdClass $row, string $column): string
    {
        $v = self::raw($row, $column);
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        throw new InvalidArgumentException(sprintf('Column [%s] is not a string (%s).', $column, var_export($v, true)));
    }

    public static function nstr(stdClass $row, string $column): ?string
    {
        return self::raw($row, $column) === null ? null : self::str($row, $column);
    }

    /** Decimal columns arrive as strings from PDO; keep them as canonical "123.45" strings. */
    public static function money(stdClass $row, string $column): string
    {
        $v = self::raw($row, $column);
        if (is_string($v) && is_numeric($v)) {
            return number_format((float) $v, 2, '.', '');
        }
        if (is_int($v) || is_float($v)) {
            return number_format((float) $v, 2, '.', '');
        }
        throw new InvalidArgumentException(sprintf('Column [%s] is not a decimal (%s).', $column, var_export($v, true)));
    }

    public static function nmoney(stdClass $row, string $column): ?string
    {
        return self::raw($row, $column) === null ? null : self::money($row, $column);
    }

    public static function nfloat(stdClass $row, string $column): ?float
    {
        $v = self::raw($row, $column);
        if ($v === null) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }
        throw new InvalidArgumentException(sprintf('Column [%s] is not numeric (%s).', $column, var_export($v, true)));
    }

    public static function nbool(stdClass $row, string $column): ?bool
    {
        $v = self::raw($row, $column);
        if ($v === null) {
            return null;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }
        if (is_string($v) && in_array($v, ['0', '1'], true)) {
            return $v === '1';
        }
        throw new InvalidArgumentException(sprintf('Column [%s] is not a boolean (%s).', $column, var_export($v, true)));
    }

    public static function bool(stdClass $row, string $column): bool
    {
        return self::nbool($row, $column) ?? false;
    }
}
