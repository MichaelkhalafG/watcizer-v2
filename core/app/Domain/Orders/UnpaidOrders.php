<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Activity\ActivityLog;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Payment\CallbackPolicy;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Card orders nobody paid for, and the stock they hold (2026-09-26).
 *
 * `add_order` reserves stock the moment it creates an order, for every payment method. A card order
 * then waits `pending` for Paymob — and a shopper who leaves Paymob's page without trying to pay
 * produces NO callback at all. Nothing else released such an order, so it held its units forever:
 * the shopper who came back and chose cash met "Insufficient stock", and the unit was lost to
 * everybody else too. Two ways out, both through `InventoryService::releaseOrder()`:
 *
 *   • EXPIRY — `orders:expire-unpaid` (every minute) cancels one still unpaid after the window;
 *   • SUPERSEDE — a new order from the same shopper first cancels that shopper's own unpaid ones,
 *     so the one who came back to pay differently is not refused their own unit.
 *
 * ── What counts as unpaid ─────────────────────────────────────────────────────────────────────
 *
 * `pending` + `payment_method = paymob` + a reservation in core's ledger + no successful payment
 * attempt. The ledger condition is what keeps orders from BEFORE the Phase 2 switch out of it:
 * the legacy app created those, core never reserved anything for them, and they are cleared by
 * hand from the dashboard. WhatsApp orders are `pending` too and are never touched — they wait for
 * a person, not for Paymob.
 *
 * ── A payment that arrives afterwards ─────────────────────────────────────────────────────────
 *
 * The status change is a CLAIM (`where status = pending`), the same shape as the dashboard cancel
 * and the payment callback, so an expiry and a callback in the same second cannot both win. A
 * success that arrives after the claim finds a `cancelled` order, which `CallbackPolicy` never
 * reopens: it records a finding for the operator (refund, or re-open by hand). An intention
 * `expiration` shorter than the window would make Paymob refuse such a payment first; it is sent
 * only once its unit has been measured (PaymobProvider::intentionExpiration()), and until then the
 * finding is the whole net.
 *
 * ── The e-mail (2026-10-05, developer: "one-click recovery") ───────────────────────────────────
 *
 * An EXPIRY e-mails the customer a link that brings the order back (`OrderRecovery`) and sends the
 * admins an immediate copy with the phone number, so someone can call the same hour — unless the
 * shopper already came back and ordered (`OrderRecovery::shouldRemind`). A SUPERSEDE sends nothing:
 * the shopper is placing the next order right now. Queued in the cancel's own transaction (an
 * expiry that rolls back owes no e-mail), sent after it commits. It used to send nothing at all,
 * on the grounds that the card order's confirmation had never gone out either.
 */
final class UnpaidOrders
{
    public const EXPIRED = 'payment_expired';

    public const SUPERSEDED = 'payment_superseded';

    public function __construct(private readonly InventoryService $inventory, private readonly OrderMailer $mailer) {}

    /**
     * The unpaid card orders created before `$olderThanMinutes` ago, oldest first.
     *
     * @return list<int>
     */
    public function expiredIds(int $olderThanMinutes, int $limit = 200): array
    {
        return array_values(array_map(
            fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0),
            self::unpaid()->where('o.created_at', '<', now()->subMinutes($olderThanMinutes))
                ->orderBy('o.id')->limit($limit)->pluck('o.id')->all(),
        ));
    }

    /**
     * Cancel this shopper's own unpaid card orders, before they place a new one.
     *
     * @return list<int> the orders cancelled
     */
    public function supersedeFor(?int $userId, ?string $guestToken, int $storefrontId): array
    {
        if ($userId === null && ($guestToken === null || trim($guestToken) === '')) {
            return [];
        }
        $query = self::unpaid()->where('o.storefront_id', $storefrontId);
        $userId !== null
            ? $query->where('o.user_id', $userId)
            : $query->whereNull('o.user_id')->where('o.guest_token', $guestToken);

        $done = [];
        foreach ($query->orderBy('o.id')->pluck('o.id')->all() as $id) {
            $id = (int) (is_numeric($id) ? $id : 0);
            if ($this->cancel($id, self::SUPERSEDED)) {
                $done[] = $id;
            }
        }

        return $done;
    }

    /**
     * Cancel one unpaid order and give its stock back — or do nothing if it is no longer unpaid.
     *
     * @return bool true when THIS call cancelled it
     */
    public function cancel(int $orderId, string $why): bool
    {
        $mailIds = [];
        $done = DB::transaction(function () use ($orderId, $why, &$mailIds): bool {
            // The claim: only a still-pending card order moves. A callback that marked it paid, a
            // dashboard cancel or a parallel run all leave zero rows here, and this call stops.
            $claimed = DB::table('orders')
                ->where('id', $orderId)->where('status', 'pending')->where('payment_method', 'paymob')
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
            if ($claimed === 0) {
                return false;
            }

            $order = DB::table('orders')->where('id', $orderId)->first(['order_number', 'storefront_id']);
            $storefrontId = $order === null ? null : Row::nint($order, 'storefront_id');
            $movements = $this->inventory->releaseOrder($orderId, 'payment_failed', Actor::system(), $storefrontId, $why);

            $number = $order === null ? null : Row::nstr($order, 'order_number');
            ActivityLog::record(
                'orders',
                $orderId,
                ActivityLog::UPDATED,
                ['status' => 'pending', 'note' => null, 'stock_movements' => 0],
                ['status' => 'cancelled', 'note' => $why, 'stock_movements' => count($movements)],
                $number === null || trim($number) === '' ? '#'.$orderId : $number,
                $storefrontId,
            );

            if ($why === self::EXPIRED && OrderRecovery::shouldRemind($orderId)) {
                $mailIds = $this->mailer->paymentExpired($orderId);
            }

            return true;
        });
        if ($done) {
            $this->mailer->flush($mailIds);
        }

        return $done;
    }

    /** Pending card orders core reserved stock for, with no successful payment on record. */
    private static function unpaid(): Builder
    {
        return DB::table('orders as o')
            ->where('o.status', 'pending')
            ->where('o.payment_method', 'paymob')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('inventory_movements as m')
                ->whereColumn('m.reference_id', 'o.id')->where('m.reference_type', 'orders')
                ->whereIn('m.reason', InventoryService::SELLING_REASONS))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('inventory_movements as m')
                ->whereColumn('m.reference_id', 'o.id')->where('m.reference_type', 'orders')
                ->whereIn('m.reason', InventoryService::RELEASE_REASONS))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('payment_statuses as ps')
                ->whereColumn('ps.order_id', 'o.id')
                // `outcome` since wave 4C; the legacy `success` enum on attempts written before it.
                ->where(fn (Builder $w) => $w->where('ps.outcome', CallbackPolicy::OUTCOME_SUCCESS)->orWhere('ps.success', 'true')));
    }
}
