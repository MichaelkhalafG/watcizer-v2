<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Inventory\StockWriteGuard;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Generator;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Step 1A of the legacy import (2026-11-11): remove the Brand Fashion products that the
 * September SHEET import created and that nobody sells on Watchizer — so the clean import from the
 * old site does not land beside 7,000 hidden, messier copies of itself.
 *
 * ── How a product's ORIGIN is read (measured on the production dump of 2026-11-11) ────────────
 *
 * | bucket | the test | why it is reliable |
 * |---|---|---|
 * | transform | its id is in legacy `products` | the transform preserves ids (D1); 626 rows, none logged as "created" |
 * | sheet | `import_ref LIKE 'woo:%'` | `import:catalogue` stamps the WordPress post id there and nothing else writes the column; all 7,087 resolve to a real WordPress product, all have `created_by` NULL |
 * | dashboard | neither | `ProductWriter::create` from a screen; all 1,459 carry `created_by` and a "created" log row by a named user |
 *
 * ── What is never touched ───────────────────────────────────────────────────────────────────
 *
 * Transform and dashboard products, whatever their state; and any sheet product ACTIVE ON
 * WATCHIZER (`storefront_product` row for storefront 1 visible, product active, not archived).
 *
 * ── What happens to the rest of the sheet products ──────────────────────────────────────────
 *
 *   • DUPLICATE — another live product has the same SKU (or, with no SKU, the same name): LISTED,
 *     never removed. The developer decides.
 *   • HISTORY — an order line, a ledger movement, or any business reference (cart, wishlist,
 *     rating, banner, campaign, promotion, stock alert, price watch, home rail, wheel): NEVER
 *     hard-deleted. With `--history=archive` it is archived (the dashboard's own delete: hidden
 *     everywhere, history intact, `restore` brings it back); by default it is left as it is.
 *   • otherwise — HARD-DELETED with every child row, after a backup that `restore()` reverses.
 *
 * `--opening-balance-is-not-history` is the one narrowing the developer may choose: a ledger whose
 * ONLY rows are the sheet import's own `import` opening balance is the importer's own write, not a
 * sale or a count — the rule `ProductImporter::discard()` has applied since 2026-09-14. Off by
 * default, because the instruction for this step says "stock-ledger movements" without exception.
 */
final class CatalogueCleanup
{
    public const SHEET_PREFIX = 'woo:';

    /** Rows that belong to the product and go with it (the FK-cascading children, named). */
    public const CHILD_TABLES = [
        'catalog_product_search', 'catalog_product_images', 'catalog_product_variants',
        'catalog_product_translations', 'catalog_product_watch_specs', 'catalog_product_feature',
        'catalog_product_gender', 'catalog_product_color', 'storefront_category_product', 'storefront_product',
        // Derived, not history: PriceWatch::refresh() keeps one row per LIVE product ("since when has
        // this price held") and rebuilds it daily. Listing it as a blocker made every product "history".
        'core_price_watch',
    ];

    /** Rows that are somebody's history or content: one of these and the product is not deleted. */
    public const BLOCKING_TABLES = [
        'order_items' => 'product_id',
        'inventory_movements' => 'product_id',
        'cart_items' => 'product_id',
        'wishlist_items' => 'product_id',
        'product_ratings' => 'product_id',
        'storefront_banners' => 'product_id',
        'storefront_campaign_products' => 'product_id',
        'promotion_rule_conditions' => 'product_id',
        'promotion_rule_rewards' => 'product_id',
        'promotion_order_discount_lines' => 'product_id',
        'core_stock_alerts' => 'product_id',
        'core_price_batch_items' => 'product_id',
        'storefront_wheel_spins' => 'gift_product_id',
        'offers' => 'main_product_id',
    ];

    /** JSON id lists that can name a product. */
    public const BLOCKING_JSON = [
        'storefront_home_rails' => 'product_ids',
        'storefront_wheel_prizes' => 'gift_product_ids',
        'core_reengagement_settings' => 'manual_product_ids',
        'core_reengagement_runs' => 'manual_product_ids',
        'offers' => 'gift_product_ids',
    ];

    public const ORIGIN_TRANSFORM = 'transform';

    public const ORIGIN_SHEET = 'sheet';

    public const ORIGIN_DASHBOARD = 'dashboard';

    public const DELETE = 'delete';

    public const HISTORY = 'history';

    public const DUPLICATE = 'duplicate';

    public const KEEP_WATCHIZER = 'keep_watchizer';

    public const KEEP_ORIGIN = 'keep_origin';

    public function __construct(private readonly ProductWriter $products) {}

    /**
     * Every product, classified. Nothing is written.
     *
     * @return array{rows: array<int, array<string, mixed>>, buckets: array<string, int>, origins: array<string, int>}
     */
    public function classify(bool $openingBalanceIsNotHistory = false): array
    {
        $this->assertReferencesKnown();

        $legacyIds = [];
        foreach (DB::connection('legacy')->table('products')->pluck('id') as $id) {
            $legacyIds[Coerce::int($id)] = true;
        }

        $titles = [];
        foreach (DB::table('catalog_product_translations')->where('locale', 'en')->get(['product_id', 'title']) as $t) {
            $r = Row::cast($t);
            $titles[Row::int($r, 'product_id')] = Row::str($r, 'title');
        }

        $watchizerActive = [];
        foreach (DB::table('storefront_product as sp')
            ->join('catalog_products as cp', 'cp.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
            ->where('cp.is_active', 1)->whereNull('cp.deleted_at')
            ->pluck('sp.product_id') as $id) {
            $watchizerActive[Coerce::int($id)] = true;
        }

        $placements = [];
        foreach (DB::table('storefront_product')->get(['product_id', 'storefront_id', 'is_visible']) as $p) {
            $r = Row::cast($p);
            $placements[Row::int($r, 'product_id')][Row::int($r, 'storefront_id')] = Row::bool($r, 'is_visible');
        }

        $products = DB::table('catalog_products')->orderBy('id')
            ->get(['id', 'import_ref', 'wa_code', 'sku', 'created_by', 'is_active', 'deleted_at']);

        // Identity indexes over LIVE products, for the duplicate test.
        $bySku = [];
        $byName = [];
        foreach ($products as $p) {
            $r = Row::cast($p);
            if (Row::nstr($r, 'deleted_at') !== null) {
                continue;
            }
            $id = Row::int($r, 'id');
            $sku = LegacyText::skuKey(Row::nstr($r, 'sku'));
            if ($sku !== null) {
                $bySku[$sku][] = $id;
            } else {
                $name = LegacyText::nameKey($titles[$id] ?? '');
                if ($name !== '') {
                    $byName[$name][] = $id;
                }
            }
        }

        $history = $this->historyOf($openingBalanceIsNotHistory);

        $rows = [];
        $buckets = [];
        $origins = [];
        foreach ($products as $p) {
            $r = Row::cast($p);
            $id = Row::int($r, 'id');
            $ref = Row::nstr($r, 'import_ref');
            $origin = isset($legacyIds[$id]) ? self::ORIGIN_TRANSFORM
                : ($ref !== null && str_starts_with($ref, self::SHEET_PREFIX) ? self::ORIGIN_SHEET : self::ORIGIN_DASHBOARD);
            $origins[$origin] = ($origins[$origin] ?? 0) + 1;

            $sku = LegacyText::skuKey(Row::nstr($r, 'sku'));
            $others = $sku !== null ? ($bySku[$sku] ?? []) : ($byName[LegacyText::nameKey($titles[$id] ?? '')] ?? []);
            $others = array_values(array_filter($others, fn (int $o): bool => $o !== $id));

            if ($origin !== self::ORIGIN_SHEET) {
                $bucket = self::KEEP_ORIGIN;
            } elseif (isset($watchizerActive[$id])) {
                $bucket = self::KEEP_WATCHIZER;
            } elseif (Row::nstr($r, 'deleted_at') === null && $others !== []) {
                $bucket = self::DUPLICATE;
            } elseif (isset($history[$id])) {
                $bucket = self::HISTORY;
            } else {
                $bucket = self::DELETE;
            }
            $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;

            if ($origin === self::ORIGIN_SHEET) {
                $rows[$id] = [
                    'id' => $id,
                    'bucket' => $bucket,
                    'wa_code' => Row::str($r, 'wa_code'),
                    'import_ref' => $ref,
                    'sku' => Row::nstr($r, 'sku'),
                    'title' => $titles[$id] ?? '',
                    'archived' => Row::nstr($r, 'deleted_at') !== null,
                    'placements' => $placements[$id] ?? [],
                    'history' => $history[$id] ?? [],
                    'duplicate_of' => $bucket === self::DUPLICATE ? $others : [],
                    'matched_by' => $bucket === self::DUPLICATE ? ($sku !== null ? 'sku' : 'name') : null,
                ];
            }
        }

        ksort($buckets);
        ksort($origins);

        return ['rows' => $rows, 'buckets' => $buckets, 'origins' => $origins];
    }

    /**
     * What each duplicate is a duplicate OF, in words a person can act on.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    public function describe(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $legacy = [];
        foreach (DB::connection('legacy')->table('products')->whereIn('id', $ids)->pluck('id') as $id) {
            $legacy[Coerce::int($id)] = true;
        }
        $out = [];
        foreach (DB::table('catalog_products as cp')
            ->leftJoin('catalog_product_translations as t', fn (JoinClause $j) => $j->on('t.product_id', '=', 'cp.id')->where('t.locale', 'en'))
            ->whereIn('cp.id', $ids)
            ->get(['cp.id', 'cp.wa_code', 'cp.sku', 'cp.import_ref', 'cp.is_active', 't.title']) as $p) {
            $r = Row::cast($p);
            $id = Row::int($r, 'id');
            $ref = Row::nstr($r, 'import_ref');
            $placements = [];
            foreach (DB::table('storefront_product')->where('product_id', $id)->get(['storefront_id', 'is_visible']) as $sp) {
                $s = Row::cast($sp);
                $placements[] = Row::int($s, 'storefront_id').(Row::bool($s, 'is_visible') ? ':visible' : ':hidden');
            }
            $out[] = [
                'id' => $id,
                'wa_code' => Row::str($r, 'wa_code'),
                'sku' => Row::nstr($r, 'sku'),
                'origin' => isset($legacy[$id]) ? self::ORIGIN_TRANSFORM
                    : ($ref !== null && str_starts_with($ref, self::SHEET_PREFIX) ? self::ORIGIN_SHEET : self::ORIGIN_DASHBOARD),
                'active' => Row::bool($r, 'is_active'),
                'placements' => implode(',', $placements),
                'title' => Row::nstr($r, 'title') ?? '',
            ];
        }

        return $out;
    }

    /**
     * Copy every row an apply would change into a JSON-lines file — a header line, then one
     * `{"t": table, "r": row}` per row, parent rows first — read it back and verify the counts
     * BEFORE anything is written. Streamed: memory stays flat at any catalogue size (the first
     * version built one document and died at 128 MB on 6,500 products, before writing anything).
     *
     * @param  list<int>  $deleteIds
     * @param  list<int>  $archiveIds
     * @return array<string, int>
     */
    public function backup(string $file, array $deleteIds, array $archiveIds, bool $openingBalanceIsNotHistory): array
    {
        $handle = fopen($file, 'wb');
        if ($handle === false) {
            throw new RuntimeException("Cannot write the backup: {$file}");
        }
        $header = [
            'kind' => 'bf-catalogue-clean',
            'version' => 2,
            'created_at' => date(DATE_ATOM),
            'database' => DB::connection()->getDatabaseName(),
            'delete_ids' => $deleteIds,
            'archive_ids' => $archiveIds,
        ];
        fwrite($handle, json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $plan = [['catalog_products', 'id', array_merge($deleteIds, $archiveIds)]];
        foreach (self::CHILD_TABLES as $table) {
            $plan[] = [$table, 'product_id', $deleteIds];
        }
        if ($openingBalanceIsNotHistory) {
            $plan[] = ['inventory_movements', 'product_id', $deleteIds];
        }

        $expected = [];
        foreach ($plan as [$table, $column, $ids]) {
            $expected[$table] = $this->eachRow($table, $column, $ids, function (array $row) use ($handle, $table): void {
                fwrite($handle, json_encode(['t' => $table, 'r' => $row], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            });
        }
        if (! fclose($handle)) {
            throw new RuntimeException("The backup could not be closed: {$file}. Nothing was changed.");
        }

        // Read it back: a backup nobody has read is a hope.
        $read = [];
        $first = true;
        foreach ($this->lines($file) as $line) {
            $decoded = Coerce::arr(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            if ($first) {
                $first = false;

                continue;
            }
            $table = Coerce::str($decoded['t'] ?? null);
            $read[$table] = ($read[$table] ?? 0) + 1;
        }
        foreach ($expected as $table => $n) {
            if (($read[$table] ?? 0) !== $n) {
                throw new RuntimeException("The backup of {$table} reads back ".($read[$table] ?? 0)." rows, expected {$n}. Nothing was changed.");
            }
        }

        return $expected;
    }

    /**
     * Hard-delete these products (children first), archive those. Chunked transactions; one
     * activity row per product so the log says who removed what and why.
     *
     * @param  list<int>  $deleteIds
     * @param  list<int>  $archiveIds
     * @return array{deleted: int, archived: int}
     */
    public function apply(array $deleteIds, array $archiveIds, bool $openingBalanceIsNotHistory,
        string $reason = 'bf:catalogue-clean — sheet import of 2026-09-16, not active on Watchizer'): array
    {
        $deleted = 0;
        foreach (array_chunk($deleteIds, 200) as $chunk) {
            DB::transaction(function () use ($chunk, $openingBalanceIsNotHistory, $reason, &$deleted): void {
                // Re-check INSIDE the transaction: a sale between the dry run and now is history.
                $blocked = $this->historyOf($openingBalanceIsNotHistory, $chunk);
                $ids = array_values(array_filter($chunk, fn (int $id): bool => ! isset($blocked[$id])));
                if ($ids === []) {
                    return;
                }
                $labels = DB::table('catalog_product_translations')->whereIn('product_id', $ids)
                    ->where('locale', 'en')->pluck('title', 'product_id');

                foreach (self::CHILD_TABLES as $table) {
                    DB::table($table)->whereIn('product_id', $ids)->delete();
                }
                if ($openingBalanceIsNotHistory) {
                    DB::table('inventory_movements')->whereIn('product_id', $ids)->where('reason', 'import')->delete();
                }
                DB::table('catalog_products')->whereIn('id', $ids)->delete();

                foreach ($ids as $id) {
                    ActivityLog::record('catalog_products', $id, ActivityLog::DELETED,
                        label: Coerce::nstr($labels[$id] ?? null),
                        after: ['reason' => $reason],
                    );
                }
                $deleted += count($ids);
            });
        }

        foreach ($archiveIds as $id) {
            $this->products->archive($id, null);
        }

        // Every storefront's catalogue caches (a deleted product has no row left to flush by).
        $this->flushAll($deleteIds);

        return ['deleted' => $deleted, 'archived' => count($archiveIds)];
    }

    /**
     * O-4 (Michael, 2026-11-11 night): the duplicates review sheet (`twins-review.csv`, written by
     * `bf:catalogue-prepare`) decided. A row whose proposal is "NOT a duplicate" is removed — its clean
     * old product is imported instead (the importer's name identity carries the photo since). Of each
     * group of "A real duplicate" rows (the row and its twins that are real rows too) ONE stays: the
     * one the team edited (activity-log updates), else the oldest; the others are removed. Any other
     * proposal is not acted on, and every id must still be a live sheet copy in the duplicate bucket
     * — else it is refused and listed, never removed.
     *
     * @param  array<int, array<string, mixed>>  $rows  classify()['rows']
     * @return array{remove: list<int>, keep: list<int>, refused: list<string>, decisions: list<array{id: int, decision: string, why: string}>}
     */
    public function reviewed(string $csvFile, array $rows): array
    {
        $handle = fopen($csvFile, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$csvFile}");
        }
        $header = fgetcsv($handle, escape: '\\');
        if ($header === false) {
            throw new RuntimeException("{$csvFile} is empty");
        }
        $header = array_map(fn (?string $h): string => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), $header);
        foreach (['sheet_id', 'twins', 'proposal'] as $column) {
            if (! in_array($column, $header, true)) {
                throw new RuntimeException("{$csvFile} has no {$column} column: not a twins-review sheet");
            }
        }
        $notDuplicate = [];
        $real = [];
        $twins = [];
        $refused = [];
        while (($cells = fgetcsv($handle, escape: '\\')) !== false) {
            if ($cells === [null] || count($cells) !== count($header)) {
                continue;
            }
            $row = array_combine($header, array_map(fn (?string $c): string => (string) $c, $cells));
            $id = Coerce::int($row['sheet_id']);
            $proposal = $row['proposal'];
            if (str_starts_with($proposal, 'NOT a duplicate')) {
                $notDuplicate[] = $id;
            } elseif (str_starts_with($proposal, 'A real duplicate')) {
                $real[$id] = true;
                preg_match_all('/(\d+) BF-/', $row['twins'], $m);
                $twins[$id] = array_map('intval', $m[1]);
            } else {
                $refused[] = "{$id}: proposal not decided by O-4 — {$proposal}";
            }
        }
        fclose($handle);

        // The real rows in groups: a row and those of its twins that are real rows too.
        $group = [];
        foreach (array_keys($real) as $id) {
            $members = array_values(array_filter([$id, ...$twins[$id]], fn (int $t): bool => isset($real[$t])));
            sort($members);
            $group[implode(',', $members)] = $members;
        }
        $edits = [];
        foreach (DB::table('core_activity_log')->where('subject_type', 'catalog_products')->where('action', ActivityLog::UPDATED)
            ->whereIn('subject_id', array_keys($real))->groupBy('subject_id')->selectRaw('subject_id, COUNT(*) AS n')->get() as $e) {
            $r = Row::cast($e);
            $edits[Row::int($r, 'subject_id')] = Row::int($r, 'n');
        }

        $decisions = [];
        $remove = [];
        $keep = [];
        foreach ($notDuplicate as $id) {
            $decisions[] = ['id' => $id, 'decision' => 'remove', 'why' => 'not a duplicate: its clean old product is imported'];
            $remove[] = $id;
        }
        $seen = [];
        foreach ($group as $members) {
            if (isset($seen[$members[0]])) {
                continue;
            }
            $kept = $members[0];
            foreach ($members as $m) {
                $seen[$m] = true;
                if (($edits[$m] ?? 0) > ($edits[$kept] ?? 0)) {
                    $kept = $m;
                }
            }
            foreach ($members as $m) {
                if ($m === $kept) {
                    $decisions[] = ['id' => $m, 'decision' => 'keep', 'why' => 'one of a real duplicate ('.implode(', ', $members).'): '.(($edits[$m] ?? 0) > 0 ? 'the one the team edited' : 'the oldest — nobody edited any')];
                    $keep[] = $m;
                } else {
                    $decisions[] = ['id' => $m, 'decision' => 'remove', 'why' => 'a real duplicate of '.$kept];
                    $remove[] = $m;
                }
            }
        }

        $checked = [];
        foreach ($remove as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null || ($row['bucket'] ?? null) !== self::DUPLICATE || ! str_starts_with(Coerce::str($row['import_ref'] ?? null), self::SHEET_PREFIX)) {
                $refused[] = "{$id}: not a live sheet copy in the duplicate bucket any more — left alone";

                continue;
            }
            $checked[] = $id;
        }

        return ['remove' => $checked, 'keep' => $keep, 'refused' => $refused, 'decisions' => $decisions];
    }

    /**
     * Put a backup back: archived rows get their before-values, deleted rows are re-inserted in
     * the file's order (parent rows first). Refuses if any deleted id exists again (somebody
     * re-created it since). One transaction: all of it or none of it.
     *
     * @return array<string, int>
     */
    public function restore(string $file): array
    {
        $lines = $this->lines($file);
        $header = Coerce::arr(json_decode((string) $lines->current(), true, 512, JSON_THROW_ON_ERROR));
        if (($header['kind'] ?? null) !== 'bf-catalogue-clean' || Coerce::int($header['version'] ?? null) !== 2) {
            throw new RuntimeException('Not a bf:catalogue-clean backup (version 2).');
        }
        $deleteIds = Coerce::intList($header['delete_ids'] ?? []);
        $archiveSet = array_flip(Coerce::intList($header['archive_ids'] ?? []));
        $deleteSet = array_flip($deleteIds);

        if ($deleteIds !== [] && DB::table('catalog_products')->whereIn('id', $deleteIds)->exists()) {
            throw new RuntimeException('Some of the deleted product ids exist again; restore refused. Nothing was changed.');
        }

        $counts = [];
        /*
         * Re-inserting a product row writes its stock columns, which `StockWriteGuard` refuses
         * outside `InventoryService` — rightly, and it refused the first restore. A restore is the
         * one honest exception: it puts back the SAME rows, ledger movements included, so the
         * ledger and the columns agree again exactly as they did (`inventory:verify` checks it).
         * Scoped to this call, as the transform's run is.
         */
        StockWriteGuard::allow(function () use ($lines, $deleteSet, $archiveSet, &$counts): void {
            DB::transaction(function () use ($lines, $deleteSet, $archiveSet, &$counts): void {
                $batch = [];
                $batchTable = '';
                $lines->next();
                while ($lines->valid()) {
                    $entry = Coerce::arr(json_decode((string) $lines->current(), true, 512, JSON_THROW_ON_ERROR));
                    $lines->next();
                    $table = Coerce::str($entry['t'] ?? null);
                    $row = Coerce::arr($entry['r'] ?? null);
                    $id = Coerce::int($row['id'] ?? null);
                    if ($table === 'catalog_products' && isset($archiveSet[$id])) {
                        DB::table('catalog_products')->where('id', $id)->update([
                            'deleted_at' => $row['deleted_at'] ?? null,
                            'is_active' => $row['is_active'] ?? 1,
                            'updated_by' => $row['updated_by'] ?? null,
                            'updated_at' => $row['updated_at'] ?? null,
                        ]);
                        $counts['archived_restored'] = ($counts['archived_restored'] ?? 0) + 1;

                        continue;
                    }
                    if ($table === 'catalog_products' && ! isset($deleteSet[$id])) {
                        continue;
                    }
                    if ($table !== $batchTable || count($batch) >= 500) {
                        self::insertBatch($batchTable, $batch, $counts);
                        $batch = [];
                        $batchTable = $table;
                    }
                    $batch[] = $row;
                }
                self::insertBatch($batchTable, $batch, $counts);

                foreach (array_keys($deleteSet) as $productId) {
                    ActivityLog::record('catalog_products', $productId, ActivityLog::RESTORED,
                        after: ['reason' => 'bf:catalogue-clean --restore'],
                    );
                }
            });
        });

        $this->flushAll($deleteIds);

        return $counts;
    }

    /**
     * @param  list<array<mixed>>  $batch
     * @param  array<string, int>  $counts
     */
    private static function insertBatch(string $table, array $batch, array &$counts): void
    {
        if ($table === '' || $batch === []) {
            return;
        }
        DB::table($table)->insert($batch);
        $counts[$table] = ($counts[$table] ?? 0) + count($batch);
    }

    /**
     * The file's lines, one at a time, without their line ends.
     *
     * @return Generator<int, string>
     */
    private function lines(string $file): Generator
    {
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Cannot read {$file}");
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * product id => what blocks a hard delete (table names). With $only, just those ids.
     *
     * @param  list<int>|null  $only
     * @return array<int, list<string>>
     */
    public function historyOf(bool $openingBalanceIsNotHistory, ?array $only = null): array
    {
        $out = [];
        foreach (self::BLOCKING_TABLES as $table => $column) {
            $q = ($table === 'offers' || $table === 'wishlist_items')
                ? DB::connection('legacy')->table($table)
                : DB::table($table);
            $q->whereNotNull($column);
            if ($only !== null) {
                $q->whereIn($column, $only);
            }
            if ($table === 'inventory_movements' && $openingBalanceIsNotHistory) {
                $q->where('reason', '!=', 'import');
            }
            foreach ($q->distinct()->pluck($column) as $id) {
                $out[Coerce::int($id)][] = $table;
            }
        }
        foreach (self::BLOCKING_JSON as $table => $column) {
            $q = $table === 'offers' ? DB::connection('legacy')->table($table) : DB::table($table);
            foreach ($q->whereNotNull($column)->pluck($column) as $json) {
                foreach (Coerce::intList(json_decode(Coerce::str($json), true)) as $id) {
                    if ($only === null || in_array($id, $only, true)) {
                        $out[$id][] = $table;
                    }
                }
            }
        }

        return array_map(fn (array $t): array => array_values(array_unique($t)), $out);
    }

    /**
     * The guard on this class's own lists: every foreign key that points at `catalog_products`
     * must be a child or a blocker here. A table added tomorrow fails the command loudly instead
     * of being left holding ids of deleted products.
     */
    public function assertReferencesKnown(): void
    {
        $known = array_merge(self::CHILD_TABLES, array_keys(self::BLOCKING_TABLES));
        $rows = DB::select(
            'SELECT k.TABLE_NAME AS t FROM information_schema.REFERENTIAL_CONSTRAINTS rc
             JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
               AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME
             WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND rc.REFERENCED_TABLE_NAME = ?',
            ['catalog_products'],
        );
        foreach ($rows as $row) {
            if (! is_object($row)) {
                continue;
            }
            $table = Row::str(Row::cast($row), 't');
            if (! in_array($table, $known, true)) {
                throw new RuntimeException("[{$table}] references catalog_products and bf:catalogue-clean does not know it. Add it to CHILD_TABLES or BLOCKING_TABLES first.");
            }
        }
    }

    /** @param list<int> $productIds */
    private function flushAll(array $productIds): void
    {
        $cache = app(StorefrontCache::class);
        foreach (DB::table('storefronts')->pluck('id') as $sf) {
            $storefrontId = Coerce::int($sf);
            foreach ($productIds as $id) {
                $cache->forgetProduct($storefrontId, $id);
            }
            $cache->flush($storefrontId);
        }
    }

    /**
     * Each row of the table for these ids, WITHOUT its generated columns (`primary_guard` is
     * virtual — a backup that carried it could never be inserted back), handed over one by one.
     *
     * @param  list<int>  $ids
     * @param  callable(array<mixed>): void  $each  one row, keyed by column name
     * @return int how many rows were handed over
     */
    private function eachRow(string $table, string $column, array $ids, callable $each): int
    {
        $columns = [];
        foreach (DB::select(
            'SELECT COLUMN_NAME AS c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             AND EXTRA NOT LIKE ? ORDER BY ORDINAL_POSITION',
            [$table, '%GENERATED%'],
        ) as $c) {
            if (is_object($c)) {
                $columns[] = Row::str(Row::cast($c), 'c');
            }
        }

        $n = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table($table)->whereIn($column, $chunk)->orderBy($column)->get($columns) as $row) {
                $each(get_object_vars(Row::cast($row)));
                $n++;
            }
        }

        return $n;
    }
}
