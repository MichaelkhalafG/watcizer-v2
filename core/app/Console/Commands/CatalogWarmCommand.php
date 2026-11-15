<?php

namespace App\Console\Commands;

use App\Compat\CatalogWarmer;
use App\Domain\Catalog\SearchExposure;
use App\Domain\Content\DataBlocks;
use App\Storefront\CategoryTree;
use App\Storefront\StorefrontCache;
use Illuminate\Console\Command;

/**
 * Rebuild each active storefront's listing index and catalogue now (see CatalogWarmer). Runs every
 * 5 minutes from routes/console.php, inside the TTL, so a shopper never pays the rebuild.
 *
 *   php artisan catalog:warm                  # every active storefront
 *   php artisan catalog:warm --storefront=1   # just one
 */
final class CatalogWarmCommand extends Command
{
    protected $signature = 'catalog:warm {--storefront=* : only these storefront ids}';

    protected $description = "Rebuild the storefronts' listing index, category tree and (when cold) sitemaps before a shopper or crawler has to";

    public function handle(CatalogWarmer $warmer): int
    {
        $only = array_values(array_map('intval', array_filter((array) $this->option('storefront'), 'is_numeric')));
        // What search engines and AI are shown of each product (2026-11-14, SearchExposure): every save keeps it, and
        // this catches what no save touched (a bulk price change). Only changed rows are written; a change flushes the
        // shops' caches so the pages, the listings and the sitemaps below read it.
        $exposure = app(SearchExposure::class)->refreshAll();
        $this->line(sprintf('search exposure: %d changed (%d hidden, %d restricted)', $exposure['changed'], $exposure['hidden'], $exposure['restricted']));
        if ($exposure['changed'] > 0) {
            foreach ($warmer->activeStorefronts() as $sf) {
                app(StorefrontCache::class)->flush($sf);
            }
        }

        $ids = $only !== [] ? array_values(array_intersect($warmer->activeStorefronts(), $only)) : $warmer->activeStorefronts();
        foreach ($ids === [] ? [] : $warmer->warm($ids) as $id => $ms) {
            $this->line(sprintf('storefront %d: listing index rebuilt in %d ms', $id, (int) round($ms)));
        }
        // The category tree of EVERY active storefront, every run (2026-11-12 incident: only storefront 1's listing
        // was warmed, the tree expired hourly and its cold build stampeded). Every 5 minutes against a 1-hour TTL:
        // a shopper never meets an expired tree.
        foreach ($warmer->treeStorefronts($only) as $id) {
            $t = microtime(true);
            $tree = CategoryTree::warm($id);
            $this->line($tree === null
                ? sprintf('storefront %d: category tree — a request is building it now (left to it)', $id)
                : sprintf('storefront %d: category tree rebuilt in %d ms (%d nodes)', $id, (int) round((microtime(true) - $t) * 1000), count((array) ($tree['nodes'] ?? []))));
        }
        // Each active shop's sitemaps, where they are not cached (2026-11-14): built here, not in Google's request.
        foreach ($warmer->warmSitemaps($only) as $id => $locales) {
            $this->line(sprintf('storefront %d: sitemaps ready (%s)', $id, implode(', ', array_map(fn (string $l, int $ms): string => "{$l} {$ms} ms", array_keys($locales), $locales))));
        }
        // The live data blocks of each shop's newest articles, where they are not cached (SEO Phase 3, 2026-11-14).
        foreach ($warmer->treeStorefronts($only) as $id) {
            $built = app(DataBlocks::class)->warmNewest($id);
            if ($built > 0) {
                $this->line(sprintf('storefront %d: data blocks of %d article(s) resolved', $id, $built));
            }
        }
        // Peak memory of the run — so the cron's warm can be watched against PHP's memory_limit (L8).
        $this->line(sprintf('peak memory: %.1f MB', memory_get_peak_usage() / 1048576));

        return self::SUCCESS;
    }
}
