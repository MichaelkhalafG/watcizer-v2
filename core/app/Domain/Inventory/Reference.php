<?php

namespace App\Domain\Inventory;

/**
 * What a movement points at: `reference_type` + `reference_id` on `inventory_movements`.
 *
 * The convention is the TABLE name for a shared commerce table (`orders`, `order_items`) and a
 * `legacy:` prefix for a row the transform read out of the frozen legacy set
 * (`legacy:products` — the step-20 baseline). Never a PHP class name: the ledger outlives the
 * class map, and the ERP connector reads it without this codebase.
 */
final class Reference
{
    public function __construct(
        public readonly string $type,
        public readonly int $id,
        public readonly ?int $lineId = null,
    ) {}

    /** An order as a whole: used for a lookup, never for a movement. */
    public static function order(int $orderId): self
    {
        return new self('orders', $orderId);
    }

    /**
     * One LINE of an order — the identity a stock movement actually has.
     *
     * `order_items.id` is the only value that is exactly one-per-movement: an order can carry the
     * same product in the same bucket twice (two band colours are two cart lines), so neither the
     * order id nor (order, product, bucket) identifies a movement. M1e's unique index is built on
     * this, which is what makes a commit or a release exactly-once at the database level.
     */
    public static function orderLine(int $orderId, int $lineId): self
    {
        return new self('orders', $orderId, $lineId);
    }

    public static function legacyProduct(int $productId): self
    {
        return new self('legacy:products', $productId);
    }
}
