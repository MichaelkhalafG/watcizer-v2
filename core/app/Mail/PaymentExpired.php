<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Orders\OrderRecovery;
use Illuminate\Mail\Mailable;

/**
 * "Your order wasn't completed" — to the customer of a card order nobody paid for, the minute
 * `orders:expire-unpaid` cancels it (2026-10-05). What happened, that the items were released, and a
 * link that brings the order back (`OrderRecovery`, valid 7 days). Both languages, like every order
 * e-mail. Takes `OrderEmailData::for()`'s array, like the others.
 */
final class PaymentExpired extends Mailable
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private readonly array $data) {}

    public function build(): self
    {
        $number = is_scalar($this->data['orderNumber'] ?? null) ? (string) $this->data['orderNumber'] : '';
        $orderId = is_int($this->data['orderId'] ?? null) ? $this->data['orderId'] : 0;

        return $this
            ->subject('طلبك #'.$number.' لم يكتمل | Your Watchizer order #'.$number.' wasn\'t completed')
            ->view('emails.payment-expired', $this->data + [
                'recoveryUrl' => OrderRecovery::url($orderId),
                'recoveryDays' => OrderRecovery::DAYS,
            ]);
    }
}
