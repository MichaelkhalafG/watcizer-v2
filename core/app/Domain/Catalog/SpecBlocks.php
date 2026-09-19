<?php

namespace App\Domain\Catalog;

use App\Models\Catalog\Product;
use App\Support\Coerce;
use App\Support\ManageText;
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
     * Which colour questions each family is asked, labelled (J-6, 2026-09-19).
     *
     * Every family at once, keyed by family, because the product form derives the family in the
     * BROWSER as the operator changes the primary category — the same `option.family` mechanism
     * task 4.1 built so the specification block could react without a round trip. Sending one
     * family's roles would mean a request per category change, or a second copy of the derivation
     * rule in JavaScript, and wave 4B rejected both.
     *
     * ── Shown, not required ─────────────────────────────────────────────────────────────────
     *
     * J-6 asked for these to be REQUIRED per family. Measured against the live catalogue first,
     * as every field rule on this project is:
     *
     *     main colour missing:  7,713 of 7,713   (no product has ever had one)
     *     watch dial missing:   4,264 of 4,645
     *     watch band missing:   4,263 of 4,645
     *
     * A required colour would refuse a save on essentially every product in the shop, which is the
     * exact failure the 2026-09-18 field-rules decision was written to avoid: *"a rule that
     * refuses the save punishes whoever is fixing something rather than whoever left it
     * incomplete."* Legacy has these `nullable` too.
     *
     * So the FAMILY SCOPING ships — which is the half that closes the trap, because a handbag is
     * no longer asked for a strap colour and cannot write into the column the storefront renders
     * as a watch band — and the requirement does not. The form says which colours matter for this
     * family instead of refusing to save without them.
     *
     * @return array<string, list<array{key: string, label: string}>>
     */
    public static function colorRoles(): array
    {
        $labels = [
            'main' => ManageText::t('products.color_main', 'اللون الأساسي'),
            'dial' => ManageText::t('products.color_dial', 'لون القرص'),
            'band' => ManageText::t('products.color_band', 'لون السوار'),
        ];

        /** @var array<string, mixed> $config */
        $config = config('catalog.color_roles', []);

        $out = [];
        foreach (array_merge(['default'], Product::FAMILIES) as $family) {
            $roles = $config[$family] ?? $config['default'] ?? [];
            $list = [];
            foreach (is_array($roles) ? $roles : [] as $role) {
                if (! is_string($role) || ! isset($labels[$role])) {
                    throw new InvalidArgumentException('config/catalog.php: unknown colour role ['.Coerce::str($role).'].');
                }
                $list[] = ['key' => $role, 'label' => $labels[$role]];
            }
            $out[$family] = $list;
        }

        return $out;
    }

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
            /*
             * ── The label goes through the seam HERE, not in config (🟠-4, 2026-09-17) ────────
             *
             * `config/catalog.php` holds 589 Arabic characters — spec-block names, field labels —
             * and neither ratchet looked at `config/`, so an English operator read the whole
             * specifications panel in Arabic and nothing failed.
             *
             * The fix cannot be a `ManageText::t()` inside the config file: config is CACHED
             * (`php artisan config:cache`), so the translation would be resolved once, at cache
             * time, in whatever locale happened to be active — and then frozen for every operator
             * until the next deploy. That is worse than the bug.
             *
             * So the config value stays as the ARABIC FALLBACK, exactly like a `t()` call's second
             * argument, and the key is derived from the field's own stable `key`. Same contract as
             * the rest of the seam: Arabic renders with `lang/ar` empty, English comes from
             * `lang/en/manage.php`, and `ConfigTranslationTest` asserts every declared field has an
             * English entry.
             */
            $label = is_string($field['label'] ?? null) ? $field['label'] : $field['key'];

            $one = [
                'key' => $field['key'],
                'label' => ManageText::t('specs.field_'.$field['key'], $label),
                'type' => $type,
            ];
            if (is_string($field['unit'] ?? null)) {
                $one['unit'] = $field['unit'];
            }
            if (is_string($field['lookup'] ?? null)) {
                $one['lookup'] = $field['lookup'];
            }
            /*
             * A field's HINT, through the same seam as its label and for the same reason: the
             * config value is the Arabic fallback and the key is derived from the field's own
             * stable `key`, so `config:cache` cannot freeze one locale's text.
             *
             * Added 2026-10-05 for `case_size`, where the label names the measurement and only a
             * sentence can say WHICH measurement it is — a diameter, not a circumference. Optional
             * everywhere: a field that says all it needs to in its label carries none.
             */
            if (is_string($field['hint'] ?? null)) {
                $one['hint'] = ManageText::t('specs.hint_'.$field['key'], $field['hint']);
            }
            $fields[] = $one;
        }

        return [
            'family' => $family,
            // The block's own name ("مواصفات الساعة"), keyed on the FAMILY — same reasoning as the
            // field labels above: the config value is the fallback, the key is derived and stable.
            'label' => ManageText::t(
                'specs.block_'.$family,
                is_string($block['label'] ?? null) ? $block['label'] : $family,
            ),
            'table' => is_string($block['table'] ?? null) ? $block['table'] : 'specs',
            'fields' => $fields,
        ];
    }

    /**
     * Does this family's block declare this field?
     *
     * Asked by the wave-4D importer before it writes a material: `material_id` exists on a bag, a
     * wallet and a fashion product, and not on a perfume or an electronics item. Reading the block
     * beats a hard-coded family list, which is the mistake the legacy dashboard made about watches
     * and had to be hotfixed for.
     */
    public static function hasField(string $family, string $key): bool
    {
        $block = self::for($family);
        if ($block === null) {
            return false;
        }

        foreach ($block['fields'] as $field) {
            if ($field['key'] === $key) {
                return true;
            }
        }

        return false;
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
            /*
             * A RETIRED row is not offered (wave 4D, task C3). Declared per lookup in
             * `config/catalog.php` rather than sniffed from the schema: `catalog_units` is the only
             * list with a retirement column today, and a helper that quietly filtered on a column
             * "if it happens to exist" would be the kind of rule nobody can find later.
             *
             * It filters the PICKER only. A product already pointing at a retired unit keeps
             * rendering it, which is the point: hiding the row must not silently blank a
             * measurement on a live page.
             */
            ->when(Coerce::bool($entry['retirable'] ?? null), fn ($query) => $query->whereNull('m.retired_at'))
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
