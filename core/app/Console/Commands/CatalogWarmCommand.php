<?php

namespace App\Console\Commands;

use App\Compat\CatalogWarmer;
use App\Storefront\CategoryTree;
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

    protected $description = "Rebuild the storefronts' listing index and category tree before a shopper has to";

    public function handle(CatalogWarmer $warmer): int
    {
        $only = array_values(array_map('intval', array_filter((array) $this->option('storefront'), 'is_numeric')));
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
        // Peak memory of the run — so the cron's warm can be watched against PHP's memory_limit (L8).
        $this->line(sprintf('peak memory: %.1f MB', memory_get_peak_usage() / 1048576));

        return self::SUCCESS;
    }
}
