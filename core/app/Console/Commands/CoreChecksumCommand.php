<?php

namespace App\Console\Commands;

use App\Transform\LegacySource;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * core:checksum — CHECKSUM TABLE over the legacy set, the clean set, or both, plus one
 * combined digest. Read-only. Used by the rehearsal protocol to prove that a dry-run
 * (or a real run) left every legacy table byte-identical and that a second real run
 * left every clean table byte-identical.
 */
final class CoreChecksumCommand extends Command
{
    protected $signature = 'core:checksum {--set=legacy : legacy | clean | all} {--json : machine-readable output}';

    protected $description = 'CHECKSUM TABLE digest of the legacy and/or clean tables (read-only)';

    /** @var list<string> */
    public const CLEAN_TABLES = [
        'catalog_brands', 'catalog_brand_translations', 'catalog_grades', 'catalog_grade_translations',
        'catalog_colors', 'catalog_color_translations', 'catalog_sizes', 'catalog_size_translations',
        'catalog_units', 'catalog_unit_translations', 'catalog_materials', 'catalog_material_translations',
        'catalog_shapes', 'catalog_shape_translations', 'catalog_movement_types', 'catalog_movement_type_translations',
        'catalog_closure_types', 'catalog_closure_type_translations', 'catalog_display_types', 'catalog_display_type_translations',
        'catalog_features', 'catalog_feature_translations', 'catalog_genders', 'catalog_gender_translations',
        'catalog_products', 'catalog_product_translations', 'catalog_product_watch_specs', 'catalog_product_images',
        'catalog_product_feature', 'catalog_product_gender', 'catalog_product_color', 'catalog_product_variants',
        'catalog_product_search', 'storefronts', 'storefront_product', 'storefront_categories',
        'storefront_category_translations', 'storefront_category_product', 'storefront_banners', 'storefront_redirects',
        'inventory_movements', 'integration_outbox', 'transform_id_map',
    ];

    public function handle(): int
    {
        $set = $this->option('set');
        $set = is_string($set) ? $set : 'legacy';
        $tables = match ($set) {
            'legacy' => LegacySource::TABLES,
            'clean' => self::CLEAN_TABLES,
            'all' => array_merge(LegacySource::TABLES, self::CLEAN_TABLES),
            default => null,
        };
        if ($tables === null) {
            $this->error("--set must be legacy, clean or all (got [$set]).");

            return self::INVALID;
        }

        $result = self::compute($tables);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['table', 'rows', 'checksum'], array_map(fn (array $r) => [$r['table'], $r['rows'], $r['checksum']], $result['tables']));
        $this->info("combined sha1 ({$set}, ".count($tables).' tables): '.$result['digest']);

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $tables
     * @return array{digest: string, tables: list<array{table: string, rows: int, checksum: string}>}
     */
    public static function compute(array $tables): array
    {
        $db = DB::connection();
        $rows = [];
        $parts = [];
        foreach ($tables as $table) {
            $row = $db->selectOne('CHECKSUM TABLE `'.str_replace('`', '', $table).'`');
            $checksum = is_object($row) && property_exists($row, 'Checksum') && is_scalar($row->Checksum) ? (string) $row->Checksum : 'n/a';
            $count = Row::int((object) ['n' => $db->table($table)->count()], 'n');
            $rows[] = ['table' => $table, 'rows' => $count, 'checksum' => $checksum];
            $parts[] = "$table:$count:$checksum";
        }

        return ['digest' => sha1(implode('|', $parts)), 'tables' => $rows];
    }
}
