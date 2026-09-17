<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Mail\AdminOrderNotification;
use App\Mail\OrderConfirmation;
use App\Mail\OrderStatusUpdate;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * The ONE door for order e-mail (prerequisite (a), 2026-09-13).
 *
 * ── The shape, and why it is not simply `Mail::to(...)->send(...)` ───────────────────────────
 *
 * The legacy app sends in the request and wraps each send in a try/catch that logs. That loses
 * the message on any SMTP hiccup, and — worse — loses the FACT that a message was owed: the only
 * trace is a line in `laravel.log` nobody reads. Prerequisite (a) exists because that failure is
 * silent, so "silent" is the one property this class may not have.
 *
 * Every send is therefore TWO steps:
 *
 *   1. **enqueue** — one `integration_outbox` row per message, written in the same breath as the
 *      state change (inside its transaction where there is one, so an order that rolls back owes
 *      no e-mail and an order that commits always does). The row IS the obligation.
 *   2. **flush** — attempt delivery immediately, in the same request, right after the commit.
 *      Success marks the row `sent`. Failure leaves it `pending` with a backoff, and the
 *      one-minute cron (`mail:drain`) retries it. Nothing is lost by a failure and nothing is
 *      duplicated by a retry.
 *
 * ── Exactly once, enforced by the database ───────────────────────────────────────────────────
 *
 * Two guards, both necessary:
 *
 *   - `integration_outbox.dedupe_key` is UNIQUE (M1k). A second enqueue of the same event for the
 *     same recipient is refused by MariaDB, so a double-clicked *Advance* button, a resubmitted
 *     checkout or a replayed callback cannot enqueue a second message at all.
 *   - delivery CLAIMS its row with a conditional `UPDATE … WHERE status = 'pending'`. Only one
 *     process can win that update, so the in-request flush and a cron tick that overlaps it
 *     cannot both send the same row. A row stuck in `sending` (the process was killed mid-send)
 *     is reclaimed by `mail:drain --reclaim` after a grace period.
 *
 * ── A failed notification is an operational fact, not an exception ───────────────────────────
 *
 * Nothing in here throws at its caller. An SMTP failure never rolls back an order, never blocks a
 * status change and never reaches the shopper as a 500 — it becomes a row with `status = failed`,
 * an `attempts` count and the exception's class and message in `last_error`, visible on the order
 * screen and counted by `mail:drain --report` (which exits non-zero while any row is failed, the
 * same loudness `payments:findings` has for money).
 */
final class OrderMailer
{
    /** The outbox channel these rows live on — `CompatCheckout::MAIL_CHANNEL`'s value, shared. */
    public const CHANNEL = 'mail';

    public const EVENT_PLACED = 'order.placed';

    public const KIND_CUSTOMER = 'customer_confirmation';

    public const KIND_ADMIN = 'admin_notification';

    public const KIND_STATUS = 'status_update';

    /** Statuses a row can hold. `sending` is a claim, not a resting state. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    /**
     * Written by a run that places orders THAT ARE NOT REAL — the compat harness, the WriteTarget
     * tools (🟠-5, 2026-09-17).
     *
     * A resting state that nothing ever claims: `deliver()` and `mail:drain` both select `pending`,
     * so a parked row cannot be sent by any path, now or a month from now. That is the whole point.
     * Those runs already set `MAIL_MAILER=log`, which stops mail going out DURING the run and does
     * nothing about what is left behind — a `pending` row from harness order 3381 waits in the
     * outbox until somebody runs `mail:drain` on a host with real SMTP, and then four real admin
     * addresses are told about a test order.
     *
     * Parked rather than deleted: the row is evidence that the harness exercised the mail path,
     * which is one of the things the harness is for.
     */
    public const STATUS_PARKED = 'parked';

    /** Is this process one whose orders are not real? See `config('notifications.send.park')`. */
    public static function parking(): bool
    {
        return (bool) config('notifications.send.park', false);
    }

    /**
     * An order was placed: the customer's confirmation and one notification per admin.
     *
     * `$notifyCustomer` reproduces the legacy rule exactly — `sendOrderEmails()` only builds the
     * customer's confirmation `if ($paymentMethod === 'cash')`, so a card order tells the admins
     * at checkout and tells the CUSTOMER only once the callback says the money arrived. A
     * WhatsApp order tells the admins and never the customer, because the order is not yet a
     * sale. Those are the legacy semantics and they are deliberately preserved: see the trigger
     * table in `docs/wave4c/ORDER_EMAILS_2026-09-13.md`.
     *
     * @return list<int> the outbox ids enqueued by THIS call (a duplicate enqueues nothing)
     */
    public function placed(int $orderId, bool $notifyCustomer): array
    {
        $ids = [];

        if ($notifyCustomer) {
            $id = $this->enqueueCustomer(self::EVENT_PLACED, self::KIND_CUSTOMER, $orderId, 'customer', null);
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        foreach ($this->adminRecipients($orderId) as $recipient) {
            $id = $this->enqueue(
                event: self::EVENT_PLACED,
                dedupe: self::adminKey($orderId, $recipient),
                kind: self::KIND_ADMIN,
                orderId: $orderId,
                recipient: $recipient,
                extra: [],
            );
            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * An order's status changed: the customer's status e-mail, one per transition.
     *
     * The caller is responsible for only calling this on a REAL change — `OrderFulfilment`
     * refuses a transition that is not one step forward, so a no-op cannot reach here; the
     * `dedupe_key` is the second guard, and it is the one that holds when two operators click at
     * the same instant.
     *
     * Admins are deliberately NOT notified of a status change: the legacy dashboard mails only
     * the customer, and the person changing the status is the person who would receive it.
     *
     * @return list<int>
     */
    public function statusChanged(int $orderId, string $status): array
    {
        $id = $this->enqueueCustomer(
            'order.status.'.$status,
            self::KIND_STATUS,
            $orderId,
            'status:'.$status,
            ['status' => $status],
        );

        return $id === null ? [] : [$id];
    }

    /**
     * Attempt delivery of the rows an enqueue just wrote.
     *
     * Two reasons this does nothing but return:
     *
     *   - `notifications.send.inline` is off — the host's relay is slow or rate-limited and every
     *     message is left to the cron. The obligation is already recorded either way.
     *   - a transaction is still open. Sending inside one is a bug with two faces: the row the
     *     sender is about to mark `sent` may roll back (so the customer gets the e-mail twice
     *     after the retry), and an SMTP round trip holds the row locks it is nested in for
     *     seconds. The rows stay `pending` and the cron takes them.
     *     (`notifications.send.inside_transaction` lifts this, and exists solely because the test
     *     suite wraps every feature test in a transaction — see that key's own comment.)
     *
     * @param  list<int>  $ids
     */
    public function flush(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        if (! config()->boolean('notifications.send.inline')) {
            return;
        }

        if (DB::transactionLevel() > 0 && ! config()->boolean('notifications.send.inside_transaction')) {
            Log::warning('OrderMailer::flush called inside a transaction; leaving '.count($ids).' row(s) to mail:drain.');

            return;
        }

        foreach ($ids as $id) {
            $this->deliver($id);
        }
    }

    /** Enqueue and attempt in one call — what every caller outside a transaction wants. */
    public function placedNow(int $orderId, bool $notifyCustomer): void
    {
        $this->flush($this->placed($orderId, $notifyCustomer));
    }

    /** Enqueue and attempt in one call. */
    public function statusChangedNow(int $orderId, string $status): void
    {
        $this->flush($this->statusChanged($orderId, $status));
    }

    /**
     * Deliver ONE outbox row, claiming it first so nothing else can.
     *
     * Returns true when this call sent the message. False covers every other outcome — somebody
     * else had the row, the order is gone, the send failed — and none of them throws, because
     * every caller is in the middle of something more important than an e-mail.
     */
    public function deliver(int $id): bool
    {
        // THE claim. `status = pending` in the WHERE is what makes this exclusive: the loser of a
        // race updates zero rows and walks away.
        $claimed = DB::table('integration_outbox')
            ->where('id', $id)
            ->where('channel', self::CHANNEL)
            ->where('status', self::STATUS_PENDING)
            ->where('available_at', '<=', now())
            ->update([
                'status' => self::STATUS_SENDING,
                'attempts' => DB::raw('attempts + 1'),
                /*
                 * `available_at` is stamped with the moment of the CLAIM, so on a `sending` row it
                 * reads "when this attempt started". That is what `mail:drain --reclaim` measures
                 * the grace period from; leaving it at the enqueue time would make a row that was
                 * enqueued an hour ago reclaimable one second after its first attempt began.
                 */
                'available_at' => now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $raw = DB::table('integration_outbox')->where('id', $id)->first();
        if (! is_object($raw)) {
            return false;                                   // cannot happen; not worth an exception
        }
        $row = Row::cast($raw);

        $orderId = Row::int($row, 'aggregate_id');
        $payload = self::payload(Row::nstr($row, 'payload'));
        $kind = is_string($payload['kind'] ?? null) ? $payload['kind'] : '';
        $recipient = is_string($payload['recipient'] ?? null) ? $payload['recipient'] : '';

        if ($recipient === '') {
            $this->park($id, MailFailure::ours(MailFailure::NO_ADDRESS, 'the row carries no recipient address'));

            return false;
        }

        $data = OrderEmailData::for($orderId);
        if ($data === null) {
            // The order was deleted between the enqueue and the send. Parked, not retried: there
            // is nothing left to tell anybody about.
            $this->park($id, "order {$orderId} no longer exists");

            return false;
        }

        $mailable = self::mailable($kind, $data);
        if ($mailable === null) {
            $this->park($id, "unknown mail kind [{$kind}]");

            return false;
        }

        $number = is_scalar($data['orderNumber'] ?? null) ? (string) $data['orderNumber'] : '';

        try {
            Mail::to($recipient)->send($mailable);

            DB::table('integration_outbox')->where('id', $id)->update([
                'status' => self::STATUS_SENT,
                'processed_at' => now(),
                'last_error' => null,
            ]);

            // The legacy app logged every send; keep it, because a mail log an operator can grep
            // by order number is how "did he get it?" gets answered on the telephone.
            Log::info("order mail sent [{$kind}] order {$number} → ".self::maskEmail($recipient));

            return true;
        } catch (Throwable $e) {
            $this->recordFailure($id, $kind, $number, $recipient, $e);

            return false;
        }
    }

    /**
     * Every mail row for one order, newest first — what the order screen shows and what makes
     * "an e-mail went out" checkable rather than assumed.
     *
     * @return list<array<string, mixed>>
     */
    public static function forOrder(int $orderId): array
    {
        $out = [];
        foreach (
            DB::table('integration_outbox')
                ->where('channel', self::CHANNEL)
                ->where('aggregate_type', 'orders')
                ->where('aggregate_id', $orderId)
                ->orderByDesc('id')
                ->get(['id', 'event', 'payload', 'status', 'attempts', 'available_at', 'processed_at', 'last_error', 'created_at']) as $raw
        ) {
            $row = Row::cast($raw);
            $payload = self::payload(Row::nstr($row, 'payload'));
            $kind = is_string($payload['kind'] ?? null) ? $payload['kind'] : '';

            $out[] = [
                'id' => Row::int($row, 'id'),
                'event' => Row::str($row, 'event'),
                'kind' => $kind,
                'kind_label' => self::kindLabel($kind),
                'recipient' => is_string($payload['recipient'] ?? null) ? $payload['recipient'] : null,
                'status' => Row::str($row, 'status'),
                'attempts' => Row::int($row, 'attempts'),
                'created_at' => Row::nstr($row, 'created_at'),
                'processed_at' => Row::nstr($row, 'processed_at'),
                'available_at' => Row::nstr($row, 'available_at'),
                /*
                 * The CLASSIFICATION, not the transport's text (🟠-2). `last_error` itself no longer
                 * leaves the server: it is masked at write time, but a masked string is still a
                 * developer's artefact, and what an operator needs is whose problem this is —
                 * the relay's, the address's, or ours. `error_kind` is the machine-readable word and
                 * `error_label` the sentence the screen renders.
                 */
                'error_kind' => ($kindOfError = MailFailure::kindOf(Row::nstr($row, 'last_error'))),
                'error_label' => $kindOfError === null ? null : MailFailure::label($kindOfError),
            ];
        }

        return $out;
    }

    /** The Arabic label for a kind — one home, so the screen and the command agree. */
    public static function kindLabel(string $kind): string
    {
        return match ($kind) {
            self::KIND_CUSTOMER => 'تأكيد الطلب (للعميل)',
            self::KIND_ADMIN => 'إشعار طلب جديد (للإدارة)',
            self::KIND_STATUS => 'تحديث حالة الطلب (للعميل)',
            default => $kind,
        };
    }

    // ── enqueue ──────────────────────────────────────────────────────────────────────────────

    /**
     * The customer's copy of something, with the two "nothing to send" outcomes recorded rather
     * than dropped.
     *
     * @param  array<string, mixed>  $extra
     */
    private function enqueueCustomer(string $event, string $kind, int $orderId, string $keyPart, ?array $extra): ?int
    {
        $recipient = self::customerEmail($orderId);
        $dedupe = self::key($orderId, $keyPart);

        if ($recipient === null) {
            /*
             * A guest checkout with no e-mail address, or a user row without one. The legacy app
             * skipped this in silence (`if ($customerEmail)`); core records a `skipped` row so the
             * order screen can say "there was nobody to tell" instead of showing nothing at all
             * and leaving an operator to wonder whether the mailer is broken.
             */
            return $this->enqueue(
                event: $event,
                dedupe: $dedupe,
                kind: $kind,
                orderId: $orderId,
                recipient: null,
                extra: $extra ?? [],
                status: self::STATUS_SKIPPED,
                error: MailFailure::ours(MailFailure::NO_ADDRESS, 'the order carries no customer e-mail address'),
            );
        }

        return $this->enqueue(
            event: $event,
            dedupe: $dedupe,
            kind: $kind,
            orderId: $orderId,
            recipient: $recipient,
            extra: $extra ?? [],
        );
    }

    /**
     * One outbox row, or NULL when the unique key says this message already exists.
     *
     * @param  array<string, mixed>  $extra
     */
    private function enqueue(
        string $event,
        string $dedupe,
        string $kind,
        int $orderId,
        ?string $recipient,
        array $extra,
        string $status = self::STATUS_PENDING,
        ?string $error = null,
    ): ?int {
        /*
         * A run that places orders which are not real parks EVERY row it writes (🟠-5). Applied
         * here, at the one door every enqueue goes through, rather than at each caller — the
         * failure this prevents is a caller that forgets.
         *
         * A status the caller chose deliberately (`skipped`, `failed` for "no admin recipients") is
         * left alone: those are already resting states that nothing sends, and overwriting them
         * would lose why the row is there.
         */
        if ($status === self::STATUS_PENDING && self::parking()) {
            $status = self::STATUS_PARKED;
            $error = $error ?? 'parked: written by a run whose orders are not real (notifications.send.park)';
        }

        $payload = [
            'kind' => $kind,
            'recipient' => $recipient,
            'order_id' => $orderId,
            'order_number' => self::orderNumber($orderId),
        ] + $extra;

        try {
            return (int) DB::table('integration_outbox')->insertGetId([
                'channel' => self::CHANNEL,
                'event' => $event,
                'dedupe_key' => $dedupe,
                'aggregate_type' => 'orders',
                'aggregate_id' => $orderId,
                'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'status' => $status,
                'attempts' => 0,
                'available_at' => now(),
                'processed_at' => $status === self::STATUS_PENDING ? null : now(),
                'last_error' => $error,
                'created_at' => now(),
            ]);
        } catch (QueryException $e) {
            /*
             * The unique key refused it: this exact message is already on record. That is the
             * guard working, not an error — and it is caught HERE rather than pre-checked with a
             * SELECT, because a check-then-insert has a window and a unique index does not.
             */
            if (self::isDuplicate($e)) {
                Log::info("order mail already enqueued [{$dedupe}]; second enqueue ignored.");

                return null;
            }

            throw $e;
        }
    }

    /**
     * Who is told about a new order.
     *
     * An empty list is recorded against the order as a FAILED row, once, rather than passed over.
     * `ORDER_ADMIN_EMAILS` unset is a configuration mistake whose only symptom would otherwise be
     * that nobody in the shop hears about orders any more — precisely the silence prerequisite (a)
     * is about. It is deliberately not left `pending`: retrying forever would hide the mistake
     * behind a queue, and an admin notification about an order placed last week is not worth
     * sending once somebody notices.
     *
     * @return list<string>
     */
    private function adminRecipients(int $orderId): array
    {
        /** @var list<string> $configured */
        $configured = config()->array('notifications.admin_emails');

        if ($configured === []) {
            $this->enqueue(
                event: self::EVENT_PLACED,
                dedupe: self::key($orderId, 'admin:unconfigured'),
                kind: self::KIND_ADMIN,
                orderId: $orderId,
                recipient: null,
                extra: [],
                status: self::STATUS_FAILED,
                error: MailFailure::ours(MailFailure::CONFIG, 'ORDER_ADMIN_EMAILS is empty: no admin notification recipients are configured'),
            );

            Log::error("order mail: no admin recipients configured (ORDER_ADMIN_EMAILS); order {$orderId} notified nobody in the shop.");

            return [];
        }

        return $configured;
    }

    // ── failure handling ─────────────────────────────────────────────────────────────────────

    /**
     * A send that threw: count the attempt, remember why, and either schedule a retry or park it
     * where a human will see it.
     */
    private function recordFailure(int $id, string $kind, string $number, string $recipient, Throwable $e): void
    {
        $attempts = Coerce::int(DB::table('integration_outbox')->where('id', $id)->value('attempts'));
        $max = max(1, config()->integer('notifications.send.max_attempts'));

        /*
         * MASKED and CLASSIFIED — never the transport's own words (🟠-2, 2026-09-17).
         *
         * This used to be `$e::class.': '.$e->getMessage()`. A Symfony TransportException for a
         * rejected SMTP login carries the mail account's USERNAME and the relay's host:port in its
         * message, so a misconfigured relay put half the shop's mail credentials onto a screen any
         * `view-orders` holder can open, and into every backup of this table. Nobody had to be
         * attacked for it to leak; it only had to fail.
         *
         * `MailFailure` keeps the exception class and the words that tell a developer where to
         * look, strikes out the shapes that carry a secret or an identity, and adds the
         * classification the screen actually renders.
         */
        $error = MailFailure::masked($e);

        if ($attempts >= $max) {
            DB::table('integration_outbox')->where('id', $id)->update([
                'status' => self::STATUS_FAILED,
                'processed_at' => now(),
                'last_error' => $error,
            ]);

            Log::error("order mail FAILED permanently [{$kind}] order {$number} → ".self::maskEmail($recipient), [
                'outbox_id' => $id, 'attempts' => $attempts, 'exception' => $e::class,
            ]);

            return;
        }

        DB::table('integration_outbox')->where('id', $id)->update([
            'status' => self::STATUS_PENDING,
            'available_at' => now()->addMinutes(self::backoffMinutes($attempts)),
            'last_error' => $error,
        ]);

        Log::warning("order mail deferred [{$kind}] order {$number} → ".self::maskEmail($recipient), [
            'outbox_id' => $id, 'attempts' => $attempts, 'exception' => $e::class,
        ]);
    }

    /** Terminal, no retry: the row can never succeed, so it is parked where someone will see it. */
    private function park(int $id, string $why): void
    {
        DB::table('integration_outbox')->where('id', $id)->update([
            'status' => self::STATUS_FAILED,
            'processed_at' => now(),
            'last_error' => $why,
        ]);

        Log::error("order mail parked [outbox {$id}]: {$why}");
    }

    private static function backoffMinutes(int $attempts): int
    {
        /** @var list<int> $ladder */
        $ladder = config()->array('notifications.send.backoff_minutes');
        if ($ladder === []) {
            return 1;
        }

        $index = max(0, min($attempts - 1, count($ladder) - 1));

        return max(1, $ladder[$index]);
    }

    // ── small helpers ────────────────────────────────────────────────────────────────────────

    /** @param  array<string, mixed>  $data */
    private static function mailable(string $kind, array $data): ?Mailable
    {
        return match ($kind) {
            self::KIND_CUSTOMER => new OrderConfirmation($data),
            self::KIND_ADMIN => new AdminOrderNotification($data),
            self::KIND_STATUS => new OrderStatusUpdate($data),
            default => null,
        };
    }

    private static function key(int $orderId, string $part): string
    {
        return 'order:'.$orderId.':'.$part;
    }

    /**
     * The admin key names the address, so a glance at the table says who was told.
     *
     * A composed key that would outgrow the column's 191 characters falls back to a hash of the
     * address — correctness first: a truncated key could collide with another admin's and silently
     * suppress their copy.
     */
    private static function adminKey(int $orderId, string $recipient): string
    {
        $key = self::key($orderId, 'admin:'.$recipient);

        return mb_strlen($key) <= 191 ? $key : self::key($orderId, 'admin#'.substr(sha1($recipient), 0, 16));
    }

    private static function customerEmail(int $orderId): ?string
    {
        $row = DB::table('orders as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.id', $orderId)
            ->first(['o.guest_email', 'u.email as user_email']);

        if (! is_object($row)) {
            return null;
        }
        $order = Row::cast($row);

        // A registered order uses the account's address; a guest order its own. Legacy order of
        // preference exactly (`$order->user?->email ?? $order->guest_email`).
        $email = Row::nstr($order, 'user_email') ?? Row::nstr($order, 'guest_email');

        return $email === null || trim($email) === '' ? null : trim($email);
    }

    private static function orderNumber(int $orderId): string
    {
        $number = DB::table('orders')->where('id', $orderId)->value('order_number');

        return is_scalar($number) ? (string) $number : '';
    }

    /** @return array<string, mixed> */
    private static function payload(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }
        $decoded = json_decode($json, true);

        /** @var array<string, mixed> $out */
        $out = is_array($decoded) ? $decoded : [];

        return $out;
    }

    /**
     * 1062 / 23000 — the unique key refused the insert.
     *
     * Matched on the SQLSTATE and the driver code rather than on the message, which is localised
     * and version-dependent.
     */
    private static function isDuplicate(QueryException $e): bool
    {
        return $e->getCode() === '23000' && Coerce::int($e->errorInfo[1] ?? 0) === 1062;
    }

    /**
     * `m***@example.com` — enough to tell two recipients apart in a log, not enough to be a list
     * of customer addresses in a file that gets shipped to a log aggregator.
     */
    private static function maskEmail(string $email): string
    {
        $at = strpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return substr($email, 0, 1).'***'.substr($email, $at);
    }
}
