<?php

namespace Tests\Support;

use PHPUnit\Framework\Assert;
use stdClass;

/**
 * Typed narrowing for test code.
 *
 * Query-builder reads and `TestResponse::json()` both hand back `mixed`, and PHPStan runs at
 * level 10 over `tests/` as well as `app/`. Casting at every call site would either add noise or
 * (worse) hide a null behind `(int)`. These helpers narrow ONCE and FAIL LOUDLY: a null where a
 * row was expected is a broken fixture, and a test should say so rather than silently compare
 * zero against zero.
 */
final class T
{
    /** A database row that must exist. */
    public static function row(mixed $value): stdClass
    {
        Assert::assertInstanceOf(stdClass::class, $value, 'expected a database row, got '.get_debug_type($value));

        return $value;
    }

    /** A scalar column value that must be numeric. */
    public static function int(mixed $value): int
    {
        Assert::assertTrue(is_numeric($value), 'expected a number, got '.get_debug_type($value));

        return (int) $value;
    }

    public static function float(mixed $value): float
    {
        Assert::assertTrue(is_numeric($value), 'expected a number, got '.get_debug_type($value));

        return (float) $value;
    }

    public static function str(mixed $value): string
    {
        Assert::assertTrue(is_scalar($value), 'expected a scalar, got '.get_debug_type($value));

        return (string) $value;
    }

    /**
     * A JSON body (or any part of one) that must be an array.
     *
     * @return array<array-key, mixed>
     */
    public static function arr(mixed $value): array
    {
        Assert::assertIsArray($value, 'expected an array, got '.get_debug_type($value));

        return $value;
    }

    /**
     * A list of JSON objects, e.g. `cart_item` or the `me/orders` payload.
     *
     * @return list<array<array-key, mixed>>
     */
    public static function rows(mixed $value): array
    {
        $out = [];
        foreach (self::arr($value) as $row) {
            Assert::assertIsArray($row, 'expected a list of objects, got '.get_debug_type($row).' in it');
            $out[] = $row;
        }

        return $out;
    }
}
