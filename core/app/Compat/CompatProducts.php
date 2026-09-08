<?php

namespace App\Compat;

use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Product rows in the legacy `products` column vocabulary, read from the clean tables for
 * storefront 1: `catalog_products` ⨝ `storefront_product` (visible) ⟕ `catalog_product_watch_specs`
 * ⟕ cover image, plus translations, pivots and placements fetched per id set (never per row).
 *
 * The legacy column → clean home mapping is CLEAN_CORE_STUDY §2.2 / Appendix A; this class is
 * the single place the reverse mapping (clean → legacy names) lives for the compat layer.
 */
final class CompatProducts
{
    /** @var list<string> legacy watch columns, in the legacy select order of `all_product` */
    public const WATCH_COLUMNS = [
        'band_closure_id', 'dial_display_type_id', 'case_size_type_id', 'case_shape_id', 'band_material_id',
        'watch_movement_id', 'band_length', 'band_size_type_id', 'water_resistance', 'water_resistance_size_type_id',
        'band_width', 'band_width_size_type_id', 'case_thickness', 'case_thickness_size_type_id',
        'dial_case_material_id', 'dial_glass_material_id', 'watch_height', 'watch_height_size_type_id',
        'watch_width', 'watch_width_size_type_id', 'watch_length', 'watch_length_size_type_id',
    ];

    /** legacy column => clean watch_specs column */
    private const WATCH_MAP = [
        'band_closure_id' => 'band_closure_id', 'dial_display_type_id' => 'dial_display_type_id',
        'case_size_type_id' => 'case_size_unit_id', 'case_shape_id' => 'case_shape_id',
        'band_material_id' => 'band_material_id', 'watch_movement_id' => 'movement_type_id',
        'band_length' => 'band_length', 'band_size_type_id' => 'band_length_unit_id',
        'water_resistance' => 'water_resistance', 'water_resistance_size_type_id' => 'water_resistance_unit_id',
        'band_width' => 'band_width', 'band_width_size_type_id' => 'band_width_unit_id',
        'case_thickness' => 'case_thickness', 'case_thickness_size_type_id' => 'case_thickness_unit_id',
        'dial_case_material_id' => 'case_material_id', 'dial_glass_material_id' => 'glass_material_id',
        'watch_height' => 'height', 'watch_height_size_type_id' => 'height_unit_id',
        'watch_width' => 'width', 'watch_width_size_type_id' => 'width_unit_id',
        'watch_length' => 'length', 'watch_length_size_type_id' => 'length_unit_id',
        'case_size' => 'case_size', 'interchangeable_dial' => 'interchangeable_dial',
        'interchangeable_strap' => 'interchangeable_strap', 'watch_box' => 'watch_box',
    ];

    public function __construct(private readonly int $storefrontId) {}

    /** Visible, active, not soft-deleted products of the storefront in the legacy vocabulary. */
    public function query(): Builder
    {
        $sf = $this->storefrontId;
        $select = [
            'p.id', 'p.brand_id', 'p.grade_id', 'p.model_number', 'p.warranty_years', 'p.rating_avg', 'p.rating_count',
            'p.selling_price', 'p.sale_price', 'p.stock_express', 'p.stock_market', 'p.search_keywords', 'p.is_active',
            'p.specs', 'p.family', 'p.created_at', 'p.updated_at', 'sp.slug AS storefront_slug',
        ];
        foreach (self::WATCH_MAP as $legacy => $clean) {
            $select[] = "ws.$clean AS ws_$legacy";
        }

        return DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $j) use ($sf): void {
                $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $sf)->where('sp.is_visible', '=', 1);
            })
            ->leftJoin('catalog_product_watch_specs as ws', 'ws.product_id', '=', 'p.id')
            ->where('p.is_active', 1)
            ->whereNull('p.deleted_at')
            ->select($select)
            ->selectRaw('(SELECT ci.path FROM catalog_product_images ci WHERE ci.product_id = p.id AND ci.is_cover = 1 ORDER BY ci.sort, ci.id LIMIT 1) AS cover_path');
    }

    /** The legacy watch column value (raw PDO type: int / decimal string / null) of a row. */
    public static function watch(stdClass $row, string $legacyColumn): int|string|null
    {
        $v = $row->{'ws_'.$legacyColumn} ?? null;
        if ($v === null || is_int($v)) {
            return $v;
        }
        if (is_string($v)) {
            return $v;
        }
        if (is_float($v)) {
            return (string) $v;
        }

        return null;
    }

    /** Legacy `warranty_years` is varchar: "1", "3" or null; clean is tinyint. */
    public static function warranty(stdClass $row): ?string
    {
        $v = Row::nint($row, 'warranty_years');

        return $v === null ? null : (string) $v;
    }

    /** Legacy `percentage_discount` (decimal 8,2 string) derived from the valid sale (study §2.2, A-22). */
    public static function percentageDiscount(stdClass $row): string
    {
        $selling = Row::nstr($row, 'selling_price') ?? '0';
        $sale = Row::nstr($row, 'sale_price');
        $s = (float) $selling;
        $d = $sale === null ? null : (float) $sale;
        if ($d === null || $s <= 0 || $d <= 0 || $d >= $s) {
            return LegacyJson::decimal2(0);
        }

        return LegacyJson::decimal2(round(($s - $d) / $s * 100));
    }

    /**
     * product id => locale => translation row (clean column names).
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, array<string, mixed>>>
     */
    public function translations(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $rows = DB::table('catalog_product_translations')
            ->select(['id', 'product_id', 'locale', 'title', 'short_description', 'long_description', 'model_name', 'country', 'stone'])
            ->whereIn('product_id', $ids)
            ->orderBy('product_id')->orderBy('locale')
            ->get();
        foreach ($rows as $row) {
            $out[Row::int($row, 'product_id')][Row::str($row, 'locale')] = [
                'id' => Row::int($row, 'id'),
                'title' => Row::nstr($row, 'title'),
                'short_description' => Row::nstr($row, 'short_description'),
                'long_description' => Row::nstr($row, 'long_description'),
                'model_name' => Row::nstr($row, 'model_name'),
                'country' => Row::nstr($row, 'country'),
                'stone' => Row::nstr($row, 'stone'),
            ];
        }

        return $out;
    }

    /**
     * product id => {features: ids, genders: ids, dial: color ids, band: color ids}, each ascending.
     *
     * @param  list<int>  $ids
     * @return array<int, array{features: list<int>, genders: list<int>, dial: list<int>, band: list<int>}>
     */
    public function pivots(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        /** @var array<int, list<int>> $features */
        $features = [];
        /** @var array<int, list<int>> $genders */
        $genders = [];
        /** @var array<int, list<int>> $dial */
        $dial = [];
        /** @var array<int, list<int>> $band */
        $band = [];
        foreach (DB::table('catalog_product_feature')->select(['product_id', 'feature_id'])->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('feature_id')->get() as $r) {
            $features[Row::int($r, 'product_id')][] = Row::int($r, 'feature_id');
        }
        foreach (DB::table('catalog_product_gender')->select(['product_id', 'gender_id'])->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('gender_id')->get() as $r) {
            $genders[Row::int($r, 'product_id')][] = Row::int($r, 'gender_id');
        }
        foreach (DB::table('catalog_product_color')->select(['product_id', 'color_id', 'role'])->whereIn('product_id', $ids)->whereIn('role', ['dial', 'band'])->orderBy('product_id')->orderBy('color_id')->get() as $r) {
            if (Row::str($r, 'role') === 'dial') {
                $dial[Row::int($r, 'product_id')][] = Row::int($r, 'color_id');
            } else {
                $band[Row::int($r, 'product_id')][] = Row::int($r, 'color_id');
            }
        }
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['features' => $features[$id] ?? [], 'genders' => $genders[$id] ?? [], 'dial' => $dial[$id] ?? [], 'band' => $band[$id] ?? []];
        }

        return $out;
    }

    /**
     * product id => {type: node|null, sub: node|null} — the depth-1 placement and the primary depth-2 placement.
     *
     * @param  list<int>  $ids
     * @return array<int, array{type: array<string, mixed>|null, sub: array<string, mixed>|null}>
     */
    public function placements(array $ids, CompatCategories $categories): array
    {
        /** @var array<int, array{type: array<string, mixed>|null, sub: array<string, mixed>|null}> $out */
        $out = [];
        if ($ids === []) {
            return $out;
        }
        foreach ($ids as $id) {
            $out[$id] = ['type' => null, 'sub' => null];
        }
        // Deterministic order (milestone audit 🔴-2): primary rows first, then the deepest node,
        // then the lowest node id. M1d guarantees at most one primary per product, so the first
        // depth-≥2 row this loop sees IS the primary; the tie-breaks only matter for legacy data
        // that predates the constraint.
        $rows = DB::table('storefront_category_product as scp')
            ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
            ->select(['scp.product_id', 'scp.storefront_category_id', 'scp.is_primary'])
            ->where('scp.storefront_id', $this->storefrontId)
            ->whereIn('scp.product_id', $ids)
            ->orderByDesc('scp.is_primary')->orderByDesc('c.depth')->orderBy('c.id')
            ->get();
        foreach ($rows as $r) {
            $node = $categories->node(Row::int($r, 'storefront_category_id'));
            if ($node === null || $node['legacy_source'] === 'category' || $node['legacy_source'] === 'category_root') {
                continue;
            }
            $pid = Row::int($r, 'product_id');
            if ($node['depth'] === 1 && $out[$pid]['type'] === null) {
                $out[$pid]['type'] = $node;
            } elseif ($node['depth'] >= 2 && Row::bool($r, 'is_primary') && $out[$pid]['sub'] === null) {
                $out[$pid]['sub'] = $node;
            }
        }
        // A primary placement implies its depth-1 ancestor when no explicit type placement exists.
        foreach ($out as $pid => $p) {
            if ($p['type'] === null && $p['sub'] !== null) {
                $parentId = $p['sub']['parent_id'] ?? null;
                $out[$pid]['type'] = is_int($parentId) ? $categories->node($parentId) : null;
            }
        }

        return $out;
    }

    /**
     * Product ratings from the shared table (read-only): product id => {avg: float|null, count: int}.
     *
     * @param  list<int>  $ids
     * @return array<int, array{avg: float|null, count: int}>
     */
    public function ratingStats(array $ids): array
    {
        $out = [];
        if ($ids === []) {
            return $out;
        }
        $rows = DB::connection('legacy')->table('product_ratings')
            ->selectRaw('product_id, AVG(rating) AS avg_rating, COUNT(*) AS n')
            ->whereIn('product_id', $ids)
            ->groupBy('product_id')
            ->get();
        foreach ($rows as $r) {
            $out[Row::int($r, 'product_id')] = ['avg' => Row::nfloat($r, 'avg_rating'), 'count' => Row::int($r, 'n')];
        }

        return $out;
    }
}
