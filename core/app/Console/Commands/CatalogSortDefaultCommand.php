<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * catalog:sort-default — EVERY PRODUCT'S SORT VALUE BECOMES 1 (Michael, 2026-11-24, D6). The team set non-featured products
 * to 1 by hand so that featured ones (0) came first. Nothing is featured today and every 0 was the old default, so every
 * row of `storefront_product` whose sort value is not 1 becomes 1; from now on the team sets 0 only on what they want first
 * (the column's default is 1 since the migration of the same day).
 *
 * WRITES `storefront_product.sort_order` (only that column - not updated_at, so the row keeps its own history) and its
 * own backup table. Four passes, as every data step of this project:
 *   (default)  DRY RUN: the counts per shop and per current value. Writes nothing.
 *   --apply    each row is keyed by its id AND (shop, product id), backed up with its value, and written only if its value
 *              is still the one read (a team edit in between is left alone and counted).
 *   --apply    again: nothing to change.
 *   --undo     every backed-up row back to its value - only where it is still 1 (a product the team set since is left as
 *              the team set it, and listed). The backup is dropped once every row is back.
 * The shops' listing caches are flushed after a write.
 */
final class CatalogSortDefaultCommand extends Command
{
    public const BACKUP = 'bk_sort_20261124_storefront_product';

    protected $signature = 'catalog:sort-default {--apply : write (default: a dry run)} {--undo : put every backed-up row back}';

    protected $description = 'Set every product sort value to 1 (0 stays for what the team features from now on); dry run unless --apply';

    /** The backup table's CREATE, public so a test can make it on another connection first (Tests\Support\SideDdl). */
    public static function backupDdl(): string
    {
        return 'CREATE TABLE IF NOT EXISTS '.self::BACKUP.' (sp_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, storefront_id BIGINT UNSIGNED NOT NULL, product_id BIGINT UNSIGNED NOT NULL, sort_before INT NOT NULL, created_at TIMESTAMP NULL)';
    }

    public function handle(StorefrontCache $cache): int
    {
        if ((bool) $this->option('undo')) {
            return $this->undo($cache);
        }
        $apply = (bool) $this->option('apply');

        $counts = DB::table('storefront_product')->where('sort_order', '<>', 1)
            ->selectRaw("storefront_id, CASE WHEN sort_order = 0 THEN '0' ELSE 'other' END AS v, COUNT(*) AS n")
            ->groupBy('storefront_id', 'v')->orderBy('storefront_id')->orderBy('v')->get();
        $total = 0;
        foreach ($counts as $raw) {
            $r = Row::cast($raw);
            $total += Row::int($r, 'n');
            $this->line(sprintf('  shop %d: %d row(s) at %s', Row::int($r, 'storefront_id'), Row::int($r, 'n'), Row::str($r, 'v') === '0' ? '0' : 'another value than 0 or 1'));
        }
        $this->line(sprintf('  already 1: %d row(s)', DB::table('storefront_product')->where('sort_order', 1)->count()));
        if ($total === 0) {
            $this->line('NOTHING TO DO: every product is 1 already, 0 rows changed.');

            return self::SUCCESS;
        }
        if (! $apply) {
            $this->line("DRY RUN: {$total} row(s) would become 1. Nothing was written; run again with --apply.");

            return self::SUCCESS;
        }

        DB::statement(self::backupDdl());
        $changed = 0;
        $moved = 0;
        $shops = [];
        $last = 0;
        while (true) {
            $rows = DB::table('storefront_product')->where('sort_order', '<>', 1)->where('id', '>', $last)
                ->orderBy('id')->limit(1000)->get(['id', 'storefront_id', 'product_id', 'sort_order']);
            if ($rows->isEmpty()) {
                break;
            }
            DB::transaction(function () use ($rows, &$changed, &$moved, &$shops, &$last): void {
                foreach ($rows as $raw) {
                    $r = Row::cast($raw);
                    $id = Row::int($r, 'id');
                    $last = $id;
                    $sf = Row::int($r, 'storefront_id');
                    $product = Row::int($r, 'product_id');
                    $before = Row::int($r, 'sort_order');
                    DB::table(self::BACKUP)->insertOrIgnore(['sp_id' => $id, 'storefront_id' => $sf, 'product_id' => $product, 'sort_before' => $before, 'created_at' => now()]);
                    $n = DB::table('storefront_product')->where('id', $id)->where('storefront_id', $sf)->where('product_id', $product)
                        ->where('sort_order', $before)->update(['sort_order' => 1]);
                    if ($n === 1) {
                        $changed++;
                        $shops[$sf] = ($shops[$sf] ?? 0) + 1;
                    } else {
                        $moved++;
                    }
                }
            });
        }
        foreach (array_keys($shops) as $sf) {
            $cache->flush($sf);
        }
        ksort($shops);
        $this->line(sprintf('APPLIED: %d row(s) set to 1 (%s); %d left as found (changed by someone between the read and the write).',
            $changed, implode(', ', array_map(fn (int $sf, int $n): string => "shop {$sf}: {$n}", array_keys($shops), $shops)), $moved));

        return self::SUCCESS;
    }

    private function undo(StorefrontCache $cache): int
    {
        if (! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            $this->line('NOTHING TO UNDO: no backup table.');

            return self::SUCCESS;
        }
        $restored = 0;
        $kept = [];
        $shops = [];
        foreach (DB::table(self::BACKUP)->orderBy('sp_id')->cursor() as $raw) {
            $b = Row::cast($raw);
            $id = Row::int($b, 'sp_id');
            $sf = Row::int($b, 'storefront_id');
            $n = DB::table('storefront_product')->where('id', $id)->where('storefront_id', $sf)->where('product_id', Row::int($b, 'product_id'))
                ->where('sort_order', 1)->update(['sort_order' => Row::int($b, 'sort_before')]);
            if ($n === 1) {
                $restored++;
                $shops[$sf] = true;
            } elseif (DB::table('storefront_product')->where('id', $id)->where('sort_order', Row::int($b, 'sort_before'))->doesntExist()) {
                $kept[] = sprintf('shop %d product %d', $sf, Row::int($b, 'product_id'));
            }
        }
        foreach (array_keys($shops) as $sf) {
            $cache->flush(Coerce::int($sf));
        }
        if ($kept === []) {
            DB::statement('DROP TABLE IF EXISTS '.self::BACKUP);
            $this->line("UNDONE: {$restored} row(s) back to their value; the backup is dropped.");

            return self::SUCCESS;
        }
        $this->line(sprintf('UNDONE: %d row(s) back; %d left as the team set them since (the backup is kept): %s', $restored, count($kept), implode('; ', array_slice($kept, 0, 50))));

        return self::SUCCESS;
    }
}
