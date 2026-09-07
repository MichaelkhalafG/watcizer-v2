<?php

namespace App\Storefront;

use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Native lookup tables for a storefront, cached per version (§5.2 `sf:{id}:lookups:v{n}`):
 * `{id, slug?, name: {ar, en}, …}` rows keyed by id. Cards and details read names from here so a
 * page of 96 products costs no lookup query. A missing locale simply has no key (fallback OFF).
 */
final class Lookups
{
    /** native key => [master table, translation table, translation fk] */
    public const TABLES = [
        'brands' => ['catalog_brands', 'catalog_brand_translations', 'brand_id'],
        'grades' => ['catalog_grades', 'catalog_grade_translations', 'grade_id'],
        'colors' => ['catalog_colors', 'catalog_color_translations', 'color_id'],
        'materials' => ['catalog_materials', 'catalog_material_translations', 'material_id'],
        'shapes' => ['catalog_shapes', 'catalog_shape_translations', 'shape_id'],
        'display_types' => ['catalog_display_types', 'catalog_display_type_translations', 'display_type_id'],
        'movements' => ['catalog_movement_types', 'catalog_movement_type_translations', 'movement_type_id'],
        'closure_types' => ['catalog_closure_types', 'catalog_closure_type_translations', 'closure_type_id'],
        'units' => ['catalog_units', 'catalog_unit_translations', 'unit_id'],
        'genders' => ['catalog_genders', 'catalog_gender_translations', 'gender_id'],
        'features' => ['catalog_features', 'catalog_feature_translations', 'feature_id'],
    ];

    /** @var array<string, array<int, array<string, mixed>>>|null */
    private ?array $data = null;

    public function __construct(private readonly StorefrontCache $cache, private readonly StorefrontContext $ctx) {}

    /** @return array<string, array<int, array<string, mixed>>> */
    public function all(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $locales = $this->ctx->locales();

        /** @var array<string, array<int, array<string, mixed>>> $data */
        $data = $this->cache->remember($this->ctx->id(), 'lookups', '', config()->integer('storefront.ttl.lookups'), fn () => self::load($locales));

        return $this->data = $data;
    }

    /**
     * @param  list<string>  $locales
     * @return array<string, array<int, array<string, mixed>>>
     */
    private static function load(array $locales): array
    {
        $out = [];
        foreach (self::TABLES as $key => [$master, $trTable, $fk]) {
            $columns = match ($key) {
                'brands' => ['id', 'slug', 'logo_path', 'is_active'],
                'grades' => ['id', 'image_path'],
                'colors' => ['id', 'hex'],
                'units' => ['id', 'code'],
                default => ['id'],
            };
            $select = array_map(fn (string $c) => "m.{$c}", $columns);
            $select[] = 't.locale AS tr_locale';
            $select[] = 't.name AS tr_name';
            if ($key === 'grades') {
                $select[] = 't.description AS tr_description';
            }
            // One query per lookup: master ⟕ translations of the storefront's locales (§5.3 meta budget).
            $joined = DB::table("{$master} as m")
                ->leftJoin("{$trTable} as t", function (JoinClause $j) use ($fk, $locales): void {
                    $j->on("t.{$fk}", '=', 'm.id')->whereIn('t.locale', $locales);
                })
                ->select($select)
                ->orderBy('m.id')->orderBy('t.id')
                ->get();
            $rows = [];
            foreach ($joined as $row) {
                $id = Row::int($row, 'id');
                if (! isset($rows[$id])) {
                    $r = ['id' => $id];
                    if ($key === 'brands') {
                        $r['slug'] = Row::str($row, 'slug');
                        $logo = Row::nstr($row, 'logo_path');
                        $r['logo'] = $logo === null ? null : ImageUrl::object($logo, null, null, null, null);
                        $r['is_active'] = Row::bool($row, 'is_active');
                    } elseif ($key === 'grades') {
                        $image = Row::nstr($row, 'image_path');
                        $r['image'] = $image === null ? null : ImageUrl::object($image, null, null, null, null);
                        $r['description'] = [];
                    } elseif ($key === 'colors') {
                        $r['hex'] = Row::nstr($row, 'hex');
                    } elseif ($key === 'units') {
                        $r['code'] = Row::str($row, 'code');
                    }
                    $r['name'] = [];
                    $rows[$id] = $r;
                }
                $locale = Row::nstr($row, 'tr_locale');
                $name = Row::nstr($row, 'tr_name');
                if ($locale === null || $name === null) {
                    continue;
                }
                /** @var array<string, string> $names */
                $names = $rows[$id]['name'];
                $names[$locale] = $name;
                $rows[$id]['name'] = $names;
                if ($key === 'grades') {
                    /** @var array<string, string> $desc */
                    $desc = $rows[$id]['description'] ?? [];
                    $d = Row::nstr($row, 'tr_description');
                    if ($d !== null && $d !== '') {
                        $desc[$locale] = $d;
                    }
                    $rows[$id]['description'] = $desc;
                }
            }
            $out[$key] = $rows;
        }

        return $out;
    }

    /** @return array<string, mixed>|null */
    public function get(string $table, ?int $id): ?array
    {
        if ($id === null) {
            return null;
        }

        return $this->all()[$table][$id] ?? null;
    }

    /**
     * `{id, slug?, name}` reference of a lookup row, or null.
     *
     * @return array<string, mixed>|null
     */
    public function ref(string $table, ?int $id): ?array
    {
        $row = $this->get($table, $id);
        if ($row === null) {
            return null;
        }
        $ref = ['id' => $row['id']];
        if (isset($row['slug'])) {
            $ref['slug'] = $row['slug'];
        }
        if (isset($row['code'])) {
            $ref['code'] = $row['code'];
        }
        if (isset($row['hex'])) {
            $ref['hex'] = $row['hex'];
        }
        $ref['name'] = $row['name'];

        return $ref;
    }

    /** @return list<array<string, mixed>> */
    public function list(string $table): array
    {
        return array_values($this->all()[$table] ?? []);
    }

    public function brandIdBySlug(string $slug): ?int
    {
        foreach ($this->all()['brands'] ?? [] as $id => $row) {
            if (($row['slug'] ?? null) === $slug) {
                return $id;
            }
        }

        return null;
    }
}
