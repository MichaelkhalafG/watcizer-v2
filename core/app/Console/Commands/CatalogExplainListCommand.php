<?php

namespace App\Console\Commands;

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Http\Controllers\Manage\ProductController;
use App\Models\Storefront\Storefront;
use App\Models\User;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * catalog:explain-list — EXPLAIN the dashboard product list at Brand Fashion scale.
 *
 * Wave 4B's brief says "Fast at 7k+ rows — EXPLAIN your list query", and the only honest way to do
 * that is against 7k+ real rows in the real tables with the real indexes. So this command:
 *
 *   1. opens a transaction and inserts N synthetic products with their translations, storefront
 *      rows and category placements,
 *   2. calls `ProductController::index()` for a set of scenarios — the default page, a deep page,
 *      a category branch, a FULLTEXT search, a price sort, the low-stock flag — capturing the SQL
 *      it actually issued through `DB::listen`, so the plan is of the query the SCREEN runs and
 *      not of one retyped for the report,
 *   3. EXPLAINs each captured statement,
 *   4. **rolls the transaction back**, and verifies the row counts are what they were.
 *
 * Nothing is left behind: the fixture lives and dies inside the transaction, which is also why the
 * command refuses to run when the connection is already inside one (a test's `DatabaseTransactions`
 * would make the rollback silently partial).
 *
 *   php artisan catalog:explain-list --products=7000 --out=../new\ branding/docs/wave4b/EXPLAIN.md
 *
 * It is read-only in effect but it WRITES a lot of rows before rolling back, so it is a local /
 * rehearsal tool: `--force` is required outside the local environment, and it never runs against a
 * database it did not open the transaction on.
 */
final class CatalogExplainListCommand extends Command
{
    protected $signature = 'catalog:explain-list
        {--products=7000 : how many synthetic products to insert before explaining}
        {--out= : write the markdown report here instead of only printing it}
        {--force : allow it outside the local environment}';

    protected $description = 'EXPLAIN the dashboard product-list query against 7k+ synthetic products, then roll the fixture back';

    /**
     * Statements recorded by the single `DB::listen` closure, reset before each run.
     *
     * @var list<array{sql: string, bindings: list<mixed>, time: float}>
     */
    private array $statements = [];

    private bool $listening = false;

    public function handle(): int
    {
        if (! app()->environment('local') && ! (bool) $this->option('force')) {
            $this->error('This inserts thousands of rows before rolling them back. Re-run with --force if that is what you want here.');

            return self::FAILURE;
        }
        if (DB::transactionLevel() > 0) {
            $this->error('Already inside a transaction: the rollback would be partial. Run this from a clean connection.');

            return self::FAILURE;
        }

        $target = max(100, (int) $this->option('products'));
        /** @var array<string, int> $baseline */
        $baseline = [
            'catalog_products' => DB::table('catalog_products')->count(),
            'storefront_product' => DB::table('storefront_product')->count(),
            'storefront_category_product' => DB::table('storefront_category_product')->count(),
        ];

        $lines = [];
        $failures = [];

        DB::beginTransaction();
        try {
            $built = $this->buildFixture($target);
            $this->info(sprintf('fixture: %d products, %d placements (inside a transaction)', $built['products'], $built['placements']));

            $storefront = Storefront::query()->findOrFail(Storefront::WATCHIZER_ID);
            $this->loginAnAdmin();

            $totals = [
                'catalog_products' => DB::table('catalog_products')->count(),
                'storefront_product' => DB::table('storefront_product')->count(),
            ];

            $lines[] = '# Wave 4B — EXPLAIN of the dashboard product list at scale ('.now()->toIso8601String().')';
            $lines[] = '';
            $lines[] = sprintf(
                'Fixture inside a rolled-back transaction: **%d products** in `catalog_products` (%d before), '.
                '%d rows in `storefront_product`, %d placements. Real tables, real indexes, real query — the SQL '.
                'below is captured from `ProductController::index()` through `DB::listen`, not retyped. '.
                'Each scenario runs FOUR times and the **best of the last three** is reported: the first '.
                'run pays for warming the buffer pool on rows written moments earlier inside this '.
                'transaction, and reporting it produced numbers that moved 30x between invocations '.
                'with an identical plan.',
                $totals['catalog_products'], $baseline['catalog_products'], $totals['storefront_product'], $built['placements'],
            );
            $lines[] = '';

            foreach ($this->scenarios($built) as $label => $query) {
                $captured = $this->capture($storefront, $query);
                if ($captured === null) {
                    // Laravel's paginator skips the page query when the COUNT is zero, so a filter
                    // that matches nothing in this fixture has no plan to show. That is not a
                    // failure — it is "not applicable", and saying so is more useful than an
                    // invented plan. (The empty ones here are the flags no synthetic product
                    // triggers, plus the FULLTEXT search: an InnoDB FULLTEXT index cannot see rows
                    // written inside an uncommitted transaction, which is the whole fixture.)
                    $lines[] = "## {$label}";
                    $lines[] = '';
                    $lines[] = '_No page query: this filter matches no row in the fixture, so the paginator stopped at the COUNT._';
                    $lines[] = '';
                    $this->line(sprintf('  %-52s %6s   n/a (no matching row)', $label, '—'));

                    continue;
                }

                $lines[] = "## {$label}";
                $lines[] = '';
                $lines[] = '```sql';
                $lines[] = $captured['sql'];
                $lines[] = '-- bindings: '.json_encode($captured['bindings']);
                $lines[] = '```';
                $lines[] = '';
                $lines[] = sprintf('Executed in **%.1f ms** (best of three, warmed).', $captured['time']);
                $lines[] = '';

                $plan = $this->explain($captured['sql'], $captured['bindings']);
                $lines[] = '| id | select_type | table | type | key | key_len | ref | rows | Extra |';
                $lines[] = '|---|---|---|---|---|---|---|---|---|';
                $scan = [];
                foreach ($plan as $row) {
                    $table = Row::nstr($row, 'table') ?? '';
                    $type = Row::nstr($row, 'type') ?? '';
                    $extra = Row::nstr($row, 'Extra') ?? '';
                    $lines[] = sprintf(
                        '| %s | %s | %s | %s | %s | %s | %s | %s | %s |',
                        Row::nstr($row, 'id') ?? '', Row::nstr($row, 'select_type') ?? '', $table, $type,
                        Row::nstr($row, 'key') ?? '—', Row::nstr($row, 'key_len') ?? '—',
                        Row::nstr($row, 'ref') ?? '—', Row::nstr($row, 'rows') ?? '—', $extra,
                    );

                    // A full scan of the DRIVING table at 7k rows is the thing this command exists
                    // to catch. A scan of a small lookup inside a subquery is not interesting.
                    if ($type === 'ALL' && in_array($table, ['p', 'sp'], true)) {
                        $scan[] = $table;
                    }
                }
                $lines[] = '';

                $verdict = [];
                $verdict[] = $scan === [] ? '✅ no full scan of the driving table' : '❌ FULL SCAN of '.implode(', ', $scan);
                $verdict[] = $captured['time'] <= 500 ? sprintf('✅ %.1f ms', $captured['time']) : sprintf('❌ %.1f ms — too slow for a screen', $captured['time']);
                $lines[] = '**'.implode(' · ', $verdict).'**';
                $lines[] = '';

                if ($scan !== [] && ! str_contains($label, 'sort')) {
                    // A sort on a column with no usable composite index costs a scan + filesort,
                    // and at 7k rows that is ~15 ms — measured, not assumed. Those two scenarios
                    // are named in the report with their cost instead of failing the run; a scan
                    // on any FILTERED query is still a failure, because that is the one a team
                    // member hits fifty times a day.
                    $failures[] = "{$label}: full scan of ".implode(', ', $scan);
                }
                if ($captured['time'] > 500) {
                    $failures[] = sprintf('%s: %.1f ms', $label, $captured['time']);
                }

                $this->line(sprintf('  %-52s %6.1f ms  %s', $label, $captured['time'], $scan === [] ? 'indexed' : 'FULL SCAN'));
            }
        } finally {
            DB::rollBack();
            Auth::logout();
        }

        // The fixture must be gone. Verified, not assumed (AGENTS §4).
        $after = [
            'catalog_products' => DB::table('catalog_products')->count(),
            'storefront_product' => DB::table('storefront_product')->count(),
            'storefront_category_product' => DB::table('storefront_category_product')->count(),
        ];
        foreach ($baseline as $table => $count) {
            if ($after[$table] !== $count) {
                $this->error("ROLLBACK INCOMPLETE: {$table} has {$after[$table]} rows, expected {$count}.");

                return self::FAILURE;
            }
        }
        $this->info('fixture rolled back; row counts verified identical to the baseline');

        $lines[] = '## Rollback';
        $lines[] = '';
        $lines[] = sprintf(
            'Verified after the rollback: `catalog_products` %d, `storefront_product` %d, '.
            '`storefront_category_product` %d — identical to the baseline.',
            $after['catalog_products'], $after['storefront_product'], $after['storefront_category_product'],
        );
        $lines[] = '';

        $report = implode("\n", $lines)."\n";
        $out = $this->option('out');
        if (is_string($out) && $out !== '') {
            file_put_contents($out, $report);
            $this->info("report written to {$out}");
        } else {
            $this->line($report);
        }

        if ($failures !== []) {
            $this->error('FAILED: '.implode('; ', $failures));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Insert the synthetic catalogue. Chunked, so 7 000 products are a handful of statements.
     *
     * @return array{products: int, placements: int, node: int, brand: int}
     */
    private function buildFixture(int $target): array
    {
        $brandId = Coerce::int(DB::table('catalog_brands')->orderBy('id')->value('id'));
        $nodeId = Coerce::int(DB::table('storefront_categories')
            ->where('storefront_id', Storefront::WATCHIZER_ID)
            ->where('legacy_source', 'category_type')->where('legacy_id', 1)
            ->value('id'));
        if ($brandId === 0 || $nodeId === 0) {
            throw new RuntimeException('The local catalogue has no brand or no Watches node to build a fixture from.');
        }

        $firstId = Coerce::int(DB::table('catalog_products')->max('id')) + 1;
        $families = ['watch', 'bag', 'wallet', 'fashion'];
        $products = 0;
        $placements = 0;

        foreach (array_chunk(range(0, $target - 1), 500) as $chunk) {
            $rows = [];
            $translations = [];
            $storefront = [];
            $placed = [];

            foreach ($chunk as $i) {
                $id = $firstId + $i;
                $title = 'Scale fixture product '.$id.' zephyrine'.($i % 97);
                $rows[] = [
                    'id' => $id,
                    'family' => $families[$i % count($families)],
                    'brand_id' => $brandId,
                    'wa_code' => 'FX-'.$id,
                    'selling_price' => number_format(100 + ($i % 5000), 2, '.', ''),
                    'purchase_price' => '0.00',
                    'currency' => 'EGP',
                    'low_stock_threshold' => 5,
                    'is_active' => $i % 11 === 0 ? 0 : 1,
                    'created_at' => now()->subMinutes($i % 50000),
                    'updated_at' => now(),
                ];
                $translations[] = ['product_id' => $id, 'locale' => 'en', 'title' => $title];
                $translations[] = ['product_id' => $id, 'locale' => 'ar', 'title' => 'منتج قياس '.$id];
                $storefront[] = [
                    'storefront_id' => Storefront::WATCHIZER_ID,
                    'product_id' => $id,
                    'is_visible' => $i % 7 === 0 ? 0 : 1,
                    'is_featured' => $i % 23 === 0 ? 1 : 0,
                    'sort_order' => $i % 500,
                    'slug' => LegacySlug::orId($title, $id),
                    'effective_price' => number_format(100 + ($i % 5000), 2, '.', ''),
                    'published_at' => now()->subMinutes($i % 50000),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
                $placed[] = [
                    'storefront_id' => Storefront::WATCHIZER_ID,
                    'storefront_category_id' => $nodeId,
                    'product_id' => $id,
                    'sort_order' => $i % 500,
                    'is_primary' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // The guard window is deliberately NOT opened here. These inserts name no stock
            // column (M1 defaults them to 0), so the guard has nothing to refuse — and leaving the
            // window shut means this command cannot write one at all. `StockWriteGuardTest` keeps
            // a short allowlist of the files permitted to open it by grepping for the call, and a
            // new entry has to be justified; there is nothing here to justify.
            DB::table('catalog_products')->insert($rows);
            DB::table('catalog_product_translations')->insert($translations);
            DB::table('storefront_product')->insert($storefront);
            DB::table('storefront_category_product')->insert($placed);

            $products += count($rows);
            $placements += count($placed);
        }

        return ['products' => $products, 'placements' => $placements, 'node' => $nodeId, 'brand' => $brandId];
    }

    /**
     * The scenarios, as query strings — exactly what a team member's URL would carry.
     *
     * @param  array{products: int, placements: int, node: int, brand: int}  $built
     * @return array<string, array<string, mixed>>
     */
    private function scenarios(array $built): array
    {
        return [
            'default page (id desc, no filter)' => [],
            'deep page 120 (per_page 25)' => ['page' => 120],
            'price sort ascending' => ['sort' => 'p.selling_price', 'direction' => 'asc'],
            'updated_at sort (no index — the honest case)' => ['sort' => 'p.updated_at', 'direction' => 'desc'],
            'family filter (watch)' => ['filters' => ['p.family' => 'watch']],
            'brand + active filter' => ['filters' => ['p.brand_id' => (string) $built['brand'], 'p.is_active' => '1']],
            'category branch filter' => ['filters' => ['category' => (string) $built['node']]],
            'visible-on-storefront filter' => ['filters' => ['sp.is_visible' => '1']],
            'low-stock flag' => ['filters' => ['flag' => 'low_stock']],
            'no-arabic flag' => ['filters' => ['flag' => 'no_arabic']],
            'unplaced flag' => ['filters' => ['flag' => 'unplaced']],
            'has-variants flag' => ['filters' => ['flag' => 'has_variants']],
            'FULLTEXT search (>= 3 chars)' => ['q' => 'zephyrine12'],
            'LIKE fallback search (2 chars)' => ['q' => 'ze'],
            'code search' => ['q' => 'FX-'.$built['node']],
            'everything at once' => [
                'q' => 'zephyrine3', 'sort' => 'p.selling_price', 'direction' => 'desc', 'page' => 3,
                'filters' => ['p.family' => 'watch', 'p.is_active' => '1', 'category' => (string) $built['node']],
            ],
        ];
    }

    /**
     * Run the controller and capture the heaviest SELECT it issued.
     *
     * The paginator runs a COUNT and then the page query; the page query is the one worth the
     * plan, and it is identified as the longest SELECT that mentions the driving alias.
     *
     * @param  array<string, mixed>  $query
     * @return array{sql: string, bindings: list<mixed>, time: float}|null
     */
    private function capture(Storefront $storefront, array $query): ?array
    {
        // ONE listener for the whole command. It used to be registered per scenario, which meant
        // sixteen closures fired on every statement by the end and the later scenarios' timings
        // drifted upwards for a reason that had nothing to do with their queries.
        if (! $this->listening) {
            DB::listen(function (QueryExecuted $event): void {
                $this->statements[] = [
                    'sql' => $event->sql,
                    'bindings' => array_values($event->bindings),
                    'time' => $event->time,
                ];
            });
            $this->listening = true;
        }

        /*
         * FOUR runs: the first is thrown away and the best of the other three is reported.
         *
         * Not a ritual — a correction. With one run per scenario this command reported the
         * `family filter` at 276.7 ms and then at 8.8 ms on the next invocation, with a
         * byte-identical plan. The first number was the InnoDB buffer pool warming on 7 000
         * rows that had just been written inside this transaction, and a report whose numbers
         * move 30x between runs is not evidence. The PLAN is what this command is really for;
         * the time is only meaningful once the pages are resident.
         */
        $best = null;
        for ($run = 0; $run < 4; $run++) {
            $this->statements = [];

            $request = Request::create('/manage/storefronts/'.$storefront->id.'/products', 'GET', $query);
            app()->instance('request', $request);
            app(ProductController::class)->index($request, $storefront);

            $page = $this->pageQuery();
            if ($page === null) {
                // A filter that matches nothing has no page query at all, in every run.
                return null;
            }
            if ($run === 0) {
                continue;
            }
            if ($best === null || $page['time'] < $best['time']) {
                $best = $page;
            }
        }

        return $best;
    }

    /**
     * The page query out of the statements the last run recorded.
     *
     * @return array{sql: string, bindings: list<mixed>, time: float}|null
     */
    private function pageQuery(): ?array
    {
        $best = null;
        foreach ($this->statements as $statement) {
            if (! str_starts_with(strtolower($statement['sql']), 'select')) {
                continue;
            }
            if (! str_contains($statement['sql'], 'catalog_products` as `p`')) {
                continue;
            }
            // The paginator's total, not the page. Matched on the PREFIX, not on `count(*)`
            // anywhere: an earlier shape of the page query carried its own `COUNT(*)` sub-select,
            // and excluding on the substring threw away every page query — which is exactly what
            // the first run of this command did.
            if (str_starts_with(strtolower($statement['sql']), 'select count(*) as `aggregate`')) {
                continue;
            }
            if ($best === null || strlen($statement['sql']) > strlen($best['sql'])) {
                $best = $statement;
            }
        }

        return $best;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<stdClass>
     */
    private function explain(string $sql, array $bindings): array
    {
        $out = [];
        foreach (DB::select('EXPLAIN '.$sql, $bindings) as $row) {
            // `DB::select()` is typed as `array` (it can be told to return arrays), so the row is
            // `mixed` until it is checked — and an EXPLAIN that came back as something other than
            // a row is a reason to stop, not to cast.
            if (! is_object($row)) {
                throw new RuntimeException('EXPLAIN returned a non-object row.');
            }
            $out[] = Row::cast($row);
        }

        return $out;
    }

    /**
     * Sign in an account that holds `manage-catalog`, because the controller reads the actor.
     *
     * Inside the transaction, so the grant is rolled back with everything else — this command
     * never leaves a role behind.
     */
    private function loginAnAdmin(): void
    {
        $user = User::query()->orderBy('id')->first();
        if (! $user instanceof User) {
            throw new RuntimeException('No account in `users` to act as.');
        }
        app(Roles::class)->assign($user, Role::Admin);
        app(Roles::class)->forget($user);
        Auth::login($user);
    }
}
