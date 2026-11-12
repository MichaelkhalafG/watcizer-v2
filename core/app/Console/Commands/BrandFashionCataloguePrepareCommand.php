<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\BrandFashion\CataloguePrepare;
use App\Support\Coerce;
use App\Support\WriteTarget;
use Illuminate\Console\Command;

/**
 * `php artisan bf:catalogue-prepare <extract dir>` — the data decisions of 2026-11-11 (D-19, D-10,
 * D-12, D-11, D-15), applied between the cleanup and the import. Details: {@see CataloguePrepare}.
 *
 * WRITES only with `--apply`: lookup rows (3), brand rows, `storefront_categories.is_active`,
 * Brand Fashion visibility of the dashboard twins, one promotion rule (SATIN10) — each through the
 * dashboard's writer, each activity-logged. A dry run writes `prepare-plan.json` and
 * `twins-review.csv`. `--apply` also writes `prepare-undo.json`; `--restore=<that file>` reverses it.
 */
final class BrandFashionCataloguePrepareCommand extends Command
{
    protected $signature = 'bf:catalogue-prepare
        {extract : the bf:legacy-extract folder (products.jsonl)}
        {--apply : write (default is a dry run)}
        {--out= : where the plan, the review sheet and the undo file go (default: storage/import/bf-prepare-<timestamp>)}
        {--restore= : reverse an apply from its prepare-undo.json, and nothing else}
        {--allow-remote : required to write anywhere but a local copy}';

    protected $description = 'Brand Fashion: lookups, brands, categories, twins and SATIN10 before the legacy import (dry run by default)';

    public function handle(CataloguePrepare $prepare): int
    {
        $restore = Coerce::str($this->option('restore'));
        $apply = (bool) $this->option('apply');
        if (($apply || $restore !== '') && ! (bool) $this->option(WriteTarget::ALLOW_REMOTE)) {
            $remote = WriteTarget::remote();
            if ($remote !== []) {
                WriteTarget::refuse($this, 'bf:catalogue-prepare', $remote,
                    ['3 lookup rows, the missing brands, category switches, twin visibility, the SATIN10 rule'],
                    ['run it on a local copy', 'or pass --allow-remote after the runbook\'s database backup'],
                );

                return self::FAILURE;
            }
        }
        if ($restore !== '') {
            foreach ($prepare->restore(Coerce::arr(json_decode((string) file_get_contents($restore), true, 512, JSON_THROW_ON_ERROR))) as $line) {
                $this->line('  '.$line);
            }

            return self::SUCCESS;
        }

        $dir = rtrim(str_replace('\\', '/', Coerce::str($this->argument('extract'))), '/');
        if (! is_file($dir.'/products.jsonl')) {
            $this->error("{$dir}/products.jsonl not found (bf:legacy-extract).");

            return self::FAILURE;
        }
        $out = Coerce::str($this->option('out')) ?: storage_path('import/bf-prepare-'.date('Ymd-His'));
        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            $this->error("Cannot create {$out}");

            return self::FAILURE;
        }

        $plan = $prepare->plan($dir.'/products.jsonl');
        file_put_contents($out.'/prepare-plan.json', json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $h = fopen($out.'/twins-review.csv', 'wb');
        if ($h !== false) {
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['sheet_id', 'wordpress_id', 'sku', 'title', 'matched_by', 'twins', 'twin_count', 'evidence', 'proposal'], escape: '\\');
            foreach ($plan['review'] as $r) {
                fputcsv($h, array_map(fn (mixed $v): string => Coerce::str($v), array_values($r)), escape: '\\');
            }
            fclose($h);
        }

        $this->info('bf:catalogue-prepare — '.($apply ? 'APPLY' : 'DRY RUN (nothing is written)'));
        foreach ($plan['renames'] as $rn) {
            $this->line(sprintf('  O-3  %-13s %-8s Arabic %s -> %s: %s', Coerce::str($rn['list']), Coerce::str($rn['en']), Coerce::str($rn['current'] ?? null, '-'), Coerce::str($rn['to']), Coerce::str($rn['action'])));
        }
        foreach ($plan['lookups'] as $l) {
            $this->line(sprintf('  D-19 %-13s %-8s %s', Coerce::str($l['list']), Coerce::str($l['en']), Coerce::nint($l['exists'] ?? null) === null ? 'create' : 'exists — kept'));
        }
        $this->line('  D-10 brands to create: '.count($plan['brands']).' — '.implode(', ', array_map(fn (array $b): string => Coerce::str($b['name']).' ('.Coerce::int($b['products']).')', $plan['brands'])));
        $this->line('  D-12 categories to switch on: '.count($plan['nodes']).' — '.implode(', ', array_map(fn (array $n): string => Coerce::str($n['path']), $plan['nodes'])));
        $this->line('  D-11 dashboard twins to make visible on Brand Fashion: '.count($plan['twins']).'; review sheet: '.count($plan['review']).' rows');
        $this->line('  D-15 SATIN10: '.(Coerce::nint($plan['coupon']['exists'] ?? null) !== null ? 'the code exists — kept' : 'create on Brand Fashion (category node '.Coerce::str($plan['coupon']['category_id'] ?? null, 'MISSING').')'));
        $this->line('  files: '.$out);
        if (! $apply) {
            return self::SUCCESS;
        }

        $undo = $prepare->apply($plan);
        file_put_contents($out.'/prepare-undo.json', json_encode($undo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->info(sprintf('  done: %d renamed, %d lookups, %d brands, %d categories, %d twins, rule %s. Undo: php artisan bf:catalogue-prepare --restore="%s"',
            count(Coerce::arr($undo['renames'])), count(Coerce::arr($undo['lookups'])), count(Coerce::arr($undo['brands'])), count(Coerce::arr($undo['nodes'])), count(Coerce::arr($undo['placements'])),
            Coerce::str($undo['rule'] ?? null, '-'), $out.'/prepare-undo.json'));
        foreach (Coerce::arr($undo['refused']) as $r) {
            $this->warn('  refused: '.Coerce::str($r));
        }

        return self::SUCCESS;
    }
}
