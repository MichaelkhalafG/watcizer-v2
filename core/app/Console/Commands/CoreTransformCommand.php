<?php

namespace App\Console\Commands;

use App\Transform\Audit\AuditReport;
use App\Transform\Audit\AuditRunner;
use App\Transform\Config;
use App\Transform\IdMap;
use App\Transform\LegacySource;
use App\Transform\Reconciliation;
use App\Transform\StepResult;
use App\Transform\Steps;
use App\Transform\Steps\Step;
use App\Transform\TransformContext;
use App\Transform\TransformOptions;
use App\Transform\Writer;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * core:transform — the one-way, additive, idempotent legacy → clean-core transform
 * (CLEAN_CORE_STUDY §2.9, the 21 steps of §2.9.2, the audit of §2.9.5).
 *
 *   --audit         pre-flight only: run A-01…A-25 (+X-codes), write the report, stop
 *   --dry-run       full pass inside ONE transaction that is rolled back at the end;
 *                   the legacy checksum is proven identical before/after
 *   --only=6,7      run a subset of steps (dependencies are the caller's problem)
 *   --images-root   override config transform.images_root for A-11/A-12
 *   --output        override the run directory (default storage/transform/<timestamp>)
 *   --force         skip the production confirmation prompt (ConfirmableTrait)
 *
 * Reads legacy ONLY through App\Transform\LegacySource (whitelist + database-level
 * read-only session); writes ONLY clean tables through App\Transform\Writer (explicit
 * column lists, upsert by preserved/natural key). Never deletes anything.
 */
final class CoreTransformCommand extends Command
{
    use ConfirmableTrait;

    protected $signature = 'core:transform
        {--dry-run : run everything, then roll it all back}
        {--audit : run the dirty-data audit only}
        {--only= : comma-separated step numbers}
        {--images-root= : Uploads_Images root for A-11/A-12}
        {--output= : run directory (default storage/transform/<timestamp>)}
        {--chunk= : rows per chunk (default config transform.chunk)}
        {--force : run without the production confirmation}';

    protected $description = 'Legacy → clean-core transform (21 steps) with pre-flight audit and counts reconciliation';

    /** @return list<Step> */
    public static function steps(): array
    {
        return [
            new Steps\Step01Brands, new Steps\Step02Lookups, new Steps\Step03Units, new Steps\Step04Colors,
            new Steps\Step05Sizes, new Steps\Step06Products, new Steps\Step07ProductTranslations,
            new Steps\Step08WatchSpecs, new Steps\Step09CoverImages, new Steps\Step10GalleryImages,
            new Steps\Step11FeatureGenderPivots, new Steps\Step12ColorPivots, new Steps\Step13Variants,
            new Steps\Step14Storefront, new Steps\Step15CategoryTypes, new Steps\Step16SubTypes,
            new Steps\Step17LegacyCategories, new Steps\Step18StorefrontProduct, new Steps\Step19Placements,
            new Steps\Step20InventoryBaseline, new Steps\Step21SearchIndex,
        ];
    }

    public function handle(): int
    {
        if (! $this->confirmToProceed('The transform writes clean tables in the PRODUCTION database.')) {
            return self::FAILURE;
        }

        $startedAt = microtime(true);
        $config = Config::stringKeyed(config('transform'));
        $options = $this->buildOptions($config);

        if (! is_dir($options->outputDir) && ! mkdir($options->outputDir, 0775, true) && ! is_dir($options->outputDir)) {
            $this->error("Cannot create output directory {$options->outputDir}");

            return self::FAILURE;
        }

        $db = DB::connection();
        $legacy = new LegacySource;
        $this->banner($db->getConfig('host'), $db->getDatabaseName(), $legacy, $options);

        if (! Schema::hasTable(IdMap::TABLE)) {
            $this->error('transform_id_map is missing — run `php artisan migrate` (M1b) first.');

            return self::FAILURE;
        }

        // Database-level read-only for the legacy session (skipped only when a harness already
        // holds a transaction on it — MariaDB refuses to change tx characteristics mid-transaction).
        if ($legacy->connection()->transactionLevel() === 0) {
            $legacy->enforceReadOnly();
            $this->info('legacy session: tx_read_only = 1 (database refuses every write on this connection)');
        } else {
            $this->warn('legacy session: already inside a transaction (test harness) — database-level read-only NOT applied; the table whitelist still holds.');
        }

        // ── pre-flight audit ──────────────────────────────────────────────────────
        $auditStart = microtime(true);
        $report = (new AuditRunner($legacy, $options->imagesRoot, $config))->run();
        $auditMs = (microtime(true) - $auditStart) * 1000;
        file_put_contents($options->outputDir.'/audit.csv', $report->toCsv());
        file_put_contents($options->outputDir.'/audit.md', $report->toMarkdown('core:transform audit — '.date('Y-m-d H:i:s')));
        $this->printAudit($report, $auditMs);

        if ($options->auditOnly) {
            $this->writeSummary($options, $startedAt, $report, [], null, null, $auditMs, 'audit');

            return $report->isBlocked() ? self::FAILURE : self::SUCCESS;
        }
        if ($report->isBlocked()) {
            $this->error('Audit is BLOCKING — transform not started. Fix the legacy data and re-run.');
            $this->writeSummary($options, $startedAt, $report, [], null, null, $auditMs, 'blocked');

            return self::FAILURE;
        }

        // ── transform ─────────────────────────────────────────────────────────────
        $legacyBefore = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];
        $writer = new Writer($db, $options->chunk);
        $idMap = new IdMap($db, $writer);
        $ctx = new TransformContext($legacy, $db, $writer, $idMap, $options, $config);

        /** @var list<StepResult> */
        $results = [];
        $reconciliation = null;
        $exit = self::SUCCESS;
        $autoIncrement = null;

        if ($options->dryRun) {
            $db->beginTransaction();
        }

        try {
            $this->line(sprintf('id map: %d pairs loaded from transform_id_map', $idMap->load()));
            $this->newLine();
            $this->line(str_pad('step', 6).str_pad('name', 24).str_pad('read', 8).str_pad('ins', 8).str_pad('upd', 8).str_pad('same', 8).'ms');

            foreach (self::steps() as $step) {
                if (! $options->runsStep($step->number())) {
                    continue;
                }
                $result = new StepResult($step->number(), $step->name(), $step->target());
                $t0 = microtime(true);
                $db->transaction(function () use ($step, $ctx, $result): void {
                    $step->run($ctx, $result);
                });
                $result->durationMs = (microtime(true) - $t0) * 1000;
                $results[] = $result;
                $this->line(sprintf('%-6s%-24s%-8d%-8d%-8d%-8d%.0f', $step->number(), $step->name(), $result->read, $result->writes->inserted, $result->writes->updated, $result->writes->unchanged, $result->durationMs));
                foreach ($result->notes as $note) {
                    $this->line("      · $note");
                }
                if ($step->number() === 6) {
                    $autoIncrement = ($result->counters['max_id'] ?? 0) + 1;
                }
            }

            $db->transaction(function () use ($idMap, &$results): void {
                $flush = $idMap->flush();
                $r = new StepResult(0, 'id_map_flush', IdMap::TABLE);
                $r->writes = $flush;
                $results[] = $r;
            });

            $this->newLine();
            $reconciliation = (new Reconciliation($ctx))->run();
            $this->printReconciliation($reconciliation);
            if (! $reconciliation->passed()) {
                $exit = self::FAILURE;
            }

            if ($options->dryRun) {
                $db->rollBack();
                $this->warn('DRY RUN — every clean-table write above was rolled back.');
            } elseif ($autoIncrement !== null) {
                if ($db->transactionLevel() > 0) {
                    $this->warn("catalog_products AUTO_INCREMENT = $autoIncrement NOT issued: an outer transaction is open (DDL would commit it).");
                } else {
                    $db->statement("ALTER TABLE catalog_products AUTO_INCREMENT = $autoIncrement");
                    $this->info("catalog_products AUTO_INCREMENT = $autoIncrement");
                }
            }
        } catch (Throwable $e) {
            if ($options->dryRun && $db->transactionLevel() > 0) {
                $db->rollBack();
            }
            $this->error(get_class($e).': '.$e->getMessage());
            $this->line($e->getTraceAsString());
            $this->writeSummary($options, $startedAt, $report, $results, $reconciliation, $ctx, $auditMs, 'error: '.$e->getMessage());

            return 2;
        }

        $legacyAfter = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];
        if ($legacyBefore !== $legacyAfter) {
            $this->error("LEGACY TABLES CHANGED during the run (digest $legacyBefore → $legacyAfter). This must never happen.");
            $exit = self::FAILURE;
        } else {
            $this->info("legacy checksum identical before/after: $legacyAfter");
        }

        $inserted = array_sum(array_map(fn (StepResult $r) => $r->writes->inserted, $results));
        $updated = array_sum(array_map(fn (StepResult $r) => $r->writes->updated, $results));
        $this->info(sprintf('totals: inserted %d, updated %d, unchanged %d — %s', $inserted, $updated, array_sum(array_map(fn (StepResult $r) => $r->writes->unchanged, $results)), $inserted + $updated === 0 ? 'ZERO NET CHANGES (idempotent re-run)' : 'changes applied'));
        $this->line(sprintf('total time %.1fs — output %s', microtime(true) - $startedAt, $options->outputDir));

        $this->writeSummary($options, $startedAt, $report, $results, $reconciliation, $ctx, $auditMs, $exit === self::SUCCESS ? ($options->dryRun ? 'dry-run ok' : 'ok') : 'failed');

        return $exit;
    }

    /** @param  array<string, mixed>  $config */
    private function buildOptions(array $config): TransformOptions
    {
        $only = [];
        $onlyOpt = $this->option('only');
        if (is_string($onlyOpt) && trim($onlyOpt) !== '') {
            foreach (explode(',', $onlyOpt) as $n) {
                if (is_numeric(trim($n))) {
                    $only[] = (int) trim($n);
                }
            }
        }
        $root = $this->option('images-root');
        $root = is_string($root) && $root !== '' ? $root : (is_string($config['images_root'] ?? null) ? $config['images_root'] : '../backend/public/Uploads_Images');
        if (! preg_match('#^([a-zA-Z]:[\\\\/]|/)#', $root)) {
            $root = base_path($root);
        }
        $chunkOpt = $this->option('chunk');
        $chunk = is_numeric($chunkOpt) ? max(1, (int) $chunkOpt) : (is_int($config['chunk'] ?? null) ? $config['chunk'] : 500);
        $dryRun = (bool) $this->option('dry-run');
        $auditOnly = (bool) $this->option('audit');
        $outputOpt = $this->option('output');
        $dir = is_string($outputOpt) && $outputOpt !== ''
            ? $outputOpt
            : storage_path((is_string($config['output_dir'] ?? null) ? $config['output_dir'] : 'transform').'/'.date('Ymd_His').($auditOnly ? '-audit' : ($dryRun ? '-dry' : '')));

        return new TransformOptions($dryRun, $auditOnly, $only, $root, $chunk, $dir);
    }

    private function banner(mixed $host, string $database, LegacySource $legacy, TransformOptions $options): void
    {
        $host = is_string($host) ? $host : '?';
        $this->info('core:transform — '.($options->auditOnly ? 'AUDIT ONLY' : ($options->dryRun ? 'DRY RUN (rolled back)' : 'REAL RUN')));
        $this->line("clean  (write): $host/$database");
        $this->line('legacy (read) : '.$legacy->host().'/'.$legacy->databaseName());
        if ($legacy->host() !== $host || $legacy->databaseName() !== $database) {
            $this->warn('legacy and clean connections point at DIFFERENT databases — the study assumes the same one (D1).');
        }
        $this->line("images root   : {$options->imagesRoot}");
        $this->line("output        : {$options->outputDir}");
        if ($options->only !== []) {
            $this->warn('running only steps '.implode(',', $options->only));
        }
        $this->newLine();
    }

    private function printAudit(AuditReport $report, float $ms): void
    {
        $rows = [];
        foreach ($report->all() as $f) {
            $rows[] = [$f->code, $f->count(), $f->blocks() ? 'BLOCKS' : ($f->blocking ? 'blocking (0)' : ''), mb_substr($f->title, 0, 70)];
        }
        $this->table(['code', 'count', 'status', 'check'], $rows);
        $this->line(sprintf('audit: %d codes, %d non-zero, %s — %.0f ms', count($report->all()), $report->nonZero(), $report->isBlocked() ? 'BLOCKED' : 'no blocking finding', $ms));
        foreach ($report->all() as $f) {
            if ($f->caveat !== '') {
                $this->warn("{$f->code}: {$f->caveat}");
            }
        }
        $this->newLine();
    }

    private function printReconciliation(Reconciliation $rec): void
    {
        $rows = [];
        foreach ($rec->rows as $r) {
            $rows[] = [$r['table'], mb_substr($r['source'], 0, 48), mb_substr($r['relation'], 0, 34), $r['expected'], $r['actual'], $r['ok'] ? 'OK' : 'MISMATCH'];
        }
        $this->table(['clean table', 'legacy source', 'relation', 'expected', 'actual', 'status'], $rows);
        if ($rec->passed()) {
            $this->info('reconciliation: ALL COUNTS RECONCILE ('.count($rec->rows).' checks).');
        } else {
            $this->error('reconciliation: FAILED — see MISMATCH rows.');
        }
    }

    /** @param  list<StepResult>  $results */
    private function writeSummary(TransformOptions $options, float $startedAt, AuditReport $report, array $results, ?Reconciliation $rec, ?TransformContext $ctx, float $auditMs, string $status): void
    {
        $summary = [
            'status' => $status,
            'mode' => $options->auditOnly ? 'audit' : ($options->dryRun ? 'dry-run' : 'real'),
            'started_at' => date('c', (int) $startedAt),
            'duration_s' => round(microtime(true) - $startedAt, 2),
            'audit_ms' => round($auditMs),
            'only' => $options->only,
            'images_root' => $options->imagesRoot,
            'chunk' => $options->chunk,
            'audit' => $report->toSummaryArray(),
            'audit_blocked' => $report->isBlocked(),
            'steps' => array_map(fn (StepResult $r) => $r->toArray(), $results),
            'totals' => [
                'inserted' => array_sum(array_map(fn (StepResult $r) => $r->writes->inserted, $results)),
                'updated' => array_sum(array_map(fn (StepResult $r) => $r->writes->updated, $results)),
                'unchanged' => array_sum(array_map(fn (StepResult $r) => $r->writes->unchanged, $results)),
            ],
            'reconciliation' => $rec === null ? null : ['passed' => $rec->passed(), 'rows' => $rec->rows],
        ];
        file_put_contents($options->outputDir.'/summary.json', json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if ($rec !== null) {
            file_put_contents($options->outputDir.'/reconciliation.md', $rec->toMarkdown());
        }
        if ($ctx !== null) {
            $out = fopen($options->outputDir.'/diff.csv', 'w');
            if ($out !== false) {
                fputcsv($out, ['code', 'entity', 'id', 'legacy', 'clean'], ',', '"', '');
                foreach ($ctx->diff as $row) {
                    fputcsv($out, [$row['code'], $row['entity'], $row['id'], $row['legacy'], $row['clean']], ',', '"', '');
                }
                fclose($out);
            }
        }
    }
}
