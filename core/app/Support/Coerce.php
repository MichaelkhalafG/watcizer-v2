<?php

namespace App\Support;

use App\Transform\Row;

/**
 * LENIENT narrowing for values that arrive from outside the application — a validated request
 * payload, a config array, a JSON column.
 *
 * The third of the three narrowing helpers, and the distinction between them is the point:
 *
 *   • {@see Row} — stdClass rows from the query builder. STRICT: a column that is
 *     not the declared type throws, because the transform reading a wrong type means the schema
 *     moved under it.
 *   • {@see Val} — array DTOs the application itself built (cached trees, lookup blobs). STRICT
 *     for the same reason: we wrote it, so a wrong type is our bug.
 *   • **this** — anything a BROWSER sent. Lenient: `"12"` is 12, `"on"` is true, an absent key is
 *     null. A dashboard form posts strings for everything, and throwing on the eighth field of a
 *     forty-field product form would turn a typo into a 500.
 *
 * Laravel's own `$request->string()`/`->integer()` do most of this, and are preferred where a
 * Request is in hand. These exist for the layer BELOW the controller: the writers take an
 * `array<string, mixed>` (so they can be called from a command or a test as easily as from a
 * form) and must narrow it themselves at level 10.
 */
final class Coerce
{
    /** A non-empty trimmed string, or null. Empty string and whitespace both become null. */
    public static function nstr(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /** A string, always — '' for anything that is not a scalar. */
    public static function str(mixed $value, string $default = ''): string
    {
        return self::nstr($value) ?? $default;
    }

    public static function nint(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public static function int(mixed $value, int $default = 0): int
    {
        return self::nint($value) ?? $default;
    }

    public static function nfloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public static function float(mixed $value, float $default = 0.0): float
    {
        return self::nfloat($value) ?? $default;
    }

    /**
     * A checkbox's value.
     *
     * `filter_var` rather than a cast, because a cast makes the STRING "0" true and a form posts
     * "0"/"false"/"off" for an unchecked box in three different frameworks' conventions.
     */
    public static function bool(mixed $value, bool $default = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * A string-keyed array, or an empty one.
     *
     * @return array<string, mixed>
     */
    public static function arr(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[is_string($key) ? $key : (string) $key] = $item;
        }

        return $out;
    }

    /**
     * A de-duplicated list of ints, preserving first-seen order.
     *
     * @return list<int>
     */
    public static function intList(mixed $value): array
    {
        $seen = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $id = self::nint($item);
            if ($id !== null) {
                $seen[$id] = true;
            }
        }

        return array_keys($seen);
    }

    /**
     * A list of ints in the given order, duplicates INCLUDED — for a reorder payload, where the
     * order is the meaning and de-duplicating would silently drop a position.
     *
     * @return list<int>
     */
    public static function orderedIntList(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            $id = self::nint($item);
            if ($id !== null) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * One locale pair (`{ar: …, en: …}`) out of a payload field, trimmed.
     *
     * @return array<string, string>
     */
    public static function pair(mixed $value, string ...$locales): array
    {
        $raw = self::arr($value);
        $out = [];
        foreach ($locales === [] ? ['ar', 'en'] : $locales as $locale) {
            $out[$locale] = self::str($raw[$locale] ?? null);
        }

        return $out;
    }
}
