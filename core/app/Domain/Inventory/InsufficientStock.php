<?php

namespace App\Domain\Inventory;

use RuntimeException;

/**
 * A decrement that the conditional UPDATE refused because the bucket does not hold enough.
 *
 * Carries the product id so the compat checkout can reproduce the legacy 422 body verbatim
 * (`{"success":false,"message":"Insufficient stock","product_id":N}`).
 */
final class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly string $bucket,
        public readonly int $requested,
    ) {
        parent::__construct("Insufficient {$bucket} stock for product {$productId} (requested {$requested})");
    }
}
