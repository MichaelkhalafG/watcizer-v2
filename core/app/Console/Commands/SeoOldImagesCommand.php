<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Seo\OldImages;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * seo:old-images — READ ONLY (2026-11-24). The open 404s under /wp-content/uploads/ on a shop, most asked first, in two
 * groups: those that now redirect (a copy of an original the legacy map holds - {@see OldImages}) with where they go,
 * and those that stay 404 (their original was never imported). Writes nothing; the deploy prints it for the record.
 *
 *   php artisan seo:old-images --storefront=2 --limit=100
 */
final class SeoOldImagesCommand extends Command
{
    protected $signature = 'seo:old-images
        {--storefront=2 : the shop}
        {--limit=100 : the most-asked paths to list per group}';

    protected $description = 'List the old image 404s that now redirect and those that stay 404 (read only)';

    public function handle(): int
    {
        $sf = Coerce::int($this->option('storefront'), 2);
        $limit = max(1, Coerce::int($this->option('limit'), 100));
        $redirect = [];
        $stays = [];
        $rows = DB::table('seo_not_found')->where('storefront_id', $sf)->whereNull('resolved_at')
            ->where('path', 'like', OldImages::PREFIX.'%')->orderByDesc('hits')->get(['path', 'hits']);
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $path = Row::str($row, 'path');
            $hit = OldImages::resolve($sf, rawurldecode((string) strtok($path, '?#')));
            if ($hit !== null) {
                $redirect[] = [Row::int($row, 'hits'), $path, $hit['to']];
            } else {
                $stays[] = [Row::int($row, 'hits'), $path, ''];
            }
        }
        $this->line(sprintf('seo:old-images storefront %d: %d open 404s under %s - %d now redirect (the product is clear), %d stay 404.',
            $sf, count($rows), OldImages::PREFIX, count($redirect), count($stays)));
        $this->line('');
        $this->line('REDIRECT NOW (asks, old path -> where it goes):');
        foreach (array_slice($redirect, 0, $limit) as [$hits, $path, $to]) {
            $this->line(sprintf('  %5d  %s -> %s', $hits, $path, $to));
        }
        $this->line('');
        $this->line('STAY 404 (asks, old path):');
        foreach (array_slice($stays, 0, $limit) as [$hits, $path]) {
            $this->line(sprintf('  %5d  %s', $hits, $path));
        }

        return self::SUCCESS;
    }
}
