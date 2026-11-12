<?php

namespace App\Console\Commands;

use App\Feeds\GoogleFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * WRITES each configured storefront's Google Merchant (Shopping) feed file (`GoogleFeed`,
 * config/feeds.php section `google`, 2026-11-11 D-9). Hourly at :45 on the schedule; the URL only
 * serves what this wrote. Same shape as `feeds:meta`:
 *
 *   php artisan feeds:google                             # every storefront with a feed configured
 *   php artisan feeds:google --storefront=brandfashion   # just one; an unconfigured one is an error
 */
final class FeedsGoogleCommand extends Command
{
    protected $signature = 'feeds:google {--storefront=* : storefront codes (default: every one with a feed configured)}';

    protected $description = "Write the storefronts' Google Merchant (Shopping) feed files";

    public function handle(GoogleFeed $feed): int
    {
        $asked = [];
        foreach ((array) $this->option('storefront') as $code) {
            if (is_string($code) && $code !== '') {
                $asked[] = $code;
            }
        }
        $status = self::SUCCESS;
        if ($asked === []) {
            foreach (GoogleFeed::unusable() as $code) {
                $this->error("storefront {$code}: a token is set but the Google feed cannot run (40 letters/digits, an active storefront, a domain) — nothing written.");
                Log::channel('scheduled')->warning("feeds:google storefront={$code} token set but unusable, nothing written");
                $status = self::FAILURE;
            }
        }
        $codes = $asked === [] ? GoogleFeed::configured() : $asked;
        if ($codes === []) {
            $this->info('No storefront has a Google feed configured — nothing written.');

            return $status;
        }
        foreach ($codes as $code) {
            if (GoogleFeed::config($code) === null) {
                $this->error("storefront {$code}: no Google feed configured (config/feeds.php, its token) — nothing written.");
                $status = self::FAILURE;

                continue;
            }
            $r = $feed->generate($code);
            Log::channel('scheduled')->info(sprintf('feeds:google storefront=%s products=%d bytes=%d file=%s ms=%d no_mpn=%d over_limit=%d',
                $code, $r['products'], $r['bytes'], $r['changed'] ? 'replaced' : 'unchanged', (int) round($r['ms']), $r['no_mpn'], count($r['over_limit'])));
            $this->line(sprintf('storefront %s: %d products (%d without an mpn), %.1f KB, %s, in %d ms',
                $code, $r['products'], $r['no_mpn'], $r['bytes'] / 1024, $r['changed'] ? 'written' : 'unchanged (file kept)', (int) round($r['ms'])));
            foreach ($r['over_limit'] as $o) {
                $this->warn(sprintf('  product %d: %s is %d characters, over Google\'s %d — written whole, not cut', $o['id'], $o['field'], $o['length'], $o['limit']));
            }
        }

        return $status;
    }
}
