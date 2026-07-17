<?php

namespace App\Mail;

use App\Models\Order;
use App\Mail\Concerns\BuildsOrderEmailData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Customer order-confirmation email.
 *
 * Synchronous (does NOT implement ShouldQueue) — sends immediately when an
 * order is placed. Callers wrap the send in try/catch so an SMTP failure never
 * breaks the checkout response.
 */
class OrderConfirmation extends Mailable
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
            ->subject('✅ تم استلام طلبك — Watchizer | Order #' . $data['orderNumber'] . ' Confirmed')
            ->view('emails.order-confirmation', $data);
    }
}
