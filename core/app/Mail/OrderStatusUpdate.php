<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Order status-change e-mail — the legacy `App\Mail\OrderStatusUpdate`, ported.
 *
 * The template carries copy for all six statuses in both languages, including the `shipped` and
 * `delivered` values that were unreachable until M1i widened the enum (2026-09-12). See
 * `OrderConfirmation` for why this takes an array and why it is not `ShouldQueue`.
 */
final class OrderStatusUpdate extends Mailable
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private readonly array $data) {}

    public function build(): self
    {
        $number = is_scalar($this->data['orderNumber'] ?? null) ? (string) $this->data['orderNumber'] : '';
        $statusAr = is_scalar($this->data['statusAr'] ?? null) ? (string) $this->data['statusAr'] : '';

        // The legacy subject, byte for byte.
        return $this
            ->subject('📦 تحديث حالة الطلب #'.$number.' — '.$statusAr.' | Watchizer Order Update')
            ->view('emails.order-status-update', $this->data);
    }
}
