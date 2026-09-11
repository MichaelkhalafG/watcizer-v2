<?php

namespace App\Domain\Catalog;

use App\Models\Catalog\Product;
use App\Support\Coerce;
use App\Transform\FamilyResolver;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * The family-specific half of the product form: which block to show, which fields are in it, how
 * to validate them, and where they are stored.
 *
 * Declared in `config/catalog.php` and read here. The form, the validator and the writer all go
 * through this one class, so a new attribute is a config line and never three edits that can drift
 * apart.
 *
 * Two storage shapes, and the difference is the schema's, not a preference:
 *
 *   • `watch` → the dedicated `catalog_product_watch_specs` table (M1), one row per product, with
 *     real foreign keys to the lookup tables. Its fields ARE columns.
 *   • everything else → `catalog_products.specs`, a JSON column. Its fields are JSON keys, named
 *     so that {@see FamilyResolver} reads the family back out of them unchanged
 *     (see the header of `config/catalog.php`).
 *
 * @phpstan-type SpecField array{key: string, label: string, type: string, unit?: string, lookup?: string}
 * @phpstan-type SpecBlock array{family: string, label: string, table: string, fields: list<SpecField>}
 */
final class SpecBlocks
{
    public const WATCH_SPECS_TABLE = 'catalog_product_watch_specs';

    /** Field types a block may declare. Anything else is a config typo, and is refused loudly. */
    public const TYPES = ['string', 'integer', 'decimal', 'boolean', 'lookup'];

    /**
     * The block for a family, or null when the family has none (`fashion`, `other`).
     *
     * @return SpecBlock|null
     */
    public static function for(string $family): ?array
    {
        /** @var array<string, mixed> $blocks */
        $blocks = config('catalog.blocks', []);
        $block = $blocks[$family] ?? null;
        if (! is_array($block)) {
            return null;
        }

        $fields = [];
        foreach (is_array($block['fields'] ?? null) ? $block['fields'] : [] as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null)) {
                continue;
            }
            $type = is_string($field['type'] ?? null) ? $field['type'] : 'string';
            if (! in_array($type, self::TYPES, true)) {
                throw new InvalidArgumentException("config/catalog.php: field [{$field['key']}] has unknown type [{$type}].");
            }
            $one = [
                'key' => $field['key'],
                'label' => is_string($field['label'] ?? null) ? $field['label'] : $field['key'],
                'type' => $type,
            ];
            if (is_string($field['unit'] ?? null)) {
                $one['unit'] = $field['unit'];
            }
            if (is_string($field['lookup'] ?? null)) {
                $one['lookup'] = $field['lookup'];
            }
            $fields[] = $one;
        }

        return [
            'family' => $family,
            'label' => is_string($block['label'] ?? null) ? $block['label'] : $family,
            'table' => is_string($block['table'] ?? null) ? $block['table'] : 'specs',
            'fields' => $fields,
        ];
    }

    /**
     * Every family that HAS a block. Used by the form (it ships all blocks so switching the
     * category re-renders without a round trip) and by the tests that prove the round trip.
     *
     * @return array<string, SpecBlock>
     */
    public static function all(): array
    {
        $out = [];
        foreach (Product::FAMILIES as $family) {
            $block = self::for($family);
            if ($block !== null) {
                $out[$family] = $block;
            }
        }

        return $out;
    }

    /**
     * Validation rules for a family's block, keyed as the request sends them (`specs.<key>`).
     *
     * A lookup field is validated with `exists`, so a hostile payload cannot point a foreign key
     * at a row that is not there — the FK would refuse anyway, with a 500 instead of a message.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $family): array
    {
        $block = self::for($family);
        if ($block === null) {
            return [];
        }

        $rules = [];
        foreach ($block['fields'] as $field) {
            $key = 'specs.'.$field['key'];
            $rules[$key] = match ($field['type']) {
                'integer' => ['nullable', 'integer', 'min:0', 'max:1000000'],
                'decimal' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
                'boolean' => ['nullable', 'boolean'],
                'lookup' => ['nullable', 'integer', Rule::exists(self::lookupTable($field['lookup'] ?? ''), 'id')],
                default => ['nullable', 'string', 'max:191'],
            };
            if (isset($field['unit'])) {
                $rules['specs.'.$field['unit']] = ['nullable', 'integer', Rule::exists('catalog_units', 'id')];
            }
        }

        return $rules;
    }

    /**
     * Turn a validated payload into what the WATCH SPECS table takes: only declared columns, each
     * cast to its declared type, `null` for an empty value.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, int|float|bool|string|null>
     */
    public static function watchColumns(array $input): array
    {
        $block = self::for('watch');
        if ($block === null || $block['table'] !== 'watch_specs') {
            return [];
        }

        $out = [];
        foreach ($block['fields'] as $field) {
            $out[$field['key']] = self::cast($input[$field['key']] ?? null, $field['type']);
            if (isset($field['unit'])) {
                $out[$field['unit']] = self::cast($input[$field['unit']] ?? null, 'lookup');
            }
        }

        return $out;
    }

    /**
     * Turn a validated payload into the `specs` JSON for a family: declared keys only, empty
     * values dropped entirely rather than stored as null.
     *
     * Dropping is deliberate — `{"width_cm": null}` and `{}` mean the same thing to a reader but
     * not to a diff, and the transform's reconciliation compares JSON.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, int|float|bool|string>
     */
    public static function jsonSpecs(string $family, array $input): array
    {
        $block = self::for($family);
        if ($block === null || $block['table'] !== 'specs') {
            return [];
        }

        $out = [];
        foreach ($block['fields'] as $field) {
            $value = self::cast($input[$field['key']] ?? null, $field['type']);
            if ($value !== null) {
                $out[$field['key']] = $value;
            }
        }

        return $out;
    }

    /** The master table behind a lookup key, from `config('catalog.lookups')`. */
    public static function lookupTable(string $lookup): string
    {
        /** @var array<string, mixed> $lookups */
        $lookups = config('catalog.lookups', []);
        $entry = $lookups[$lookup] ?? null;
        $table = is_array($entry) && is_string($entry['master'] ?? null) ? $entry['master'] : null;

        if ($table === null) {
            throw new InvalidArgumentException("config/catalog.php: unknown lookup [{$lookup}].");
        }

        return $table;
    }

    /**
     * Every lookup list a family's block needs, as `{key: [{value, label}]}` — the options the
     * form renders. Only the lists that family actually uses, so a bag form does not ship the
     * movement types.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function optionsFor(string $family): array
    {
        $block = self::for($family);
        if ($block === null) {
            return [];
        }

        $needed = [];
        foreach ($block['fields'] as $field) {
            if ($field['type'] === 'lookup' && isset($field['lookup'])) {
                $needed[$field['lookup']] = true;
            }
            if (isset($field['unit'])) {
                $needed['units'] = true;
            }
        }

        $out = [];
        foreach (array_keys($needed) as $lookup) {
            $out[$lookup] = self::options($lookup);
        }

        return $out;
    }

    /**
     * One lookup list, Arabic label with the English in the hint position (the dashboard's
     * convention: Arabic first, English for the team member who knows the supplier's term).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(string $lookup): array
    {
        $entry = Coerce::arr(Coerce::arr(config('catalog.lookups', []))[$lookup] ?? null);
        $master = Coerce::nstr($entry['master'] ?? null);
        $translations = Coerce::nstr($entry['translations'] ?? null);
        $fk = Coerce::nstr($entry['fk'] ?? null);
        if ($master === null || $translations === null || $fk === null) {
            return [];
        }

        $rows = DB::table($master.' as m')
            ->leftJoin($translations.' as ar', function (JoinClause $join) use ($fk): void {
                $join->on('ar.'.$fk, '=', 'm.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin($translations.' as en', function (JoinClause $join) use ($fk): void {
                $join->on('en.'.$fk, '=', 'm.id')->where('en.locale', '=', 'en');
            })
            ->orderBy('m.id')
            ->get(['m.id', 'ar.name as name_ar', 'en.name as name_en']);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $ar = trim(Row::nstr($row, 'name_ar') ?? '');
            $en = trim(Row::nstr($row, 'name_en') ?? '');
            $label = $ar !== '' ? $ar : ($en !== '' ? $en : '#'.$id);
            if ($ar !== '' && $en !== '' && $ar !== $en) {
                $label .= ' — '.$en;
            }
            $out[] = ['value' => (string) $id, 'label' => $label];
        }

        return $out;
    }

    private static function cast(mixed $value, string $type): int|float|bool|string|null
    {
        if ($value === null || $value === '' || (is_array($value) && $value === [])) {
            return null;
        }
        if (is_array($value) || is_object($value)) {
            return null;
        }

        return match ($type) {
            'integer', 'lookup' => is_numeric($value) ? (int) $value : null,
            'decimal' => is_numeric($value) ? (float) $value : null,
            // A checkbox that was never touched arrives as absent/null (already handled above);
            // "0"/"false" must read as false and not as "a non-empty string, so true".
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? null,
            default => is_scalar($value) ? (string) $value : null,
        };
    }
}
