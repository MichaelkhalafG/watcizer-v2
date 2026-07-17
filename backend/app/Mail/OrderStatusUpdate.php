<?php

namespace App\Mail;

use App\Models\Order;
use App\Mail\Concerns\BuildsOrderEmailData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Order status-change email sent to the customer when an admin updates the
 * order status from the dashboard. Synchronous (no ShouldQueue).
 */
class OrderStatusUpdate extends Mailable
{
    use Queueable, SerializesModels, BuildsOrderEmailData;

    public Order $order;

    public function __construct(Order $order)
    {
        $this->order = $order;
    }

    public function build()
    {
        $data = $this->orderEmailData($this->order);

        return $this
            ->subject('📦 تحديث حالة الطلب #' . $data['orderNumber'] . ' — ' . $data['statusAr'] . ' | Watchizer Order Update')
            ->view('emails.order-status-update', $data);
    }
}
