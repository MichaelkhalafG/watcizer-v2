<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

/**
 * One line of a {@see CartSnapshot}: what the customer chose, at the price the SERVER computed.
 *
 * `unitPrice` is always the server's own number (`CompatCart::catalogPrice()` over the
 * storefront's `effective_price` / `effective_sale_price`), never the client's — a promotion whose
 * condition read a client-supplied price would be a discount a shopper could grant themselves.
 */
final readonly class CartLine
{
    public function __construct(
        public ?int $productId,
        public ?int $variantId,
        public ?int $offerId,
        public int $quantity,
        public float $unitPrice,
        /** Express | Market — the bucket the units come from, needed to reserve a reward correctly. */
        public ?string $typeStock = null,
    ) {}

    public function lineTotal(): float
    {
        return round($this->unitPrice * $this->quantity, 2);
    }
}
