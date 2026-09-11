<?php

namespace App\Console\Commands;

use App\Transform\LegacySource;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * core:checksum — a content digest of the legacy set, the clean set, or both, plus one combined
 * sha1. Read-only. The rehearsal protocol uses it to prove that a run left every legacy table
 * byte-identical and that a second real run left every clean table byte-identical.
 *
 * Two methods, chosen per table:
 *
 *  • `CHECKSUM TABLE` for ordinary tables — fast, and stable for them.
 *  • an explicit column digest for tables carrying a GENERATED (virtual) column.
 *
 * The second method exists because rehearsal #2 caught `CHECKSUM TABLE` returning two different
 * values for `storefront_category_product` while its 928 rows were provably identical column by
 * column: the table gained a virtual column in M1d (`primary_guard`), and MariaDB's row-image
 * checksum follows the physical layout, which a rolled-back insert (every test suite run) leaves
 * different. A digest that reads the real columns in primary-key order cannot drift that way.
 */
final class CoreChecksumCommand extends Command
{
    protected $signature = 'core:checksum {--set=legacy : legacy | frozen | clean | all} {--json : machine-readable output}';

    protected $description = 'Content digest of the legacy and/or clean tables (read-only)';

    /**
     * The shared COMMERCE tables (study §2.6). They live in the legacy set for historical reasons
     * — the transform still writes none of them — but from wave 3 the CORE application writes
     * them: a cart, an order, an address or a payment status created through the compat layer
     * lands here, and `offers.stock` is decremented here by `InventoryService::adjustOffer()`.
     *
     * So "the legacy tables did not change" stopped being true for these six the moment the
     * checkout moved, and a rehearsal that runs the harness (which really places orders) must
     * compare the FROZEN set instead. `--set=legacy` still digests all 65 and is still what the
     * transform asserts before and after its own run.
     *
     * @var list<string>
     */
    public const SHARED_COMMERCE_TABLES = [
        'addresses', 'carts', 'cart_items', 'orders', 'order_items', 'payment_statuses', 'offers',
    ];

    /** @var list<string> */
    /**
     * Core-owned tables whose content is AUTHORED IN THE DASHBOARD, not produced by the transform.
     *
     * **These are never dropped.** Switch night's drop-and-rebuild (study §3.4 step 3b) exists to
     * throw away transform OUTPUT and rebuild it from legacy; a row a human typed into the
     * dashboard has no legacy source to rebuild from, so dropping it is pure data loss at the worst
     * possible moment. Wave 4A found this the hard way: `storefronts` was in the drop list, so the
     * storefront-settings screen's every save — name, domain, locales, currency, settings JSON, the
     * per-storefront logo — would have vanished on the night the team went live.
     *
     * The rule this list encodes (AGENTS §2.20, decided 2026-09-11): **if the dashboard authors it,
     * it is not in the drop list.** That covers `storefronts` and `storefront_banners` today,
     * `core_user_roles` (which would have locked every administrator out), and the payment
     * provider/method tables when 4C builds them (§3.9.1).
     *
     * `storefronts` is the one table BOTH sides touch: the transform `ensure()`s row 1 exists so a
     * fresh install has a storefront, and the dashboard owns its columns afterwards — which is why
     * `StorefrontSeeder::ensure()` is insert-only for those columns (§2.9.6).
     *
     * @var list<string>
     */
    public const DASHBOARD_TABLES = [
        'storefronts', 'storefront_banners', 'core_user_roles',
    ];

    /**
     * Every table core owns: transform output PLUS dashboard-authored. This is the set for
     * "may core write this?" and for a digest that covers the whole clean side.
     *
     * @var list<string>
     */
    public const CORE_TABLES = [...self::CLEAN_TABLES, ...self::DASHBOARD_TABLES];

    /**
     * Transform OUTPUT — the tables §3.4 step 3b drops and rebuilds. Dashboard-authored tables are
     * deliberately absent; see {@see self::DASHBOARD_TABLES}.
     *
     * @var list<string>
     */
    public const CLEAN_TABLES = [
        'catalog_brands', 'catalog_brand_translations', 'catalog_grades', 'catalog_grade_translations',
        'catalog_colors', 'catalog_color_translations', 'catalog_sizes', 'catalog_size_translations',
        'catalog_units', 'catalog_unit_translations', 'catalog_materials', 'catalog_material_translations',
        'catalog_shapes', 'catalog_shape_translations', 'catalog_movement_types', 'catalog_movement_type_translations',
        'catalog_closure_types', 'catalog_closure_type_translations', 'catalog_display_types', 'catalog_display_type_translations',
        'catalog_features', 'catalog_feature_translations', 'catalog_genders', 'catalog_gender_translations',
        'catalog_products', 'catalog_product_translations', 'catalog_product_watch_specs', 'catalog_product_images',
        'catalog_product_feature', 'catalog_product_gender', 'catalog_product_color', 'catalog_product_variants',
        'catalog_product_search', 'storefront_product', 'storefront_categories',
        'storefront_category_translations', 'storefront_category_product', 'storefront_redirects',
        'inventory_movements', 'integration_outbox', 'core_transform_id_map',
    ];

    public function handle(): int
    {
        $set = $this->option('set');
        $set = is_string($set) ? $set : 'legacy';
        $tables = match ($set) {
            'legacy' => LegacySource::TABLES,
            'frozen' => self::frozenTables(),
            'clean' => self::CORE_TABLES,
            'transform' => self::CLEAN_TABLES,
            'dashboard' => self::DASHBOARD_TABLES,
            'all' => array_merge(LegacySource::TABLES, self::CORE_TABLES),
            default => null,
        };
        if ($tables === null) {
            $this->error("--set must be legacy, frozen, clean, transform, dashboard or all (got [$set]).");

            return self::INVALID;
        }

        $result = self::compute($tables);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['table', 'rows', 'checksum', 'method'], array_map(fn (array $r) => [$r['table'], $r['rows'], $r['checksum'], $r['method']], $result['tables']));
        $this->info("combined sha1 ({$set}, ".count($tables).' tables): '.$result['digest']);

        return self::SUCCESS;
    }

    /**
     * The legacy tables NOTHING in core may write: the 65 minus the shared commerce ones. This
     * is the set a rehearsal asserts unchanged end to end once the run includes a harness pass.
     *
     * @return list<string>
     */
    public static function frozenTables(): array
    {
        return array_values(array_diff(LegacySource::TABLES, self::SHARED_COMMERCE_TABLES));
    }

    /**
     * @param  list<string>  $tables
     * @return array{digest: string, tables: list<array{table: string, rows: int, checksum: string, method: string}>}
     */
    public static function compute(array $tables): array
    {
        $db = DB::connection();
        $generated = self::generatedColumnTables($db, $tables);
        $rows = [];
        $parts = [];
        foreach ($tables as $table) {
            $name = str_replace('`', '', $table);
            $count = Row::int((object) ['n' => $db->table($table)->count()], 'n');
            if (isset($generated[$name])) {
                $checksum = 'md5:'.self::columnDigest($db, $name, $generated[$name]);
                $method = 'columns';
            } else {
                $row = $db->selectOne('CHECKSUM TABLE `'.$name.'`');
                $checksum = is_object($row) && property_exists($row, 'Checksum') && is_scalar($row->Checksum) ? (string) $row->Checksum : 'n/a';
                $method = 'CHECKSUM TABLE';
            }
            $rows[] = ['table' => $table, 'rows' => $count, 'checksum' => $checksum, 'method' => $method];
            $parts[] = "$table:$count:$checksum";
        }

        return ['digest' => sha1(implode('|', $parts)), 'tables' => $rows];
    }

    /**
     * Tables (of the given set) that carry at least one GENERATED column, mapped to their REAL
     * columns in ordinal order. A generated column is a pure function of the real ones, so
     * leaving it out loses nothing and removes the storage-layout sensitivity.
     *
     * @param  list<string>  $tables
     * @return array<string, list<string>>
     */
    private static function generatedColumnTables(Connection $db, array $tables): array
    {
        if ($tables === []) {
            return [];
        }
        $names = array_values(array_unique(array_map(fn (string $t) => str_replace('`', '', $t), $tables)));
        $rows = $db->table('information_schema.COLUMNS')
            ->select(['TABLE_NAME', 'COLUMN_NAME', 'EXTRA', 'ORDINAL_POSITION'])
            ->where('TABLE_SCHEMA', $db->getDatabaseName())
            ->whereIn('TABLE_NAME', $names)
            ->orderBy('TABLE_NAME')->orderBy('ORDINAL_POSITION')
            ->get();

        $real = [];
        $hasGenerated = [];
        foreach ($rows as $row) {
            $table = Row::str($row, 'TABLE_NAME');
            $column = Row::str($row, 'COLUMN_NAME');
            if (str_contains(strtoupper(Row::nstr($row, 'EXTRA') ?? ''), 'GENERATED')) {
                $hasGenerated[$table] = true;

                continue;
            }
            $real[$table][] = $column;
        }

        $out = [];
        foreach (array_keys($hasGenerated) as $table) {
            $out[$table] = $real[$table] ?? [];
        }

        return $out;
    }

    /**
     * md5 of every row's real columns, in primary-key order (falling back to every column when a
     * table has no primary key). Streamed with a cursor and hashed incrementally, so the digest
     * costs one pass and no memory, and does not depend on GROUP_CONCAT limits.
     *
     * @param  list<string>  $columns
     */
    private static function columnDigest(Connection $db, string $table, array $columns): string
    {
        if ($columns === []) {
            return md5('');
        }
        $order = self::primaryKeyColumns($db, $table);
        $order = $order === [] ? $columns : $order;

        $query = $db->table($table)->select($columns);
        foreach ($order as $column) {
            $query->orderBy($column);
        }

        $hash = hash_init('md5');
        foreach ($query->cursor() as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = property_exists($row, $column) ? $row->{$column} : null;
                $values[] = $value === null ? "\0NULL" : (is_scalar($value) ? (string) $value : '?');
            }
            hash_update($hash, implode("\x1f", $values)."\x1e");
        }

        return hash_final($hash);
    }

    /** @return list<string> */
    private static function primaryKeyColumns(Connection $db, string $table): array
    {
        $rows = $db->table('information_schema.STATISTICS')
            ->select(['COLUMN_NAME'])
            ->where('TABLE_SCHEMA', $db->getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', 'PRIMARY')
            ->orderBy('SEQ_IN_INDEX')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[] = Row::str($row, 'COLUMN_NAME');
        }

        return $out;
    }
}
