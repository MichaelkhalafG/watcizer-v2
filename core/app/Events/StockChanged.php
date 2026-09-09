<?php

namespace App\Events;

use App\Domain\Inventory\Reference;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired AFTER the transaction that moved a stock bucket commits (study §4.2).
 *
 * Listeners must never assume they can still influence the movement — the number is already
 * durable. `$quantityBefore` is derived (`after - delta`) so a listener can answer "did this
 * product just come back into stock?" without another query.
 */
final class StockChanged
{
    use Dispatchable;

    public function __construct(
        public readonly int $productId,
        public readonly string $bucket,
        public readonly int $delta,
        public readonly int $quantityAfter,
        public readonly string $reason,
        public readonly ?Reference $reference = null,
        public readonly ?int $storefrontId = null,
        public readonly ?string $externalRef = null,
    ) {}

    public function quantityBefore(): int
    {
        return $this->quantityAfter - $this->delta;
    }
}
