<?php

namespace App\Compat;

use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;

/**
 * Lookup names (en/ar) and legacy-shaped lookup rows read from the clean tables, cached per
 * storefront version. Used by every compat builder so a PDP or a card never runs one query
 * per lookup table.
 *
 * Legacy lookup table => [clean master table, clean translation table, translation fk,
 * legacy translation fk, legacy name column, extra master columns].
 */
final class CompatNames
{
    /** @var array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}> */
    public const LOOKUPS = [
        'brands' => ['catalog_brands', 'catalog_brand_translations', 'brand_id', 'brand_id', 'brand_name'],
        'grades' => ['catalog_grades', 'catalog_grade_translations', 'grade_id', 'grade_id', 'grade_name'],
        'colors' => ['catalog_colors', 'catalog_color_translations', 'color_id', 'color_id', 'color_name'],
        'materials' => ['catalog_materials', 'catalog_material_translations', 'material_id', 'material_id', 'material_name'],
        'shapes' => ['catalog_shapes', 'catalog_shape_translations', 'shape_id', 'shape_id', 'shape_name'],
        'size_types' => ['catalog_units', 'catalog_unit_translations', 'unit_id', 'size_type_id', 'size_type_name'],
        'display_types' => ['catalog_display_types', 'catalog_display_type_translations', 'display_type_id', 'display_type_id', 'display_type_name'],
        'closure_types' => ['catalog_closure_types', 'catalog_closure_type_translations', 'closure_type_id', 'closure_type_id', 'closure_type_name'],
        'movement_types' => ['catalog_movement_types', 'catalog_movement_type_translations', 'movement_type_id', 'movement_type_id', 'movement_type_name'],
        'features' => ['catalog_features', 'catalog_feature_translations', 'feature_id', 'feature_id', 'feature_name'],
        'genders' => ['catalog_genders', 'catalog_gender_translations', 'gender_id', 'gender_id', 'gender_name'],
    ];

    /**
     * Everything the compat layer needs about lookups, in one cached blob:
     *  masters[table][id] = {id, created_at, updated_at, extra…}
     *  translations[table][id][locale] = {id (clean row id), name, description?}
     *
     * @var array{masters: array<string, array<int, array<string, mixed>>>, translations: array<string, array<int, array<string, array<string, mixed>>>>}|null
     */
    private ?array $data = null;

    public function __construct(private readonly StorefrontCache $cache, private readonly int $storefrontId) {}

    /** @return array{masters: array<string, array<int, array<string, mixed>>>, translations: array<string, array<int, array<string, array<string, mixed>>>>} */
    public function data(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $ttl = config()->integer('compat.ttl.names');

        /** @var array{masters: array<string, array<int, array<string, mixed>>>, translations: array<string, array<int, array<string, array<string, mixed>>>>} $data */
        $data = $this->cache->remember($this->storefrontId, 'compat_names', '', $ttl, fn () => self::load());

        return $this->data = $data;
    }

    /** @return array{masters: array<string, array<int, array<string, mixed>>>, translations: array<string, array<int, array<string, array<string, mixed>>>>} */
    private static function load(): array
    {
        $masters = [];
        $translations = [];
        foreach (self::LOOKUPS as $legacy => [$master, $trTable, $fk]) {
            $columns = match ($legacy) {
                'brands' => ['id', 'logo_path', 'created_at', 'updated_at'],
                'grades' => ['id', 'image_path', 'created_at', 'updated_at'],
                'colors' => ['id', 'hex', 'created_at', 'updated_at'],
                default => ['id', 'created_at', 'updated_at'],
            };
            $masters[$legacy] = [];
            foreach (DB::table($master)->select($columns)->orderBy('id')->get() as $row) {
                $id = Row::int($row, 'id');
                $m = ['id' => $id, 'created_at' => Row::nstr($row, 'created_at'), 'updated_at' => Row::nstr($row, 'updated_at')];
                if ($legacy === 'brands') {
                    $m['image'] = LegacyJson::basename(Row::nstr($row, 'logo_path'));
                } elseif ($legacy === 'grades') {
                    $m['image'] = LegacyJson::basename(Row::nstr($row, 'image_path'));
                } elseif ($legacy === 'colors') {
                    $m['color_value'] = Row::nstr($row, 'hex');
                }
                $masters[$legacy][$id] = $m;
            }
            $trColumns = $legacy === 'grades' ? ['id', $fk, 'locale', 'name', 'description'] : ['id', $fk, 'locale', 'name'];
            $translations[$legacy] = [];
            foreach (DB::table($trTable)->select($trColumns)->orderBy('id')->get() as $row) {
                $t = ['id' => Row::int($row, 'id'), 'name' => Row::nstr($row, 'name')];
                if ($legacy === 'grades') {
                    $t['description'] = Row::nstr($row, 'description');
                }
                $translations[$legacy][Row::int($row, $fk)][Row::str($row, 'locale')] = $t;
            }
        }

        return ['masters' => $masters, 'translations' => $translations];
    }

    /** Translated name of a lookup row for a locale, null when the row or the locale is missing (fallback OFF). */
    public function name(string $legacyTable, ?int $id, string $locale): ?string
    {
        if ($id === null) {
            return null;
        }
        $t = $this->data()['translations'][$legacyTable][$id][$locale] ?? null;
        $name = is_array($t) ? ($t['name'] ?? null) : null;

        return is_string($name) ? $name : null;
    }

    /** @return array<string, mixed>|null */
    public function master(string $legacyTable, ?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        return $this->data()['masters'][$legacyTable][$id] ?? null;
    }

    /**
     * Legacy `translations[]` rows of a lookup: {id, locale, <legacy fk>, <legacy name column>[, description]}.
     *
     * @param  list<string>  $localeOrder
     * @return list<array<string, mixed>>
     */
    public function translationRows(string $legacyTable, int $id, array $localeOrder): array
    {
        [, , , $legacyFk, $nameColumn] = self::LOOKUPS[$legacyTable];
        $rows = [];
        foreach ($localeOrder as $locale) {
            $t = $this->data()['translations'][$legacyTable][$id][$locale] ?? null;
            if (! is_array($t)) {
                continue;
            }
            $row = ['id' => $t['id'], 'locale' => $locale, $legacyFk => $id, $nameColumn => $t['name']];
            if ($legacyTable === 'grades') {
                $row['description'] = $t['description'] ?? null;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * A full legacy lookup row as `Model::with('translations')->get()` serialises it:
     * columns, then the current-locale appended attribute, then translations[].
     *
     * @param  list<string>  $localeOrder
     * @return array<string, mixed>|null
     */
    public function legacyRow(string $legacyTable, int $id, string $appLocale, array $localeOrder): ?array
    {
        $master = $this->master($legacyTable, $id);
        if ($master === null) {
            return null;
        }
        [, , , , $nameColumn] = self::LOOKUPS[$legacyTable];
        $row = ['id' => $master['id']];
        if ($legacyTable === 'brands' || $legacyTable === 'grades') {
            $row['image'] = $master['image'];
        } elseif ($legacyTable === 'colors') {
            $row['color_value'] = $master['color_value'];
        }
        $row['created_at'] = LegacyJson::ts(self::nstr($master['created_at']));
        $row['updated_at'] = LegacyJson::ts(self::nstr($master['updated_at']));
        $row[$nameColumn] = $this->name($legacyTable, $id, $appLocale);
        if ($legacyTable === 'grades') {
            $t = $this->data()['translations']['grades'][$id][$appLocale] ?? null;
            $row['description'] = is_array($t) ? ($t['description'] ?? null) : null;
        }
        $row['translations'] = $this->translationRows($legacyTable, $id, $localeOrder);

        return $row;
    }

    /** @return list<int> */
    public function ids(string $legacyTable): array
    {
        return array_keys($this->data()['masters'][$legacyTable] ?? []);
    }

    private static function nstr(mixed $v): ?string
    {
        return is_string($v) ? $v : null;
    }
}
