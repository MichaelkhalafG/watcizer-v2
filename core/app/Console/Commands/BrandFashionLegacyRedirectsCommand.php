<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\BrandFashion\LegacyRedirects;
use App\Support\Coerce;
use App\Support\WriteTarget;
use Illuminate\Console\Command;

/**
 * `php artisan bf:legacy-redirects <extract dir>` — step 2 of the Brand Fashion legacy import
 * (2026-11-11): the old site's URLs → the new site's, as a reviewable CSV and (with `--apply`) as
 * `storefront_redirects` rows for Brand Fashion, source `bf_legacy`.
 *
 * WRITES only with `--apply`, and only `storefront_redirects` (insert or update by path). A dry
 * run writes `redirect-map.csv`. Built from the database AS IT IS when it runs — run it again on
 * switch day, after the last visibility decisions. Undo: `DELETE FROM storefront_redirects WHERE
 * storefront_id = 2 AND source = 'bf_legacy'` (no other writer uses that source).
 *
 * NOTHING SERVES these rows yet — the domain plan says what must (BF-REDIRECT-SERVE).
 */
final class BrandFashionLegacyRedirectsCommand extends Command
{
    protected $signature = 'bf:legacy-redirects
        {extract : the bf:legacy-extract folder (products.jsonl + site.json)}
        {--image-map= : image-map.jsonl from bf:legacy-import, to map /wp-content/uploads/ originals}
        {--asset-base= : the public base of Brand Fashion images, e.g. https://api.brandfashionegy.com}
        {--out= : where redirect-map.csv goes (default: the extract folder)}
        {--apply : store the rows}
        {--allow-remote : required to write anywhere but a local copy}';

    protected $description = 'Brand Fashion: build the 301 map from the old site\'s URLs (dry run by default)';

    public function handle(LegacyRedirects $redirects): int
    {
        $dir = rtrim(str_replace('\\', '/', Coerce::str($this->argument('extract'))), '/');
        if (! is_file($dir.'/products.jsonl') || ! is_file($dir.'/site.json')) {
            $this->error("{$dir} must hold products.jsonl and site.json (bf:legacy-extract).");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if ($apply && ! (bool) $this->option(WriteTarget::ALLOW_REMOTE)) {
            $remote = WriteTarget::remote();
            if ($remote !== []) {
                WriteTarget::refuse($this, 'bf:legacy-redirects', $remote,
                    ['storefront_redirects rows for storefront 2, source bf_legacy'],
                    ['run it on a local copy', 'or pass --allow-remote'],
                );

                return self::FAILURE;
            }
        }

        $started = microtime(true);
        $imageMap = Coerce::nstr($this->option('image-map'));
        $assetBase = Coerce::nstr($this->option('asset-base'));
        $rows = $redirects->build($dir.'/products.jsonl', $dir.'/site.json', $imageMap, $assetBase);

        $out = Coerce::str($this->option('out')) ?: $dir;
        $handle = fopen($out.'/redirect-map.csv', 'wb');
        if ($handle === false) {
            $this->error('Cannot write redirect-map.csv');

            return self::FAILURE;
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['from', 'to', 'status', 'kind', 'note'], escape: '\\');
        foreach ($rows as $row) {
            fputcsv($handle, [$row['from'], $row['to'], $row['status'], $row['kind'], $row['note']], escape: '\\');
        }
        fclose($handle);

        $this->info('bf:legacy-redirects — '.count($rows).' old URLs'.($apply ? ' (APPLY)' : ' (DRY RUN)'));
        foreach ($redirects->counts() as $k => $n) {
            $this->line(sprintf('  %-34s %6d', $k, $n));
        }
        if ($apply) {
            $done = $redirects->store($rows);
            $this->info(sprintf('  stored %d rows; %d need no row (same URL)', $done['written'], $done['skipped_same']));
        }
        $this->line('  map: '.$out.'/redirect-map.csv');
        $this->line(sprintf('  %.1f s, peak %.1f MB', microtime(true) - $started, memory_get_peak_usage(true) / 1048576));

        return self::SUCCESS;
    }
}
