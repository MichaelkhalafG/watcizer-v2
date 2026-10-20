<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Inventory\InventoryService;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The numbers the dashboard home page draws (2026-10-21).
 *
 * ── The rule every query here obeys ─────────────────────────────────────────────────────────
 *
 * This page loads every time somebody opens the dashboard of a LIVE shop. A slow query here is a slow
 * dashboard for the person running the business, which matters more than any chart. So:
 *
 *  • every order query is bounded by an INDEX RANGE — a date window on `orders_created_at_index`, or the
 *    open-status range on `orders_status_sf_created_idx` (added by this release). None of them scans the table.
 *  • the window is {@see self::WINDOW_DAYS} days and the series asks for {@see self::SERIES_DAYS}, five more,
 *    so the "is today normal" baseline comes out of the SAME rows rather than a second query.
 *  • nothing here is cached. A dashboard that shows a number from ten minutes ago, with no way to tell, is
 *    worse than one that is honestly a little slower — and the measured cost does not justify a cache.
 *
 * Measured on a scratch copy carrying 250,000 synthetic orders and 350,000 order lines (the production-shaped
 * catalogue, a synthetic order history — the local database holds far too few orders to time anything):
 * sales 16 ms, payments 12 ms, queue 34 ms, top products 31 ms, restock 26 ms — about 120 ms for all five,
 * worst case ("all shops"). Per storefront the queue drops to 6 ms. See `RUN_20261021.md`.
 *
 * ── Money has ONE definition here, and it is not a new one ──────────────────────────────────
 *
 * `ordered` and `collected` are exactly the two sums the customers screen already uses
 * ({@see \App\Domain\Customers\Customers}), so a figure on this page and a figure on that one cannot drift:
 *
 *   ordered    SUM(total) WHERE status <> 'cancelled'          — what the shop sold
 *   collected  SUM(total) WHERE status IN (delivered,completed) — what it has actually delivered
 *
 * The gap between them is work in progress, and `cancelled` is reported beside them rather than folded into
 * either. The old home page refused to show revenue at all because the definition was open; it is not open
 * any more, so it is shown.
 */
final class DashboardMetrics
{
    /** The window every "last N days" figure on the page uses. */
    public const WINDOW_DAYS = 30;

    /** Five more than the window: four earlier same-weekdays for today's baseline, out of the same query. */
    public const SERIES_DAYS = 35;

    public const TOP_LIMIT = 5;

    /** How deep the one sales aggregation goes — enough to find the out-of-stock sellers inside it. */
    public const SOLD_DEPTH = 50;

    /** Orders that are still somebody's job. Terminal states (delivered, completed, cancelled) are not. */
    public const OPEN_STATUSES = ['pending', 'processing', 'shipped'];

    /**
     * Today, the window, and the day-by-day series — ONE query.
     *
     * @param  list<int>|null  $scope  storefront ids, or null for every shop
     * @return array{
     *     today: array{orders: int, ordered: float},
     *     baseline: array{orders: float, ordered: float, weeks: int},
     *     window: array{orders: int, ordered: float, collected: float, cancelled: float, cancelled_orders: int, aov: float},
     *     series: list<array{date: string, orders: int, ordered: float}>,
     * }
     */
    public function sales(?array $scope): array
    {
        $from = Carbon::now()->startOfDay()->subDays(self::SERIES_DAYS - 1);
        $rows = $this->scoped(DB::table('orders as o'), $scope)
            ->where('o.created_at', '>=', $from)
            ->groupBy(DB::raw('DATE(o.created_at)'))
            ->orderBy(DB::raw('DATE(o.created_at)'))
            ->get([
                DB::raw('DATE(o.created_at) as d'),
                DB::raw('COUNT(*) as orders'),
                DB::raw("COALESCE(SUM(CASE WHEN o.status <> 'cancelled' THEN o.total_price_for_order ELSE 0 END), 0) as ordered"),
                DB::raw("COALESCE(SUM(CASE WHEN o.status IN ('delivered','completed') THEN o.total_price_for_order ELSE 0 END), 0) as collected"),
                DB::raw("COALESCE(SUM(CASE WHEN o.status = 'cancelled' THEN o.total_price_for_order ELSE 0 END), 0) as cancelled"),
                DB::raw("COALESCE(SUM(CASE WHEN o.status = 'cancelled' THEN 1 ELSE 0 END), 0) as cancelled_orders"),
            ]);

        /** @var array<string, array{orders: int, ordered: float, collected: float, cancelled: float, cancelled_orders: int}> $byDay */
        $byDay = [];
        foreach ($rows as $row) {
            $byDay[Row::str($row, 'd')] = [
                'orders' => Row::int($row, 'orders'),
                'ordered' => (float) Row::str($row, 'ordered'),
                'collected' => (float) Row::str($row, 'collected'),
                'cancelled' => (float) Row::str($row, 'cancelled'),
                'cancelled_orders' => Row::int($row, 'cancelled_orders'),
            ];
        }

        $today = Carbon::now()->startOfDay();
        $windowFrom = $today->copy()->subDays(self::WINDOW_DAYS - 1);

        // The series the page draws: every day in the window, including the ones with no orders — a gap in a
        // bar chart must be a zero-height bar, not a missing bar, or a quiet week looks like a short month.
        $series = [];
        $window = ['orders' => 0, 'ordered' => 0.0, 'collected' => 0.0, 'cancelled' => 0.0, 'cancelled_orders' => 0];
        for ($cursor = $windowFrom->copy(); $cursor <= $today; $cursor->addDay()) {
            $key = $cursor->toDateString();
            $day = $byDay[$key] ?? ['orders' => 0, 'ordered' => 0.0, 'collected' => 0.0, 'cancelled' => 0.0, 'cancelled_orders' => 0];
            $series[] = ['date' => $key, 'orders' => $day['orders'], 'ordered' => round($day['ordered'], 2)];
            $window['orders'] += $day['orders'];
            $window['ordered'] += $day['ordered'];
            $window['collected'] += $day['collected'];
            $window['cancelled'] += $day['cancelled'];
            $window['cancelled_orders'] += $day['cancelled_orders'];
        }

        /*
         * IS TODAY NORMAL? — the only honest comparison a shop can make before the day is over.
         *
         * Against yesterday is wrong (a Friday is not a Tuesday) and against "the daily average" is wrong for
         * the same reason. This is the same weekday, up to four weeks back, averaged over the weeks that
         * actually have data — which is why the series asks for 35 days and not 30. A shop with less than one
         * week of history gets `weeks: 0` and the page says so instead of printing a meaningless percentage.
         */
        $past = [];
        for ($w = 1; $w <= 4; $w++) {
            $key = $today->copy()->subWeeks($w)->toDateString();
            if (isset($byDay[$key])) {
                $past[] = $byDay[$key];
            }
        }
        $weeks = count($past);
        $baseline = [
            'orders' => $weeks === 0 ? 0.0 : array_sum(array_column($past, 'orders')) / $weeks,
            'ordered' => $weeks === 0 ? 0.0 : array_sum(array_column($past, 'ordered')) / $weeks,
            'weeks' => $weeks,
        ];

        $todayRow = $byDay[$today->toDateString()] ?? ['orders' => 0, 'ordered' => 0.0];

        return [
            'today' => ['orders' => $todayRow['orders'], 'ordered' => round($todayRow['ordered'], 2)],
            'baseline' => ['orders' => round($baseline['orders'], 2), 'ordered' => round($baseline['ordered'], 2), 'weeks' => $weeks],
            'window' => [
                'orders' => $window['orders'],
                'ordered' => round($window['ordered'], 2),
                'collected' => round($window['collected'], 2),
                'cancelled' => round($window['cancelled'], 2),
                'cancelled_orders' => $window['cancelled_orders'],
                'aov' => $window['orders'] === 0 ? 0.0 : round($window['ordered'] / $window['orders'], 2),
            ],
            'series' => $series,
        ];
    }

    /**
     * The work queue: orders nobody has finished, and how old the oldest is.
     *
     * NOT windowed, on purpose. A pending order from six weeks ago is exactly the one worth seeing, and a
     * 30-day cut-off would hide it. That is affordable because `orders_status_sf_created_idx` makes this a
     * covering range over the open statuses rather than a scan of every order ever placed.
     *
     * @param  list<int>|null  $scope
     * @return array{total: int, by_status: list<array{status: string, orders: int}>, oldest: ?string, oldest_days: ?int}
     */
    public function queue(?array $scope): array
    {
        $rows = $this->scoped(DB::table('orders as o'), $scope)
            ->whereIn('o.status', self::OPEN_STATUSES)
            ->groupBy('o.status')
            ->get(['o.status', DB::raw('COUNT(*) as orders'), DB::raw('MIN(o.created_at) as oldest')]);

        $byStatus = [];
        $total = 0;
        $oldest = null;
        foreach ($rows as $row) {
            $byStatus[Row::str($row, 'status')] = Row::int($row, 'orders');
            $total += Row::int($row, 'orders');
            $at = Row::nstr($row, 'oldest');
            if ($at !== null && ($oldest === null || $at < $oldest)) {
                $oldest = $at;
            }
        }

        // In the queue's own order, not the database's, and a status with none still shows as 0: "0 waiting
        // to be packed" is an answer, a missing row is a question.
        $out = [];
        foreach (self::OPEN_STATUSES as $status) {
            $out[] = ['status' => $status, 'orders' => $byStatus[$status] ?? 0];
        }

        return [
            'total' => $total,
            'by_status' => $out,
            'oldest' => $oldest,
            // an int: a dashboard says "11 days", never "11.33 days"
            'oldest_days' => $oldest === null ? null : (int) Carbon::parse($oldest)->startOfDay()->diffInDays(Carbon::now()->startOfDay()),
        ];
    }

    /**
     * How the window's orders were paid for — the cash-on-delivery exposure, in one line.
     *
     * @param  list<int>|null  $scope
     * @return list<array{method: string, orders: int, value: float}>
     */
    public function payments(?array $scope): array
    {
        $rows = $this->scoped(DB::table('orders as o'), $scope)
            ->where('o.created_at', '>=', Carbon::now()->startOfDay()->subDays(self::WINDOW_DAYS - 1))
            ->where('o.status', '<>', 'cancelled')
            ->groupBy('o.payment_method')
            ->orderByDesc(DB::raw('COUNT(*)'))
            ->get(['o.payment_method', DB::raw('COUNT(*) as orders'), DB::raw('COALESCE(SUM(o.total_price_for_order), 0) as value')]);

        $out = [];
        foreach ($rows as $row) {
            $method = Row::nstr($row, 'payment_method') ?? '';
            $out[] = [
                'method' => $method === '' ? 'unknown' : $method,
                'orders' => Row::int($row, 'orders'),
                'value' => round((float) Row::str($row, 'value'), 2),
            ];
        }

        return $out;
    }

    /**
     * WHAT SOLD IN THE WINDOW — one aggregation, two answers.
     *
     * `topProducts` and `restock` used to ask the same expensive question twice (group `order_items` over the
     * window, join `orders`); measured at 250,000 orders that was about 30 ms and two queries thrown away. The
     * aggregation now runs ONCE, deep enough to answer both:
     *
     *   • the best sellers        — the first {@see self::TOP_LIMIT} rows
     *   • the restock list        — those same sellers that have nothing left on either shelf
     *
     * So "reorder" means **"a product among the period's {@see self::SOLD_DEPTH} best sellers that is now out of
     * stock"**, not "anything that sold once and ran out" — which is the better rule anyway: a product that sold
     * a single unit and is empty is not what anybody reorders first.
     *
     * Gift lines (`is_reward`) are excluded throughout: a product given away by a promotion did not sell.
     *
     * @param  list<int>|null  $scope
     * @return array{
     *     top: list<array{product_id: int, name: string, units: int, value: float, in_stock: bool}>,
     *     restock: list<array{product_id: int, name: string, units: int}>,
     * }
     */
    public function sold(?array $scope): array
    {
        $rows = $this->soldInWindow($scope)
            ->orderByDesc(DB::raw('SUM(oi.quantity)'))
            ->limit(self::SOLD_DEPTH)
            ->get(['oi.product_id', DB::raw('SUM(oi.quantity) as units'), DB::raw('COALESCE(SUM(oi.total_price), 0) as value')]);

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = Row::int($row, 'product_id');
        }
        $products = $this->productFacts($ids);

        $top = [];
        $restock = [];
        foreach ($rows as $row) {
            $id = Row::int($row, 'product_id');
            $fact = $products[$id] ?? null;
            // a product deleted or switched off since it sold is not a restocking decision, and not a
            // best seller worth linking to either
            if ($fact === null) {
                continue;
            }
            if (count($top) < self::TOP_LIMIT) {
                $top[] = [
                    'product_id' => $id,
                    'name' => $fact['name'],
                    'units' => Row::int($row, 'units'),
                    'value' => round((float) Row::str($row, 'value'), 2),
                    'in_stock' => $fact['in_stock'],
                ];
            }
            if (! $fact['in_stock'] && count($restock) < self::TOP_LIMIT) {
                $restock[] = ['product_id' => $id, 'name' => $fact['name'], 'units' => Row::int($row, 'units')];
            }
        }

        return ['top' => $top, 'restock' => $restock];
    }

    /**
     * Units sold per product inside the window, for the storefronts in scope — the shared half of
     * {@see self::topProducts} and {@see self::restock}.
     *
     * @param  list<int>|null  $scope
     */
    private function soldInWindow(?array $scope): Builder
    {
        return $this->scoped(DB::table('order_items as oi')->join('orders as o', 'o.id', '=', 'oi.order_id'), $scope)
            ->where('o.created_at', '>=', Carbon::now()->startOfDay()->subDays(self::WINDOW_DAYS - 1))
            ->where('o.status', '<>', 'cancelled')
            ->where('oi.is_reward', 0)
            ->groupBy('oi.product_id');
    }

    /**
     * A name and a stock verdict for a handful of product ids. The name is the ENGLISH title where there is
     * one — this is a dashboard whose operators read both, and a product list is scanned, not read.
     *
     * @param  list<int>  $ids
     * @return array<int, array{name: string, in_stock: bool}>
     */
    private function productFacts(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $columns = InventoryService::columns();
        $rows = DB::table('catalog_products as p')
            ->leftJoin('catalog_product_translations as en', function (JoinClause $join): void {
                $join->on('en.product_id', '=', 'p.id')->where('en.locale', '=', 'en');
            })
            ->leftJoin('catalog_product_translations as ar', function (JoinClause $join): void {
                $join->on('ar.product_id', '=', 'p.id')->where('ar.locale', '=', 'ar');
            })
            ->whereIn('p.id', $ids)
            ->get(['p.id', 'p.'.$columns['express'].' as express', 'p.'.$columns['market'].' as market', 'en.title as title_en', 'ar.title as title_ar']);

        $out = [];
        foreach ($rows as $row) {
            $name = trim(Row::nstr($row, 'title_en') ?? '');
            if ($name === '') {
                $name = trim(Row::nstr($row, 'title_ar') ?? '');
            }
            $out[Row::int($row, 'id')] = [
                'name' => $name === '' ? '#'.Row::int($row, 'id') : $name,
                'in_stock' => Row::int($row, 'express') > 0 || Row::int($row, 'market') > 0,
            ];
        }

        return $out;
    }

    /**
     * Narrow a query to the storefronts in scope. Null means every shop (an unscoped grant); an EMPTY list
     * means a grant that names no storefront, which must match nothing rather than everything.
     *
     * @param  list<int>|null  $scope
     */
    private function scoped(Builder $query, ?array $scope): Builder
    {
        if ($scope === null) {
            return $query;
        }

        return $query->whereIn('o.storefront_id', $scope === [] ? [0] : $scope);
    }
}
