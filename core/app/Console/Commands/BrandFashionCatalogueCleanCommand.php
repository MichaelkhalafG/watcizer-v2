<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\BrandFashion\CatalogueCleanup;
use App\Support\Coerce;
use App\Support\WriteTarget;
use Illuminate\Console\Command;

/**
 * `php artisan bf:catalogue-clean` — step 1A of the Brand Fashion legacy import (2026-11-11).
 *
 * WRITES, but only with `--apply`: it hard-deletes sheet-imported products that are not active on
 * Watchizer and carry no history, and (with `--history=archive`) archives the ones that do. A DRY
 * RUN IS THE DEFAULT and writes nothing but report files. Before any write it saves every row it
 * will change to `backup.json`, reads it back and checks the counts; `--restore=<backup.json>`
 * puts everything back. It asks {@see WriteTarget} before writing, like every tool that writes.
 *
 * Buckets, origins and the reasons are in {@see CatalogueCleanup}.
 */
final class BrandFashionCatalogueCleanCommand extends Command
{
    protected $signature = 'bf:catalogue-clean
        {--apply : write (default is a dry run that writes only report files)}
        {--history=keep : keep|archive — what to do with sheet products that have history (never deleted)}
        {--opening-balance-is-not-history : treat a ledger holding ONLY the sheet import\'s own opening balance as no history}
        {--out= : directory for the report and the backup (default storage/import/bf-clean-<timestamp>)}
        {--restore= : put a backup.json back, and nothing else}
        {--reviewed= : O-4 — act on the duplicates review sheet (twins-review.csv) INSTEAD of the buckets: remove the "not a duplicate" rows and all but one of each real duplicate}
        {--allow-remote : required to write anywhere but a local copy}';

    protected $description = 'Brand Fashion: remove the sheet-imported products not active on Watchizer (dry run by default; backup + restore)';

    public function handle(CatalogueCleanup $cleanup): int
    {
        $restore = Coerce::str($this->option('restore'));
        $apply = (bool) $this->option('apply');

        if (($apply || $restore !== '') && ! (bool) $this->option(WriteTarget::ALLOW_REMOTE)) {
            $remote = WriteTarget::remote();
            if ($remote !== []) {
                WriteTarget::refuse($this, 'bf:catalogue-clean', $remote,
                    ['deletes sheet-imported products and their child rows', 'archives products with history (--history=archive)'],
                    ['run it on a local copy', 'or pass --allow-remote after taking the database backup of the runbook'],
                );

                return self::FAILURE;
            }
        }

        if ($restore !== '') {
            $counts = $cleanup->restore($restore);
            $this->info('Restored from '.$restore);
            foreach ($counts as $table => $n) {
                $this->line(sprintf('  %-32s %7d', $table, $n));
            }

            return self::SUCCESS;
        }

        $history = Coerce::str($this->option('history'), 'keep');
        if (! in_array($history, ['keep', 'archive'], true)) {
            $this->error('--history must be keep or archive.');

            return self::FAILURE;
        }
        $openingNotHistory = (bool) $this->option('opening-balance-is-not-history');

        $out = Coerce::str($this->option('out'));
        if ($out === '') {
            $out = storage_path('import/bf-clean-'.date('Ymd-His'));
        }
        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            $this->error("Cannot create {$out}");

            return self::FAILURE;
        }

        $started = microtime(true);
        $result = $cleanup->classify($openingNotHistory);
        $rows = $result['rows'];

        $reviewed = Coerce::str($this->option('reviewed'));
        if ($reviewed !== '') {
            return $this->reviewed($cleanup, $reviewed, $rows, $out, $apply, $openingNotHistory, $started);
        }

        $delete = [];
        $historyIds = [];
        $duplicates = [];
        foreach ($rows as $id => $row) {
            match ($row['bucket']) {
                CatalogueCleanup::DELETE => $delete[] = $id,
                CatalogueCleanup::HISTORY => $historyIds[] = $id,
                CatalogueCleanup::DUPLICATE => $duplicates[] = $id,
                default => null,
            };
        }
        $archive = $history === 'archive'
            ? array_values(array_filter($historyIds, fn (int $id): bool => ! Coerce::bool($rows[$id]['archived'] ?? false)))
            : [];

        $this->writeReports($out, $cleanup, $rows, $result);

        $this->line('');
        $this->info('bf:catalogue-clean — '.($apply ? 'APPLY' : 'DRY RUN (nothing is written)'));
        $this->line('  origins (all products):');
        foreach ($result['origins'] as $origin => $n) {
            $this->line(sprintf('    %-12s %6d', $origin, $n));
        }
        $this->line('  buckets:');
        foreach ($result['buckets'] as $bucket => $n) {
            $this->line(sprintf('    %-16s %6d', $bucket, $n));
        }
        $this->line(sprintf('  will hard-delete : %d', count($delete)));
        $this->line(sprintf('  history (kept)   : %d%s', count($historyIds), $history === 'archive' ? ' — '.count($archive).' will be ARCHIVED' : ' — left as they are (--history=archive to archive)'));
        $this->line(sprintf('  duplicates       : %d — listed in duplicates.csv, never removed', count($duplicates)));
        $this->line('  opening balance  : '.($openingNotHistory ? 'NOT history (its movements go with the product)' : 'history (strict)'));
        $this->line('  reports          : '.$out);

        if (! $apply) {
            $this->line(sprintf('  %.1f s, peak %.1f MB', microtime(true) - $started, memory_get_peak_usage(true) / 1048576));

            return self::SUCCESS;
        }

        $backup = $out.'/backup.json';
        $counts = $cleanup->backup($backup, $delete, $archive, $openingNotHistory);
        $this->line('  backup written and read back: '.$backup);
        foreach ($counts as $table => $n) {
            $this->line(sprintf('    %-32s %7d', $table, $n));
        }

        $done = $cleanup->apply($delete, $archive, $openingNotHistory);
        $this->info(sprintf('  deleted %d, archived %d. Undo: php artisan bf:catalogue-clean --restore="%s"', $done['deleted'], $done['archived'], $backup));
        $this->line(sprintf('  %.1f s, peak %.1f MB', microtime(true) - $started, memory_get_peak_usage(true) / 1048576));

        return self::SUCCESS;
    }

    /**
     * O-4: the review sheet's decisions, with the same backup, delete and restore as the buckets.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function reviewed(CatalogueCleanup $cleanup, string $sheet, array $rows, string $out, bool $apply, bool $openingNotHistory, float $started): int
    {
        if (! is_file($sheet)) {
            $this->error("No such file: {$sheet}");

            return self::FAILURE;
        }
        $r = $cleanup->reviewed($sheet, $rows);
        // History is checked BEFORE the backup, so the backup holds exactly what is deleted (a product apply()
        // skipped at its own re-check would sit in the backup and make --restore refuse). Pass
        // --opening-balance-is-not-history as in step 5 (D-5): the sheet's own opening balance is no history.
        foreach ($cleanup->historyOf($openingNotHistory, $r['remove']) as $id => $tables) {
            $r['refused'][] = "{$id}: has history (".implode(', ', $tables).') — left alone';
            $r['remove'] = array_values(array_diff($r['remove'], [$id]));
            foreach ($r['decisions'] as $i => $d) {
                if ($d['id'] === $id) {
                    $r['decisions'][$i]['decision'] = 'refused';
                    $r['decisions'][$i]['why'] = 'has history: '.implode(', ', $tables);
                }
            }
        }

        $handle = fopen($out.'/reviewed.csv', 'wb');
        if ($handle !== false) {
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['id', 'decision', 'why', 'title'], escape: '\\');
            foreach ($r['decisions'] as $d) {
                fputcsv($handle, [$d['id'], $d['decision'], $d['why'], Coerce::str($rows[$d['id']]['title'] ?? null)], escape: '\\');
            }
            fclose($handle);
        }

        $this->line('');
        $this->info('bf:catalogue-clean --reviewed — '.($apply ? 'APPLY' : 'DRY RUN (nothing is written)'));
        $this->line(sprintf('  remove : %d (the "not a duplicate" rows and all but one of each real duplicate)', count($r['remove'])));
        $this->line(sprintf('  keep   : %d (%s)', count($r['keep']), implode(', ', $r['keep'])));
        $this->line(sprintf('  refused: %d', count($r['refused'])));
        foreach ($r['refused'] as $line) {
            $this->warn('    '.$line);
        }
        $this->line('  decisions: '.$out.'/reviewed.csv');

        if (! $apply) {
            return self::SUCCESS;
        }

        $backup = $out.'/backup.json';
        $counts = $cleanup->backup($backup, $r['remove'], [], $openingNotHistory);
        $this->line('  backup written and read back: '.$backup);
        foreach ($counts as $table => $n) {
            $this->line(sprintf('    %-32s %7d', $table, $n));
        }
        $done = $cleanup->apply($r['remove'], [], $openingNotHistory, 'bf:catalogue-clean --reviewed — O-4, the duplicates review of 2026-11-11');
        if ($done['deleted'] !== count($r['remove'])) {
            $this->error(sprintf('  deleted %d of %d: the others gained history in the seconds since the check and were left alone — the backup also lists them, so --restore will refuse until they are removed from it; stop and ask.', $done['deleted'], count($r['remove'])));
        }
        $this->info(sprintf('  deleted %d. Undo: php artisan bf:catalogue-clean --restore="%s"', $done['deleted'], $backup));
        $this->line(sprintf('  %.1f s, peak %.1f MB', microtime(true) - $started, memory_get_peak_usage(true) / 1048576));

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @param  array{rows: array<int, array<string, mixed>>, buckets: array<string, int>, origins: array<string, int>}  $result
     */
    private function writeReports(string $out, CatalogueCleanup $cleanup, array $rows, array $result): void
    {
        $files = [];
        foreach ([CatalogueCleanup::DELETE, CatalogueCleanup::HISTORY, CatalogueCleanup::DUPLICATE, CatalogueCleanup::KEEP_WATCHIZER] as $bucket) {
            $handle = fopen($out.'/'.$bucket.'.csv', 'wb');
            if ($handle === false) {
                continue;
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['id', 'wa_code', 'import_ref', 'sku', 'title', 'archived', 'placements', 'history', 'matched_by', 'duplicate_of'], escape: '\\');
            $files[$bucket] = $handle;
        }

        $dupTargets = [];
        foreach ($rows as $row) {
            foreach (Coerce::intList($row['duplicate_of'] ?? []) as $o) {
                $dupTargets[$o] = true;
            }
        }
        $described = [];
        foreach ($cleanup->describe(array_keys($dupTargets)) as $d) {
            $described[Coerce::int($d['id'])] = $d;
        }

        foreach ($rows as $row) {
            $bucket = Coerce::str($row['bucket'] ?? null);
            if (! isset($files[$bucket])) {
                continue;
            }
            $placements = [];
            foreach (Coerce::arr($row['placements'] ?? null) as $sf => $visible) {
                $placements[] = $sf.':'.((bool) $visible ? 'visible' : 'hidden');
            }
            $others = [];
            foreach (Coerce::intList($row['duplicate_of'] ?? []) as $o) {
                $d = $described[$o] ?? null;
                $others[] = $d === null ? (string) $o : sprintf('%d %s [%s, %s, sku %s] %s', $o, Coerce::str($d['wa_code']), Coerce::str($d['origin']),
                    Coerce::str($d['placements']) === '' ? 'no placement' : Coerce::str($d['placements']), Coerce::str($d['sku'] ?? null, '-'), Coerce::str($d['title']));
            }
            fputcsv($files[$bucket], [
                Coerce::int($row['id']), Coerce::str($row['wa_code']), Coerce::str($row['import_ref'] ?? null), Coerce::str($row['sku'] ?? null),
                Coerce::str($row['title']), Coerce::bool($row['archived'] ?? false) ? 'yes' : 'no', implode(' ', $placements),
                implode(' ', array_map(fn (mixed $t): string => Coerce::str($t), Coerce::arr($row['history'] ?? null))),
                Coerce::str($row['matched_by'] ?? null), implode(' | ', $others),
            ], escape: '\\');
        }
        foreach ($files as $handle) {
            fclose($handle);
        }

        file_put_contents($out.'/summary.json', json_encode([
            'generated_at' => date(DATE_ATOM),
            'origins' => $result['origins'],
            'buckets' => $result['buckets'],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
