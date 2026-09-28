<?php

namespace App\Compat;

use App\Domain\Inventory\InventoryService;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\DB;

/**
 * Keeps each storefront's listing index built before a shopper needs it (2026-09-28).
 *
 * The index (and the catalogue under it) takes ~1 s to build and used to be built by whichever
 * shopper tapped first after it went stale — every 10 minutes when its TTL ran out, and after every
 * dashboard write that bumped the storefront's cache version. Two things now build it instead:
 *
 *  - `catalog:warm` on the schedule, every 5 minutes: the entries are replaced before they expire;
 *  - right after a request or command that flushed a storefront has answered (AppServiceProvider,
 *    `compat.warm_on_write`): the write's own process pays the rebuild, after its response is sent.
 */
final class CatalogWarmer
{
    public function __construct(
        private readonly StorefrontCache $cache,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * The storefronts to warm: active AND listed in `compat.warm_storefronts` (the ones shoppers
     * reach through this API). See config/compat.php for why not every active one.
     *
     * @return list<int>
     */
    public function activeStorefronts(): array
    {
        $listed = array_filter((array) config('compat.warm_storefronts', []), 'is_int');
        $ids = [];
        foreach (DB::table('storefronts')->where('is_active', true)->orderBy('id')->pluck('id') as $id) {
            if (is_numeric($id) && in_array((int) $id, $listed, true)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * Only the storefronts `activeStorefronts()` allows, out of these.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function allowed(array $ids): array
    {
        return array_values(array_intersect($ids, $this->activeStorefronts()));
    }

    /**
     * @param  list<int>  $storefrontIds
     * @return array<int, float> storefront id => ms it took
     */
    public function warm(array $storefrontIds): array
    {
        $took = [];
        foreach ($storefrontIds as $id) {
            $t = hrtime(true);
            (new CompatServices($this->cache, $this->inventory, CompatStorefront::pinned($id)))->listing->warm();
            $took[$id] = (hrtime(true) - $t) / 1e6;
        }

        return $took;
    }
}
