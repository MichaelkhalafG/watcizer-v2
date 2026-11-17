<?php

namespace App\Console\Commands;

use App\Feeds\PublicFeed;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * WRITES each active storefront's PUBLIC product feed file (PublicFeed, 2026-11-17) — read-only data for assistants,
 * served at /feeds/products.json on the shop and linked from its /llms.txt. Hourly at :50 on the schedule. Writes only
 * files under feeds.path, never the database.
 *
 *   php artisan feeds:public                            # every active storefront
 *   php artisan feeds:public --storefront=brandfashion  # just one
 */
final class FeedsPublicCommand extends Command
{
    protected $signature = 'feeds:public {--storefront=* : storefront codes (default: every active one)}';

    protected $description = "Write the storefronts' public product feed files (read-only data for assistants)";

    public function handle(PublicFeed $feed): int
    {
        $codes = array_values(array_filter(array_map(fn (mixed $c): string => Coerce::str($c), (array) $this->option('storefront'))));
        if ($codes === []) {
            $codes = array_map(fn (mixed $c): string => Coerce::str($c), DB::table('storefronts')->where('is_active', 1)->orderBy('id')->pluck('code')->all());
        }
        $status = self::SUCCESS;
        foreach ($codes as $code) {
            try {
                $r = $feed->generate($code);
                $this->info(sprintf('%s: %d products, %d bytes', $code, $r['products'], $r['bytes']));
            } catch (Throwable $e) {
                $this->error("{$code}: ".$e->getMessage());
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
