<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Legacy order email (single template that branches on $type).
 *
 * Superseded by App\Mail\OrderConfirmation + App\Mail\AdminOrderNotification,
 * which the order flow now uses. Kept for backward compatibility. No longer
 * implements ShouldQueue — emails send synchronously (QUEUE_CONNECTION=sync),
 * so there is no worker/cron dependency.
 */
class OrderCreatedMail extends Mailable
{
    use Queueable, SerializesModels;

    public Order $order;
    public string $type; // 'customer' or 'admin'

    public function __construct(Order $order, string $type = 'customer')
    {
        $this->type = $type;

        // Eager-load everything the template renders so it never lazy-loads
        // (and never triggers errors) while building the email body.
        $order->loadMissing([
            'order_item.product.translations',
            'order_item.offer.translations',
            'address.shippingCity.translations',
            'user',
        ]);

        $this->order = $order;
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
