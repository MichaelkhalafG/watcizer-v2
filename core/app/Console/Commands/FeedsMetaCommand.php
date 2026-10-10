<?php

namespace App\Console\Commands;

use App\Feeds\MetaFeed;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * WRITES each configured storefront's Meta catalogue feed file (`MetaFeed`, config/feeds.php).
 * Hourly at :40 on the schedule (routes/console.php); the URL only serves what this wrote.
 *
 *   php artisan feeds:meta                          # every storefront with a feed configured
 *   php artisan feeds:meta --storefront=watchizer   # just one; an unconfigured one is an error
 *
 * Reports what it wrote, the stock split, and every value over Meta's field limits — written whole,
 * never cut, so a person decides.
 */
final class FeedsMetaCommand extends Command
{
    protected $signature = 'feeds:meta {--storefront=* : storefront codes (default: every one with a feed configured)}';

    protected $description = "Write the storefronts' Meta catalogue feed files";

    public function handle(MetaFeed $feed): int
    {
        $asked = [];
        foreach ((array) $this->option('storefront') as $code) {
            if (is_string($code) && $code !== '') {
                $asked[] = $code;
            }
        }
        $status = self::SUCCESS;
        if ($asked === []) {
            // A token that is set but unusable is a fault — somebody meant the feed to be on. One line
            // per run until it is fixed. A storefront with NO token is simply off: nothing logged.
            foreach (MetaFeed::unusable() as $code) {
                $this->error("storefront {$code}: a token is set but the feed cannot run (40 letters/digits, an active storefront, a domain) — nothing written.");
                Log::channel('scheduled')->warning("feeds:meta storefront={$code} token set but unusable (needs 40 letters/digits, an active storefront, a domain), nothing written");
                $status = self::FAILURE;
            }
        }
        $codes = $asked === [] ? MetaFeed::configured() : $asked;
        if ($codes === []) {
            // Nothing switched on: the hourly run does nothing and writes nothing (no empty log lines).
            $this->info('No storefront has a Meta feed configured — nothing written.');

            return $status;
        }

        foreach ($codes as $code) {
            if (MetaFeed::config($code) === null) {
                $this->error("storefront {$code}: no Meta feed configured (config/feeds.php, its token) — nothing written.");
                $status = self::FAILURE;

                continue;
            }
            $r = $feed->generate($code);
            // The trace a scheduled run leaves (config/logging.php `scheduled`): one line per storefront.
            Log::channel('scheduled')->info(sprintf('feeds:meta storefront=%s products=%d bytes=%d file=%s ms=%d over_limit=%d',
                $code, $r['products'], $r['bytes'], $r['changed'] ? 'replaced' : 'unchanged', (int) round($r['ms']), count($r['over_limit'])));
            $this->line(sprintf('storefront %s: %d products, %.1f KB, %s, in %d ms',
                $code, $r['products'], $r['bytes'] / 1024, $r['changed'] ? 'written' : 'unchanged (file kept)', (int) round($r['ms'])));
            $this->line(sprintf('  stock: %d express, %d market only, %d out of stock', $r['stock']['express'], $r['stock']['market'], $r['stock']['out']));
            foreach ($r['over_limit'] as $o) {
                $this->warn(sprintf('  product %d: %s is %d characters, over Meta\'s %d by %d — written whole, not cut',
                    $o['id'], $o['field'], $o['length'], $o['limit'], $o['length'] - $o['limit']));
            }
        }

        return $status;
    }
}
