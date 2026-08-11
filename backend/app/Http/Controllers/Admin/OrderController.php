<?php

namespace App\Http\Controllers\Admin;

use App\Models\Order;
use App\Models\Address;
use App\Models\ShippingCity;
use Illuminate\Http\Request;
use App\Mail\OrderStatusUpdate;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OrderController extends Controller
{
    public function index(Request $request)
    {
        // Server-side text search (?q=) — the client DataTable is disabled on this
        // paginated page, so search runs in the query.
        $q = trim((string) $request->input('q'));

        $order = Order::with('address.shipping_city' , 'paymentStatus')
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('order_number', 'like', "%{$q}%")
                        ->orWhere('guest_name', 'like', "%{$q}%")
                        ->orWhere('guest_email', 'like', "%{$q}%")
                        ->orWhere('guest_phone', 'like', "%{$q}%");
                });
            })
            ->orderBy('order_number', 'desc')
            ->paginate(50)->withQueryString();

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

        $previousStatus = $order->status;
        $order->status  = $request->status;
        $order->save();

        // Notify the customer of the new status (guest-safe). Synchronous send,
        // wrapped so an SMTP failure never breaks the dashboard save.
        if ($previousStatus !== $order->status) {
            $customerEmail = $order->user?->email ?? $order->guest_email;
            if ($customerEmail) {
                try {
                    Mail::to($customerEmail)->send(new OrderStatusUpdate($order));
                    Log::info('Status update email sent to: ' . $customerEmail . ' | Order: ' . $order->order_number . ' | Status: ' . $order->status);
                } catch (\Throwable $e) {
                    Log::error('Status update email FAILED | Order: ' . $order->order_number, ['exception' => $e->getMessage()]);
                }
            }
        }

        return redirect(route('order.index'))->with('success' , trans('messages.edit'));
    }

    public function showPayment($id)
    {
        $payment = Order::with('paymentStatus')->findOrFail($id);

        return view('Dashboard.order.show-payment' , compact('payment'))->render();
    }
}
