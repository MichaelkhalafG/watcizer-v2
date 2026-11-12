<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\BrandFashion\LegacyExtractor;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan bf:legacy-extract` — read the OLD Brand Fashion WordPress database, write the
 * cleaned catalogue to a file (2026-11-11, step 1B of the legacy import).
 *
 * WRITES NOTHING TO ANY DATABASE. It opens its own connection to a restored copy of the WordPress
 * dump, sets that session READ ONLY before the first query, and writes three files into `--out`:
 *
 *   • `products.jsonl` — one cleaned product per line (products only: no order, customer or user);
 *   • `images-needed.txt` — the ORIGINAL file of every image the import will read, relative to
 *     `wp-content/uploads/`, one per line, sorted — the list to extract from the uploads archive;
 *   • `extract-report.json` — every count and the label/value tallies behind the report;
 *   • `site.json` — the old site's other URLs (terms, pages, posts) with their Rank Math fields.
 *
 * The password is read from `BF_LEGACY_DB_PASSWORD` in the environment, never from an option (an
 * option lands in the shell history) and never printed.
 */
final class BrandFashionLegacyExtractCommand extends Command
{
    protected $signature = 'bf:legacy-extract
        {--wp-host=127.0.0.1 : the server holding the restored WordPress dump}
        {--wp-port=3306}
        {--wp-database=bf_legacy}
        {--wp-user=root : password from BF_LEGACY_DB_PASSWORD}
        {--wp-prefix=wp_}
        {--site=https://www.brandfashionegy.com : the old site home URL (legacy URLs are built on it)}
        {--out= : directory for the three files (default storage/import/bf-legacy-<timestamp>)}';

    protected $description = 'Read the old Brand Fashion WordPress catalogue (read-only) and write the cleaned products file + the image list';

    public function handle(): int
    {
        $started = microtime(true);
        $out = Coerce::str($this->option('out'));
        if ($out === '') {
            $out = storage_path('import/bf-legacy-'.date('Ymd-His'));
        }
        if (! is_dir($out) && ! mkdir($out, 0775, true) && ! is_dir($out)) {
            $this->error("Cannot create {$out}");

            return self::FAILURE;
        }

        config(['database.connections.bf_wordpress' => [
            'driver' => 'mariadb',
            'host' => Coerce::str($this->option('wp-host')),
            'port' => Coerce::int($this->option('wp-port'), 3306),
            'database' => Coerce::str($this->option('wp-database')),
            'username' => Coerce::str($this->option('wp-user')),
            'password' => Coerce::str(getenv('BF_LEGACY_DB_PASSWORD') ?: ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => false,
        ]]);
        $db = DB::connection('bf_wordpress');
        $db->statement('SET SESSION TRANSACTION READ ONLY');

        $extractor = new LegacyExtractor($db, Coerce::str($this->option('wp-prefix')), Coerce::str($this->option('site')));

        $productsFile = fopen($out.'/products.jsonl', 'wb');
        if ($productsFile === false) {
            $this->error('Cannot write products.jsonl');

            return self::FAILURE;
        }

        $images = [];
        $written = 0;
        foreach ($extractor->products() as $record) {
            $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            fwrite($productsFile, $line."\n");
            $written++;
            foreach (Coerce::arr($record['images'] ?? null) as $image) {
                $file = Coerce::str(Coerce::arr($image)['file'] ?? null);
                if ($file !== '') {
                    $images[$file] = true;
                }
            }
        }
        fclose($productsFile);

        // The rest of the old site's URLs (terms, pages, posts) with their Rank Math fields — and the
        // articles' own image originals, which join the one list to extract (D-13).
        $site = $extractor->site();
        foreach (Coerce::arr($site['posts'] ?? null) as $post) {
            $p = Coerce::arr($post);
            foreach (array_merge([Coerce::str($p['cover_file'] ?? null)], array_map(fn (mixed $f): string => Coerce::str($f), Coerce::arr($p['image_files'] ?? null))) as $file) {
                if ($file !== '') {
                    $images[$file] = true;
                }
            }
        }
        file_put_contents($out.'/site.json', json_encode($site, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $list = array_keys($images);
        sort($list, SORT_STRING);
        file_put_contents($out.'/images-needed.txt', implode("\n", $list).($list === [] ? '' : "\n"));

        $report = [
            'generated_at' => date(DATE_ATOM),
            'source' => [
                'database' => Coerce::str($this->option('wp-database')),
                'prefix' => Coerce::str($this->option('wp-prefix')),
                'site' => Coerce::str($this->option('site')),
            ],
            'products_written' => $written,
            'images_needed' => count($list),
            'counts' => $extractor->counts(),
            'tallies' => $extractor->tallies(),
            'seconds' => round(microtime(true) - $started, 1),
            'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
        ];
        file_put_contents($out.'/extract-report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->info("bf:legacy-extract — {$written} products, ".count($list).' image originals');
        foreach ($extractor->counts() as $name => $n) {
            $this->line(sprintf('  %-40s %6d', $name, $n));
        }
        $this->line('  files: '.$out);
        $this->line(sprintf('  %.1f s, peak %.1f MB', $report['seconds'], $report['peak_memory_mb']));

        return self::SUCCESS;
    }
}
