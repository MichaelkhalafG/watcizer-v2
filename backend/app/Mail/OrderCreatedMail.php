<?php

namespace App\Mail;

use App\Models\Order;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Order confirmation email.
 *
 * QUEUED: implements ShouldQueue so it never blocks the checkout response on
 * SMTP — the job is pushed to the `jobs` table (QUEUE_CONNECTION=database) and
 * sent by a worker. A worker MUST be running for emails to actually go out:
 *   local/dev:  php artisan queue:work
 *   production: run it under Supervisor (or `queue:work --daemon`)
 * The restock (ProductObserver) and re-engagement (SendReEngagementEmails)
 * mail already depend on this same worker.
 */
class OrderCreatedMail extends Mailable implements ShouldQueue
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
