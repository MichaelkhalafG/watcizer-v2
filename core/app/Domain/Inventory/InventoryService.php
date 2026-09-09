<?php

namespace App\Domain\Inventory;

use App\Events\StockChanged;
use App\Models\Inventory\InventoryMovement;
use App\Support\Sql;
use App\Transform\Row;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single door for every stock mutation (CLEAN_CORE_STUDY §4.2, wave 3).
 *
 * One class, one table, one event. Nothing else in this application writes `stock_express`,
 * `stock_market`, `in_stock`, `catalog_product_variants.stock_*` or `offers.stock` —
 * {@see StockWriteGuard} enforces that at the SQL level outside production, and
 * `tests/Feature/Inventory/StockWriteGuardTest.php` proves both halves of the rule.
 *
 * Three properties hold for every method here:
 *
 *  1. **Atomic.** A decrement is a single conditional UPDATE (`WHERE col >= n`), the same guard
 *     the legacy checkout used, now applied to every mutation rather than just the sale path.
 *     Concurrency is proven with real concurrent processes by `inventory:prove-concurrency`.
 *  2. **Append-only.** Every movement is an INSERT. A correction is a new row with the opposite
 *     delta; no row is ever updated or deleted. `quantity_after` is read back inside the
 *     transaction, so the ledger's last value for a bucket always equals the column.
 *  3. **Observable.** `StockChanged` fires after commit, never inside the transaction, so a
 *     listener cannot roll a sale back by throwing.
 */
final class InventoryService
{
    public const BUCKET_EXPRESS = 'express';

    public const BUCKET_MARKET = 'market';

    /** `inventory_movements.reason` — the closed set from the schema comment (M1). */
    public const REASONS = [
        'order', 'order_cancel', 'payment_failed', 'restock', 'manual',
        'import', 'adjustment', 'erp_sync', 'transform',
    ];

    /** Reasons that RETURN stock reserved by an order; used to make a release idempotent. */
    public const RELEASE_REASONS = ['order_cancel', 'payment_failed'];

    /** @return array{express: string, market: string} */
    public static function columns(): array
    {
        return ['express' => 'stock_express', 'market' => 'stock_market'];
    }

    /** The legacy `type_stock` enum maps to a bucket exactly as the legacy checkout mapped it. */
    public static function bucketForTypeStock(?string $typeStock): string
    {
        // Legacy: `$field = $request->type_stock === 'Express' ? 'stock' : 'market_stock'` —
        // anything that is not the literal string 'Express', NULL included, means market.
        return $typeStock === 'Express' ? self::BUCKET_EXPRESS : self::BUCKET_MARKET;
    }

    /**
     * Apply a relative change to one bucket, atomically. Returns the ledger row.
     *
     * @throws InsufficientStock when a decrement would take the bucket below zero
     */
    public function adjust(
        int $productId,
        string $bucket,
        int $delta,
        string $reason,
        ?Reference $ref = null,
        ?Actor $actor = null,
        ?int $storefrontId = null,
        ?string $externalRef = null,
        ?string $note = null,
    ): InventoryMovement {
        $column = self::assertBucket($bucket);
        self::assertReason($reason);
        if ($delta === 0) {
            throw new InvalidArgumentException('adjust() needs a non-zero delta; use set() for an absolute value.');
        }
        $actor ??= Actor::system();

        /** @var array{movement: InventoryMovement, after: int} $result */
        $result = StockWriteGuard::allow(fn (): array => DB::transaction(function () use ($productId, $column, $bucket, $delta, $reason, $ref, $actor, $storefrontId, $externalRef, $note): array {
            $other = $column === 'stock_express' ? 'stock_market' : 'stock_express';

            $query = DB::table('catalog_products')->where('id', $productId);
            if ($delta < 0) {
                $query->where($column, '>=', -$delta);
            }
            $affected = $query->update([
                $column => Sql::delta($column, $delta),
                'in_stock' => Sql::eitherBucketPositive($column, $other, $delta),
                'updated_at' => now(),
            ]);

            if ($affected === 0) {
                // Either the product is gone or the bucket cannot cover the decrement. Tell the
                // two apart so a caller does not report "insufficient stock" for a missing row.
                $exists = DB::table('catalog_products')->where('id', $productId)->exists();
                if (! $exists) {
                    throw new InvalidArgumentException("catalog_products row {$productId} does not exist.");
                }
                throw new InsufficientStock($productId, $bucket, -$delta);
            }

            $afterValue = DB::table('catalog_products')->where('id', $productId)->lockForUpdate()->value($column);
            $after = (int) (is_numeric($afterValue) ? $afterValue : 0);

            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'variant_id' => null,
                'bucket' => $bucket,
                'quantity_delta' => $delta,
                'quantity_after' => $after,
                'reason' => $reason,
                'reference_type' => $ref?->type,
                'reference_id' => $ref?->id,
                'reference_line_id' => $ref?->lineId,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'storefront_id' => $storefrontId,
                'external_ref' => $externalRef,
                'note' => $note,
                'created_at' => now()->toDateTimeString(),
            ]);

            return ['movement' => $movement, 'after' => $after];
        }));

        DB::afterCommit(fn () => event(new StockChanged($productId, $bucket, $delta, $result['after'], $reason, $ref, $storefrontId, $externalRef)));

        return $result['movement'];
    }

    /**
     * Absolute set (dashboard form, XLSX import, ERP sync): computes the delta and delegates.
     * Returns null when the bucket already holds that quantity — an import that changes nothing
     * must not grow the ledger.
     */
    public function set(
        int $productId,
        string $bucket,
        int $quantity,
        string $reason,
        ?Reference $ref = null,
        ?Actor $actor = null,
        ?int $storefrontId = null,
        ?string $externalRef = null,
        ?string $note = null,
    ): ?InventoryMovement {
        $column = self::assertBucket($bucket);
        self::assertReason($reason);
        if ($quantity < 0) {
            throw new InvalidArgumentException('set() needs a quantity of zero or more.');
        }

        return DB::transaction(function () use ($productId, $column, $bucket, $quantity, $reason, $ref, $actor, $storefrontId, $externalRef, $note): ?InventoryMovement {
            $row = DB::table('catalog_products')->where('id', $productId)->lockForUpdate()->first(['id', $column]);
            if (! $row instanceof \stdClass) {
                throw new InvalidArgumentException("catalog_products row {$productId} does not exist.");
            }
            $current = Row::int($row, $column);
            $delta = $quantity - $current;
            if ($delta === 0) {
                return null;
            }

            return $this->adjust($productId, $bucket, $delta, $reason, $ref, $actor, $storefrontId, $externalRef, $note);
        });
    }

    /**
     * Reserve stock for an order that is already persisted: one movement per product line, in
     * `order_items.id` order, all-or-nothing (the caller's transaction owns the rollback).
     *
     * Takes the order row's write lock first, so two concurrent commits of the same order cannot
     * both decrement; M1e's unique index refuses the second set of movements underneath.
     *
     * Reads the lines back out of `order_items` rather than trusting an in-memory list, so the
     * ledger can never disagree with what the order actually says it sold.
     *
     * @return list<InventoryMovement>
     *
     * @throws InsufficientStock|InsufficientOfferStock
     */
    public function commitOrder(int $orderId, ?Actor $actor = null, ?int $storefrontId = null): array
    {
        return DB::transaction(function () use ($orderId, $actor, $storefrontId): array {
            // Serialise every actor on this order. Without it two concurrent commits each read
            // the same lines and each decrement, and the second decrement is stock nobody bought.
            $this->lockOrder($orderId);

            $movements = [];
            foreach ($this->orderLines($orderId) as $line) {
                $qty = Row::int($line, 'quantity');
                if ($qty < 1) {
                    continue;
                }
                $productId = Row::nint($line, 'product_id');
                if ($productId !== null) {
                    $movements[] = $this->adjust(
                        $productId,
                        self::bucketForTypeStock(Row::nstr($line, 'type_stock')),
                        -$qty,
                        'order',
                        Reference::orderLine($orderId, Row::int($line, 'id')),
                        $actor,
                        $storefrontId,
                    );

                    continue;
                }
                $offerId = Row::nint($line, 'offer_id');
                if ($offerId !== null) {
                    $this->adjustOffer($offerId, -$qty);
                }
            }

            return $movements;
        });
    }

    /**
     * Give an order's reserved stock back. Idempotent under CONCURRENCY, not merely in sequence:
     * the order row is locked before the "has this been released?" check, so the Paymob failure
     * path, a customer cancellation and the per-minute reconciler can fire for the same order at
     * the same instant and exactly one of them credits the stock. Proven with twelve real
     * processes by `inventory:prove-release-race`.
     *
     * @param  string  $reason  order_cancel | payment_failed
     * @return list<InventoryMovement>
     */
    public function releaseOrder(int $orderId, string $reason, ?Actor $actor = null, ?int $storefrontId = null): array
    {
        if (! in_array($reason, self::RELEASE_REASONS, true)) {
            throw new InvalidArgumentException('releaseOrder() reason must be one of '.implode(', ', self::RELEASE_REASONS).", got [{$reason}].");
        }

        return DB::transaction(function () use ($orderId, $reason, $actor, $storefrontId): array {
            // The lock comes BEFORE the check. `isReleased()` followed by a write is a
            // check-then-act: twelve concurrent cancellations all read "not released yet" and all
            // credit the stock back, which is the 🔴-1 the review found. Locking the order row
            // first makes the check and the write one indivisible step, and M1e's unique index
            // refuses the duplicate underneath even if a future caller forgets to come through
            // here.
            $this->lockOrder($orderId);

            if ($this->isReleased($orderId)) {
                return [];
            }

            $movements = [];
            foreach ($this->orderLines($orderId) as $line) {
                $qty = Row::int($line, 'quantity');
                if ($qty < 1) {
                    continue;
                }
                $productId = Row::nint($line, 'product_id');
                if ($productId !== null) {
                    $movements[] = $this->adjust(
                        $productId,
                        self::bucketForTypeStock(Row::nstr($line, 'type_stock')),
                        $qty,
                        $reason,
                        Reference::orderLine($orderId, Row::int($line, 'id')),
                        $actor,
                        $storefrontId,
                    );

                    continue;
                }
                $offerId = Row::nint($line, 'offer_id');
                if ($offerId !== null) {
                    $this->adjustOffer($offerId, $qty);
                }
            }

            return $movements;
        });
    }

    /**
     * Take the order row's write lock. Every stock path that touches an order takes it FIRST and
     * in the same order (order row, then product rows), so the two paths cannot deadlock against
     * each other. Called only from inside a transaction, which is what makes the lock last.
     */
    private function lockOrder(int $orderId): void
    {
        DB::table('orders')->where('id', $orderId)->lockForUpdate()->value('id');
    }

    /** Has this order already been credited back? True when any release movement references it. */
    public function isReleased(int $orderId): bool
    {
        return DB::table('inventory_movements')
            ->where('reference_type', 'orders')
            ->where('reference_id', $orderId)
            ->whereIn('reason', self::RELEASE_REASONS)
            ->exists();
    }

    /** Has this order ever reserved stock? False for an order whose lines were all offers. */
    public function isCommitted(int $orderId): bool
    {
        return DB::table('inventory_movements')
            ->where('reference_type', 'orders')
            ->where('reference_id', $orderId)
            ->where('reason', 'order')
            ->exists();
    }

    /**
     * Offers keep their own `offers.stock` column on the legacy table (study §4.2) and have no
     * ledger rows until offers are cleaned. The legacy code did `$offer->stock -= n; save()` —
     * a read-modify-write that two concurrent orders can interleave into an oversell. This is
     * the same conditional UPDATE the products use, so the race is gone.
     *
     * LOUD: this is the ONE place core writes a legacy table, and it writes it on the `default`
     * connection by design — the read-only `legacy` connection would refuse it. See the wave-3
     * flag list.
     *
     * @throws InsufficientOfferStock
     */
    public function adjustOffer(int $offerId, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        StockWriteGuard::allow(function () use ($offerId, $delta): void {
            $query = DB::table('offers')->where('id', $offerId);
            if ($delta < 0) {
                $query->where('stock', '>=', -$delta);
            }
            // updated_at is touched because the legacy code did (`$offer->stock -= n; $offer->save()`),
            // and `all_offer` is proxied, so the column is still read by the running legacy app.
            $affected = $query->update(['stock' => Sql::delta('stock', $delta), 'updated_at' => now()]);
            if ($affected === 0 && $delta < 0 && DB::table('offers')->where('id', $offerId)->exists()) {
                throw new InsufficientOfferStock($offerId, -$delta);
            }
        });
    }

    /**
     * Append a movement that makes the LEDGER agree with the column, without moving the column.
     *
     * The only writer of this is `inventory:verify --fix`. It exists because a drift means some
     * statement wrote the column behind the service's back: the column is what the storefront
     * actually sold against, so the column is the truth and the ledger is the thing that needs a
     * correcting entry. Appending one keeps the drift visible in the history, which an edit would
     * erase. Returns null when there is nothing to correct.
     */
    public function rebase(int $productId, string $bucket, ?string $note = null): ?InventoryMovement
    {
        $column = self::assertBucket($bucket);
        $current = DB::table('catalog_products')->where('id', $productId)->value($column);
        if ($current === null) {
            return null;
        }
        $after = (int) (is_numeric($current) ? $current : 0);
        $delta = $after - $this->ledgerQuantity($productId, $bucket);
        if ($delta === 0) {
            return null;
        }

        return InventoryMovement::create([
            'product_id' => $productId,
            'variant_id' => null,
            'bucket' => $bucket,
            'quantity_delta' => $delta,
            'quantity_after' => $after,
            'reason' => 'adjustment',
            'reference_type' => null,
            'reference_id' => null,
            'reference_line_id' => null,
            'actor_type' => Actor::SYSTEM,
            'actor_id' => null,
            'storefront_id' => null,
            'external_ref' => null,
            'note' => $note ?? 'ledger re-based onto the column',
            'created_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * The ledger's own answer for a bucket: Σ quantity_delta. `inventory:verify` compares it to
     * the column, which is the invariant that makes the ledger trustworthy.
     */
    public function ledgerQuantity(int $productId, string $bucket): int
    {
        self::assertBucket($bucket);

        return (int) DB::table('inventory_movements')
            ->where('product_id', $productId)->where('bucket', $bucket)
            ->sum('quantity_delta');
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function orderLines(int $orderId): Collection
    {
        /** @var Collection<int, \stdClass> $rows */
        $rows = DB::table('order_items')
            ->select(['id', 'product_id', 'offer_id', 'quantity', 'type_stock'])
            ->where('order_id', $orderId)
            ->orderBy('id')
            ->get();

        return $rows;
    }

    private static function assertBucket(string $bucket): string
    {
        $columns = self::columns();
        if (! isset($columns[$bucket])) {
            throw new InvalidArgumentException("Unknown stock bucket [{$bucket}]; expected express or market.");
        }

        return $columns[$bucket];
    }

    private static function assertReason(string $reason): void
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new InvalidArgumentException("Unknown movement reason [{$reason}]; expected one of ".implode(', ', self::REASONS).'.');
        }
    }
}
