<?php

namespace App\Console\Commands;

use App\Compat\CatalogWarmer;
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

    protected $description = "Rebuild the storefronts' listing index before a shopper has to";

    public function handle(CatalogWarmer $warmer): int
    {
        $only = array_map('intval', array_filter((array) $this->option('storefront'), 'is_numeric'));
        $ids = $only !== [] ? array_values(array_intersect($warmer->activeStorefronts(), $only)) : $warmer->activeStorefronts();
        if ($ids === []) {
            $this->info('No active storefront to warm.');

            return self::SUCCESS;
        }
        foreach ($warmer->warm($ids) as $id => $ms) {
            $this->line(sprintf('storefront %d: listing index rebuilt in %d ms', $id, (int) round($ms)));
        }
        // Peak memory of the run — so the cron's warm can be watched against PHP's memory_limit (L8).
        $this->line(sprintf('peak memory: %.1f MB', memory_get_peak_usage() / 1048576));

        return self::SUCCESS;
    }
}
