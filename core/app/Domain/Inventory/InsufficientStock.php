<?php

namespace App\Domain\Inventory;

use RuntimeException;

/**
 * A decrement that the conditional UPDATE refused because the bucket does not hold enough.
 *
 * Carries the product id so the compat checkout can reproduce the legacy 422 body verbatim
 * (`{"success":false,"message":"Insufficient stock","product_id":N}`), and the variant id when the
 * refusal was at variant level — which the legacy body has no field for, and deliberately does not
 * gain one (wave 3.5: the compat layer never sells a variant product at all).
 */
final class InsufficientStock extends RuntimeException
{
    public function __construct(
        public readonly int $productId,
        public readonly string $bucket,
        public readonly int $requested,
        public readonly ?int $variantId = null,
    ) {
        $where = $variantId === null ? "product {$productId}" : "product {$productId} variant {$variantId}";
        parent::__construct("Insufficient {$bucket} stock for {$where} (requested {$requested})");
    }
}
