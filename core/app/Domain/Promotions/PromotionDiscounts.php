<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The record of what a MONEY reward took off an order (wave 4D, M1r).
 *
 * ── Why this exists ──────────────────────────────────────────────────────────────────────────
 *
 * A free-item reward explains itself: it is a row in `order_items` carrying `promotion_rule_id`.
 * A money reward is only a smaller number on the order, so without this nothing records which rule
 * discounted it or by how much — and an operator asking "why is this order 25 less than its lines?"
 * has nowhere to look.
 *
 * ── It is written, never rewritten ───────────────────────────────────────────────────────────
 *
 * Same contract as the inventory ledger and the activity log: the row is the record of a decision
 * the shop made at a moment, so nothing here updates or deletes one. A CANCELLED order keeps its
 * discount row — the promotion really did apply, the customer really was quoted that total, and
 * erasing it would make the cancellation unexplainable afterwards.
 */
final class PromotionDiscounts
{
    /**
     * Record the discount an order was given. Returns false when there was nothing to record.
     *
     * Idempotent by construction: the table carries a UNIQUE on `order_id` (one rule per cart, so
     * one discount per order) and this INSERTs IGNORE-style through a pre-check inside the caller's
     * transaction. A retried checkout cannot double the recorded discount.
     */
    public static function record(int $orderId, PromotionOutcome $outcome): bool
    {
        if ($outcome->ruleId === null || $outcome->discount <= 0.0) {
            // The common case by far: no promotion, or a free-item one that moved no money.
            return false;
        }

        if (DB::table('promotion_order_discounts')->where('order_id', $orderId)->exists()) {
            return false;
        }

        DB::table('promotion_order_discounts')->insert([
            'order_id' => $orderId,
            'promotion_rule_id' => $outcome->ruleId,
            'amount' => number_format($outcome->discount, 2, '.', ''),
            'free_shipping' => $outcome->freeShipping,
            'created_at' => now(),
        ]);

        return true;
    }

    /**
     * What this order was discounted, or null — for the order screen.
     *
     * The rule's NAME comes with it: an id is not an answer to "why is this order cheaper", and the
     * rule is guaranteed to still exist because `PromotionWriter::delete()` refuses to delete a rule
     * that has granted anything and the table's foreign key restricts it besides.
     *
     * @return array{rule_id: int, rule_name: string|null, amount: string, free_shipping: bool}|null
     */
    public static function forOrder(int $orderId): ?array
    {
        $row = DB::table('promotion_order_discounts as d')
            ->leftJoin('promotion_rules as r', 'r.id', '=', 'd.promotion_rule_id')
            ->where('d.order_id', $orderId)
            ->first(['d.promotion_rule_id', 'd.amount', 'd.free_shipping', 'r.name as rule_name']);

        if (! $row instanceof stdClass) {
            return null;
        }
        $discount = Row::cast($row);

        return [
            'rule_id' => Row::int($discount, 'promotion_rule_id'),
            'rule_name' => Row::nstr($discount, 'rule_name'),
            'amount' => Row::money($discount, 'amount'),
            'free_shipping' => Row::bool($discount, 'free_shipping'),
        ];
    }
}
