<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

/**
 * One reward a rule granted: a REAL order line at zero price (wave 4D, D-24).
 *
 * Not a discount and not a display artefact. When the checkout grants this it becomes a row in the
 * shared `order_items` with `piece_price = 0`, `is_reward = 1` and `promotion_rule_id` set, and its
 * units are reserved through `InventoryService` with reason `promotion_reward` — so the legacy
 * application, which reads the same rows, serialises it exactly as core does. Nothing is filtered
 * anywhere (§3.16.9 resolution 🔴-2).
 */
final readonly class RewardLine
{
    public function __construct(
        public int $ruleId,
        public int $rewardId,
        public ?int $productId,
        public ?int $variantId,
        public int $quantity,
        /** The bucket the units are taken from. Express unless the product only has Market stock. */
        public string $typeStock,
    ) {}
}
