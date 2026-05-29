<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Models\User;

class DashboardController extends Controller
{
    public function index()
    {
        return view('Dashboard.dashboard.dashboard');
    }

    public function get_sales(Request $request)
    {
        $filter = $request->get('filter', 'today');

        $now = Carbon::now();

        switch ($filter) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;

            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                $previousStart = $start->copy()->subMonth()->startOfMonth();
                $previousEnd = $end->copy()->subMonth()->endOfMonth();
                break;

            case 'this_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                $previousStart = $start->copy()->subYear()->startOfYear();
                $previousEnd = $end->copy()->subYear()->endOfYear();
                break;

            default:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;
        }

        $orderCount = Order::whereBetween('created_at', [$start, $end])->count();
        $previousOrderCount = Order::whereBetween('created_at', [$previousStart, $previousEnd])->count();

        $percentageChange = $previousOrderCount > 0
            ? (($orderCount - $previousOrderCount) / $previousOrderCount) * 100
            : ($orderCount > 0 ? 100 : 0);

        return response()->json([
            'orderCount' => $orderCount,
            'percentageChange' => round($percentageChange, 2),
            'filter' => $filter,
        ]);
    }

    public function get_profit(Request $request)
    {
        $filter = $request->get('filter', 'today');

        $now = Carbon::now();

        switch ($filter) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;

            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                $previousStart = $start->copy()->subMonth()->startOfMonth();
                $previousEnd = $end->copy()->subMonth()->endOfMonth();
                break;

            case 'this_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                $previousStart = $start->copy()->subYear()->startOfYear();
                $previousEnd = $end->copy()->subYear()->endOfYear();
                break;

            default:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;
        }

        $currentProfit = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('order_items.created_at', [$start, $end])
            ->selectRaw('SUM((order_items.piece_price - products.purchase_price) * order_items.quantity) as profit')
            ->value('profit') ?? 0;

        $previousProfit = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('order_items.created_at', [$previousStart, $previousEnd])
            ->selectRaw('SUM((order_items.piece_price - products.purchase_price) * order_items.quantity) as profit')
            ->value('profit') ?? 0;

        $percentageChange = $previousProfit > 0
            ? (($currentProfit - $previousProfit) / $previousProfit) * 100
            : ($currentProfit > 0 ? 100 : 0);

        return response()->json([
            'profit' => $currentProfit,
            'percentageChange' => round($percentageChange, 2),
            'filter' => $filter,
        ]);
    }

    public function get_order_total_price(Request $request)
    {
        $filter = $request->get('filter', 'today');

        $now = Carbon::now();

        switch ($filter) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;

            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                $previousStart = $start->copy()->subMonth()->startOfMonth();
                $previousEnd = $end->copy()->subMonth()->endOfMonth();
                break;

            case 'this_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                $previousStart = $start->copy()->subYear()->startOfYear();
                $previousEnd = $end->copy()->subYear()->endOfYear();
                break;

            default:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;
        }

        $currentTotalPrice = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('order_items.created_at', [$start, $end])
            ->selectRaw('SUM(order_items.piece_price * order_items.quantity) as total_price')
            ->value('total_price') ?? 0;

        $previousTotalPrice = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('order_items.created_at', [$previousStart, $previousEnd])
            ->selectRaw('SUM(order_items.piece_price * order_items.quantity) as total_price')
            ->value('total_price') ?? 0;

        $percentageChange = $previousTotalPrice > 0
            ? (($currentTotalPrice - $previousTotalPrice) / $previousTotalPrice) * 100
            : ($currentTotalPrice > 0 ? 100 : 0);

        return response()->json([
            'total_price' => $currentTotalPrice,
            'percentageChange' => round($percentageChange, 2),
            'filter' => $filter,
        ]);
    }

    public function get_order_total_price_shipping(Request $request)
    {
        $filter = $request->get('filter', 'today');

        $now = Carbon::now();

        switch ($filter) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;

            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                $previousStart = $start->copy()->subMonth()->startOfMonth();
                $previousEnd = $end->copy()->subMonth()->endOfMonth();
                break;

            case 'this_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                $previousStart = $start->copy()->subYear()->startOfYear();
                $previousEnd = $end->copy()->subYear()->endOfYear();
                break;

            default:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;
        }

        $currentTotalPriceShipping = Order::whereBetween('created_at', [$start, $end])
        ->sum('total_price_for_order') ?? 0;

        $previousTotalPriceShipping = Order::whereBetween('created_at', [$previousStart, $previousEnd])
        ->sum('total_price_for_order') ?? 0;

        $percentageChange = $previousTotalPriceShipping > 0
            ? (($currentTotalPriceShipping - $previousTotalPriceShipping) / $previousTotalPriceShipping) * 100
            : ($currentTotalPriceShipping > 0 ? 100 : 0);

        return response()->json([
            'total_price_shipping' => $currentTotalPriceShipping,
            'percentageChange' => round($percentageChange, 2),
            'filter' => $filter,
        ]);
    }

    public function get_customer(Request $request)
    {
        $filter = $request->get('filter', 'today');

        $now = Carbon::now();

        switch ($filter) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;

            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                $previousStart = $start->copy()->subMonth()->startOfMonth();
                $previousEnd = $end->copy()->subMonth()->endOfMonth();
                break;

            case 'this_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                $previousStart = $start->copy()->subYear()->startOfYear();
                $previousEnd = $end->copy()->subYear()->endOfYear();
                break;

            default:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                $previousStart = $start->copy()->subDay();
                $previousEnd = $end->copy()->subDay();
                break;
        }

        $currentNewCustomers = User::whereBetween('created_at', [$start, $end])->count();

        $previousNewCustomers = User::whereBetween('created_at', [$previousStart, $previousEnd])->count();

        $percentageChange = $previousNewCustomers > 0
            ? (($currentNewCustomers - $previousNewCustomers) / $previousNewCustomers) * 100
            : ($currentNewCustomers > 0 ? 100 : 0);

        return response()->json([
            'newCustomers' => $currentNewCustomers,
            'percentageChange' => round($percentageChange, 2),
            'filter' => $filter,
        ]);
    }

    public function get_top_selling(Request $request)
    {
        $filter = $request->get('filter', 'today');

        $now = Carbon::now();

        switch ($filter) {
            case 'today':
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;

            case 'this_month':
                $start = $now->copy()->startOfMonth();
                $end = $now->copy()->endOfMonth();
                break;

            case 'this_year':
                $start = $now->copy()->startOfYear();
                $end = $now->copy()->endOfYear();
                break;

            default:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
        }

        $topSellingProducts = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
        ->join('product_translations', function ($join) {
            $join->on('products.id', '=', 'product_translations.product_id')
                ->where('product_translations.locale', '=', app()->getLocale());
        })
        ->whereBetween('order_items.created_at', [$start, $end])
        ->select(
            'products.id',
            'product_translations.product_title as product_title',
            'products.image',
            'products.wa_code',
            DB::raw('SUM(order_items.quantity) as total_sold'),
            DB::raw('SUM((order_items.piece_price - products.purchase_price) * order_items.quantity) as total_profit'),
            DB::raw('AVG(order_items.piece_price) as selling_price')
        )
        ->groupBy('products.id', 'product_translations.product_title', 'products.image' , 'products.wa_code')
        ->orderByDesc('total_sold')
        ->limit(10)
        ->get();

        return response()->json([
            'top_selling_products' => $topSellingProducts,
            'filter' => $filter,
        ]);

    }
}
