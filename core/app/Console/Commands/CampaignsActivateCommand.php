<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Campaigns\CampaignWriter;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `campaigns:activate` — record the start quantities of every campaign that has gone live
 * (the twelve updates, Part 2, 2026-11-05).
 *
 * A campaign saved with a start in the future has nothing to record yet: the progress bar's "start" is
 * the stock at the moment the sale begins, not at the moment somebody typed it. This runs on the
 * one-minute cron, finds the campaigns that are live and still have a product with no start, and writes
 * it — once per product, never again ({@see CampaignWriter::activate()}). A storefront whose campaign it
 * touched is flushed, so the bar appears without waiting for a cache to expire.
 *
 * It writes ONLY `storefront_campaign_products.start_*` / `snapshot_at` and
 * `storefront_campaigns.activated_at`. With nothing to do it says so and exits 0.
 */
final class CampaignsActivateCommand extends Command
{
    protected $signature = 'campaigns:activate';

    protected $description = 'Record the starting stock of every product in a campaign that has gone live (once per product)';

    public function handle(StorefrontCache $cache): int
    {
        $now = now()->format('Y-m-d H:i:s');
        // The storefronts that have something to record, read BEFORE the write so they can be flushed after it.
        $storefronts = DB::table('storefront_campaigns as c')
            ->join('storefront_campaign_products as cp', 'cp.campaign_id', '=', 'c.id')
            ->where('c.is_active', 1)->where('c.starts_at', '<=', $now)->where('c.ends_at', '>', $now)
            ->whereNull('cp.snapshot_at')
            ->distinct()->pluck('c.storefront_id')->all();

        if ($storefronts === []) {
            $this->line('Nothing to record: every live campaign already has its start quantities.');

            return self::SUCCESS;
        }

        $recorded = CampaignWriter::activate();
        foreach ($storefronts as $id) {
            if (is_numeric($id) && Storefront::query()->whereKey((int) $id)->exists()) {
                $cache->flush((int) $id);
            }
        }
        $this->info("Recorded the starting stock of {$recorded} campaign product(s) on ".count($storefronts).' storefront(s).');

        return self::SUCCESS;
    }
}
