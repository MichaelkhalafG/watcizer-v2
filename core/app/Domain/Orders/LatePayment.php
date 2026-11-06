<?php

declare(strict_types=1);

namespace App\Domain\Orders;

use App\Domain\Activity\ActivityLog;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientOfferStock;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Money that arrived for an order which had already been cancelled for non-payment (2026-11-05).
 *
 * ── The case ─────────────────────────────────────────────────────────────────────────────────
 *
 * Order 000092 (Watchizer, valU, 4,445 EGP): the first attempt was declined and core cancelled the
 * order at once, returning its stock. Two minutes later the shopper tried again on the same payment
 * page and it succeeded. `CallbackPolicy` recorded the attempt and a finding and left the order
 * cancelled — the rule then was "a callback never moves an order out of a terminal state, and
 * never re-reserves stock" — and nothing told anybody. The customer was charged for an order the
 * shop did not know it owed.
 *
 * A decline no longer cancels (`CallbackPolicy`, correction 1), so that exact sequence now pays the
 * order through the ordinary branch. This class is the SECOND net, for every way a success can
 * still land on a cancelled order: the hour ran out and `orders:expire-unpaid` cancelled it while
 * the page was still open; the shopper placed another order, which superseded this one, and then
 * paid the first page anyway; an order cancelled by the old rule before this deploy.
 *
 * ── What it does, and the one thing it will not ──────────────────────────────────────────────
 *
 * It reserves every line again through `InventoryService::reinstateOrder()` — the ledger's
 * `order_reinstate` movements, all or nothing — and only then marks the order paid. If any unit is
 * gone, or a product has been withdrawn since, NOTHING is written: the caller raises the finding
 * and the alert, and a person refunds or sources the watch. It never oversells to make a payment
 * fit.
 *
 * It brings back only an order that was cancelled FOR NON-PAYMENT (its last release is
 * `payment_failed`: a decline under the old rule, an expiry, a supersede). An order a person
 * cancelled from the dashboard (`order_cancel`) is refused unless the caller says so explicitly
 * (`orders:reinstate-paid --cancelled-by-operator`): someone decided that, and money arriving
 * afterwards is a refund question, not a status change.
 *
 * ── One implementation, two callers ──────────────────────────────────────────────────────────
 *
 * The payment callback and `orders:reinstate-paid` both call {@see self::reinstate()} (AGENTS §3:
 * a screen and a command that make the same dangerous decision share the CODE). {@see self::assess()}
 * is the read-only half the command prints as its dry run.
 */
final class LatePayment
{
    public const NOT_FOUND = 'order_not_found';

    public const NOT_CANCELLED = 'not_cancelled';

    public const NOT_CARD = 'not_a_card_order';

    public const NEVER_RELEASED = 'no_released_stock';

    public const BY_OPERATOR = 'cancelled_by_operator';

    public const ALREADY = 'already_reinstated';

    public const NO_STOCK = 'insufficient_stock';

    public const UNSELLABLE = 'no_longer_sellable';

    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * What a reinstatement would find, WITHOUT writing: the order's state, why it was cancelled, and
     * per line what is on the shelf now against what the order needs.
     *
     * @return array{
     *     ok: bool,
     *     reason: string|null,
     *     order: array{id: int, number: string, status: string, storefront_id: int|null, total: string}|null,
     *     cancelled_by: string|null,
     *     lines: list<array{line_id: int, product_id: int|null, variant_id: int|null, offer_id: int|null, name: string, bucket: string, quantity: int, available: int|null, enough: bool, sellable: bool}>,
     *     newer_orders: list<string>
     * }
     */
    public function assess(int $orderId, bool $allowOperatorCancelled = false): array
    {
        $raw = DB::table('orders')->where('id', $orderId)
            ->first(['id', 'order_number', 'status', 'storefront_id', 'payment_method', 'total_price_for_order']);
        if (! is_object($raw)) {
            return ['ok' => false, 'reason' => self::NOT_FOUND, 'order' => null, 'cancelled_by' => null, 'lines' => [], 'newer_orders' => []];
        }
        $row = Row::cast($raw);
        $order = [
            'id' => Row::int($row, 'id'),
            'number' => Row::nstr($row, 'order_number') ?? ('#'.$orderId),
            'status' => Row::str($row, 'status'),
            'storefront_id' => Row::nint($row, 'storefront_id'),
            'total' => Row::str($row, 'total_price_for_order'),
        ];

        $cancelledBy = $this->lastReleaseReason($orderId);
        $lines = $this->lines($orderId);
        $newer = self::numbers(OrderRecovery::laterLiveOrders($orderId));

        $reason = match (true) {
            $order['status'] !== 'cancelled' => self::NOT_CANCELLED,
            Row::nstr($row, 'payment_method') !== 'paymob' => self::NOT_CARD,
            $this->inventory->isReinstated($orderId) => self::ALREADY,
            $cancelledBy === null => self::NEVER_RELEASED,
            $cancelledBy !== 'payment_failed' && ! $allowOperatorCancelled => self::BY_OPERATOR,
            default => null,
        };
        if ($reason === null) {
            foreach ($lines as $line) {
                if (! $line['sellable']) {
                    $reason = self::UNSELLABLE;
                    break;
                }
                if (! $line['enough']) {
                    $reason = self::NO_STOCK;
                    break;
                }
            }
        }

        return ['ok' => $reason === null, 'reason' => $reason, 'order' => $order, 'cancelled_by' => $cancelledBy, 'lines' => $lines, 'newer_orders' => $newer];
    }

    /**
     * Bring the order back: reserve its lines again, mark it paid, write the activity entry.
     *
     * Runs in a transaction of its own (a savepoint inside the caller's). On a refusal nothing of
     * this call is left behind and `ok` is false with the reason — the caller decides what to record.
     *
     * @return array{ok: bool, reason: string|null, movements: int, newer_orders: list<string>, order_number: string|null}
     */
    public function reinstate(
        int $orderId,
        string $provider,
        ?string $method,
        Actor $actor,
        string $note,
        bool $allowOperatorCancelled = false,
    ): array {
        try {
            return DB::transaction(function () use ($orderId, $provider, $method, $actor, $note, $allowOperatorCancelled): array {
                // The order row first — the one lock order (orders → products → variants).
                $raw = DB::table('orders')->where('id', $orderId)->lockForUpdate()
                    ->first(['id', 'order_number', 'status', 'storefront_id', 'payment_method']);
                if (! is_object($raw)) {
                    return self::refused(self::NOT_FOUND);
                }
                $row = Row::cast($raw);
                $number = Row::nstr($row, 'order_number');
                $storefrontId = Row::nint($row, 'storefront_id');

                if (Row::str($row, 'status') !== 'cancelled') {
                    return self::refused(self::NOT_CANCELLED, $number);
                }
                if (Row::nstr($row, 'payment_method') !== 'paymob') {
                    return self::refused(self::NOT_CARD, $number);
                }
                if ($this->inventory->isReinstated($orderId)) {
                    return self::refused(self::ALREADY, $number);
                }
                $cancelledBy = $this->lastReleaseReason($orderId);
                if ($cancelledBy === null) {
                    return self::refused(self::NEVER_RELEASED, $number);
                }
                if ($cancelledBy !== 'payment_failed' && ! $allowOperatorCancelled) {
                    return self::refused(self::BY_OPERATOR, $number);
                }

                // Throws when a unit is gone or a product was withdrawn: caught below, after this
                // transaction has rolled back, so a half-reserved order cannot exist.
                $movements = $this->inventory->reinstateOrder($orderId, $actor, $storefrontId, $note);

                DB::table('orders')->where('id', $orderId)->update([
                    'status' => 'processing',
                    'paid_via_provider' => $provider,
                    'paid_via_method' => $method,
                    'updated_at' => now(),
                ]);

                ActivityLog::record(
                    'orders',
                    $orderId,
                    ActivityLog::UPDATED,
                    ['status' => 'cancelled', 'note' => null, 'stock_movements' => 0],
                    ['status' => 'processing', 'note' => $note, 'stock_movements' => count($movements)],
                    $number === null || trim($number) === '' ? '#'.$orderId : $number,
                    $storefrontId,
                );

                return [
                    'ok' => true,
                    'reason' => null,
                    'movements' => count($movements),
                    'newer_orders' => self::numbers(OrderRecovery::laterLiveOrders($orderId)),
                    'order_number' => $number,
                ];
            });
        } catch (InsufficientStock|InsufficientOfferStock) {
            return self::refused(self::NO_STOCK);
        } catch (InvalidArgumentException) {
            // `assertSellable()`: the product or the variant was withdrawn since the order was placed.
            return self::refused(self::UNSELLABLE);
        } catch (LogicException) {
            return self::refused(self::ALREADY);
        }
    }

    /** The sentence for a refusal — English, for the command and the log; the screen has its own. */
    public static function explain(string $reason): string
    {
        return match ($reason) {
            self::NOT_FOUND => 'the order does not exist',
            self::NOT_CANCELLED => 'the order is not cancelled, so there is nothing to bring back',
            self::NOT_CARD => 'the order is not an online-payment order',
            self::NEVER_RELEASED => 'the ledger holds no released stock for this order (it never reserved any, or was cancelled outside the service)',
            self::BY_OPERATOR => 'the order was cancelled by a person from the dashboard, not for non-payment — pass --cancelled-by-operator to bring it back anyway',
            self::ALREADY => 'the order was already reinstated once',
            self::NO_STOCK => 'the stock is no longer there: at least one line cannot be reserved again',
            self::UNSELLABLE => 'a product or a variant on the order has been withdrawn from sale since',
            default => $reason,
        };
    }

    /** `payment_failed` (a decline, an expiry, a supersede), `order_cancel` (a person), or null. */
    private function lastReleaseReason(int $orderId): ?string
    {
        $reason = DB::table('inventory_movements')
            ->where('reference_type', 'orders')->where('reference_id', $orderId)
            ->whereIn('reason', InventoryService::RELEASE_REASONS)
            ->orderByDesc('id')->value('reason');

        return is_string($reason) ? $reason : null;
    }

    /**
     * @return list<array{line_id: int, product_id: int|null, variant_id: int|null, offer_id: int|null, name: string, bucket: string, quantity: int, available: int|null, enough: bool, sellable: bool}>
     */
    private function lines(int $orderId): array
    {
        $out = [];
        foreach (
            DB::table('order_items as i')
                ->leftJoin('catalog_products as p', 'p.id', '=', 'i.product_id')
                ->leftJoin('catalog_product_variants as v', 'v.id', '=', 'i.variant_id')
                ->leftJoin('catalog_product_translations as t', fn (JoinClause $join) => $join->on('t.product_id', '=', 'i.product_id')->where('t.locale', 'en'))
                ->where('i.order_id', $orderId)->orderBy('i.id')
                ->get([
                    'i.id', 'i.product_id', 'i.variant_id', 'i.offer_id', 'i.quantity', 'i.type_stock',
                    'p.stock_express as p_express', 'p.stock_market as p_market', 'p.is_active as p_active', 'p.deleted_at as p_deleted',
                    'v.stock_express as v_express', 'v.stock_market as v_market', 'v.is_active as v_active', 'v.label as v_label',
                    't.title as title',
                ]) as $raw
        ) {
            $row = Row::cast($raw);
            $productId = Row::nint($row, 'product_id');
            $variantId = Row::nint($row, 'variant_id');
            $offerId = Row::nint($row, 'offer_id');
            $quantity = Row::int($row, 'quantity');
            $bucket = InventoryService::bucketForTypeStock(Row::nstr($row, 'type_stock'));

            $available = null;
            $sellable = true;
            if ($productId !== null) {
                $target = StockTarget::fromLine($productId, $variantId);
                $available = $this->inventory->ledgerQuantity($target, $bucket);
                $column = $variantId !== null
                    ? Row::nint($row, $bucket === InventoryService::BUCKET_EXPRESS ? 'v_express' : 'v_market')
                    : Row::nint($row, $bucket === InventoryService::BUCKET_EXPRESS ? 'p_express' : 'p_market');
                // The COLUMN is what `apply()` decrements; the ledger sum is shown only if the
                // column is missing (a deleted row), which is itself unsellable.
                $available = $column ?? $available;
                $sellable = Row::nstr($row, 'p_deleted') === null && Row::bool($row, 'p_active')
                    && ($variantId === null || Row::bool($row, 'v_active'));
            } elseif ($offerId !== null) {
                $stock = DB::table('offers')->where('id', $offerId)->value('stock');
                $available = is_numeric($stock) ? (int) $stock : 0;
            }

            $name = Row::nstr($row, 'title') ?? ($offerId !== null ? 'offer #'.$offerId : 'product #'.($productId ?? 0));
            $label = Row::nstr($row, 'v_label');

            $out[] = [
                'line_id' => Row::int($row, 'id'),
                'product_id' => $productId,
                'variant_id' => $variantId,
                'offer_id' => $offerId,
                'name' => $label === null ? $name : $name.' — '.$label,
                'bucket' => $bucket,
                'quantity' => $quantity,
                'available' => $available,
                'enough' => $quantity < 1 || ($available !== null && $available >= $quantity),
                'sellable' => $sellable,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private static function numbers(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('orders')->whereIn('id', $ids)->orderBy('id')->get(['id', 'order_number']) as $raw) {
            $out[] = Row::nstr($raw, 'order_number') ?? ('#'.Row::int($raw, 'id'));
        }

        return $out;
    }

    /** @return array{ok: bool, reason: string|null, movements: int, newer_orders: list<string>, order_number: string|null} */
    private static function refused(string $reason, ?string $number = null): array
    {
        return ['ok' => false, 'reason' => $reason, 'movements' => 0, 'newer_orders' => [], 'order_number' => $number];
    }
}
