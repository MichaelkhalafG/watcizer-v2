<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;

/**
 * Customer order-confirmation e-mail — the legacy `App\Mail\OrderConfirmation`, ported.
 *
 * ── Why this takes an ARRAY and not an order ─────────────────────────────────────────────────
 *
 * The legacy mailable took an `Order` model and built its own data in `build()`. Core has no
 * Eloquent order, and more importantly the data is built ONCE per event and shared by the
 * customer's message and every admin's copy — so it is built by `OrderEmailData` and handed in.
 * The mailable's whole job is the subject line and the view name.
 *
 * ── Why NOT `ShouldQueue` ────────────────────────────────────────────────────────────────────
 *
 * Deliberate, and the same decision the legacy app reached (its comment: "mailables no longer
 * implement ShouldQueue"). `QUEUE_CONNECTION=sync` on shared hosting means `ShouldQueue` would
 * send in-request anyway, but through the queue's serializer — buying nothing and adding a failure
 * mode. Retries are the OUTBOX's job here (`OrderMailer`, `mail:drain`), which survives a process
 * death in a way the sync queue does not.
 */
final class OrderConfirmation extends Mailable
{
    /** @param  array<string, mixed>  $data */
    public function __construct(private readonly array $data) {}

    public function build(): self
    {
        $number = is_scalar($this->data['orderNumber'] ?? null) ? (string) $this->data['orderNumber'] : '';

        // The legacy subject, byte for byte.
        return $this
            ->subject('✅ تم استلام طلبك — Watchizer | Order #'.$number.' Confirmed')
            ->view('emails.order-confirmation', $this->data);
    }
}
