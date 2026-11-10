<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

/**
 * What `PromotionEngine::evaluate()` answered (wave 4D, §3.16.1).
 *
 * ── It carries the SKIPS, and that is the point ──────────────────────────────────────────────
 *
 * One rule per cart, so at most one winner. But the developer's addition is that a rule which
 * never applies must not be silent to the ADMIN — *"an admin advertising a gift that never applies
 * is the failure mode that actually costs the client"* — so the outcome also names every rule that
 * MATCHED the cart and was then refused, with the reason.
 *
 * The customer's side is unaffected either way: a refused rule means the cart they would have had.
 * A cart must never fail because of a promotion.
 */
final readonly class PromotionOutcome
{
    /**
     * @param  int|null  $ruleId  the winning rule, or null for "no promotion applies"
     * @param  list<RewardLine>  $rewards  the free ITEMS it granted (empty when nothing won)
     * @param  array<int, string>  $skipped  rule id => a `PromotionRules::SKIP_*` reason
     * @param  float  $discount  what the money rewards take off the order total, in EGP
     * @param  bool  $freeShipping  true when the discount above includes a waived shipping cost
     * @param  list<array{rule_id: int, code: string|null, label_en: string|null, label_ar: string|null, amount: float, free_shipping: bool}>  $applied
     *                                                                                                                                                   every rule that moved money, the winner first (more than one only when the rules are stackable) — M1u
     * @param  list<array{index: int, rule_id: int, product_id: int|null, variant_id: int|null, quantity: int, amount: float}>  $lines
     *                                                                                                                                  what came off each cart line (`index` into the snapshot's lines), summing to the goods part of `discount`
     * @param  array{code: string, status: string, rule_id: int|null, messages: array{en: string, ar: string}, wheel?: array{phone_hint: string, expires_at: string, order_phone_hint: string|null}}|null  $coupon  `wheel` — the spin's facts when the code is the wheel's (2026-11-07)
     *                                                                                                                                                                                                              what happened to the code the shopper typed, or null when none was typed
     */
    public function __construct(
        public ?int $ruleId = null,
        public array $rewards = [],
        public array $skipped = [],
        public float $discount = 0.0,
        public bool $freeShipping = false,
        public array $applied = [],
        public array $lines = [],
        public ?array $coupon = null,
    ) {}

    /** Nothing applies. The most common answer, and the one that must cost nothing. */
    public static function none(): self
    {
        return new self;
    }

    /**
     * Did a rule actually deliver something?
     *
     * A money reward delivers a DISCOUNT and no reward lines, so "won and granted nothing" is now a
     * real and legitimate shape. Testing `rewards !== []` alone would have reported a EGP 50
     * discount as "no promotion applied" — and the order would have carried the discount anyway,
     * which is the worst of both.
     */
    public function applies(): bool
    {
        return $this->ruleId !== null && ($this->rewards !== [] || $this->discount > 0.0 || $this->freeShipping);
    }

    /** Total reward units, for a report or a screen's summary. */
    public function rewardUnits(): int
    {
        $total = 0;
        foreach ($this->rewards as $reward) {
            $total += $reward->quantity;
        }

        return $total;
    }
}
