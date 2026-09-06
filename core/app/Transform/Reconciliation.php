<?php

namespace App\Transform;

use App\Models\Storefront\Storefront;
use App\Support\LegacySlug;
use App\Transform\Steps\Step04Colors;

/**
 * The counts contract: every clean table against its legacy source(s) with the expected
 * relationship, computed INDEPENDENTLY of the steps (fresh legacy queries + the same
 * derivation rules), so a step that silently drops rows cannot pass. Any mismatch is a
 * failure; the command exits non-zero and says so loudly.
 */
final class Reconciliation
{
    /** @var list<array{table: string, source: string, relation: string, expected: int, actual: int, ok: bool}> */
    public array $rows = [];

    public function __construct(private readonly TransformContext $ctx) {}

    public function passed(): bool
    {
        foreach ($this->rows as $row) {
            if (! $row['ok']) {
                return false;
            }
        }

        return true;
    }

    public function run(): self
    {
        $ctx = $this->ctx;
        $legacy = $ctx->legacy;
        $db = $ctx->db;
        $sf = $ctx->storefrontId;
        $this->rows = [];

        $count = fn (string $table): int => $db->table($table)->count();

        // 1–3 masters
        $this->check('catalog_brands', 'brands', '=', $legacy->count('brands'), $count('catalog_brands'));
        $this->check('catalog_brand_translations', 'brands × 2 locales', '= 2×', 2 * $legacy->count('brands'), $count('catalog_brand_translations'));
        foreach (['grades' => 'grade', 'materials' => 'material', 'shapes' => 'shape', 'movement_types' => 'movement_type', 'closure_types' => 'closure_type', 'display_types' => 'display_type', 'features' => 'feature', 'genders' => 'gender'] as $plural => $singular) {
            $this->check("catalog_{$plural}", $plural, '=', $legacy->count($plural), $count("catalog_{$plural}"));
            $this->check("catalog_{$singular}_translations", "$plural × 2 locales", '= 2×', 2 * $legacy->count($plural), $count("catalog_{$singular}_translations"));
        }
        $this->check('catalog_units', 'size_types', '=', $legacy->count('size_types'), $count('catalog_units'));
        $this->check('catalog_unit_translations', 'size_types × 2', '= 2×', 2 * $legacy->count('size_types'), $count('catalog_unit_translations'));

        // 4 colors: legacy colors + new_colors that do not match a legacy row
        $legacyColors = $legacy->count('colors');
        $index = [];
        $colorNames = $ctx->legacyTranslations('color_translations', 'color_id', 'color_name');
        foreach ($legacy->table('colors')->select(['id', 'color_value'])->get() as $row) {
            $id = Row::int($row, 'id');
            $index[mb_strtolower(trim($colorNames[$id]['en'] ?? '')).'|'.(Step04Colors::normaliseHex(Row::nstr($row, 'color_value')) ?? '')] = true;
        }
        $newDistinct = 0;
        foreach ($legacy->table('new_colors')->select(['name_en', 'hex'])->get() as $row) {
            if (! isset($index[mb_strtolower(trim(Row::str($row, 'name_en'))).'|'.(Step04Colors::normaliseHex(Row::nstr($row, 'hex')) ?? '')])) {
                $newDistinct++;
            }
        }
        $this->check('catalog_colors', "colors ($legacyColors) + non-duplicate new_colors ($newDistinct)", '=', $legacyColors + $newDistinct, $count('catalog_colors'));
        $this->check('catalog_color_translations', 'colors × 2', '= 2×', 2 * ($legacyColors + $newDistinct), $count('catalog_color_translations'));

        // 5 sizes
        $this->check('catalog_sizes', 'new_sizes', '=', $legacy->count('new_sizes'), $count('catalog_sizes'));
        $this->check('catalog_size_translations', 'new_sizes × 2', '= 2×', 2 * $legacy->count('new_sizes'), $count('catalog_size_translations'));

        // 6–7 products
        $products = $legacy->count('products');
        $this->check('catalog_products', 'products', '= (ids preserved)', $products, $count('catalog_products'));
        $this->check('catalog_products (id set)', 'products.id', 'every legacy id present', $products, $this->sharedIds('products', 'catalog_products'));
        $this->check('catalog_product_translations', 'products × 2 locales', '= 2×', 2 * $products, $count('catalog_product_translations'));
        foreach (['ar', 'en'] as $locale) {
            $this->check("catalog_product_translations[$locale]", 'products', "= one $locale row per product", $products, $db->table('catalog_product_translations')->where('locale', $locale)->count());
        }

        // 8 watch specs: family watch OR any watch column non-null
        $families = $ctx->families();
        $watchCols = ['case_size', 'case_shape_id', 'dial_case_material_id', 'dial_glass_material_id', 'case_thickness', 'band_material_id', 'band_closure_id', 'band_length', 'band_width', 'dial_display_type_id', 'watch_movement_id', 'water_resistance', 'watch_height', 'watch_width', 'watch_length', 'interchangeable_dial', 'interchangeable_strap', 'watch_box'];
        $expectedSpecs = 0;
        foreach ($legacy->table('products')->select(array_merge(['id'], $watchCols))->orderBy('id')->cursor() as $row) {
            $has = false;
            foreach ($watchCols as $c) {
                if ($row->{$c} !== null) {
                    $has = true;
                    break;
                }
            }
            if ($has || ($families[Row::int($row, 'id')] ?? '') === 'watch') {
                $expectedSpecs++;
            }
        }
        $this->check('catalog_product_watch_specs', 'products with family=watch or any watch column', '=', $expectedSpecs, $count('catalog_product_watch_specs'));

        // 9–10 images
        $covers = $legacy->table('products')->where('image', '<>', '')->count();
        $gallery = $legacy->table('product_images')->where('image', '<>', '')->count();
        $this->check('catalog_product_images[cover]', 'products with image', '=', $covers, $db->table('catalog_product_images')->where('is_cover', 1)->count());
        $this->check('catalog_product_images[gallery]', 'product_images', '= (ids preserved)', $gallery, $db->table('catalog_product_images')->where('is_cover', 0)->count());
        $this->check('catalog_product_images[gallery id set]', 'product_images.id', 'every legacy id present', $gallery, $this->sharedIds('product_images', 'catalog_product_images'));
        $this->check('catalog_product_images[gallery order]', 'product_images.sort + 1', 'order preserved on every row', $gallery, $this->galleryOrderMatches());

        // 11–12 pivots
        $this->check('catalog_product_feature', 'distinct valid feature_product pairs', '=', $this->distinctValidPairs('feature_product', 'feature_id', 'features'), $count('catalog_product_feature'));
        $this->check('catalog_product_gender', 'distinct valid gender_product pairs', '=', $this->distinctValidPairs('gender_product', 'gender_id', 'genders'), $count('catalog_product_gender'));
        $dial = $this->distinctValidPairs('color_dial_product', 'color_id', 'colors');
        $band = $this->distinctValidPairs('color_band_product', 'color_id', 'colors');
        $main = $this->distinctValidPairs('color_dial_product', 'color_id', 'colors', nonWatchOnly: true);
        $this->check('catalog_product_color', "dial ($dial) + band ($band) + main for non-watch ($main)", '=', $dial + $band + $main, $count('catalog_product_color'));

        // 13 variants
        $this->check('catalog_product_variants', 'product_variants', '= (ids preserved)', $legacy->table('product_variants')->join('products', 'products.id', '=', 'product_variants.product_id')->count(), $count('catalog_product_variants'));

        // 14 storefront
        $this->check('storefronts[watchizer]', 'explicit id '.Storefront::WATCHIZER_ID, "= 1 row with code 'watchizer'", 1, $db->table('storefronts')->where('id', Storefront::WATCHIZER_ID)->where('code', 'watchizer')->count());

        // 15–17 categories
        $types = $legacy->count('category_types');
        $pairs = $legacy->table('products')->whereNotNull('category_type_id')->whereNotNull('sub_type_id')->distinct()->count($db->raw('CONCAT(category_type_id, ":", sub_type_id)'));
        $usedSubs = $legacy->table('products')->whereNotNull('sub_type_id')->distinct()->count('sub_type_id');
        $orphans = $legacy->count('sub_types') - $usedSubs;
        $categories = $legacy->count('categories');
        $expectedNodes = $types + $pairs + $orphans + 1 + $categories;
        $this->check('storefront_categories', "category_types ($types) + pairs ($pairs) + orphan sub types ($orphans) + root (1) + categories ($categories)", '=', $expectedNodes, $db->table('storefront_categories')->where('storefront_id', $sf)->count());
        $this->check('storefront_categories[depth 1]', 'category_types', '=', $types, $db->table('storefront_categories')->where('storefront_id', $sf)->where('legacy_source', 'category_type')->count());
        $this->check('storefront_categories[depth 2]', 'pairs + orphans', '=', $pairs + $orphans, $db->table('storefront_categories')->where('storefront_id', $sf)->where('legacy_source', 'sub_type')->count());
        $this->check('storefront_category_translations', 'nodes × 2', '= 2×', 2 * $expectedNodes, $db->table('storefront_category_translations')->join('storefront_categories', 'storefront_categories.id', '=', 'storefront_category_translations.storefront_category_id')->where('storefront_categories.storefront_id', $sf)->count());

        // 18–19 storefront product + placements (+ A-17 twin redirects)
        $this->check('storefront_product', 'products', '= one row per product (storefront '.$sf.')', $products, $db->table('storefront_product')->where('storefront_id', $sf)->count());
        $this->check('storefront_redirects[legacy_twin]', 'EN-title slug collision groups (A-17)', '= one row per group', $this->slugCollisionGroups(), $db->table('storefront_redirects')->where('storefront_id', $sf)->where('source', 'legacy_twin')->count());
        $this->check('storefront_product[visible]', 'products', '= all visible', $products, $db->table('storefront_product')->where('storefront_id', $sf)->where('is_visible', 1)->count());
        $typed = $legacy->table('products')->whereNotNull('category_type_id')->count();
        $paired = $legacy->table('products')->whereNotNull('category_type_id')->whereNotNull('sub_type_id')->count();
        $this->check('storefront_category_product', "products with type ($typed) + products with pair ($paired)", '=', $typed + $paired, $db->table('storefront_category_product')->where('storefront_id', $sf)->count());
        $this->check('storefront_category_product[primary]', 'products with pair', '= is_primary rows', $paired, $db->table('storefront_category_product')->where('storefront_id', $sf)->where('is_primary', 1)->count());

        // 20 ledger baseline
        $this->check('inventory_movements[transform]', 'products × 2 buckets', '= 2×', 2 * $products, $db->table('inventory_movements')->where('reason', 'transform')->count());
        $this->check('inventory_movements[express sum]', 'SUM(products.stock)', '= ledger sum', (int) $legacy->table('products')->sum('stock'), (int) $db->table('inventory_movements')->where('reason', 'transform')->where('bucket', 'express')->sum('quantity_after'));
        $this->check('inventory_movements[market sum]', 'SUM(products.market_stock)', '= ledger sum', (int) $legacy->table('products')->sum('market_stock'), (int) $db->table('inventory_movements')->where('reason', 'transform')->where('bucket', 'market')->sum('quantity_after'));
        $this->check('catalog_products[stock mirror]', 'products.stock / market_stock', '= stock_express / stock_market on every row', $products, $this->stockMirrorMatches());

        // 21 search
        $this->check('catalog_product_search', 'products × 2 locales', '= 2×', 2 * $products, $count('catalog_product_search'));

        // id map
        $this->check('transform_id_map', "new_colors + new_sizes + covers ($covers) + category nodes (types + pairs + orphans + categories)", '=', $legacy->count('new_colors') + $legacy->count('new_sizes') + $covers + $types + $pairs + $orphans + $categories, $count('transform_id_map'));

        return $this;
    }

    private function check(string $table, string $source, string $relation, int $expected, int $actual): void
    {
        $this->rows[] = ['table' => $table, 'source' => $source, 'relation' => $relation, 'expected' => $expected, 'actual' => $actual, 'ok' => $expected === $actual];
    }

    private function sharedIds(string $legacyTable, string $cleanTable): int
    {
        $clean = [];
        foreach ($this->ctx->db->table($cleanTable)->select(['id'])->orderBy('id')->cursor() as $row) {
            $clean[Row::int($row, 'id')] = true;
        }
        $shared = 0;
        foreach ($this->ctx->legacy->table($legacyTable)->select(['id'])->orderBy('id')->cursor() as $row) {
            if (isset($clean[Row::int($row, 'id')])) {
                $shared++;
            }
        }

        return $shared;
    }

    private function galleryOrderMatches(): int
    {
        $clean = [];
        foreach ($this->ctx->db->table('catalog_product_images')->select(['id', 'sort', 'path'])->where('is_cover', 0)->orderBy('id')->cursor() as $row) {
            $clean[Row::int($row, 'id')] = [Row::int($row, 'sort'), Row::str($row, 'path')];
        }
        $ok = 0;
        foreach ($this->ctx->legacy->table('product_images')->select(['id', 'sort', 'image'])->where('image', '<>', '')->orderBy('id')->cursor() as $row) {
            $c = $clean[Row::int($row, 'id')] ?? null;
            if ($c !== null && $c[0] === Row::int($row, 'sort') + 1 && $c[1] === 'Product_image/'.trim(Row::str($row, 'image'))) {
                $ok++;
            }
        }

        return $ok;
    }

    /** Number of un-suffixed EN-title slugs shared by more than one product (A-17 groups). */
    private function slugCollisionGroups(): int
    {
        $titles = [];
        foreach ($this->ctx->legacy->table('product_translations')->select(['product_id', 'product_title'])->where('locale', 'en')->orderBy('product_id')->cursor() as $t) {
            $titles[Row::int($t, 'product_id')] = trim(Row::str($t, 'product_title'));
        }
        $perSlug = [];
        foreach ($this->ctx->legacy->table('products')->select(['id'])->orderBy('id')->cursor() as $p) {
            $id = Row::int($p, 'id');
            $slug = LegacySlug::orId($titles[$id] ?? '', $id);
            $perSlug[$slug] = ($perSlug[$slug] ?? 0) + 1;
        }

        return count(array_filter($perSlug, fn (int $n) => $n > 1));
    }

    private function stockMirrorMatches(): int
    {
        $clean = [];
        foreach ($this->ctx->db->table('catalog_products')->select(['id', 'stock_express', 'stock_market'])->orderBy('id')->cursor() as $row) {
            $clean[Row::int($row, 'id')] = [Row::int($row, 'stock_express'), Row::int($row, 'stock_market')];
        }
        $ok = 0;
        foreach ($this->ctx->legacy->table('products')->select(['id', 'stock', 'market_stock'])->orderBy('id')->cursor() as $row) {
            $c = $clean[Row::int($row, 'id')] ?? null;
            if ($c !== null && $c[0] === Row::int($row, 'stock') && $c[1] === (Row::nint($row, 'market_stock') ?? 0)) {
                $ok++;
            }
        }

        return $ok;
    }

    private function distinctValidPairs(string $pivot, string $fk, string $parent, bool $nonWatchOnly = false): int
    {
        $families = $nonWatchOnly ? $this->ctx->families() : [];
        $seen = [];
        $rows = $this->ctx->legacy->table($pivot)->select(["$pivot.product_id", "$pivot.$fk"])
            ->join('products', 'products.id', '=', "$pivot.product_id")
            ->join($parent, "$parent.id", '=', "$pivot.$fk")
            ->orderBy("$pivot.id")->get();
        foreach ($rows as $row) {
            $productId = Row::int($row, 'product_id');
            if ($nonWatchOnly && ($families[$productId] ?? 'watch') === 'watch') {
                continue;
            }
            $seen[$productId.':'.Row::int($row, $fk)] = true;
        }

        return count($seen);
    }

    public function toMarkdown(): string
    {
        $md = "# Reconciliation — clean tables vs legacy sources\n\n| Clean table | Legacy source | Relation | Expected | Actual | Status |\n|---|---|---|---:|---:|---|\n";
        foreach ($this->rows as $r) {
            $md .= sprintf("| %s | %s | %s | %d | %d | %s |\n", $r['table'], $r['source'], $r['relation'], $r['expected'], $r['actual'], $r['ok'] ? 'OK' : '**MISMATCH**');
        }
        $md .= "\n**".($this->passed() ? 'ALL COUNTS RECONCILE.' : 'RECONCILIATION FAILED — see MISMATCH rows.')."**\n";

        return $md;
    }
}
