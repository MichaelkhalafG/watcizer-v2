<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Compat\CompatCart;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Since when has each product had its current price? (re-engagement, 2026-10-01)
 *
 * The weekly e-mail may only feature a product whose price has not changed for 14 days (developer,
 * 2026-09-30: no price hold). `updated_at` cannot answer that — every sale moves it — so this keeps
 * `core_price_watch`: the price the shopper pays (`CompatCart::catalogPrice`) and the moment it
 * became that. `refresh()` runs before every planning pass and daily; a changed price restarts the
 * clock.
 *
 * The first `refresh()` has no history to read, so a new row's `since` is the LATEST of:
 *  - the product's creation (the importer sets a price only when it creates a product),
 *  - the start of core's activity log (before it, a legacy edit could have changed the price
 *    unseen), and
 *  - the last price change the activity log recorded for it.
 * That is a lower bound: the price has certainly not changed since then.
 */
final class PriceWatch
{
    /** @return array{added: int, changed: int} */
    public static function refresh(): array
    {
        $logStart = DB::table('core_activity_log')->min('created_at');
        $known = [];
        foreach (DB::table('core_price_watch')->get(['product_id', 'price']) as $raw) {
            $row = Row::cast($raw);
            $known[Row::int($row, 'product_id')] = Row::str($row, 'price');
        }
        $now = now()->toDateTimeString();
        $added = 0;
        $changed = 0;
        foreach (DB::table('catalog_products')->whereNull('deleted_at')->get(['id', 'selling_price', 'sale_price', 'created_at']) as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $price = number_format(CompatCart::catalogPrice(Row::str($row, 'selling_price'), Row::nstr($row, 'sale_price')), 2, '.', '');
            if (! isset($known[$id])) {
                $stamps = array_filter([
                    Row::nstr($row, 'created_at'),
                    is_string($logStart) ? $logStart : null,
                    self::lastLoggedChange($id),
                ]);
                DB::table('core_price_watch')->insert(['product_id' => $id, 'price' => $price, 'since' => $stamps === [] ? $now : max($stamps)]);
                $added++;
            } elseif (number_format((float) $known[$id], 2, '.', '') !== $price) {
                DB::table('core_price_watch')->where('product_id', $id)->update(['price' => $price, 'since' => $now]);
                $changed++;
            }
        }

        return ['added' => $added, 'changed' => $changed];
    }

    /**
     * Products whose price has been the same for at least `$days` days.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    public static function steady(array $productIds, int $days = 14): array
    {
        if ($productIds === []) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0),
            DB::table('core_price_watch')->whereIn('product_id', $productIds)->where('since', '<=', now()->subDays($days))->pluck('product_id')->all(),
        ));
    }

    private static function lastLoggedChange(int $productId): ?string
    {
        $at = DB::table('core_activity_log')->where('subject_type', 'catalog_products')->where('subject_id', $productId)
            ->where(fn (Builder $q) => $q->where('changes', 'like', '%"selling_price"%')->orWhere('changes', 'like', '%"sale_price"%'))
            ->max('created_at');

        return is_string($at) ? $at : null;
    }
}
