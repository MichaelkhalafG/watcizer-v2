<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OrderCreatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public Order $order;
    public string $type; // 'customer' or 'admin'

    public function __construct(Order $order, string $type = 'customer')
    {
        $this->order = $order;
        $this->type  = $type;
    }

    public function build()
    {
        $subject = $this->type === 'admin'
            ? '🔔 New Order Received - Watchizer #' . $this->order->order_number
            : 'تأكيد طلبك من Watchizer 🛍️ | Your Watchizer Order Confirmation';

        return $this
            ->subject($subject)
            ->view('Dashboard.order.email')
            ->with([
                'order' => $this->order,
                'type'  => $this->type,
            ]);
    }
}