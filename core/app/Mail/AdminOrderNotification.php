<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Admin new-order notification — the legacy `App\Mail\AdminOrderNotification`, ported.
 *
 * One message per address in `config('notifications.admin_emails')`, exactly as legacy looped
 * `config('watchizer.admin_emails')`. See `OrderConfirmation` for why this takes an array and
 * why it is not `ShouldQueue`.
 */
final class AdminOrderNotification extends Mailable
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private readonly array $data) {}

    public function build(): self
    {
        $number = is_scalar($this->data['orderNumber'] ?? null) ? (string) $this->data['orderNumber'] : '';
        $name = is_scalar($this->data['customerName'] ?? null) ? (string) $this->data['customerName'] : '';
        $total = is_numeric($this->data['total'] ?? null) ? (float) $this->data['total'] : 0.0;

        // The legacy subject, byte for byte: the three things an operator triages on.
        return $this
            ->subject('🛒 طلب جديد #'.$number.' — '.$name.' — '.number_format($total).' EGP')
            ->view('emails.admin-order-notification', $this->data);
    }
}
