<?php

declare(strict_types=1);

namespace App\Domain\Pickup;

use App\Domain\Activity\ActivityLog;
use App\Domain\Inventory\Actor;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\OrderFulfilment;
use App\Domain\Wheel\WheelReferrals;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * A pickup order after it is placed (click & collect, 2026-11-14): reserved → READY → COLLECTED, or EXPIRED / CANCELLED.
 *
 * The order's own status moves as the plan approved: reserved and ready are both `processing` (being prepared, then
 * waiting at the store); COLLECTED moves the order to `delivered` — the customer has the goods, so reports, revenue and
 * "no cancel after delivery" keep working unchanged — and `completed` closes it as for any order. EXPIRED cancels the
 * order through OrderFulfilment::cancel(), the one door that returns stock.
 *
 * Every step is a CLAIM: an UPDATE conditional on the state it moves from, so two clicks, or a click and the expiry job,
 * move the row once. Who and when go on the pickup row (`ready_by`, `extended_by`, `collected_by`) and in the activity
 * log; the customer is told by e-mail (ready, extended; collected and cancelled/expired through the status mail).
 *
 * The clock starts at READY (`expires_at` = ready + the shop's hold hours, read when the button is pressed, so a change
 * of terms never moves a running clock). ONE extension per order, by the team, of the shop's extension hours.
 *
 * Expiry cancels only PAY-AT-THE-STORE reservations. A reservation paid online is not cancelled by a job: cancelling
 * it means a refund somebody has to make, so it stays ready and the dashboard shows it as overdue for a person to
 * decide (recorded as a decision in docs/deploy/PICKUP_20261114.md).
 */
final class PickupLifecycle
{
    public const RESERVED = 'reserved';

    public const READY = 'ready';

    public const COLLECTED = 'collected';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const STATUSES = [self::RESERVED, self::READY, self::COLLECTED, self::EXPIRED, self::CANCELLED];

    /** The note the expiry job leaves on the ledger and the activity log. */
    public const EXPIRY_NOTE = 'pickup_expired';

    public function __construct(
        private readonly OrderFulfilment $fulfilment,
        private readonly OrderMailer $mailer,
    ) {}

    /** The pickup row of an order, or null for a delivery. */
    public static function row(int $orderId): ?stdClass
    {
        $raw = DB::table(PickupCheckout::TABLE)->where('order_id', $orderId)->first();

        return is_object($raw) ? Row::cast($raw) : null;
    }

    /**
     * What the order screen may offer for this pickup — so the buttons and the server agree.
     *
     * @return array{ready: bool, extend: bool, collect: bool, overdue: bool, late: bool}
     */
    public static function options(stdClass $pickup, string $orderStatus): array
    {
        $status = Row::str($pickup, 'status');
        $expires = Row::nstr($pickup, 'expires_at');

        return [
            'ready' => $status === self::RESERVED && $orderStatus === 'processing',
            'extend' => $status === self::READY && Row::nstr($pickup, 'extended_at') === null,
            'collect' => $status === self::READY && $orderStatus === 'processing',
            // ready and past its collect-by time (a prepaid one waits for a person; a pay-at-store one for the job)
            'overdue' => $status === self::READY && $expires !== null && Carbon::parse($expires)->isPast(),
            // reserved for longer than the slowest promised day — the plan's "flagged as late, not cancelled"
            'late' => $status === self::RESERVED && self::lateSince($pickup) !== null,
        ];
    }

    /**
     * Mark the order ready at its store and start the hold clock. Returns the collect-by time (app clock).
     *
     * @throws RuntimeException when the order cannot be made ready
     */
    public function ready(int $orderId, Actor $actor): string
    {
        [$order, $pickup] = self::require($orderId);
        $status = Row::str($pickup, 'status');
        if ($status !== self::RESERVED) {
            throw new RuntimeException(self::refusal($status));
        }
        $orderStatus = Row::str($order, 'status');
        if ($orderStatus === 'pending') {
            throw new RuntimeException(ManageText::t('pickup.ready_unpaid', 'هذا الطلب لم يُدفع بعد أونلاين. لا يُجهَّز للاستلام قبل تأكيد الدفع.'));
        }
        if ($orderStatus !== 'processing') {
            throw new RuntimeException(ManageText::t('pickup.not_open', 'هذا الطلب ليس مفتوحًا (:status).', ['status' => OrderFulfilment::label($orderStatus)]));
        }

        $settings = Pickup::settings(Row::int($order, 'storefront_id'));
        $now = now();
        $expires = $now->copy()->addHours($settings['hold_hours']);

        $claimed = DB::table(PickupCheckout::TABLE)->where('order_id', $orderId)->where('status', self::RESERVED)->update([
            'status' => self::READY,
            'ready_at' => $now,
            'ready_by' => $actor->id,
            'expires_at' => $expires,
            'updated_at' => $now,
        ]);
        if ($claimed === 0) {
            throw new RuntimeException(self::refusal(self::READY));
        }

        self::audit($order, ['pickup' => self::RESERVED, 'collect_by' => null], ['pickup' => self::READY, 'collect_by' => $expires->toDateTimeString()]);
        $this->mailer->pickupChangedNow($orderId, OrderMailer::PICKUP_READY);

        return $expires->toDateTimeString();
    }

    /**
     * The one extension the team may give on the customer's request. Returns the new collect-by time.
     *
     * @throws RuntimeException when the pickup cannot be extended
     */
    public function extend(int $orderId, Actor $actor): string
    {
        [$order, $pickup] = self::require($orderId);
        $status = Row::str($pickup, 'status');
        if ($status !== self::READY) {
            throw new RuntimeException($status === self::RESERVED
                ? ManageText::t('pickup.extend_not_ready', 'لم يبدأ وقت الحجز بعد: يبدأ عندما يصبح الطلب جاهزًا للاستلام.')
                : self::refusal($status));
        }
        if (Row::nstr($pickup, 'extended_at') !== null) {
            throw new RuntimeException(ManageText::t('pickup.extend_once', 'تم تمديد هذا الحجز من قبل. التمديد مرة واحدة فقط.'));
        }

        $settings = Pickup::settings(Row::int($order, 'storefront_id'));
        $was = Row::nstr($pickup, 'expires_at');
        $from = $was === null ? now() : Carbon::parse($was);
        // a reservation already past its time (the job has not reached it yet) gets the extension from NOW
        if ($from->isPast()) {
            $from = now();
        }
        $expires = $from->copy()->addHours($settings['extend_hours']);

        $claimed = DB::table(PickupCheckout::TABLE)->where('order_id', $orderId)
            ->where('status', self::READY)->whereNull('extended_at')
            ->update(['expires_at' => $expires, 'extended_at' => now(), 'extended_by' => $actor->id, 'updated_at' => now()]);
        if ($claimed === 0) {
            throw new RuntimeException(ManageText::t('pickup.extend_once', 'تم تمديد هذا الحجز من قبل. التمديد مرة واحدة فقط.'));
        }

        self::audit($order, ['collect_by' => $was], ['collect_by' => $expires->toDateTimeString()]);
        $this->mailer->pickupChangedNow($orderId, OrderMailer::PICKUP_EXTENDED);

        return $expires->toDateTimeString();
    }

    /**
     * The customer collected it: the team typed the pickup code. The order becomes `delivered`.
     *
     * @throws RuntimeException when the code is wrong or the order cannot be collected
     */
    public function collect(int $orderId, string $code, Actor $actor): void
    {
        [$order, $pickup] = self::require($orderId);
        $status = Row::str($pickup, 'status');
        if ($status !== self::READY) {
            throw new RuntimeException($status === self::RESERVED
                ? ManageText::t('pickup.collect_not_ready', 'اجعل الطلب «جاهز للاستلام» أولًا، ثم سلّمه.')
                : self::refusal($status));
        }
        if (! hash_equals(Row::str($pickup, 'code'), trim($code))) {
            throw new RuntimeException(ManageText::t('pickup.wrong_code', 'كود الاستلام غير صحيح. اطلب من العميل الكود المكتوب في رسالة الطلب.'));
        }

        $done = DB::transaction(function () use ($orderId, $actor): bool {
            $claimed = DB::table(PickupCheckout::TABLE)->where('order_id', $orderId)->where('status', self::READY)->update([
                'status' => self::COLLECTED,
                'collected_at' => now(),
                'collected_by' => $actor->id,
                'closed_at' => now(),
                'updated_at' => now(),
            ]);
            if ($claimed === 0) {
                return false;
            }
            $moved = DB::table('orders')->where('id', $orderId)->where('status', 'processing')
                ->update(['status' => 'delivered', 'updated_at' => now()]);
            if ($moved === 0) {
                // the order left `processing` (cancelled a moment ago): nothing is handed over
                throw new RuntimeException(ManageText::t('pickup.not_open_now', 'تغيّرت حالة الطلب للتو. حدّث الصفحة.'));
            }

            return true;
        });
        if (! $done) {
            throw new RuntimeException(self::refusal(self::COLLECTED));
        }

        // a friend's DELIVERED first order earns the inviter their reward (the wheel), exactly as for a delivery
        WheelReferrals::onOrderStatus($orderId, 'delivered');
        self::audit($order, ['status' => 'processing', 'pickup' => self::READY], ['status' => 'delivered', 'pickup' => self::COLLECTED]);
        $this->mailer->statusChangedNow($orderId, 'delivered');
    }

    /**
     * Cancel every pay-at-the-store reservation past its collect-by time, and return its stock. Idempotent: each row
     * is claimed (ready → expired) before its order is cancelled, so two runs never cancel or release twice.
     *
     * @return list<string> the order numbers this run expired
     */
    public function expireDue(int $limit = 100, bool $dryRun = false): array
    {
        $rows = DB::table(PickupCheckout::TABLE.' as p')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where('p.status', self::READY)->where('p.pay_at_store', true)
            ->where('p.expires_at', '<', now())
            ->orderBy('p.expires_at')->limit($limit)
            ->get(['p.order_id', 'o.order_number']);

        $done = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $orderId = Row::int($row, 'order_id');
            $number = Row::str($row, 'order_number');
            if ($dryRun) {
                $done[] = $number;

                continue;
            }

            $claimed = DB::table(PickupCheckout::TABLE)->where('order_id', $orderId)
                ->where('status', self::READY)->where('expires_at', '<', now())
                ->update(['status' => self::EXPIRED, 'closed_at' => now(), 'updated_at' => now()]);
            if ($claimed === 0) {
                continue;                                   // collected, extended or expired by someone else meanwhile
            }

            try {
                // the cancellation e-mail reads the pickup row's `expired` and says so (order-status-update)
                $this->fulfilment->cancel($orderId, Actor::system(), self::EXPIRY_NOTE);
                $done[] = $number;
            } catch (Throwable $e) {
                // already cancelled or delivered by hand: the row stays expired and closed, nothing is released twice
                Log::warning("pickup expiry: order {$number} not cancelled: ".$e->getMessage());
            }
        }

        return $done;
    }

    /**
     * Close an OPEN pickup row when its order is cancelled by any path (dashboard, unpaid card, failed payment start).
     * An expired row keeps `expired`. Called inside the cancelling transaction.
     */
    public static function closeForCancelledOrder(int $orderId): void
    {
        DB::table(PickupCheckout::TABLE)->where('order_id', $orderId)->whereIn('status', PickupStores::OPEN)
            ->update(['status' => self::CANCELLED, 'closed_at' => now(), 'updated_at' => now()]);
    }

    /**
     * A cancelled card order that is paid late and REINSTATED (LatePayment) is reserved again: the clock and the code
     * are as they were, the team marks it ready as for a new one.
     */
    public static function reopenForReinstatedOrder(int $orderId): void
    {
        DB::table(PickupCheckout::TABLE)->where('order_id', $orderId)->where('status', self::CANCELLED)
            ->update(['status' => self::RESERVED, 'closed_at' => null, 'ready_at' => null, 'expires_at' => null, 'updated_at' => now()]);
    }

    /** When a still-reserved pickup passed the slowest day it promised (calendar days; null = not late). */
    public static function lateSince(stdClass $pickup): ?Carbon
    {
        $reserved = Row::nstr($pickup, 'reserved_at');
        if ($reserved === null) {
            return null;
        }
        // business days promised; +2 calendar days per 5 is the honest upper bound without a holiday calendar
        $max = Row::int($pickup, 'ready_max_days');
        $due = Carbon::parse($reserved)->addDays($max + intdiv($max + 4, 5) * 2);

        return $due->isPast() ? $due : null;
    }

    /** A time on the app clock, as the customer and the team read it (Cairo). */
    public static function cairo(?string $time, string $format = 'j M Y, H:i'): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        return Carbon::parse($time, config()->string('app.timezone'))->timezone('Africa/Cairo')->format($format);
    }

    /**
     * @return array{0: stdClass, 1: stdClass}
     */
    private static function require(int $orderId): array
    {
        $raw = DB::table('orders')->where('id', $orderId)->first(['id', 'status', 'storefront_id', 'order_number', 'fulfilment']);
        if (! is_object($raw)) {
            throw new RuntimeException("Order {$orderId} does not exist.");
        }
        $order = Row::cast($raw);
        $pickup = self::row($orderId);
        if ($pickup === null || Row::str($order, 'fulfilment') !== 'pickup') {
            throw new RuntimeException(ManageText::t('pickup.not_pickup', 'هذا الطلب توصيل وليس استلامًا من الفرع.'));
        }

        return [$order, $pickup];
    }

    private static function refusal(string $status): string
    {
        return ManageText::t('pickup.already_status', 'حالة الاستلام الآن: :status. حدّث الصفحة.', ['status' => self::label($status)]);
    }

    /** The dashboard's label for a pickup status. */
    public static function label(string $status): string
    {
        return match ($status) {
            self::RESERVED => ManageText::t('pickup.status_reserved', 'محجوز — قيد التجهيز'),
            self::READY => ManageText::t('pickup.status_ready', 'جاهز للاستلام'),
            self::COLLECTED => ManageText::t('pickup.status_collected', 'تم الاستلام'),
            self::EXPIRED => ManageText::t('pickup.status_expired', 'انتهى الحجز'),
            self::CANCELLED => ManageText::t('pickup.status_cancelled', 'ملغى'),
            default => $status,
        };
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private static function audit(stdClass $order, array $before, array $after): void
    {
        $number = Row::nstr($order, 'order_number');
        ActivityLog::record(
            'orders',
            Row::int($order, 'id'),
            ActivityLog::UPDATED,
            $before,
            $after,
            $number === null || trim($number) === '' ? '#'.Row::int($order, 'id') : $number,
            Row::nint($order, 'storefront_id'),
        );
    }
}
