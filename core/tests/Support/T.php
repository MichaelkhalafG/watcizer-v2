<?php

namespace Tests\Support;

use App\Transform\Row;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\ViewErrorBag;
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
 *
 * ── Why the suite's ASSERTION TOTAL moves and the test count does not (review 🔵) ─────────────
 *
 * Each narrowing below is a real `PHPUnit\Framework\Assert` call — that is what "fail loudly"
 * means — so it is COUNTED. A test that walks whatever the local catalogue copy holds (every
 * category option on the form, every lookup row, every variant of a product) therefore spends
 * assertions in proportion to the DATA:
 *
 *   `CategoryChangesFamilyTest`, measured — 285 assertions; four extra category rows → 305; the
 *   rows removed again → 285. Five per node, same seven tests.
 *
 * So two batteries reporting 601 tests / 8 661 and 601 / 8 635 have NOT diverged: the legacy copy
 * behind them differed by a few nodes. Do not "stabilise" this by silencing the assertions — the
 * loud narrowing is the feature. Quote the TEST count as evidence (AGENTS §4).
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

    /**
     * One row of a query, asserted to exist — `T::row($query->first())` in one call.
     *
     * The 4B tests read a lot of single rows, and `->first()` is `mixed` to PHPStan at level 10,
     * so without this every assertion would carry its own null check.
     *
     * @param  QueryBuilder|EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    public static function one(QueryBuilder|EloquentBuilder $query): stdClass
    {
        return self::row($query->first());
    }

    /**
     * Several rows of a query, each narrowed — the list twin of {@see self::one()}.
     *
     * @param  QueryBuilder|EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return list<stdClass>
     */
    public static function many(QueryBuilder|EloquentBuilder $query): array
    {
        $out = [];
        foreach ($query->get() as $row) {
            // Every row of a query builder IS an object; `Row::cast()` is what narrows it, and
            // asserting it again would only tell PHPStan something it already knows.
            $out[] = Row::cast($row);
        }

        return $out;
    }

    /** The first session error under a key, as a string. */
    public static function err(string $key): string
    {
        $errors = session('errors');
        Assert::assertNotNull($errors, "the session carries no errors, so none under [{$key}]");

        /** @var ViewErrorBag $errors */
        return self::str($errors->first($key));
    }
}
