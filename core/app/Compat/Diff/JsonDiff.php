<?php

namespace App\Compat\Diff;

/**
 * Structural diff of two decoded JSON documents. Every difference is one finding
 * `{path, kind, legacy, compat}` with kinds: type, value, missing_in_compat, extra_in_compat,
 * order, length, key_order. Paths use JSONPath-like syntax (`$.tables.brands[3].translations[0].id`);
 * DeviationRules matches them with array indices normalised to `[*]`.
 *
 * Lists whose elements are objects with an `id` (or `locale`) key are compared as keyed sets:
 * a row missing on one side is reported once (missing_in_compat / extra_in_compat) instead of
 * cascading into every following index, and a pure re-ordering is reported as `order`.
 *
 * @phpstan-type Finding array{path: string, kind: string, legacy: mixed, compat: mixed}
 */
final class JsonDiff
{
    public const MAX = 5000;

    /** @var list<array{path: string, kind: string, legacy: mixed, compat: mixed}> */
    private array $findings = [];

    /** @var list<string> normalised paths whose lists are paired by index, not by id */
    private array $positional = [];

    /**
     * @param  list<string>  $positional  normalised paths whose lists are paired BY INDEX rather
     *                                    than by id. Wave 3 needs it for `$.cart_item`: the two
     *                                    hosts build their own rows under their own guest tokens,
     *                                    so the ids differ by construction and id-pairing would
     *                                    report every line as one missing plus one extra instead
     *                                    of comparing the two field by field.
     * @return list<array{path: string, kind: string, legacy: mixed, compat: mixed}>
     */
    public static function compare(mixed $legacy, mixed $compat, array $positional = []): array
    {
        $d = new self;
        $d->positional = $positional;
        $d->walk($legacy, $compat, '$');

        return $d->findings;
    }

    private function add(string $path, string $kind, mixed $legacy, mixed $compat): void
    {
        if (count($this->findings) < self::MAX) {
            $this->findings[] = ['path' => $path, 'kind' => $kind, 'legacy' => self::summarise($legacy), 'compat' => self::summarise($compat)];
        }
    }

    private function walk(mixed $a, mixed $b, string $path): void
    {
        if (is_array($a) && is_array($b)) {
            $aList = array_is_list($a);
            $bList = array_is_list($b);
            if ($a === [] || $b === []) {
                if ($a !== $b) {
                    $this->add($path, $a === [] ? 'extra_in_compat' : 'missing_in_compat', $a, $b);
                }

                return;
            }
            if ($aList && $bList) {
                $this->walkList($a, $b, $path);

                return;
            }
            if (! $aList && ! $bList) {
                $this->walkObject($a, $b, $path);

                return;
            }
            $this->add($path, 'type', $a, $b);

            return;
        }
        if (gettype($a) !== gettype($b)) {
            $this->add($path, 'type', $a, $b);

            return;
        }
        if ($a !== $b) {
            $this->add($path, 'value', $a, $b);
        }
    }

    /**
     * @param  array<mixed>  $a
     * @param  array<mixed>  $b
     */
    private function walkObject(array $a, array $b, string $path): void
    {
        $ka = array_keys($a);
        $kb = array_keys($b);
        foreach ($ka as $k) {
            if (! array_key_exists($k, $b)) {
                $this->add("{$path}.{$k}", 'missing_in_compat', $a[$k], null);
            }
        }
        foreach ($kb as $k) {
            if (! array_key_exists($k, $a)) {
                $this->add("{$path}.{$k}", 'extra_in_compat', null, $b[$k]);
            }
        }
        $sharedA = array_values(array_filter($ka, fn ($k) => array_key_exists($k, $b)));
        $sharedB = array_values(array_filter($kb, fn ($k) => array_key_exists($k, $a)));
        if ($sharedA !== $sharedB) {
            $this->add($path, 'key_order', implode(',', array_map('strval', $sharedA)), implode(',', array_map('strval', $sharedB)));
        }
        foreach ($sharedA as $k) {
            $this->walk($a[$k], $b[$k], "{$path}.{$k}");
        }
    }

    /**
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     */
    private function walkList(array $a, array $b, string $path): void
    {
        $key = in_array(self::normalise($path), $this->positional, true) ? null : (self::keyField($a) ?? self::keyField($b));
        if ($key !== null && self::allKeyed($a, $key) && self::allKeyed($b, $key)) {
            $this->walkKeyed($a, $b, $path, $key);

            return;
        }
        if (count($a) !== count($b)) {
            $this->add($path, 'length', count($a), count($b));
        }
        $n = min(count($a), count($b));
        for ($i = 0; $i < $n; $i++) {
            $this->walk($a[$i], $b[$i], "{$path}[{$i}]");
        }
    }

    /**
     * @param  list<mixed>  $a
     * @param  list<mixed>  $b
     */
    private function walkKeyed(array $a, array $b, string $path, string $key): void
    {
        $indexA = [];
        foreach ($a as $i => $row) {
            $indexA[self::keyOf($row, $key)] = $i;
        }
        $indexB = [];
        foreach ($b as $i => $row) {
            $indexB[self::keyOf($row, $key)] = $i;
        }
        foreach ($indexA as $k => $i) {
            if (! isset($indexB[$k])) {
                $this->add("{$path}[{$i}]", 'missing_in_compat', self::label($a[$i], $key), null);
            }
        }
        foreach ($indexB as $k => $i) {
            if (! isset($indexA[$k])) {
                $this->add("{$path}[{$i}]", 'extra_in_compat', null, self::label($b[$i], $key));
            }
        }
        $orderA = array_values(array_filter(array_keys($indexA), fn ($k) => isset($indexB[$k])));
        $orderB = array_values(array_filter(array_keys($indexB), fn ($k) => isset($indexA[$k])));
        if ($orderA !== $orderB) {
            $this->add($path, 'order', implode(',', array_map('strval', array_slice($orderA, 0, 12))), implode(',', array_map('strval', array_slice($orderB, 0, 12))));
        }
        foreach ($orderA as $k) {
            $this->walk($a[$indexA[$k]], $b[$indexB[$k]], "{$path}[{$indexA[$k]}]");
        }
    }

    /** @param  list<mixed>  $list */
    private static function keyField(array $list): ?string
    {
        if ($list === [] || ! is_array($list[0]) || array_is_list($list[0])) {
            return null;
        }
        // Translation rows are keyed by locale: their ids are synthetic on the clean side (D-02).
        if (array_key_exists('locale', $list[0])) {
            return 'locale';
        }
        if (array_key_exists('id', $list[0])) {
            return 'id';
        }

        return null;
    }

    /** @param  list<mixed>  $list */
    private static function allKeyed(array $list, string $key): bool
    {
        $seen = [];
        foreach ($list as $row) {
            if (! is_array($row) || ! array_key_exists($key, $row) || ! is_scalar($row[$key])) {
                return false;
            }
            $k = (string) $row[$key];
            if (isset($seen[$k])) {
                return false;                                   // duplicates: fall back to index-wise
            }
            $seen[$k] = true;
        }

        return true;
    }

    private static function keyOf(mixed $row, string $key): string
    {
        return is_array($row) && is_scalar($row[$key] ?? null) ? (string) $row[$key] : '';
    }

    private static function label(mixed $row, string $key): string
    {
        if (! is_array($row)) {
            return '?';
        }
        $parts = [$key.'='.self::keyOf($row, $key)];
        foreach (['name', 'name_en', 'sub_type_name', 'category_type_name', 'brand_name', 'product_title', 'title', 'slug', 'image'] as $f) {
            if (isset($row[$f]) && is_scalar($row[$f])) {
                $parts[] = $f.'='.mb_substr((string) $row[$f], 0, 60);
                break;
            }
        }

        return implode(' ', $parts);
    }

    private static function summarise(mixed $v): mixed
    {
        if (is_string($v)) {
            return mb_strlen($v) > 120 ? mb_substr($v, 0, 117).'…' : $v;
        }
        if (is_array($v)) {
            $json = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($json) && mb_strlen($json) > 120 ? mb_substr($json, 0, 117).'…' : $json;
        }

        return $v;
    }

    /** `$.tables.brands[3].translations[0].id` → `$.tables.brands[*].translations[*].id` */
    public static function normalise(string $path): string
    {
        return (string) preg_replace('/\[\d+\]/', '[*]', $path);
    }
}
