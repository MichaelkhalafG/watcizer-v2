<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\PreSwitch;
use App\Domain\Import\CategoryMerger;
use App\Domain\Import\ImageCache;
use App\Domain\Import\ImportReport;
use App\Domain\Import\JoyroomSheet;
use App\Domain\Import\ProductImporter;
use App\Domain\Import\SourceRow;
use App\Domain\Import\WooExport;
use App\Support\Coerce;
use App\Support\DeadlockRetry;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan import:catalogue` — the supplier-file importer (wave 4D).
 *
 * ── The PreSwitch exemption lives HERE, on purpose ───────────────────────────────────────────
 *
 * Creating a product is refused before the write-switch, and the importer's whole job is creating
 * products. The developer opened the door for this work (2026-09-14) with two conditions, and both
 * are visible in this file rather than described somewhere else:
 *
 *   • **Explicit** — `--allow-preswitch`. Without the flag the command refuses to start and says
 *     what to pass. Nobody imports 8 000 products by accident.
 *   • **Reversible** — {@see PreSwitch::allowing()} is a SCOPE. It opens the four creations this
 *     command needs, for this process, and closes them in a `finally` even if the import throws.
 *     There is no config key, no `.env` entry and no permanent `blocked => false` anywhere.
 *
 * And the thing the flag really means is printed before the first row is written: **everything
 * this command creates is deleted by the next `core:drop-clean` → `migrate` → `core:transform`.**
 * That is expected. It is a rehearsal — the point is to exercise creation now, so that switch
 * night holds no surprises.
 */
final class ImportCatalogueCommand extends Command
{
    protected $signature = 'import:catalogue
        {file : the source file — the WooCommerce CSV export, or the extracted Joyroom CSV}
        {--source=woo : woo|joyroom}
        {--storefront=* : storefront ids to place the products on (default: 2 for woo, 1+2 for joyroom)}
        {--allow-preswitch : REQUIRED before the write-switch — creates rows the next rebuild deletes}
        {--images=cover : cover|none — how many images to fetch per product}
        {--allow-compat-variants : import variants onto the compat storefront too (browsable, NOT buyable there until the switch)}
        {--limit=0 : stop after this many products (0 = the whole file)}
        {--refresh-images : re-download every image, ignoring what earlier runs already stored}
        {--shard= : i/n — import only every n-th row, for running n workers in parallel}
        {--dry-run : read and classify the file, write nothing}';

    protected $description = 'Import a supplier catalogue file through the dashboard writers (products, categories, stock, cover images)';

    /** The creations an import performs, named one by one so the exemption is not a blanket. */
    private const EXEMPTIONS = [
        'product',                              // catalog_products + translations + search
        'variant',                              // catalog_product_variants (shoe sizes)
        'category',                             // storefront_categories — the merged tree
        'lookup',                               // catalog_brands (Generic), catalog_sizes, catalog_materials
        PreSwitch::SECONDARY_TREE_EDIT,         // …and Brand Fashion's tree is a mirror until the switch
    ];

    public function handle(ProductImporter $importer, CategoryMerger $merger): int
    {
        $file = Coerce::str($this->argument('file'));
        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }

        $source = Coerce::str($this->option('source'), 'woo');
        if (! in_array($source, ['woo', 'joyroom'], true)) {
            $this->error('--source must be woo or joyroom.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $withImages = Coerce::str($this->option('images'), 'cover') === 'cover';
        $allowCompatVariants = (bool) $this->option('allow-compat-variants');
        $limit = Coerce::int($this->option('limit'));

        // Before the first row: the cache is a singleton, so turning reuse off here reaches the
        // instance `CoverImages` is already holding.
        if ((bool) $this->option('refresh-images')) {
            app(ImageCache::class)->refreshEverything();
        }
        $storefronts = $this->storefronts($source);
        [$shardIndex, $shardCount] = $this->shard();

        if (! $dryRun && ! PreSwitch::completed() && ! (bool) $this->option('allow-preswitch')) {
            $this->error('Refusing to import before the write-switch.');
            $this->line('');
            $this->line('  Creating a product, a category or a lookup row is blocked until the switch, because the');
            $this->line('  next rebuild deletes it (PreSwitch). This command can open that door for its own run,');
            $this->line('  but only when you say so:');
            $this->line('');
            $this->line('      php artisan import:catalogue "'.$file.'" --allow-preswitch');
            $this->line('');
            $this->line('  Everything it then creates is DELETED by the next core:drop-clean + core:transform.');

            return self::FAILURE;
        }

        $report = new ImportReport;
        $started = microtime(true);

        $this->line('');
        $this->info('import:catalogue — '.$source.'  ←  '.basename($file));
        $this->line('  storefronts : '.implode(', ', $storefronts));
        if ($shardCount > 1) {
            $this->line('  shard       : '.($shardIndex + 1).' of '.$shardCount);
        }
        $this->line('  images      : '.($withImages ? 'cover only' : 'none'));
        if ($allowCompatVariants) {
            $this->warn('  COMPAT VARIANTS ALLOWED: a variant product on Watchizer is browsable on the legacy');
            $this->warn('  frontend and NOT buyable there (both doors answer 422) until the write-switch.');
        }
        $this->line('  mode        : '.($dryRun ? 'DRY RUN (nothing is written)' : 'WRITING'));

        if (! $dryRun && ! PreSwitch::completed()) {
            $this->warn('  PRE-SWITCH EXEMPTION IS OPEN for this run: '.implode(', ', self::EXEMPTIONS));
            $this->warn('  Everything created here is DESTROYED by the next rebuild. That is expected.');
        }
        $this->line('');

        $rows = $source === 'woo' ? new WooExport($file) : new JoyroomSheet($file);

        $run = function () use ($rows, $file, $source, $importer, $merger, $storefronts, $report, $withImages, $allowCompatVariants, $limit, $dryRun, $shardIndex, $shardCount): void {
            if (! $dryRun) {
                $importer->ensureMaterials($report);
            }

            /*
             * SHARDED runs prepare the tree first, and only sharded runs need to.
             *
             * Two workers reaching an unmapped node at the same moment would both create it, and
             * the second would get the slug `crossbody-bag-2` — two sections with one name, in a
             * shop, for ever. Preparing means reading the file once with no writes, collecting the
             * node paths the rows ACTUALLY use, and creating those before any worker starts. The
             * property that "nothing is created that no product uses" is preserved exactly: the set
             * comes from the file, not from the map.
             */
            if ($shardCount > 1 && $shardIndex === 0 && ! $dryRun) {
                $this->prepareTree($file, $source, $storefronts, $merger, $report);
            }

            $seen = 0;
            foreach ($rows->rows() as $row) {
                $seen++;
                if ($limit > 0 && $seen > $limit) {
                    break;
                }
                // Every n-th row, so n workers cover the file exactly once between them.
                if ($shardCount > 1 && ($seen - 1) % $shardCount !== $shardIndex) {
                    continue;
                }

                if ($dryRun) {
                    $this->classify($row, $report);

                    continue;
                }

                try {
                    /*
                     * A DEADLOCK is retried, not reported. Sharded runs mean several importers
                     * writing the catalogue at once, and they lock the same rows in the same order
                     * — the first parallel run lost exactly one product ("Gaming Headset JR-HG2")
                     * to MariaDB 1213. `DeadlockRetry` is the policy wave 3.5 built for stock and
                     * the payment callbacks already share, so this is the third caller of one
                     * answer rather than a second answer.
                     */
                    DeadlockRetry::run(fn () => $importer->import($row, $storefronts, $report, $withImages, $allowCompatVariants));
                } catch (Throwable $e) {
                    /*
                     * One bad row must not end a run of 8 000 — and must not leave a HALF product
                     * behind either. `discard()` removes what this row created (refusing if it has
                     * any history), so the next run imports it properly instead of skipping it as
                     * "already imported". Both facts go in the report: nothing is dropped in
                     * silence, and the operator can see that a re-run will pick it up.
                     */
                    $undone = $importer->discard($row->ref);
                    $report->row($row->ref, $row->titleEn, 'failed',
                        mb_substr($e->getMessage(), 0, 180).($undone ? ' [partial row removed; re-run to import it]' : ''));
                    $report->count('failed');
                    if ($undone) {
                        $report->count('partial_rows_removed');
                    }
                }

                if ($seen % 250 === 0) {
                    $this->line(sprintf('  … %5d rows | imported %5d | flagged %5d',
                        $seen, $report->get('imported'), count($report->flagged())));
                }
            }

            foreach ($merger->created() as $path) {
                $report->note('category_created', $path);
            }
        };

        if ($dryRun) {
            $run();
        } else {
            // THE exemption. Scoped, named, and closed in a finally by PreSwitch itself.
            PreSwitch::allowing(self::EXEMPTIONS, $run);
        }

        foreach ($rows->counts() as $key => $value) {
            $report->count('source_'.$key, $value);
        }
        // The names we could not match — the answer to "which brands should we add?".
        $importer->recordUnmatchedBrands($report);

        $this->summarise($report, $source, $file, $started, $dryRun);

        return self::SUCCESS;
    }

    /** A dry run classifies without writing — the same decisions, none of the consequences. */
    private function classify(SourceRow $row, ImportReport $report): void
    {
        $report->count('would_import');
        $missing = [];
        if ($row->sku === null) {
            $missing[] = ImportReport::MISSING_SKU;
        }
        if ($row->imageUrl === null) {
            $missing[] = ImportReport::MISSING_IMAGE;
        }
        if ($row->categoryPaths === []) {
            $missing[] = ImportReport::MISSING_CATEGORY;
        }
        if (! $row->hasPrice()) {
            $missing[] = ImportReport::MISSING_PRICE;
        }
        if ($missing !== []) {
            $report->row($row->ref, $row->titleEn, 'missing_data', implode(' ', $missing));
        }
    }

    /**
     * `--shard=i/n` → [i-1, n], or [0, 1] when the option is absent.
     *
     * @return array{0: int, 1: int}
     */
    private function shard(): array
    {
        $raw = trim(Coerce::str($this->option('shard')));
        if ($raw === '') {
            return [0, 1];
        }

        $parts = explode('/', $raw);
        $index = Coerce::int($parts[0]);
        $count = Coerce::int($parts[1] ?? null);

        if ($count < 1 || $index < 1 || $index > $count) {
            return [0, 1];                      // an unusable shard is one worker, never a silent skip
        }

        return [$index - 1, $count];
    }

    /**
     * Create every category node the FILE needs, before any worker writes a product.
     *
     * @param  list<int>  $storefronts
     */
    private function prepareTree(string $file, string $source, array $storefronts, CategoryMerger $merger, ImportReport $report): void
    {
        $reader = $source === 'woo' ? new WooExport($file) : new JoyroomSheet($file);

        $paths = [];
        foreach ($reader->rows() as $row) {
            foreach ($row->categoryPaths as $path) {
                $paths[$path] = true;
            }
        }

        // Shallow paths first, so a parent is never created by the recursion of its own child —
        // the order is the merger's own, but doing it here keeps the log readable.
        $ordered = array_keys($paths);
        usort($ordered, static fn (string $a, string $b): int => substr_count($a, '/') <=> substr_count($b, '/'));

        foreach ($storefronts as $storefrontId) {
            foreach ($ordered as $path) {
                $merger->node($storefrontId, $path);
            }
        }

        foreach ($merger->created() as $path) {
            $report->note('category_created', $path);
            $report->count('categories_created');
        }

        $this->line('  tree prepared: '.count($ordered).' path(s) per storefront, '
            .count($merger->created()).' created');
    }

    /**
     * @return list<int>
     */
    private function storefronts(string $source): array
    {
        $given = Coerce::intList($this->option('storefront'));
        if ($given !== []) {
            return $given;
        }

        // Brand Fashion's own catalogue goes to Brand Fashion. Joyroom is a new ELECTRONICS
        // section on BOTH storefronts (developer decision 7).
        return $source === 'woo' ? [2] : [1, 2];
    }

    private function summarise(ImportReport $report, string $source, string $file, float $started, bool $dryRun): void
    {
        $directory = storage_path('import/'.now()->format('Ymd-His').'-'.$source);
        $paths = $report->write($directory, 'Import — '.$source.' — '.basename($file));

        $this->line('');
        $this->info(sprintf('%s in %.1fs', $dryRun ? 'Dry run complete' : 'Import complete', microtime(true) - $started));

        $counts = $report->counts();
        ksort($counts);
        $body = [];
        foreach ($counts as $key => $value) {
            $body[] = [$key, (string) $value];
        }
        $this->table(['what', 'how many'], $body);

        $this->line('  report: '.$paths['md']);
        $this->line('  rows  : '.$paths['csv'].'  ('.count($report->flagged()).' rows need a person)');

        if (! $dryRun) {
            $this->line('');
            $this->warn('  Remember: everything this run created is deleted by the next core:drop-clean + core:transform.');
        }
    }
}
