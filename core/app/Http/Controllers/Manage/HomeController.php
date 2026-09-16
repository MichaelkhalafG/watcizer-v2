<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Inventory\InventoryService;
use App\Domain\Orders\NewOrders;
use App\Models\Storefront\Storefront;
use App\Models\User;
use App\Support\ManageText;
use App\Support\Sql;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /manage — the dashboard home.
 *
 * Deliberately thin and honest. Every number below is one real query against the clean tables, and
 * there is nothing here that cannot be defended:
 *
 *  • **No charts.** The reference template ships ApexCharts; AGENTS §5 forbids adopting its
 *    libraries, and a sparkline of invented history would be worse than no sparkline.
 *  • **No invented KPIs.** "Revenue this month" and "conversion rate" are computable but not from
 *    data this application owns yet (orders are the legacy app's until the switch, and revenue
 *    needs a decision about cancelled and unpaid orders). They arrive with the orders screen in 4C.
 *  • **Low stock means what the column says.** `catalog_products.low_stock_threshold` exists and
 *    is filled per product; the count is products at or below it, not a guess at what "low" means.
 *
 * The order counts read the SHARED `orders` table, which both applications write until the switch,
 * so "today" is honest for both — a Blade-placed order shows up here too.
 */
final class HomeController
{
    public function __construct(private readonly NewOrders $newOrders) {}

    public function __invoke(Request $request): Response
    {
        return Inertia::render('Manage/Home', [
            'stats' => $this->stats($request->user()),
            'inventory' => $this->inventory(),
            'storefronts' => $this->storefronts(),
        ]);
    }

    /**
     * The catalogue AS EACH SITE SEES IT — the task-1 finding on this screen.
     *
     * The four numbers above are catalogue-wide, and that is correct: the catalogue is SHARED
     * (AGENTS §2.4), so "how many products are there" has one answer. But it is not the question
     * the team asks in the morning. They ask "how many are live on Brand Fashion, and what is
     * stopping the rest", and this screen used to answer neither — it showed one set of totals and
     * no storefront at all, which is what made a second storefront invisible in the dashboard.
     *
     * Every number is one indexed query per storefront. `not_added` is separated from `hidden` on
     * purpose: a product with no `storefront_product` row was never offered to that site, while a
     * hidden one was and someone decided against it. They lead to different actions.
     *
     * @return list<array{id: int, code: string, name: string, is_active: bool, visible: int, hidden: int, not_added: int, unplaced: int, no_arabic: int}>
     */
    private function storefronts(): array
    {
        $live = DB::table('catalog_products')->whereNull('deleted_at')->count();

        $out = [];
        foreach (Storefront::query()->orderBy('id')->get(['id', 'code', 'name', 'is_active']) as $storefront) {
            $id = (int) $storefront->id;
            $rows = DB::table('storefront_product as sp')
                ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
                ->where('sp.storefront_id', $id)
                ->whereNull('p.deleted_at');

            $visible = (clone $rows)->where('sp.is_visible', 1)->count();
            $present = (clone $rows)->count();

            $out[] = [
                'id' => $id,
                'code' => (string) $storefront->code,
                'name' => (string) $storefront->name,
                'is_active' => (bool) $storefront->is_active,
                'visible' => $visible,
                'hidden' => $present - $visible,
                'not_added' => $live - $present,
                // Placed nowhere on THIS storefront: present in the catalogue and reachable from
                // no page of this site.
                'unplaced' => (clone $rows)
                    ->whereNotExists(function (Builder $sub) use ($id): void {
                        $sub->from('storefront_category_product as scp')
                            ->whereColumn('scp.product_id', 'sp.product_id')
                            ->where('scp.storefront_id', $id)
                            ->selectRaw('1');
                    })
                    ->count(),
                // Visible today and missing its Arabic title — which the dashboard now refuses to
                // create, so a non-zero number here is legacy data and worth seeing.
                'no_arabic' => (clone $rows)
                    ->where('sp.is_visible', 1)
                    ->whereNotExists(function (Builder $sub): void {
                        $sub->from('catalog_product_translations as t')
                            ->whereColumn('t.product_id', 'sp.product_id')
                            ->where('t.locale', 'ar')
                            ->whereRaw("TRIM(COALESCE(t.title, '')) <> ''")
                            ->selectRaw('1');
                    })
                    ->count(),
            ];
        }

        return $out;
    }

    /**
     * The headline numbers.
     *
     * @param  User|null  $user  the operator, for the per-person unseen count
     * @return list<array{key: string, label: string, value: int, hint: string}>
     */
    private function stats(?User $user): array
    {
        $today = now()->startOfDay();

        /*
         * `key` is the tile's identity and stays English; `label` and `hint` are the two strings a
         * person reads, and the home screen renders both straight out of this payload — nothing in
         * `Home.tsx` can translate them, which is why they come off the seam here.
         */
        return [
            [
                'key' => 'products',
                'label' => ManageText::t('products.title', 'المنتجات'),
                'value' => DB::table('catalog_products')->whereNull('deleted_at')->count(),
                'hint' => ManageText::t('home.stat_products_hint', 'في الكتالوج النظيف'),
            ],
            [
                'key' => 'active_products',
                'label' => ManageText::t('home.stat_active_products', 'منتجات مفعّلة'),
                'value' => DB::table('catalog_products')->whereNull('deleted_at')->where('is_active', 1)->count(),
                'hint' => 'is_active = 1',   // i18n-exempt: a column name and its value, not a sentence
            ],
            [
                'key' => 'orders_today',
                'label' => ManageText::t('home.stat_orders_today', 'طلبات اليوم'),
                'value' => DB::table('orders')->where('created_at', '>=', $today)->count(),
                'hint' => ManageText::t('home.stat_orders_today_hint', 'من الجدول المشترك (يشمل الطلبات من اللوحة القديمة)'),
            ],
            /*
             * NEW SINCE YOU LAST LOOKED — the same number as the sidebar badge, from the same
             * source, deliberately.
             *
             * It is here as well as in the sidebar because the two answer the question at different
             * moments: the badge catches the eye mid-task, and this is what somebody reads when
             * they open the dashboard to decide what to do first. Two numbers that disagreed would
             * be worse than either alone, so both come from `NewOrders::countFor()`.
             *
             * "Today" above and "new" here are NOT the same figure and the hint says so: an order
             * from yesterday evening that nobody has opened is new to this operator and not
             * today's.
             */
            [
                'key' => 'orders_unseen',
                'label' => ManageText::t('home.stat_orders_unseen', 'جديد منذ آخر زيارة'),
                'value' => $this->newOrders->countFor($user),
                'hint' => ManageText::t('home.stat_orders_unseen_hint', 'طلبات وصلت منذ آخر مرة فتحتَ فيها شاشة الطلبات — لك وحدك'),
            ],
            [
                'key' => 'orders_total',
                'label' => ManageText::t('home.stat_orders_total', 'إجمالي الطلبات'),
                'value' => DB::table('orders')->count(),
                'hint' => ManageText::t('home.stat_orders_total_hint', 'كل الطلبات المسجّلة'),
            ],
        ];
    }

    /**
     * Stock health, at the level each product is authoritative at (wave 3.5): a product with
     * variants is judged by its aggregate columns, which the service keeps exact.
     *
     * @return array{out_of_stock: int, low_stock: int, variants: int, threshold_products: int, buckets: array{express: int, market: int}}
     */
    private function inventory(): array
    {
        $live = fn () => DB::table('catalog_products')->whereNull('deleted_at')->where('is_active', 1);
        $columns = InventoryService::columns();

        return [
            'out_of_stock' => $live()->where('in_stock', 0)->count(),
            // At or below the product's OWN threshold, and still orderable — the list a buyer acts on.
            'low_stock' => $live()->where('in_stock', 1)
                // Built by App\Support\Sql, the one place a column name becomes SQL text.
                ->whereRaw(Sql::belowLowStockThreshold())
                ->count(),
            'variants' => DB::table('catalog_product_variants')->count(),
            'threshold_products' => $live()->where('low_stock_threshold', '>', 0)->count(),
            'buckets' => [
                'express' => (int) $live()->sum($columns['express']),
                'market' => (int) $live()->sum($columns['market']),
            ],
        ];
    }
}
