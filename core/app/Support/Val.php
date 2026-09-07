<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Typed accessors for `array<string, mixed>` DTOs (the cached tree/lookup/meta blobs), the array
 * twin of App\Transform\Row for stdClass rows. Wrong types throw: a DTO is never silently coerced.
 */
final class Val
{
    /** @param  array<mixed>  $a */
    public static function int(array $a, string $key): int
    {
        $v = $a[$key] ?? null;
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d+$/', $v) === 1) {
            return (int) $v;
        }
        throw new InvalidArgumentException("Key [$key] is not an integer (".var_export($v, true).').');
    }

    /** @param  array<mixed>  $a */
    public static function nint(array $a, string $key): ?int
    {
        return ($a[$key] ?? null) === null ? null : self::int($a, $key);
    }

    /** @param  array<mixed>  $a */
    public static function str(array $a, string $key): string
    {
        $v = $a[$key] ?? null;
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string) $v;
        }
        throw new InvalidArgumentException("Key [$key] is not a string (".var_export($v, true).').');
    }

    /** @param  array<mixed>  $a */
    public static function nstr(array $a, string $key): ?string
    {
        return ($a[$key] ?? null) === null ? null : self::str($a, $key);
    }

    /** @param  array<mixed>  $a */
    public static function bool(array $a, string $key): bool
    {
        $v = $a[$key] ?? null;
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v)) {
            return $v !== 0;
        }

        return false;
    }

    /**
     * @param  array<mixed>  $a
     * @return array<string, mixed>
     */
    public static function arr(array $a, string $key): array
    {
        $v = $a[$key] ?? null;
        if (! is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $k => $item) {
            $out[(string) $k] = $item;
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $a
     * @return array<string, mixed>|null
     */
    public static function narr(array $a, string $key): ?array
    {
        return is_array($a[$key] ?? null) ? self::arr($a, $key) : null;
    }

    /**
     * @param  array<mixed>  $a
     * @return list<mixed>
     */
    public static function list(array $a, string $key): array
    {
        $v = $a[$key] ?? null;

        return is_array($v) ? array_values($v) : [];
    }

    /**
     * @param  array<mixed>  $a
     * @return list<int>
     */
    public static function intList(array $a, string $key): array
    {
        $out = [];
        foreach (self::list($a, $key) as $item) {
            if (is_int($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $a
     * @return array<string, string>
     */
    public static function strMap(array $a, string $key): array
    {
        $out = [];
        foreach (self::arr($a, $key) as $k => $v) {
            if (is_string($v)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }
}
