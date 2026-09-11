<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Inventory\InventoryService;
use App\Support\Sql;
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
    public function __invoke(): Response
    {
        return Inertia::render('Manage/Home', [
            'stats' => $this->stats(),
            'inventory' => $this->inventory(),
        ]);
    }

    /** @return list<array{key: string, label: string, value: int, hint: string}> */
    private function stats(): array
    {
        $today = now()->startOfDay();

        return [
            [
                'key' => 'products',
                'label' => 'المنتجات',
                'value' => DB::table('catalog_products')->whereNull('deleted_at')->count(),
                'hint' => 'في الكتالوج النظيف',
            ],
            [
                'key' => 'active_products',
                'label' => 'منتجات مفعّلة',
                'value' => DB::table('catalog_products')->whereNull('deleted_at')->where('is_active', 1)->count(),
                'hint' => 'is_active = 1',
            ],
            [
                'key' => 'orders_today',
                'label' => 'طلبات اليوم',
                'value' => DB::table('orders')->where('created_at', '>=', $today)->count(),
                'hint' => 'من الجدول المشترك (يشمل الطلبات من اللوحة القديمة)',
            ],
            [
                'key' => 'orders_total',
                'label' => 'إجمالي الطلبات',
                'value' => DB::table('orders')->count(),
                'hint' => 'كل الطلبات المسجّلة',
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
