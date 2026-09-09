<?php

namespace App\Domain\Inventory;

use RuntimeException;

/**
 * The offer equivalent of {@see InsufficientStock}. Offers keep their own `offers.stock` column
 * on the legacy table (study §4.2) and have no ledger rows yet, so they need their own signal —
 * the legacy 422 body for an offer line has no `product_id` key.
 */
final class InsufficientOfferStock extends RuntimeException
{
    public function __construct(
        public readonly int $offerId,
        public readonly int $requested,
    ) {
        parent::__construct("Insufficient offer stock for offer {$offerId} (requested {$requested})");
    }
}
