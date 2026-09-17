<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Activity\ActivityLog;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Notifications\OrderMailer;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The one door for changing an order's state from the dashboard (wave 4C).
 *
 * ── Why a domain service and not four lines in a controller ──────────────────────────────────
 *
 * Two of these transitions move real things. A CANCEL returns reserved units to the ledger, and it
 * must do so through `InventoryService` — never `UPDATE orders … ; UPDATE catalog_products …`,
 * which is the shape that loses a unit the moment two people click at once (AGENTS §3, D-21).
 * Putting the transition in the domain means an importer, a console command or a future screen
 * cannot do it any other way without going around a class whose whole purpose is being the way.
 *
 * ── The statuses are the LEGACY enum, WIDENED by decision on 2026-09-12 ─────────────────────
 *
 * `orders.status` is a shared legacy column. It held
 * `enum('pending','processing','completed','cancelled')` — no `shipped` — so wave 4C first mapped
 * the brief's "processing → shipped" onto `completed`, and flagged it.
 *
 * **That mapping was wrong for a reason worth keeping written down.** The legacy dashboard e-mails
 * the customer on EVERY status change, and `completed`'s copy reads *"Your order is complete.
 * Thank you for shopping with Watchizer!"* / *"تم اكتمال طلبك"*. Advancing at shipment therefore
 * told a customer their order was finished while the watch was still in a van — a wrong message to
 * a real person, not a label mismatch. The e-mail template already carried `shipped` and
 * `delivered` copy in both languages, unreachable because the enum could not hold the values.
 *
 * So the enum was widened (M1i, `2026_09_19_000000_order_status_shipped_delivered`) and the flow is
 * now four steps: **pending → processing → shipped → delivered → completed**, with `completed`
 * meaning CLOSED rather than "fulfilled". Measurement of what every legacy reader does with the
 * new values, and the two `backend/` display defects it found:
 * `docs/wave4c/ORDER_STATUS_2026-09-12.md`.
 *
 * ── Who may do what ─────────────────────────────────────────────────────────────────────────
 *
 * This class does not ask. The route gates decide (`manage-order-fulfilment` for the forward
 * moves, `cancel-orders` for a cancel — three abilities, not one, AGENTS §2.7), and the controller
 * carries the gate. What this class refuses is a transition that makes no sense whatever the
 * caller's role: a cancelled order does not become processing again, and a completed order is not
 * cancelled from a dashboard button.
 */
final class OrderFulfilment
{
    /**
     * The legacy enum, exactly, in the column's own ordinal order — which is also the fulfilment
     * order, because M1i inserted the two new values in the middle rather than appending them.
     * Nothing may write a value outside this list.
     */
    public const STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'completed', 'cancelled'];

    /**
     * The FORWARD moves the fulfilment ability may make — one step at a time, no skipping.
     *
     * `pending → processing` is "we have the money, or we accept the COD"; `processing → shipped`
     * is dispatch; `shipped → delivered` is the courier's confirmation; `delivered → completed`
     * CLOSES the order. None of them returns stock, which is why data-entry may make them.
     *
     * **One step at a time is deliberate.** Each move e-mails the customer (from CORE since
     * 2026-09-13 — prerequisite (a)), so allowing `processing → delivered` would skip the
     * "on its way" message the customer is waiting for, and allowing anything → `completed` would
     * bring back the very confusion this flow exists to remove.
     *
     * @var array<string, list<string>>
     */
    public const FULFILMENT_TRANSITIONS = [
        'pending' => ['processing'],
        'processing' => ['shipped'],
        'shipped' => ['delivered'],
        'delivered' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    /**
     * The states a cancellation may no longer be made from: the customer has the goods.
     *
     * @var list<string>
     */
    public const UNCANCELLABLE = ['delivered', 'completed', 'cancelled'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly OrderMailer $mailer,
    ) {}

    /**
     * Move an order forward. Returns the new status.
     *
     * @throws RuntimeException when the move is not one this order can make
     */
    public function advance(int $orderId, string $to, Actor $actor): string
    {
        $order = self::require($orderId);
        $from = Row::str($order, 'status');

        $allowed = self::FULFILMENT_TRANSITIONS[$from] ?? [];
        if (! in_array($to, $allowed, true)) {
            throw new RuntimeException(self::refusal($from, $to, $allowed));
        }

        DB::table('orders')->where('id', $orderId)->update(['status' => $to, 'updated_at' => now()]);

        /*
         * ── WHO moved this order, and when (B-GUARD-2, 2026-09-17) ─────────────────────────────
         *
         * Order transitions were not audited at all. The review read every writer and found
         * `ActivityLog::record()` called by the product, stock, category, payment-settings,
         * placement, promotion and role writers — and by nothing on the order path. A status change
         * mails a customer and moves an order towards money, and the only trace it left was the
         * column it changed.
         *
         * Recorded AFTER the update lands and BEFORE the mail goes, because the audit describes the
         * state change, not the notification: a relay that is down must not cost the audit row.
         * `record()` swallows its own failures by design, so it cannot turn a successful transition
         * into an error either way.
         */
        ActivityLog::record(
            'orders',
            $orderId,
            ActivityLog::UPDATED,
            ['status' => $from],
            ['status' => $to],
            self::orderLabel($order),
            Row::nint($order, 'storefront_id'),
        );

        /*
         * -- the customer is told (prerequisite (a), 2026-09-13) ---------------------------------
         *
         * The legacy Blade dashboard mailed `OrderStatusUpdate` on every status change that was a
         * real change (`if ($previousStatus !== $order->status)`). T-0 disables that dashboard, so
         * until this line existed an order marked shipped from the core dashboard notified nobody
         * and nothing errored -- the exact silence prerequisite (a) exists to close.
         *
         * ONE send per event, twice over: the refusal above means a no-op never reaches here at
         * all (`$from === $to` is not in `FULFILMENT_TRANSITIONS[$from]`), and
         * `integration_outbox.dedupe_key` is UNIQUE per (order, status) so two operators clicking
         * the same button in the same second still produce one message.
         *
         * No transaction is open on this path, so the send happens in the operator's request --
         * and it cannot fail the request: `OrderMailer` records every outcome and throws at
         * nobody. A relay that is down leaves a pending row for the cron and the status change
         * stands.
         */
        $this->mailer->statusChangedNow($orderId, $to);

        return $to;
    }

    /**
     * Cancel an order AND return its reserved stock — one act, one transaction.
     *
     * The stock return goes through `InventoryService::releaseOrder()`, which is idempotent by
     * design (it looks for an existing release before writing), so a double click cannot credit
     * the units twice. The order row and the ledger rows commit or roll back together: an order
     * marked cancelled whose stock never came back is the failure this shape prevents.
     *
     * @return array{status: string, released: bool, movements: int, already_cancelled: bool}
     *
     * @throws RuntimeException when the order cannot be cancelled
     */
    public function cancel(int $orderId, Actor $actor, ?string $note = null): array
    {
        $order = self::require($orderId);
        $from = Row::str($order, 'status');

        if ($from === 'cancelled') {
            throw new RuntimeException(ManageText::t('orders.already_cancelled', 'هذا الطلب ملغى بالفعل.'));
        }
        if (in_array($from, self::UNCANCELLABLE, true)) {
            /*
             * The rule is about the GOODS, not about the paperwork: once the customer has them,
             * what happens next is a RETURN — different accounting, a courier collection, possibly
             * a partial refund — and none of that is a dashboard button that silently credits
             * stock back. Before delivery (pending, processing, shipped) a cancellation is a real
             * thing the shop does, and the stock comes back through the service.
             *
             * `shipped` is deliberately still cancellable: a customer who cancels while the parcel
             * is in the van is the ordinary case, and the units do come back — the ledger records
             * the release when the decision is made, which is the same guarantee wave 3 gives for
             * every other cancellation.
             */
            throw new RuntimeException(ManageText::t(
                'orders.cancel_after_delivery',
                'لا يمكن إلغاء طلب وصل إلى العميل من هذه الشاشة. تعامل معه كمرتجع.',
            ));
        }

        $storefrontId = Row::nint($order, 'storefront_id');

        /** @var list<int> $mailIds */
        $mailIds = [];

        $result = DB::transaction(function () use ($orderId, $order, $from, $actor, $storefrontId, $note, &$mailIds): array {
            /*
             * ── The UPDATE is the claim (🔵, 2026-09-17) ──────────────────────────────────────
             *
             * The `$from === 'cancelled'` check above happens OUTSIDE this transaction, so two
             * operators clicking at the same instant both read `pending`, both pass it, and both
             * arrive here. The second used to set `cancelled` over `cancelled` and report a fresh
             * cancellation — "stock returned, 0 movements" — which is a sentence about something
             * that did not happen.
             *
             * `where('status', '!=', 'cancelled')` makes the row itself the arbiter: the loser
             * updates zero rows and says so. Same shape as `OrderMailer::deliver()`'s claim and as
             * the payment callback's, and for the same reason — a pre-check plus a write has a
             * window, and a conditional write does not.
             */
            $claimed = DB::table('orders')
                ->where('id', $orderId)
                ->where('status', '!=', 'cancelled')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);

            if ($claimed === 0) {
                /*
                 * Somebody else cancelled it between our read and this write. Not an error: the
                 * order IS cancelled, which is what the operator wanted. They are told plainly
                 * rather than shown a refusal for a thing that succeeded.
                 */
                return [
                    'status' => 'cancelled',
                    'released' => false,
                    'movements' => 0,
                    'already_cancelled' => true,
                ];
            }

            $alreadyReleased = $this->inventory->isReleased($orderId);

            // THE one door. `order_cancel` is a declared release reason, so the ledger says why the
            // units came back and `inventory:verify` can still reconcile the row.
            $movements = $this->inventory->releaseOrder($orderId, 'order_cancel', $actor, $storefrontId, $note);

            /*
             * The cancellation e-mail is ENQUEUED here, inside the transaction, and sent below
             * once it has committed. Inside is right for the record: if the stock release fails
             * and the whole cancellation rolls back, the customer must not have been told their
             * order was cancelled. Outside is right for the SEND: an SMTP round trip inside this
             * transaction would hold the locks on the order and its products for seconds.
             *
             * `order-status-update.blade.php` already carries the cancelled copy in both
             * languages -- the same template and the same trigger class as every forward move.
             */
            $mailIds = $this->mailer->statusChanged($orderId, 'cancelled');

            /*
             * ── The one action that moves money, on the record (B-GUARD-2, 2026-09-17) ────────
             *
             * A cancellation returns stock, stops a sale and is admin-only, and it was audited
             * nowhere the activity screen could show. The stock half already leaves an
             * `inventory_movements` row carrying the actor — but that answers "which units came
             * back", not "who cancelled order 1234, when, and why". The note in particular existed
             * only as an argument passed to the ledger.
             *
             * INSIDE the transaction, deliberately. If the stock release fails and the whole
             * cancellation rolls back, the audit row must go with it — an audit trail that records
             * cancellations which did not happen is worse than one that records none, because it is
             * the one source a dispute would be settled from.
             *
             * The `already_cancelled` path above returns before reaching here, and must: nothing
             * happened on that request, and a second row would make one cancellation look like two.
             *
             * `note` and the release facts travel as fields so the screen renders them in the same
             * `{field: from → to}` shape it uses everywhere. `ActivityLog::diff()` stores only what
             * differs, so a cancellation with no note writes no `note` field at all.
             */
            ActivityLog::record(
                'orders',
                $orderId,
                ActivityLog::UPDATED,
                ['status' => $from, 'note' => null, 'stock_movements' => 0],
                [
                    'status' => 'cancelled',
                    'note' => $note,
                    'stock_movements' => count($movements),
                ],
                self::orderLabel($order),
                $storefrontId,
            );

            return [
                'status' => 'cancelled',
                'released' => ! $alreadyReleased,
                'movements' => count($movements),
                'already_cancelled' => false,
            ];
        });

        $this->mailer->flush($mailIds);

        return $result;
    }

    /**
     * What a screen may offer for an order in this state — so the buttons and the server agree.
     *
     * @return array{advance: list<string>, may_cancel: bool}
     */
    public static function options(string $status): array
    {
        return [
            'advance' => self::FULFILMENT_TRANSITIONS[$status] ?? [],
            'may_cancel' => ! in_array($status, self::UNCANCELLABLE, true),
        ];
    }

    /** The Arabic label for a status — one home, so the list, the detail and the filter agree. */
    public static function label(string $status): string
    {
        /*
         * Every key here is one `Orders/Show.tsx` ALREADY renders on its own status buttons — the
         * server and the client share `lang/en/manage.php`, so the queue's `status_label` and the
         * button beside it cannot say two different things in English.
         */
        return match ($status) {
            'pending' => ManageText::t('common.status_pending', 'قيد الانتظار'),
            'processing' => ManageText::t('common.status_processing', 'قيد التنفيذ'),
            'shipped' => ManageText::t('common.status_shipped', 'تم الشحن'),
            'delivered' => ManageText::t('common.status_delivered', 'تم التوصيل'),
            // `completed` now means CLOSED, not "fulfilled" — `delivered` is the state that tells
            // the customer their order arrived, so this label had to stop claiming that.
            'completed' => ManageText::t('common.status_completed', 'مغلق'),
            'cancelled' => ManageText::t('common.status_cancelled', 'ملغى'),
            // The raw column value, untranslated on purpose: a status outside the enum is DATA the
            // operator needs to see verbatim, not a label to guess an English word for.
            default => $status,
        };
    }

    /**
     * How an order is NAMED in the activity log.
     *
     * The operator knows an order by the number printed on it, not by its primary key — and
     * `subject_label` is captured at write time precisely so the log still reads correctly when the
     * row it points at has changed or gone. Falls back to the id when the number is missing, because
     * a label is the one field here that must never be empty.
     */
    private static function orderLabel(\stdClass $order): string
    {
        $number = Row::nstr($order, 'order_number');

        return $number === null || trim($number) === ''
            ? '#'.Row::int($order, 'id')
            : $number;
    }

    private static function require(int $orderId): \stdClass
    {
        $order = DB::table('orders')->where('id', $orderId)
            ->first(['id', 'status', 'storefront_id', 'order_number', 'total_price_for_order']);

        if (! is_object($order)) {
            throw new RuntimeException("Order {$orderId} does not exist.");
        }

        // `Row::cast()`, not the raw object: every accessor in `Row` takes a stdClass, and a
        // PDO row is only stdClass by convention.
        return Row::cast($order);
    }

    /** @param  list<string>  $allowed */
    private static function refusal(string $from, string $to, array $allowed): string
    {
        /*
         * ONE sentence per refusal, with `:name` placeholders — not a sentence assembled from six
         * fragments. The concatenated version could not be translated at all: half of its words
         * were in the glue between the calls, and an English reader would have got Arabic
         * punctuation around English labels.
         */
        if ($allowed === []) {
            return ManageText::t(
                'orders.transition_terminal',
                'لا يمكن تغيير حالة هذا الطلب: حالته الحالية (:status) نهائية.',
                ['status' => self::label($from)],
            );
        }

        return ManageText::t(
            'orders.transition_not_allowed',
            'لا يمكن الانتقال من (:from) إلى (:to). الخطوة المتاحة الآن: :allowed.',
            [
                'from' => self::label($from),
                'to' => self::label($to),
                // The separator is the SHARED one the client joins lists with, so a list built
                // here and a list built in React read the same in both languages.
                'allowed' => implode(
                    ManageText::t('common.list_separator', '، '),
                    array_map(self::label(...), $allowed),
                ),
            ],
        );
    }
}
