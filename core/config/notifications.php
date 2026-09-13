<?php

/*
|--------------------------------------------------------------------------
| Order notifications (CLEAN_CORE_STUDY §3.4.1 prerequisite (a))
|--------------------------------------------------------------------------
|
| The legacy app read these from `config/watchizer.php`. Core has no such
| file, so the three values the ported e-mail templates actually need live
| here — and nowhere else, so there is one place to look when an operator
| asks why an address stopped receiving order mail.
|
| The recipient LIST is the only one of these that is a secret-adjacent
| operational value, and it is read from the environment exactly as the
| legacy app read it: `ORDER_ADMIN_EMAILS`, comma separated.
|
*/

return [

    /*
    | Who is told when an order is placed. Legacy parity: `config('watchizer.admin_emails')`,
    | `env('ORDER_ADMIN_EMAILS','')` comma-split, empty entries dropped.
    |
    | An EMPTY list is not treated as "nobody wants to know". `OrderMailer` writes a visible
    | `failed` outbox row against the order instead, because a shop whose admins silently stop
    | being told about orders is the exact failure prerequisite (a) exists to prevent.
    */
    'admin_emails' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('ORDER_ADMIN_EMAILS', ''))),
        static fn (string $email): bool => $email !== '',
    )),

    /*
    | The footer's WhatsApp support number and copyright line — the two pieces of branding the
    | ported templates read from config rather than from the order. Both default to the legacy
    | values, so an unset environment renders exactly what the legacy app renders.
    */
    'whatsapp_support' => (string) env('WATCHIZER_WHATSAPP_SUPPORT', '201551096234'),

    'brand' => [
        'copyright' => (string) env('ORDER_MAIL_COPYRIGHT', '© 2024 Watchizer. All rights reserved.'),
    ],

    /*
    | Delivery policy (§2.12: queues run `sync` plus a one-minute cron; no workers on shared
    | hosting).
    |
    | `inline` sends in the operator's / shopper's own request right after the state change has
    | committed, exactly as the legacy app does. `false` leaves every message to `mail:drain`,
    | which is what a host with a slow or rate-limited SMTP relay should switch to — the outbox
    | row is written either way, so nothing is lost by the choice.
    */
    'send' => [
        'inline' => (bool) env('ORDER_MAIL_INLINE', true),

        // Attempts before a row is parked as `failed` and stops being retried. Five one-minute
        // ticks with the backoff below spans roughly an hour and a half of relay trouble.
        'max_attempts' => (int) env('ORDER_MAIL_MAX_ATTEMPTS', 5),

        // Minutes to wait before each retry, indexed by attempts already made. The last entry
        // repeats once the list is exhausted.
        'backoff_minutes' => [1, 5, 15, 60],

        // A row left `sending` for longer than this had its process killed mid-send (a timeout,
        // a fatal, a host restart). `mail:drain --reclaim` puts it back to `pending`.
        'reclaim_after_minutes' => (int) env('ORDER_MAIL_RECLAIM_MINUTES', 15),

        /*
        | May an inline send happen while a transaction is open? NO, and this exists to be
        | switched on by the TEST SUITE only.
        |
        | In production the answer has to be no, for two reasons: the row the sender is about to
        | mark `sent` can still roll back (so the retry mails the customer a second time), and an
        | SMTP round trip inside a transaction holds its row locks for seconds. Every caller
        | therefore enqueues inside its transaction and flushes after it — and `flush()` refuses
        | anyway, as a brace behind that belt.
        |
        | `tests/Pest.php` wraps every feature test in a transaction that is never committed
        | (`DatabaseTransactions`), so with the refusal absolute a test could not observe a single
        | send. The feature tests set this true and say so; `OrderMailTransactionGuardTest` holds
        | the production rule at the DEFAULT value, which is the one that ships.
        */
        'inside_transaction' => (bool) env('ORDER_MAIL_SEND_IN_TRANSACTION', false),
    ],

];
