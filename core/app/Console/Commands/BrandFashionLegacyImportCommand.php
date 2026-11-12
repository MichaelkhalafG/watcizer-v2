<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\BrandFashion\LegacyImporter;
use App\Support\Coerce;
use App\Support\WriteTarget;
use Illuminate\Console\Command;
use Throwable;

/**
 * `php artisan bf:legacy-import <products.jsonl>` — step 1B of the Brand Fashion legacy import
 * (2026-11-11): every published product of the old site, cleaned, on Brand Fashion only.
 *
 * WRITES, but only with `--apply`; a DRY RUN IS THE DEFAULT and writes only report files. With
 * `--apply` it creates products, their translations, specs, colours, genders, images (through
 * `MediaStore` — masters and renditions in the shared Uploads_Images tree), Brand Fashion
 * placements, variants, size lookups for variant sizes it has never seen, and — with
 * `--stock=ledger` — an `import` opening balance per product in the stock ledger. It asks
 * {@see WriteTarget} before writing. It is RE-RUNNABLE: a product it created is skipped by its
 * `import_ref`, and processed images are reused from `image-map.jsonl` in `--out`.
 *
 * Undo: every product it creates carries `import_ref = bfwp:<legacy id>`; the runbook's undo is a
 * database restore (a ledger movement is history and is not deleted by any command).
 */
final class BrandFashionLegacyImportCommand extends Command
{
    protected $signature = 'bf:legacy-import
        {file : products.jsonl from bf:legacy-extract}
        {--apply : write (default is a dry run that writes only report files)}
        {--images= : the folder holding the extracted uploads (wp-content/uploads layout), or "none"}
        {--stock= : ledger|zero — REQUIRED with --apply: the legacy quantities as an opening balance, or 0 for the team to count}
        {--out= : directory for reports and image-map.jsonl (default storage/import/bf-import-<timestamp>)}
        {--limit=0 : stop after this many records (0 = all)}
        {--allow-remote : required to write anywhere but a local copy}';

    protected $description = 'Brand Fashion: import the cleaned legacy catalogue (dry run by default)';

    public function handle(LegacyImporter $importer): int
    {
        $file = Coerce::str($this->argument('file'));
        if (! is_file($file)) {
            $this->error("No such file: {$file}");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $stock = Coerce::str($this->option('stock'));
        if ($apply && ! in_array($stock, [LegacyImporter::STOCK_LEDGER, LegacyImporter::STOCK_ZERO], true)) {
            $this->error('--stock=ledger or --stock=zero is required with --apply (the decision is the developer\'s; nothing defaults).');

            return self::FAILURE;
        }
        $imagesOption = Coerce::str($this->option('images'));
        $imagesRoot = $imagesOption === '' || $imagesOption === 'none' ? null : rtrim(str_replace('\\', '/', $imagesOption), '/');
        if ($imagesRoot !== null && ! is_dir($imagesRoot)) {
            $this->error("--images is not a folder: {$imagesRoot}");

            return self::FAILURE;
        }
        if ($apply && $imagesOption === '') {
            $this->error('--images=<folder> or --images=none is required with --apply.');

            return self::FAILURE;
        }

        if ($apply && ! (bool) $this->option(WriteTarget::ALLOW_REMOTE)) {
            $remote = WriteTarget::remote();
            if ($remote !== []) {
                WriteTarget::refuse($this, 'bf:legacy-import', $remote,
                    ['products + translations + specs + images + Brand Fashion placements + variants', 'stock-ledger opening balances (--stock=ledger)'],
                    ['run it on a local copy', 'or pass --allow-remote after taking the database backup of the runbook'],
                );

                return self::FAILURE;
            }
        }

        $out = Coerce::str($this->option('out'));
        if ($out === '') {
            $out = storage_path('import/bf-import-'.date('Ymd-His'));
        }
        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            $this->error("Cannot create {$out}");

            return self::FAILURE;
        }

        $started = microtime(true);
        $importer->prepare($out.'/image-map.jsonl', $file);

        $csv = [];
        foreach (['matches' => ['legacy_id', 'legacy_url', 'title', 'sku', 'legacy_units', 'rule', 'existing_id', 'existing_wa_code', 'existing_origin', 'existing_title', 'existing_sku', 'existing_units', 'on_watchizer', 'on_brandfashion', 'proposal'],
            'skipped' => ['legacy_id', 'legacy_url', 'title', 'sku', 'reason', 'regular_price'],
            'created' => ['product_id', 'legacy_id', 'legacy_url', 'legacy_slug', 'new_slug', 'slug_kept', 'visible', 'kept_hidden_by', 'notes'],
            'failed' => ['legacy_id', 'legacy_url', 'title', 'error']] as $name => $header) {
            $handle = fopen($out.'/'.$name.'.csv', 'wb');
            if ($handle === false) {
                $this->error("Cannot write {$name}.csv");

                return self::FAILURE;
            }
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $header, escape: '\\');
            $csv[$name] = $handle;
        }

        $limit = Coerce::int($this->option('limit'));
        $seen = 0;
        $handle = fopen($file, 'rb');
        if ($handle === false) {
            $this->error("Cannot read {$file}");

            return self::FAILURE;
        }
        while (($line = fgets($handle)) !== false) {
            if (trim($line) === '') {
                continue;
            }
            $seen++;
            if ($limit > 0 && $seen > $limit) {
                break;
            }
            $record = Coerce::arr(json_decode($line, true, 512, JSON_THROW_ON_ERROR));
            $plan = $importer->plan($record);
            $importer->count('plan_'.$plan['action']);

            $legacyId = Coerce::int($record['legacy_id'] ?? null);
            $url = Coerce::str($record['legacy_url'] ?? null);
            $title = Coerce::str($record['title_en'] ?? null);
            $legacyUnits = Coerce::int(Coerce::arr($record['stock'] ?? null)['quantity'] ?? null);

            if ($plan['action'] === 'match' && $plan['existing'] !== null) {
                $e = $importer->existing($plan['existing']);
                $importer->count('match_by_'.Coerce::str($plan['rule']));
                $importer->count('match_existing_'.$e['origin']);
                fputcsv($csv['matches'], [$legacyId, $url, $title, Coerce::str($record['sku'] ?? null), $legacyUnits, $plan['rule'], $plan['existing'],
                    $e['wa_code'], $e['origin'], $e['title'], $e['sku'], $e['units'], $e['watchizer'], $e['brandfashion'], self::proposal($e)], escape: '\\');

                continue;
            }
            if ($plan['action'] === 'skip') {
                $importer->count('skip_'.$plan['reason']);
                fputcsv($csv['skipped'], [$legacyId, $url, $title, Coerce::str($record['sku'] ?? null), $plan['reason'], Coerce::str($record['regular'] ?? null)], escape: '\\');

                continue;
            }
            if ($plan['action'] !== 'create') {
                continue;
            }

            $resolved = $importer->resolve($record, $imagesRoot);
            foreach (Coerce::arr($resolved['notes'] ?? null) as $note) {
                $importer->count('note_'.Coerce::str($note));
            }
            if (! $apply) {
                $onDisk = array_filter(Coerce::arr($resolved['images'] ?? null), fn (mixed $i): bool => Coerce::bool(Coerce::arr($i)['on_disk'] ?? false));
                $importer->count($onDisk === [] ? 'would_create_without_image' : 'would_create_with_image');

                continue;
            }

            try {
                $result = $importer->create($record, $resolved, $stock, $imagesRoot);
                $importer->count('created');
                $importer->count($result['visible'] ? 'created_visible' : 'created_hidden');
                if (! $result['slug_kept']) {
                    $importer->count('slug_changed');
                }
                foreach ($result['missing'] as $m) {
                    $importer->tally('hidden_because', $m);
                }
                fputcsv($csv['created'], [$result['id'], $legacyId, $url, Coerce::str($record['legacy_slug'] ?? null), $result['slug'],
                    $result['slug_kept'] ? 'yes' : 'no', $result['visible'] ? 'yes' : 'no', implode('; ', $result['missing']),
                    implode(' ', array_map(fn (mixed $n): string => Coerce::str($n), Coerce::arr($resolved['notes'] ?? null)))], escape: '\\');
            } catch (Throwable $e) {
                $importer->count('failed');
                fputcsv($csv['failed'], [$legacyId, $url, $title, mb_substr($e->getMessage(), 0, 300)], escape: '\\');
            }

            if ($seen % 500 === 0) {
                $this->line(sprintf('  … %d records, %.0f s, %.0f MB', $seen, microtime(true) - $started, memory_get_usage(true) / 1048576));
            }
        }
        fclose($handle);
        foreach ($csv as $h) {
            fclose($h);
        }

        $seconds = round(microtime(true) - $started, 1);
        $peak = round(memory_get_peak_usage(true) / 1048576, 1);
        file_put_contents($out.'/report.json', json_encode([
            'generated_at' => date(DATE_ATOM),
            'mode' => $apply ? 'apply' : 'dry-run',
            'stock' => $stock,
            'images' => $imagesRoot ?? 'none',
            'records' => $seen,
            'counts' => $importer->counts(),
            'tallies' => $importer->tallies(),
            'seconds' => $seconds,
            'peak_memory_mb' => $peak,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->info('bf:legacy-import — '.($apply ? 'APPLY (stock='.$stock.')' : 'DRY RUN (nothing is written)').' — '.$seen.' records');
        foreach ($importer->counts() as $name => $n) {
            $this->line(sprintf('  %-40s %7d', $name, $n));
        }
        $this->line('  reports: '.$out);
        $this->line(sprintf('  %.1f s, peak %.1f MB', $seconds, $peak));

        return self::SUCCESS;
    }

    /** @param array<string, string> $existing */
    private static function proposal(array $existing): string
    {
        if ($existing['origin'] === 'sheet') {
            return 'the September sheet copy of this product: archive it (bf:catalogue-clean --history=archive) and re-run to import the clean one';
        }
        if ($existing['brandfashion'] === 'visible') {
            return 'already on Brand Fashion: nothing to create — compare price and stock';
        }

        return 'make the existing product visible on Brand Fashion (it is '.$existing['brandfashion'].' there)';
    }
}
