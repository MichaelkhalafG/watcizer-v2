<?php

namespace App\Mail;

use App\Models\Order;
use App\Mail\Concerns\BuildsOrderEmailData;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Admin new-order notification — sent to every address in
 * config('watchizer.admin_emails'). Synchronous (no ShouldQueue).
 */
class AdminOrderNotification extends Mailable
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

        $subject = '🛒 طلب جديد #' . $data['orderNumber']
            . ' — ' . $data['customerName']
            . ' — ' . number_format($data['total']) . ' EGP';

        return $this
            ->subject($subject)
            ->view('emails.admin-order-notification', $data);
    }
}
