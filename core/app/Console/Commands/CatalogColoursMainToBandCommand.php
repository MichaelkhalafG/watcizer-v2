<?php

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLog;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Move non-watch products' colours from `main` to `band` (2026-09-27).
 *
 * The dashboard asked every non-watch family (bags, wallets, fashion, electronics, perfume) for
 * a "main" colour, and no storefront surface reads `main` — so every colour the team entered on
 * such a product went nowhere. `band` is the role the storefront shows as the product's colour,
 * and the dashboard now asks these families for it (config/catalog.php `color_roles`). This moves
 * what is already there instead of having it re-entered by hand.
 *
 * Per product: each `main` colour is appended to the product's `band` colours in its own order
 * (after any band colours already there, so an existing primary stays primary), a colour that is
 * already a band colour is not duplicated, and the `main` rows are removed. One activity-log entry
 * per product changed. Watch products are never touched: a watch's colours are its dial and band,
 * and any `main` row on one is reported for a person to look at.
 *
 *   php artisan catalog:colours-main-to-band            # dry run: what would move, nothing written
 *   php artisan catalog:colours-main-to-band --apply    # move it
 */
final class CatalogColoursMainToBandCommand extends Command
{
    protected $signature = 'catalog:colours-main-to-band
        {--apply : move the colours; without it nothing is written}';

    protected $description = "Move non-watch products' colours from the unused 'main' role to 'band', the one the storefront shows";

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $rows = DB::table('catalog_product_color as pc')
            ->join('catalog_products as p', 'p.id', '=', 'pc.product_id')
            ->where('pc.role', 'main')
            ->orderBy('pc.product_id')->orderBy('pc.position')->orderBy('pc.color_id')
            ->get(['pc.product_id', 'pc.color_id', 'pc.position', 'p.family']);

        $byProduct = [];
        $watches = [];
        foreach ($rows as $raw) {
            $productId = Row::int($raw, 'product_id');
            if (Row::nstr($raw, 'family') === 'watch') {
                $watches[$productId] = true;

                continue;
            }
            $byProduct[$productId][] = Row::int($raw, 'color_id');
        }

        $colourCount = array_sum(array_map('count', $byProduct));
        $this->info(($apply ? 'Moving' : 'Would move').' '.$colourCount.' colour(s) on '.count($byProduct).' non-watch product(s) from main to band.');
        if ($watches !== []) {
            $this->warn(count($watches).' WATCH product(s) carry a main colour and are left alone for a person to check: #'.implode(', #', array_keys($watches)));
        }
        if (! $apply || $byProduct === []) {
            if (! $apply && $byProduct !== []) {
                $this->line('Products: #'.implode(', #', array_slice(array_keys($byProduct), 0, 50)).(count($byProduct) > 50 ? ' …' : ''));
                $this->line('Nothing written. Run again with --apply to move them.');
            }

            return self::SUCCESS;
        }

        $moved = 0;
        foreach ($byProduct as $productId => $colourIds) {
            DB::transaction(function () use ($productId, $colourIds, &$moved): void {
                $band = array_map(
                    fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0),
                    DB::table('catalog_product_color')->where('product_id', $productId)->where('role', 'band')
                        ->orderBy('position')->orderBy('color_id')->pluck('color_id')->all(),
                );
                $before = $band;
                $max = DB::table('catalog_product_color')->where('product_id', $productId)->where('role', 'band')->max('position');
                $next = is_numeric($max) ? (int) $max + 1 : 0;
                foreach ($colourIds as $colourId) {
                    if (! in_array($colourId, $band, true)) {
                        DB::table('catalog_product_color')->insert([
                            'product_id' => $productId, 'color_id' => $colourId, 'role' => 'band', 'position' => $next++,
                        ]);
                        $band[] = $colourId;
                    }
                }
                DB::table('catalog_product_color')->where('product_id', $productId)->where('role', 'main')->delete();

                ActivityLog::record(
                    'catalog_products', $productId, ActivityLog::UPDATED,
                    ['main_colors' => $colourIds, 'band_colors' => $before],
                    ['main_colors' => [], 'band_colors' => $band],
                    'colours: main → band',
                );
                $moved++;
            });
        }

        $this->info("Done: {$moved} product(s) updated.");

        return self::SUCCESS;
    }
}
