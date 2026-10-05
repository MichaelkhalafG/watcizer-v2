<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * The admins' copy of a card order that expired unpaid (2026-10-05, developer: "an immediate copy,
 * not a daily digest — a call the same hour is the strongest recovery we have"). Who the customer is, how to
 * reach them (tap-to-call and WhatsApp), what they ordered, and that they were e-mailed a recovery link.
 */
final class PaymentExpiredAdmin extends Mailable
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private readonly array $data) {}

    public function build(): self
    {
        $number = is_scalar($this->data['orderNumber'] ?? null) ? (string) $this->data['orderNumber'] : '';
        $name = is_scalar($this->data['customerName'] ?? null) ? (string) $this->data['customerName'] : '';
        $total = is_numeric($this->data['total'] ?? null) ? (float) $this->data['total'] : 0.0;
        $phone = is_scalar($this->data['customerPhone'] ?? null) ? (string) $this->data['customerPhone'] : '';
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        // Egyptian mobile → international for wa.me (01xxxxxxxxx → 201xxxxxxxxx).
        $international = str_starts_with($digits, '0') ? '2'.$digits : $digits;

        return $this
            ->subject('⏱ لم يكتمل الدفع #'.$number.' — '.$name.' — '.number_format($total).' EGP')
            ->view('emails.payment-expired-admin', $this->data + [
                'telUrl' => $digits === '' ? null : 'tel:+'.$international,
                'waUrl' => $digits === '' ? null : 'https://wa.me/'.$international,
            ]);
    }
}
