<?php

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use App\Models\Address;
use App\Models\ShippingCity;
use Illuminate\Http\Request;
use App\Mail\OrderCreatedMail;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    public function index()
    {
        $order = Order::with('address.shipping_city' , 'paymentStatus')->orderBy('order_number', 'desc')->get();

        $pendingCount       = Order::where('status', 'pending')->count();
        $processingCount    = Order::where('status', 'processing')->count();
        $completedCount     = Order::where('status', 'completed')->count();
        $cancelledCount     = Order::where('status', 'cancelled')->count();
        $totalPriceForOrder = Order::sum('total_price_for_order');

        return view('Dashboard.order.index', compact('order' , 'pendingCount' , 'processingCount' , 'completedCount' , 'cancelledCount' , 'totalPriceForOrder'));
    }

    public function show(Order $order)
    {
        return view('Dashboard.order.show' , compact('order'));
    }

 public function edit(Order $order)
    {
        return view('Dashboard.order.edit' , compact('order'));
    }

    public function update(Request $request, Order $order)
    {
        $request->validate([
            'status' => 'required|in:pending,processing,completed,cancelled'
        ]);

        $order->status = $request->status;

        if ($request->status == 'processing' && $order->payment_method == 'card') {
            Mail::to($order->user->email)->send(new OrderCreatedMail($order));
            Mail::to('maikelkhalaf100@gmail.com')->send(new OrderCreatedMail($order));
            Mail::to('mina7makram@gmail.com')->send(new OrderCreatedMail($order));
            Mail::to('minaawadrezk@gmail.com')->send(new OrderCreatedMail($order));
            Mail::to('Watchizer303@gmail.com')->send(new OrderCreatedMail($order));
            }

        $order->save();

        return redirect(route('order.index'))->with('success' , trans('messages.edit'));
    }

    public function showPayment($id)
    {
        $payment = Order::with('paymentStatus')->findOrFail($id);

        return view('Dashboard.order.show-payment' , compact('payment'))->render();
    }
}
