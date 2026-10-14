<?php

/*
|--------------------------------------------------------------------------
| Order notifications (CLEAN_CORE_STUDY §3.4.1 prerequisite (a))
|--------------------------------------------------------------------------
|
| The legacy app read these from `config/watchizer.php`. Core has no such
| file, so the delivery POLICY lives here — and nowhere else, so there is
| one place to look when an operator asks why mail stopped.
|
| What is NOT here any more, and where it went (one shop's values were every
| shop's):
|
|   • who is told about an order — `ORDER_ADMIN_EMAILS` → the storefront's
|     mailbox row, `storefront_mailboxes.order_recipients` (C12/W3, 2026-10-13);
|   • the WhatsApp number and copyright line — `WATCHIZER_WHATSAPP_SUPPORT`,
|     `ORDER_MAIL_COPYRIGHT` → `storefronts.settings.identity` (W2, 2026-10-13);
|   • the daily cap and reserve — `MAIL_DAILY_CAP`, `MAIL_TRANSACTIONAL_RESERVE`
|     → `storefront_mailboxes.daily_cap` / `transactional_reserve`, per mailbox
|     (W4, 2026-10-13);
|   • the mailbox itself — `MAIL_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION/FROM_*`
|     → the same row, the password encrypted (W3). `MAIL_MAILER` stays: `smtp`
|     sends through the row's transport, `log`/`array` keep mail on the machine.
|
| An EMPTY recipient list is still not "nobody wants to know": `OrderMailer`
| writes a visible `failed` outbox row against the order, because a shop whose
| admins silently stop being told about orders is the exact failure
| prerequisite (a) exists to prevent.
|
*/

return [

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

        /*
        | PARK every outbox row this process writes (🟠-5, 2026-09-17).
        |
        | Set by the compat harness and by the WriteTarget tools — anything that places ORDERS
        | THAT ARE NOT REAL. Those runs already set `MAIL_MAILER=log`, which stops mail going out
        | DURING the run; it does nothing about the rows left behind. A `pending` row written by a
        | harness order sits in the outbox until somebody runs `php artisan mail:drain` on a host
        | with real SMTP — and then four real admin addresses are told about test order 3381.
        |
        | A parked row is never claimed: `deliver()` and `mail:drain` both select `pending`. It
        | stays visible, and it stays honest about what it is.
        |
        | Default FALSE, so a normal run is unchanged and forgetting the flag can only ever mean
        | "a real e-mail was sent", never "a real e-mail was silently dropped".
        */
        'park' => (bool) env('CORE_MAIL_PARK', false),

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

    /*
    | Where the dashboard lives, for the "Open Order" button in the admin order e-mail
    | (review 🟠-5).
    |
    | PINNED, and deliberately not derived. This link used to be built with
    | `route('manage.orders.show')`, which resolves its host from the current request — or from
    | APP_URL on the console. Both are wrong here, and after Phase 2 the first one is actively
    | broken: the e-mail is composed while serving `add_order` on **api.watchizereg.com**, and
    | `.htaccess` §4 answers 404 for `/manage` on that host. Every admin order e-mail would have
    | carried a button to a 404.
    |
    | The dashboard has exactly ONE address, and this is the place that says so. It is not a
    | secret and it is not per-storefront: the dashboard is one application serving all of them.
    | `MANAGE_URL` overrides it for a staging host.
    */
    'manage_url' => rtrim((string) env('MANAGE_URL', 'https://eleganceeg.com'), '/'),

    /*
    | BULK mail's pacing (2026-10-01): restock alerts and the re-engagement campaign. The daily
    | cap and the transactional reserve are PER MAILBOX since W4 (2026-10-13), on the storefront's
    | `storefront_mailboxes` row — Watchizer 800/200 (MAIL-PLAN: Hostinger allows 1,000 per mailbox
    | per rolling 24 hours; the counter is per calendar day, so the cap sits under the limit with
    | room). Order and account mail never check the budget at all. What stays here is how many
    | rows one `bulk-mail:drain` pass attempts and how many times a failed row is retried.
    */
    'bulk' => [
        'per_run' => (int) env('MAIL_BULK_PER_RUN', 20),
        'max_attempts' => 3,
    ],

    /*
    | "E-mail me when it's back" (2026-10-01): how many shoppers are told per unit restocked, and
    | how long a row is kept — notified rows 30 days after the e-mail, unanswered ones 180 days
    | after subscribing (developer's decisions, 2026-09-29).
    */
    'stock_alerts' => [
        // The e-mail's "stop these e-mails" link points at the alert's OWN storefront's API host
        // (`StorefrontUrls::apiHost()`, W6, 2026-10-12), which serves /stock-alerts/stop/{token}
        // (on the .htaccess allow-list, runbook §4.1.1). `STOCK_ALERTS_PUBLIC_URL` is retired:
        // one global host was Watchizer's for both shops.
        'per_unit' => 5,
        'keep_notified_days' => 30,
        'keep_waiting_days' => 180,
    ],
];
