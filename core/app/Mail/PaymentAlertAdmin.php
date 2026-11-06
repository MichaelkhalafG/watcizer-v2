<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * The shop's alert that MONEY ARRIVED and the order does not show it (2026-11-05, order 000092).
 *
 * Sent to the storefront's order recipients the moment a payment finding is raised with the
 * outcome `success`: the customer was charged, and the order is cancelled, already shipped, of a
 * different amount, or was brought back while a newer order of the same shopper exists. Until this
 * e-mail existed such a finding lived on one order's page and in a console command nobody runs —
 * a charged customer waited for someone to stumble on it.
 *
 * `alert` in the data is what the enqueuer knew: `finding` (the kind), `refused` (why the order
 * could not be brought back, when it could not), `transaction` and `newer_orders`.
 */
final class PaymentAlertAdmin extends Mailable
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
        $international = str_starts_with($digits, '0') ? '2'.$digits : $digits;

        $alert = is_array($this->data['alert'] ?? null) ? $this->data['alert'] : [];
        $finding = is_string($alert['finding'] ?? null) ? $alert['finding'] : '';
        $refused = is_string($alert['refused'] ?? null) ? $alert['refused'] : null;
        $transaction = is_scalar($alert['transaction'] ?? null) ? (string) $alert['transaction'] : null;
        $newer = [];
        foreach (is_array($alert['newer_orders'] ?? null) ? $alert['newer_orders'] : [] as $other) {
            if (is_scalar($other)) {
                $newer[] = (string) $other;
            }
        }

        return $this
            ->subject('🔴 دفعة تحتاج مراجعة #'.$number.' — '.$name.' — '.number_format($total).' EGP')
            ->view('emails.payment-alert-admin', $this->data + [
                'finding' => $finding,
                'refused' => $refused,
                'transaction' => $transaction,
                'newerOrders' => $newer,
                'telUrl' => $digits === '' ? null : 'tel:+'.$international,
                'waUrl' => $digits === '' ? null : 'https://wa.me/'.$international,
            ]);
    }
}
