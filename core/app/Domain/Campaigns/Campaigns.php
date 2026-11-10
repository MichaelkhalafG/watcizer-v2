<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Timed campaigns — Hot Deals and Flash Sale (the twelve updates, Part 2, 2026-11-05).
 *
 * ONE mechanism, two presentations: a campaign is a product selection with a start and an end on one
 * storefront, shown as a home slider, a page of its own and a countdown. Which of the two looks it
 * takes is one column (`presentation`); nothing else differs, and nothing here branches on it.
 *
 * ── When a campaign is LIVE ───────────────────────────────────────────────────────────────────
 *
 * `is_active = 1` AND `starts_at <= now` AND `now < ends_at`. The end is exclusive and it is the REAL
 * end: at that instant the campaign stops being live for every reader, so its products leave the
 * slider and the page and the countdown leaves the header — there is no grace, no rollover and no
 * restart (the brief, 2.3). At most one campaign of a presentation is live on a storefront at a time
 * ({@see CampaignWriter} refuses overlapping active windows), so "the live Hot Deals campaign" is
 * always one row or none.
 *
 * ── The progress bar is REAL or absent ────────────────────────────────────────────────────────
 *
 * "12 sold · only 13 left" needs three numbers per product, all counted from the product's LAST RESTOCK
 * (Michael, 2026-11-10 — before, from the campaign's start):
 *
 *   start  the units on the shelf right after the last stock ADDITION — the latest ledger movement that adds
 *          units for a real restock ({@see self::RESTOCK_REASONS}: the dashboard's restock, count and
 *          correction, an import, an ERP sync), never units given back by an order (a cancellation, a failed
 *          payment, a release), never the second half of a move between the two buckets. The opening balance
 *          carried over from the old system (`transform`) counts too — it is the oldest addition, so it is the
 *          last one only for a product never restocked since (decision D-47, 2026-11-10: without it 46 of the 48
 *          live campaign products of the scratch copy lose their bar). For a product sold through variants: its
 *          last addition on ANY variant, and the shelf across all its variants and both buckets — the card
 *          shows the product, not one variant.
 *   sold   read from the stock LEDGER: units of order lines sold AFTER that movement, minus the units those
 *          same lines gave back (a cancellation, a failed payment) — `inventory_movements`, reasons `order`
 *          and its releases; a gift line (`promotion_reward`) is not a sale. Stock is one shared warehouse, so
 *          a sale on the other storefront counts: the units are gone either way.
 *   left   the live stock, from the card itself.
 *
 * A product with no stock addition in the ledger at all has NO bar: {@see self::progress()} returns no
 * entry for it and the storefronts render nothing. Nothing is estimated. (The campaign's start snapshot,
 * `storefront_campaign_products.start_*`, is no longer read or written; its columns stay until a later
 * clean-up migration.)
 */
final class Campaigns
{
    public const HOT_DEALS = 'hot_deals';

    public const FLASH_SALE = 'flash_sale';

    /** @var list<string> */
    public const PRESENTATIONS = [self::HOT_DEALS, self::FLASH_SALE];

    /** A selection is typed in a picker, one product at a time; this is a ceiling against a runaway paste. */
    public const MAX_PRODUCTS = 300;

    /** Where a campaign stands at an instant — for the dashboard list, which shows every campaign. */
    public const STATE_OFF = 'off';

    public const STATE_SCHEDULED = 'scheduled';

    public const STATE_LIVE = 'live';

    public const STATE_ENDED = 'ended';

    /**
     * The live campaign of each presentation on a storefront — at most one each.
     *
     * @return array<string, array{id: int, storefront_id: int, name: string, presentation: string, starts_at: string, ends_at: string, activated_at: string|null}|null>
     */
    public static function live(int $storefrontId, ?Carbon $at = null): array
    {
        $now = ($at ?? now())->format('Y-m-d H:i:s');
        $out = array_fill_keys(self::PRESENTATIONS, null);

        foreach (
            DB::table('storefront_campaigns')
                ->where('storefront_id', $storefrontId)->where('is_active', 1)
                ->where('starts_at', '<=', $now)->where('ends_at', '>', $now)
                // The writer refuses two live campaigns of one presentation; if rows written some other
                // way ever overlap, the one that started LAST wins and the answer is still one row.
                ->orderBy('starts_at')->orderBy('id')
                ->get(['id', 'storefront_id', 'name', 'presentation', 'starts_at', 'ends_at', 'activated_at']) as $raw
        ) {
            $row = self::row($raw);
            if (array_key_exists($row['presentation'], $out)) {
                $out[$row['presentation']] = $row;
            }
        }

        return $out;
    }

    /**
     * The next instant a campaign of this storefront STARTS: the earliest `starts_at` after `$at` among its
     * active campaigns, either presentation — null when none is scheduled (the follow-up of 2026-11-06).
     *
     * The storefront apps hold the campaigns answer for a few seconds so a page does not wait for it. An END
     * they can see coming (it is in the answer); a START they could not, so a sale used to reach a page with
     * the next poll. With this instant they drop their copy at that second and ask again. A campaign that is
     * switched off is not a start; one that is switched on later is found by the next poll, as before.
     */
    public static function nextStart(int $storefrontId, ?Carbon $at = null): ?string
    {
        $next = DB::table('storefront_campaigns')
            ->where('storefront_id', $storefrontId)->where('is_active', 1)
            ->where('starts_at', '>', ($at ?? now())->format('Y-m-d H:i:s'))
            ->whereColumn('ends_at', '>', 'starts_at')
            ->min('starts_at');

        return is_string($next) ? $next : null;
    }

    /** @return array{id: int, storefront_id: int, name: string, presentation: string, starts_at: string, ends_at: string, activated_at: string|null}|null */
    public static function liveOne(int $storefrontId, string $presentation, ?Carbon $at = null): ?array
    {
        return self::live($storefrontId, $at)[$presentation] ?? null;
    }

    /**
     * The campaign's products, in the team's order.
     *
     * @return list<int>
     */
    public static function productIds(int $campaignId): array
    {
        $ids = [];
        foreach (DB::table('storefront_campaign_products')->where('campaign_id', $campaignId)->orderBy('position')->orderBy('id')->pluck('product_id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * The ledger reasons that put NEW units on the shelf — the dashboard's five, and the opening balance carried over
     * from the old system (`transform`, D-47); never an order's return.
     */
    public const RESTOCK_REASONS = ['restock', 'manual', 'import', 'adjustment', 'erp_sync', 'transform'];

    /**
     * Start and sold since the last restock, per product of the campaign that has one. No entry = no bar.
     *
     * `left` is not here: it is the live stock, which the storefront card already carries, and reading it
     * a second time would let the two disagree for the length of a cache.
     *
     * @param  array{id: int}  $campaign
     * @return array<int, array{start: int, sold: int}>
     */
    public static function progress(array $campaign): array
    {
        return self::sinceRestock(self::productIds($campaign['id']));
    }

    /**
     * Three reads, whatever the number of products: the last addition of each, the shelf right after it, and the
     * units sold since.
     *
     * @param  list<int>  $productIds
     * @return array<int, array{start: int, sold: int}>
     */
    public static function sinceRestock(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        // 1. The last ADDITION of each product (the highest ledger id: movements are stamped to the second, ids are exact).
        $adds = [];
        foreach (
            DB::table('inventory_movements as m')
                ->whereIn('m.product_id', $productIds)
                ->whereIn('m.reason', self::RESTOCK_REASONS)
                ->where('m.quantity_delta', '>', 0)
                ->whereNotExists(function (Builder $q): void {
                    // the second half of a move between the buckets (InventoryController::moveBetweenBuckets — one
                    // transaction, the minus first, the same reason and note): units that were already on the shelf
                    $q->select(DB::raw('1'))->from('inventory_movements as o')
                        ->whereColumn('o.product_id', 'm.product_id')
                        ->whereRaw('o.variant_id <=> m.variant_id')
                        ->whereColumn('o.reason', 'm.reason')
                        ->whereColumn('o.bucket', '<>', 'm.bucket')
                        ->whereRaw('o.quantity_delta = -m.quantity_delta')
                        ->whereRaw('o.note <=> m.note')
                        // ids are UNSIGNED: never `m.id - 5` (out of range for the first ledger rows) — add on the other side
                        ->whereColumn('o.id', '<', 'm.id')->whereRaw('o.id + 5 >= m.id');
                })
                ->groupBy('m.product_id')
                ->selectRaw('m.product_id, MAX(m.id) AS add_id')
                ->get() as $raw
        ) {
            $adds[Row::int($raw, 'product_id')] = Row::int($raw, 'add_id');
        }
        if ($adds === []) {
            return [];
        }
        // each product's own ledger position: `$op` '<=' up to its addition, '>' after it
        $scope = function (Builder $query, string $alias, string $op) use ($adds): void {
            $query->where(function (Builder $any) use ($adds, $alias, $op): void {
                foreach ($adds as $productId => $addId) {
                    $any->orWhere(fn (Builder $one) => $one->where($alias.'.product_id', $productId)->where($alias.'.id', $op, $addId));
                }
            });
        };

        // 2. The shelf right after it: every stock line's (variant × bucket) balance at its last movement up to then.
        $lines = DB::table('inventory_movements as i');
        $scope($lines, 'i', '<=');
        $lines->groupBy('i.product_id', 'i.variant_id', 'i.bucket')->selectRaw('i.product_id, MAX(i.id) AS last_id');
        $shelf = [];
        foreach (
            DB::table('inventory_movements as b')->joinSub($lines, 'x', 'x.last_id', '=', 'b.id')
                ->groupBy('x.product_id')->selectRaw('x.product_id, SUM(b.quantity_after) AS shelf')->get() as $raw
        ) {
            $shelf[Row::int($raw, 'product_id')] = max(0, (int) (Row::nfloat($raw, 'shelf') ?? 0.0));
        }

        /*
         * 3. Sold = the units of every ORDER LINE whose sale (`reason = order`) was written AFTER the addition, net of
         * whatever that same line gave back or took again later:
         * `order` −q, `order_cancel` / `payment_failed` / `reinstate_cancel` +q, `order_reinstate` −q.
         * Summing the signed deltas of the line and negating gives the units that are really gone.
         * A gift line (`promotion_reward`) is not a sale and never joins: `s.reason` is `order` only.
         */
        $soldQuery = DB::table('inventory_movements as s')
            ->join('inventory_movements as m', function (JoinClause $join): void {
                $join->on('m.reference_id', '=', 's.reference_id')
                    ->on('m.reference_line_id', '=', 's.reference_line_id')
                    ->where('m.reference_type', '=', 'orders')
                    ->whereIn('m.reason', ['order', 'order_cancel', 'payment_failed', 'order_reinstate', 'reinstate_cancel']);
            })
            ->where('s.reference_type', 'orders')->where('s.reason', 'order');
        $scope($soldQuery, 's', '>');
        $sold = [];
        foreach ($soldQuery->groupBy('s.product_id')->selectRaw('s.product_id, -SUM(m.quantity_delta) AS sold')->get() as $raw) {
            $sold[Row::int($raw, 'product_id')] = max(0, (int) (Row::nfloat($raw, 'sold') ?? 0.0));
        }

        $out = [];
        foreach (array_keys($adds) as $productId) {
            $start = $shelf[$productId] ?? 0;
            if ($start > 0) {
                $out[$productId] = ['start' => $start, 'sold' => $sold[$productId] ?? 0];
            }
        }

        return $out;
    }

    /** off | scheduled | live | ended — what the dashboard list says about a row. */
    public static function state(bool $active, string $startsAt, string $endsAt, ?Carbon $at = null): string
    {
        $now = ($at ?? now())->format('Y-m-d H:i:s');

        return match (true) {
            $endsAt <= $now => self::STATE_ENDED,
            ! $active => self::STATE_OFF,
            $startsAt > $now => self::STATE_SCHEDULED,
            default => self::STATE_LIVE,
        };
    }

    /**
     * @return array{id: int, storefront_id: int, name: string, presentation: string, starts_at: string, ends_at: string, activated_at: string|null}
     */
    private static function row(object $raw): array
    {
        $raw = Row::cast($raw);

        return [
            'id' => Row::int($raw, 'id'),
            'storefront_id' => Row::int($raw, 'storefront_id'),
            'name' => Row::str($raw, 'name'),
            'presentation' => Row::str($raw, 'presentation'),
            'starts_at' => Row::str($raw, 'starts_at'),
            'ends_at' => Row::str($raw, 'ends_at'),
            'activated_at' => Row::nstr($raw, 'activated_at'),
        ];
    }
}
