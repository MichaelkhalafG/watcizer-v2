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
     * Idempotent by construction: one row per (order, rule) — UNIQUE since M1u, which also lets a stackable rule
     * share an order — and a pre-check inside the caller's transaction. A retried checkout cannot double the
     * recorded discount.
     *
     * M1u (2026-10-30) also writes, for each applied rule: the `code` the shopper typed, the discount PER ORDER LINE
     * (`promotion_order_discount_lines`, matched to the order's own lines) and a `promotion_redemptions` row, which
     * is what the usage limits count. The customer is read from the ORDER (account, else e-mail, else phone), so
     * the compat checkout records the same thing without knowing about any of it.
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

        // a pre-M1u outcome (or a hand-built one) carries only the total: record it against the winning rule
        $applied = $outcome->applied !== [] ? $outcome->applied : [[
            'rule_id' => $outcome->ruleId, 'code' => null, 'label_en' => null, 'label_ar' => null,
            'amount' => $outcome->discount, 'free_shipping' => $outcome->freeShipping,
        ]];

        $raw = DB::table('orders')->where('id', $orderId)->first(['storefront_id', 'user_id', 'guest_email', 'guest_phone']);
        $order = $raw instanceof stdClass ? Row::cast($raw) : null;
        $storefrontId = $order === null ? 0 : (Row::nint($order, 'storefront_id') ?? 0);
        $customerKey = $order === null ? null : (self::customerKeys(
            Row::nint($order, 'user_id'),
            Row::nstr($order, 'guest_email'),
            Row::nstr($order, 'guest_phone'),
        )[0] ?? null);

        $items = [];
        foreach (DB::table('order_items')->where('order_id', $orderId)->where('is_reward', 0)->orderBy('id')
            ->get(['id', 'product_id', 'variant_id']) as $item) {
            $items[] = Row::cast($item);
        }
        $used = [];

        foreach ($applied as $rule) {
            DB::table('promotion_order_discounts')->insert([
                'order_id' => $orderId,
                'promotion_rule_id' => $rule['rule_id'],
                'code' => $rule['code'],
                'amount' => number_format($rule['amount'], 2, '.', ''),
                'free_shipping' => $rule['free_shipping'],
                'created_at' => now(),
            ]);
            DB::table('promotion_redemptions')->insert([
                'promotion_rule_id' => $rule['rule_id'],
                'order_id' => $orderId,
                'storefront_id' => $storefrontId,
                'code' => $rule['code'],
                'customer_key' => $customerKey,
                'amount' => number_format($rule['amount'], 2, '.', ''),
                'created_at' => now(),
            ]);
        }

        foreach ($outcome->lines as $line) {
            $itemId = null;
            foreach ($items as $item) {
                $id = Row::int($item, 'id');
                if (! isset($used[$line['rule_id']][$id]) && Row::nint($item, 'product_id') === $line['product_id']
                    && Row::nint($item, 'variant_id') === $line['variant_id']) {
                    $itemId = $id;
                    $used[$line['rule_id']][$id] = true;

                    break;
                }
            }
            DB::table('promotion_order_discount_lines')->insert([
                'order_id' => $orderId,
                'order_item_id' => $itemId,
                'promotion_rule_id' => $line['rule_id'],
                'product_id' => $line['product_id'],
                'variant_id' => $line['variant_id'],
                'quantity' => $line['quantity'],
                'amount' => number_format($line['amount'], 2, '.', ''),
                'created_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Who is buying, strongest first, as `promotion_redemptions.customer_key` spells it: the account, the e-mail
     * (lower-case), the phone (its last ten digits, so 0101… and +20 101… are one number).
     *
     * @return list<string>
     */
    public static function customerKeys(?int $userId, ?string $email, ?string $phone): array
    {
        $keys = [];
        if ($userId !== null && $userId > 0) {
            $keys[] = 'u:'.$userId;
        }
        $email = $email === null ? '' : strtolower(trim($email));
        if ($email !== '') {
            $keys[] = 'e:'.$email;
        }
        $digits = preg_replace('/\D+/', '', $phone ?? '') ?? '';
        if (strlen($digits) >= 10) {
            $keys[] = 'p:'.substr($digits, -10);
        }

        return $keys;
    }

    /**
     * What an order was discounted, for the dashboard and the e-mails: the total over every rule that applied (one,
     * unless stackable rules combined), the first rule's id and name, the code(s), and each rule on its own.
     *
     * @return array{rule_id: int, rule_name: string|null, amount: string, free_shipping: bool, code: string|null, rules: list<array{rule_id: int, rule_name: string|null, code: string|null, amount: string}>}|null
     */
    public static function forOrder(int $orderId): ?array
    {
        $rows = DB::table('promotion_order_discounts as d')
            ->leftJoin('promotion_rules as r', 'r.id', '=', 'd.promotion_rule_id')
            ->where('d.order_id', $orderId)
            ->orderBy('d.id')
            ->get(['d.promotion_rule_id', 'd.code', 'd.amount', 'd.free_shipping', 'r.name as rule_name']);

        $total = 0.0;
        $free = false;
        $rules = [];
        $codes = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $total += (float) Row::money($row, 'amount');
            $free = $free || Row::bool($row, 'free_shipping');
            $code = Row::nstr($row, 'code');
            if ($code !== null) {
                $codes[] = $code;
            }
            $rules[] = [
                'rule_id' => Row::int($row, 'promotion_rule_id'),
                'rule_name' => Row::nstr($row, 'rule_name'),
                'code' => $code,
                'amount' => Row::money($row, 'amount'),
            ];
        }

        if ($rules === []) {
            return null;
        }

        return [
            'rule_id' => $rules[0]['rule_id'],
            'rule_name' => $rules[0]['rule_name'],
            'amount' => number_format($total, 2, '.', ''),
            'free_shipping' => $free,
            'code' => $codes === [] ? null : implode(', ', $codes),
            'rules' => $rules,
        ];
    }
}
