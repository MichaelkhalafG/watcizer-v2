<?php

namespace App\Domain\Inventory;

use App\Domain\Activity\ActivityLog;
use App\Events\StockChanged;
use App\Models\Inventory\InventoryMovement;
use App\Support\DeadlockRetry;
use App\Support\Sql;
use App\Transform\Row;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

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
 *
 * ── Wave 3.5: variants, and which level is AUTHORITATIVE ─────────────────────────────────────
 *
 * A movement targets a product OR one variant of it ({@see StockTarget}), and which is legal is a
 * property of the data rather than the caller's choice:
 *
 *  • **A product with NO variants** behaves exactly as it did before wave 3.5. Its own columns are
 *    authoritative, `Σ quantity_delta = catalog_products.stock_*`, and nothing about the watch and
 *    bag catalogue changes. A variant-targeted movement against such a product is refused.
 *  • **A product WITH variants** is authoritative at the VARIANT: `Σ quantity_delta per variant =
 *    catalog_product_variants.stock_*`. `catalog_products.stock_*` becomes a **maintained
 *    aggregate** — the same delta is applied to it in the same transaction, so it is always the
 *    exact sum of its variants without ever being recomputed. A product-targeted movement against
 *    such a product is refused, which is what stops the aggregate drifting away from its parts.
 *
 * `catalog_products.in_stock` is NOT the aggregate of the quantities: for a variant product it
 * means "some ACTIVE variant has stock", so a deactivated variant's units still count in the
 * quantity (they exist in the warehouse and the ledger must balance) but never make the product
 * look orderable.
 *
 * ── THE LOCK ORDER (review 2026-09-10, 🔴-1) ─────────────────────────────────────────────────
 *
 * Every row lock this class takes is acquired in ONE order:
 *
 *     orders  →  catalog_products  →  catalog_product_variants
 *
 * and there is exactly ONE piece of code that acquires stock locks: `apply()`. `adjust()` and
 * `set()` are thin argument-checking wrappers around it, so a second lock order cannot be
 * introduced by writing a second entry point. The first version of wave 3.5 got this wrong in
 * precisely that way: `set()` opened its own transaction, took the VARIANT row's lock to read the
 * current quantity, and only then called `adjust()`, which takes the PARENT product's lock first —
 * an inverted pair that deadlocks against any concurrent `adjust()` on the same variant. An
 * absolute set now computes its delta from a locked read taken INSIDE `apply()`, after the parent
 * lock, so the two paths are indistinguishable from the database's point of view.
 *
 * `offers.stock` (legacy table, `adjustOffer()`) is a single conditional UPDATE that takes no other
 * lock and holds nothing across statements, so it cannot take part in a cycle.
 */
final class InventoryService
{
    public const BUCKET_EXPRESS = 'express';

    public const BUCKET_MARKET = 'market';

    /** `inventory_movements.reason` — the closed set from the schema comment (M1). */
    public const REASONS = [
        // `promotion_reward` (wave 4D) is the reservation of a GIFT a promotion granted. It is a
        // separate reason and not `order` because the ledger is what an operator reads to answer
        // "where did these units go?", and "a promotion gave them away" is a different answer
        // from "a customer bought them" — the same distinction `order_cancel` and
        // `payment_failed` already make on the way back.
        'order', 'promotion_reward', 'order_cancel', 'payment_failed', 'restock', 'manual',
        'import', 'adjustment', 'erp_sync', 'transform',
    ];

    /** Reasons that RETURN stock reserved by an order; used to make a release idempotent. */
    public const RELEASE_REASONS = ['order_cancel', 'payment_failed'];

    /**
     * Reasons that mean SOMETHING LEFT THE SHOP FOR A CUSTOMER — the set the sellable guard polices.
     *
     * Named rather than inlined because the guard was originally written against the literal
     * `'order'`, and when wave 4D added `promotion_reward` — units given away by a promotion, which
     * is every bit as much a thing leaving the shop — it walked straight past a check that was
     * looking for one string (review 🔴-3). A list with a name is a list the next reason gets added
     * to; a literal in an `if` is not.
     */
    public const SELLING_REASONS = ['order', 'promotion_reward'];

    /** @return array{express: string, market: string} */
    public static function columns(): array
    {
        return ['express' => 'stock_express', 'market' => 'stock_market'];
    }

    /**
     * The dashboard's ONE definition of a sellable product.
     *
     * Everything the two stock screens count is counted off this base, so the KPI on `/manage`,
     * the banner on `/manage/inventory` and the rows behind the `مخزون منخفض` filter cannot
     * disagree — which they did, loudly, until 2026-09-19: the home tile said 4,946 and the
     * banner said 7,524 for the same words on two screens an operator reads in the same minute.
     * The predicate had been written out by hand in three places and two of them had drifted.
     *
     * Deleted and deactivated products are out because neither can be bought: an alert the team
     * cannot act on is not an alert.
     */
    public static function sellableProducts(): QueryBuilder
    {
        return DB::table('catalog_products')->whereNull('deleted_at')->where('is_active', 1);
    }

    /**
     * At or below the product's OWN threshold, and still orderable.
     *
     * `in_stock = 1` is the half that was missing from the banner. A product with nothing left is
     * not *low* — it is GONE, and it is counted by {@see self::outOfStockProducts()} under its own
     * name. Adding the 2,578 out-of-stock rows to the 4,946 genuinely-low ones produced an alarm
     * covering 97.5% of the catalogue, which is a number nobody reads twice.
     */
    public static function lowStockProducts(): QueryBuilder
    {
        return self::sellableProducts()->where('in_stock', 1)->whereRaw(Sql::belowLowStockThreshold());
    }

    /** Nothing left in either bucket. Separate from "low" because the answer is different. */
    public static function outOfStockProducts(): QueryBuilder
    {
        return self::sellableProducts()->where('in_stock', 0);
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
        StockTarget $target,
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

        return $this->apply($target, $bucket, $column, $delta, null, $reason, $ref, $actor, $storefrontId, $externalRef, $note)
            ?? throw new RuntimeException('adjust() with a non-zero delta must always write a movement.');
    }

    /**
     * Absolute set (dashboard form, XLSX import, ERP sync). Returns null when the bucket already
     * holds that quantity — an import that changes nothing must not grow the ledger.
     *
     * It takes NO locks and opens NO transaction of its own: the current quantity is read under
     * `apply()`'s locks, in the one canonical order, and the delta is computed there. See the
     * class docblock — this is 🔴-1 of the 2026-09-10 review.
     */
    public function set(
        StockTarget $target,
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

        return $this->apply($target, $bucket, $column, null, $quantity, $reason, $ref, $actor, $storefrontId, $externalRef, $note);
    }

    /**
     * THE one writer of a stock column, and the one acquirer of stock locks.
     *
     * Exactly one of `$delta` (relative, from `adjust()`) and `$absolute` (a target quantity, from
     * `set()`) is non-null. The absolute form resolves to a delta inside the transaction, after the
     * locks, which is what lets both public methods share a single acquisition order.
     *
     * Returns null only for an absolute set that asked for the quantity the bucket already holds.
     *
     * @throws InsufficientStock when a decrement would take the bucket below zero
     */
    private function apply(
        StockTarget $target,
        string $bucket,
        string $column,
        ?int $delta,
        ?int $absolute,
        string $reason,
        ?Reference $ref,
        ?Actor $actor,
        ?int $storefrontId,
        ?string $externalRef,
        ?string $note,
    ): ?InventoryMovement {
        $actor ??= Actor::system();
        $productId = $target->productId;

        /** @var array{movement: InventoryMovement, after: int, delta: int}|null $result */
        $result = StockWriteGuard::allow(fn (): ?array => $this->transactionWithLockRetry(function () use ($target, $productId, $column, $bucket, $delta, $absolute, $reason, $ref, $actor, $storefrontId, $externalRef, $note): ?array {
            $this->assertTarget($target);
            $other = $column === 'stock_express' ? 'stock_market' : 'stock_express';

            if ($target->isVariant()) {
                // LOCK THE PARENT FIRST. Two variants of one product each update their own row and
                // then the shared parent, and the parent's in_stock is re-derived by a subquery
                // that READS the sibling variants — so transaction A (holding variant 1) and
                // transaction B (holding variant 2) each end up wanting a row the other holds.
                // That is a genuine deadlock, and `inventory:prove-concurrency --variants=2` found
                // it on its first run: MariaDB killed eleven of twenty-four workers with error
                // 1213 and the stock landed wrong.
                //
                // Taking the product row's write lock BEFORE the variant row gives every actor the
                // same acquisition order — order row, then product row, then variant row — so a
                // cycle cannot form. The cost is that sales of DIFFERENT variants of the SAME
                // product serialise; sales of different products stay fully parallel. That cost is
                // inherent to keeping the parent aggregate exact, and it is the trade wave 3.5
                // chose deliberately over an aggregate that drifts.
                DB::table('catalog_products')->where('id', $productId)->lockForUpdate()->value('id');
            }

            if ($absolute !== null) {
                // The absolute path resolves to a delta HERE, under the locks above, which is the
                // whole point: `set()` no longer takes a lock of its own and therefore cannot
                // invert the order (review 2026-09-10 🔴-1).
                $row = DB::table($target->table())->where('id', $target->rowId())->lockForUpdate()->first(['id', $column]);
                if (! $row instanceof \stdClass) {
                    throw new InvalidArgumentException($target->table().' row '.$target->rowId().' does not exist.');
                }
                $delta = $absolute - Row::int($row, $column);
                if ($delta === 0) {
                    return null;
                }
            }
            if ($delta === null || $delta === 0) {
                throw new RuntimeException('apply() needs exactly one of a non-zero delta or an absolute quantity.');
            }

            // Direction-dependent rules, now that the delta is known whichever door we came in by.
            $this->assertSellable($target, $delta, $reason);

            // The AUTHORITATIVE row: the variant when there is one, the product otherwise.
            $query = DB::table($target->table())->where('id', $target->rowId());
            if ($delta < 0) {
                $query->where($column, '>=', -$delta);
            }
            $values = [$column => Sql::delta($column, $delta), 'updated_at' => now()];
            if (! $target->isVariant()) {
                // A product without variants keeps wave 3's exact behaviour, including in_stock.
                $values['in_stock'] = Sql::eitherBucketPositive($column, $other, $delta);
            }
            $affected = $query->update($values);

            if ($affected === 0) {
                // Either the row is gone or the bucket cannot cover the decrement. Tell the two
                // apart so a caller does not report "insufficient stock" for a missing row.
                $exists = DB::table($target->table())->where('id', $target->rowId())->exists();
                if (! $exists) {
                    throw new InvalidArgumentException($target->table()." row {$target->rowId()} does not exist.");
                }
                throw new InsufficientStock($productId, $bucket, -$delta, $target->variantId);
            }

            if ($target->isVariant()) {
                // The product columns follow by the SAME delta, so the aggregate is exact without
                // ever being recomputed; in_stock is re-derived from the ACTIVE variants only.
                $this->applyVariantAggregate($productId, $column, $delta);
            }

            $afterValue = DB::table($target->table())->where('id', $target->rowId())->lockForUpdate()->value($column);
            $after = (int) (is_numeric($afterValue) ? $afterValue : 0);

            $movement = InventoryMovement::create([
                'product_id' => $productId,
                'variant_id' => $target->variantId,
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

            return ['movement' => $movement, 'after' => $after, 'delta' => $delta];
        }));

        if ($result === null) {
            return null;
        }

        DB::afterCommit(fn () => event(new StockChanged($productId, $bucket, $result['delta'], $result['after'], $reason, $ref, $storefrontId, $externalRef, $target->variantId)));

        /*
         * ── The activity log, for HUMAN adjustments only ─────────────────────────────────────
         *
         * The ledger already answers "who moved these units" for every movement — `actor_type` and
         * `actor_id` are on the row, and it is the authority. This second entry exists so the
         * activity screen can answer "who changed what" in ONE place, without the operator having
         * to know that stock lives somewhere else.
         *
         * Filtered to a real person on purpose. Every order writes movements, so logging all of
         * them would bury the handful of edits a human made under a day's trading — and "the
         * checkout reduced stock" is not an audit question. `Actor::SYSTEM` and `Actor::ERP` are
         * the ledger's business; this table is about people.
         */
        if (in_array($actor->type, [Actor::USER, Actor::ADMIN], true)) {
            ActivityLog::record(
                $target->isVariant() ? 'catalog_product_variants' : 'catalog_products',
                $target->isVariant() ? $target->variantId : $productId,
                ActivityLog::ADJUSTED,
                [$bucket => $result['after'] - $result['delta']],
                [$bucket => $result['after']],
                label: $note,
                storefrontId: $storefrontId,
            );
        }

        return $result['movement'];
    }

    /**
     * Run one stock transaction, retrying a deadlock victim a bounded number of times with jitter
     * (review 2026-09-10 🔴-1b). See {@see DeadlockRetry::ATTEMPTS} for the count and why.
     *
     * A retry is only ours to make when we OWN the outermost transaction. Inside a caller's
     * transaction — the compat checkout wraps the order insert, the stock commit and the payment
     * row in one — MariaDB has already rolled that WHOLE transaction back, so re-running our part
     * would write into a dead transaction and quietly lose the caller's other work. There the
     * exception is re-thrown and the owner of the transaction decides.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function transactionWithLockRetry(Closure $callback): mixed
    {
        /*
         * The policy itself moved to `App\Support\DeadlockRetry` on 2026-09-13 so the payment
         * callbacks use the SAME one rather than a second copy: they lock the same rows in the same
         * order as this service, so they deadlock against it, and two implementations would drift
         * on attempts, jitter and what counts as a deadlock. Behaviour here is unchanged —
         * `DeadlockRetry::ATTEMPTS` is this class's old `LOCK_RETRY_ATTEMPTS` and the jitter is the
         * same expression.
         */
        return DeadlockRetry::run($callback);
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
        return $this->transactionWithLockRetry(function () use ($orderId, $actor, $storefrontId): array {
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
                    /*
                     * A REWARD line reserves exactly like a paid one and records a different
                     * reason (wave 4D, study §3.16.4). Doing it here, inside the loop this
                     * transaction already holds locks for, is what gives reward stock wave 3's
                     * guarantees for free: the order row is locked, so two concurrent commits
                     * cannot both reserve the gift, and `releaseOrder()` walks the same lines so a
                     * cancellation returns it with no new code at all.
                     */
                    $reason = Row::bool($line, 'is_reward') ? 'promotion_reward' : 'order';

                    $movements[] = $this->adjust(
                        StockTarget::fromLine($productId, Row::nint($line, 'variant_id')),
                        self::bucketForTypeStock(Row::nstr($line, 'type_stock')),
                        -$qty,
                        $reason,
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
    public function releaseOrder(int $orderId, string $reason, ?Actor $actor = null, ?int $storefrontId = null, ?string $note = null): array
    {
        if (! in_array($reason, self::RELEASE_REASONS, true)) {
            throw new InvalidArgumentException('releaseOrder() reason must be one of '.implode(', ', self::RELEASE_REASONS).", got [{$reason}].");
        }

        return $this->transactionWithLockRetry(function () use ($orderId, $reason, $actor, $storefrontId, $note): array {
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
                        StockTarget::fromLine($productId, Row::nint($line, 'variant_id')),
                        self::bucketForTypeStock(Row::nstr($line, 'type_stock')),
                        $qty,
                        $reason,
                        Reference::orderLine($orderId, Row::int($line, 'id')),
                        $actor,
                        $storefrontId,
                        // The operator's reason travels onto EVERY line's movement. A cancellation
                        // note that stopped at the controller would leave the ledger saying
                        // "order_cancel" and nothing about why — which is the question anyone
                        // reading the row a month later is actually asking.
                        note: $note,
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
    public function rebase(StockTarget $target, string $bucket, ?string $note = null): ?InventoryMovement
    {
        $column = self::assertBucket($bucket);
        $current = DB::table($target->table())->where('id', $target->rowId())->value($column);
        if ($current === null) {
            return null;
        }
        $after = (int) (is_numeric($current) ? $current : 0);
        $delta = $after - $this->ledgerQuantity($target, $bucket);
        if ($delta === 0) {
            return null;
        }

        return InventoryMovement::create([
            'product_id' => $target->productId,
            'variant_id' => $target->variantId,
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
    public function ledgerQuantity(StockTarget $target, string $bucket): int
    {
        self::assertBucket($bucket);

        $query = DB::table('inventory_movements')->where('bucket', $bucket);
        $target->isVariant()
            ? $query->where('variant_id', $target->variantId)
            : $query->where('product_id', $target->productId)->whereNull('variant_id');

        return (int) $query->sum('quantity_delta');
    }

    /**
     * The product columns follow a variant movement by the same delta, and `in_stock` is
     * re-derived from the ACTIVE variants in the same statement.
     *
     * No conditional guard here on purpose: the guard belongs on the authoritative row, which has
     * already accepted the change. Guarding the aggregate too would let a drifted product column
     * veto a perfectly legal variant sale, which is the wrong failure — the aggregate is derived,
     * so it follows rather than decides.
     */
    private function applyVariantAggregate(int $productId, string $column, int $delta): void
    {
        DB::table('catalog_products')->where('id', $productId)->update([
            $column => Sql::delta($column, $delta),
            'in_stock' => Sql::anyActiveVariantInStock(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The rule a caller cannot opt out of: a product with variants is moved ONLY through a
     * variant, and a product without them ONLY directly. Everything else would let
     * `catalog_products.stock_*` stop being the sum of its parts.
     */
    private function assertTarget(StockTarget $target): void
    {
        $hasVariants = StockTarget::productHasVariants($target->productId);

        if ($target->isVariant()) {
            $belongs = DB::table('catalog_product_variants')
                ->where('id', $target->variantId)->where('product_id', $target->productId)->exists();
            if (! $belongs) {
                throw new InvalidArgumentException(
                    "Variant {$target->variantId} does not belong to product {$target->productId}."
                );
            }

            return;
        }

        if ($hasVariants) {
            throw new InvalidArgumentException(
                "Product {$target->productId} has variants, so its stock moves through a variant, ".
                'never through the product. Use StockTarget::variant().'
            );
        }
    }

    /**
     * NOTHING WITHDRAWN FROM SALE MAY BE SOLD — product or variant (review 🔴-3, 2026-09-15;
     * originally variant-only, review 2026-09-10 🟠-3).
     *
     * Deactivating a product or a size is how the team takes it off sale: it disappears from every
     * listing and stops counting toward `in_stock`. But its units are still in the warehouse and
     * the ledger still balances on them, so nothing in the quantity arithmetic stopped a SALE from
     * decrementing it — a stale cart line, a queued order, a promotion reward, or a v2 checkout
     * holding an id from before the deactivation would have sold what the shop had withdrawn.
     *
     * THE SINGLE DOOR. This lives here, in the service every movement passes through, rather than
     * in each caller — so the cart, the checkout, the promotion engine and anything written later
     * are covered by construction instead of by remembering.
     *
     * The rule is therefore about DIRECTION and INTENT, not about the row being locked:
     *
     *  • `reason = order` with a negative delta → REFUSED. That is a sale.
     *  • Any positive delta → allowed. A release (`order_cancel`, `payment_failed`) must be able to
     *    give back stock it took while the variant was still active; refusing it would lose units.
     *  • A negative ADMINISTRATIVE delta (`manual`, `import`, `adjustment`, `erp_sync`,
     *    `transform`) → allowed, deliberately wider than the review asked for. Refusing every
     *    negative delta would make a withdrawn size impossible to correct, zero out or retire, and
     *    would leave `inventory:verify --fix` unable to re-base a drifted inactive variant — a
     *    worse failure than the one being fixed, and one the verifier could not route around.
     *
     * The compat layer never reaches this: a product with variants — active or not — is refused at
     * `add_to_cart` and again at `priceLines()`. This is the net under the future v2 checkout,
     * which maps it to the same 422 body as the conversion case (study §3.10.4).
     */
    private function assertSellable(StockTarget $target, int $delta, string $reason): void
    {
        // Direction and intent first: nothing below applies to a return, a correction or a restock.
        if ($delta >= 0 || ! in_array($reason, self::SELLING_REASONS, true)) {
            return;
        }

        /*
         * THE PRODUCT, checked for every target — including a variant's parent.
         *
         * This is the half review 🔴-3 found missing. Deactivating a PRODUCT is how the team takes
         * it off sale, and `catalog_products.is_active = 0` removed it from every listing while
         * leaving the quantity arithmetic untouched — so a stale cart line, a queued order or a
         * promotion reward could still decrement it. A withdrawn variant was refused and a
         * withdrawn product was not, which is the less likely case guarded and the more likely one
         * open.
         *
         * `deleted_at` is checked in the same breath: a soft-deleted product is further off sale
         * than an inactive one, and the compat door's `exists` rule was the only thing looking.
         */
        $row = DB::table('catalog_products')
            ->where('id', $target->productId)
            ->first(['is_active', 'deleted_at']);

        if ($row === null) {
            throw new InvalidArgumentException("Product {$target->productId} does not exist, so it cannot be sold.");
        }
        $product = Row::cast($row);
        if (Row::nstr($product, 'deleted_at') !== null) {
            throw new InvalidArgumentException(
                "Product {$target->productId} is archived, so it cannot be sold. ".
                'Its stock stays in the ledger and may still be corrected or released.'
            );
        }
        if (! Row::bool($product, 'is_active')) {
            throw new InvalidArgumentException(
                "Product {$target->productId} is not active, so it cannot be sold. ".
                'Its stock stays in the ledger and may still be corrected or released.'
            );
        }

        // …and THE VARIANT, when the units move through one.
        if (! $target->isVariant()) {
            return;
        }

        $active = DB::table('catalog_product_variants')->where('id', $target->variantId)->value('is_active');
        if ((int) (is_numeric($active) ? $active : 0) === 1) {
            return;
        }

        throw new InvalidArgumentException(
            "Variant {$target->variantId} of product {$target->productId} is not active, so it cannot be sold. ".
            'Its stock stays in the ledger and may still be corrected or released.'
        );
    }

    /** Does this product carry variants? Exposed for the checkout and the verifier. */
    public function hasVariants(int $productId): bool
    {
        return StockTarget::productHasVariants($productId);
    }

    /**
     * Re-derive `catalog_products.in_stock` for ONE product, at whichever level owns it.
     *
     * Activating or deactivating a variant is a CATALOG edit, not a stock movement — no units
     * move, so no ledger row is written — but it does change whether the product is orderable.
     * Wave 4's dashboard must call this after any change to `catalog_product_variants.is_active`,
     * and `inventory:verify` reports a product whose flag disagrees with its variants.
     *
     * **Safe on a product that has NO variants**, which it was not when wave 3.5 shipped: the
     * first version wrote `EXISTS(active variant with stock)` unconditionally, so calling it on a
     * plain stocked watch set `in_stock = 0` and removed it from every listing — 🟠-2 of the
     * 2026-09-10 review. The expression now tests the level first ({@see Sql::inStockAtEitherLevel}),
     * so the method is a no-op-shaped correction for a non-variant product rather than a
     * silent unpublish, and wave 4 can call it after ANY catalog edit without knowing the level.
     */
    public function recomputeInStock(int $productId): void
    {
        StockWriteGuard::allow(function () use ($productId): void {
            DB::table('catalog_products')->where('id', $productId)->update([
                'in_stock' => Sql::inStockAtEitherLevel(),
            ]);
        });
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function orderLines(int $orderId): Collection
    {
        /** @var Collection<int, \stdClass> $rows */
        $rows = DB::table('order_items')
            // `is_reward` since wave 4D: the reservation is identical, the ledger REASON is not.
            ->select(['id', 'product_id', 'variant_id', 'offer_id', 'quantity', 'type_stock', 'is_reward'])
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
