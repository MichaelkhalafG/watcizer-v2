<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * A PICKUP order is ready at its store, or its hold was extended (click & collect, 2026-11-14). The store, the code,
 * the collect-by time and the store's phone; both languages, like every order e-mail. Takes `OrderEmailData::for()`'s
 * array plus `pickupEvent` (`ready` | `extended`), which OrderMailer::deliver() copies from the outbox row.
 */
final class PickupUpdate extends Mailable
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private readonly array $data) {}

    public function build(): self
    {
        $number = is_scalar($this->data['orderNumber'] ?? null) ? (string) $this->data['orderNumber'] : '';
        $brand = is_scalar($this->data['brandName'] ?? null) ? (string) $this->data['brandName'] : '';
        $extended = ($this->data['pickupEvent'] ?? null) === 'extended';

        return $this
            ->subject($extended
                ? 'تم تمديد حجز طلبك #'.$number.' | Your '.$brand.' pickup #'.$number.' is held longer'
                : 'طلبك #'.$number.' جاهز للاستلام | Your '.$brand.' order #'.$number.' is ready for pickup')
            ->view('emails.pickup-update', $this->data + ['pickupEvent' => 'ready']);
    }
}
