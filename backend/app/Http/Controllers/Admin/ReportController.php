<?php

namespace App\Http\Controllers\Admin;

use Carbon\Carbon;
use App\Models\User;
use App\Models\Order;
use Illuminate\Http\Request;
use App\Exports\ReportExport;
use App\Http\Controllers\Controller;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function index ()
    {
        $user = User::all(['id' , 'first_name' , 'last_name']);

        return view('Dashboard.report.index' , compact('user'));
    }

    public function store (Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date',
            'end_date'   => 'nullable|date|after_or_equal:start_date',
            'status'     => 'nullable|string|in:pending,processing,completed,cancelled',
            'user'       => 'nullable|integer|exists:users,id',
        ]);

        $user  = User::all(['id' , 'first_name' , 'last_name']);
        $order = Order::query();

        if ($request->filled('start_date') && $request->filled('end_date') && $request->start_date > $request->end_date) {
            return back()->with('error' , trans('order_reports.mas'));
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {

            $order->whereBetween('created_at' , [Carbon::parse($request->start_date)->startOfDay() , Carbon::parse($request->end_date)->endOfDay()]);

        }elseif ($request->filled('start_date')) {
            if (!$request->has('end_date') || empty($request->end_date)) {
                $order->whereDate('created_at' , $request->start_date);
            }
        }

        if ($request->filled('status') && $request->status !== '') {
            $order->where('status' , $request->status);
        }

        if ($request->filled('user') && $request->user !== '') {
            $order->where('user_id' , $request->user);
        }

        if ($request->action === 'export') {
            $orders = $order->orderBy('order_number', 'desc')->get();
            return Excel::download(new ReportExport($orders), 'orders_report.xlsx');
        }

        $orders = $order->orderBy('order_number', 'desc')->get();

        return view('Dashboard.report.index' , compact('orders' , 'user'));
    }
}
