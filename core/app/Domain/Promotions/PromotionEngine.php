<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The promotions engine — one rule per cart, evaluated as a pure function (wave 4D, study §3.16).
 *
 * ── `evaluate()` WRITES NOTHING, and that is a load-bearing property ─────────────────────────
 *
 * It reads. It never inserts, updates or increments. Three things depend on that:
 *
 *  1. **"the same cart evaluated twice yields the same result; nothing accumulates"** becomes a
 *     property of the method rather than a promise about the caller.
 *  2. **The cart is read on every storefront page.** An engine that counted a skip here would
 *     turn the hottest GET in the application into a write. The counter therefore lives in
 *     {@see PromotionSkips}, called only from the checkout path.
 *  3. **"a cart with no matching rule is byte-identical to today"** is provable by construction:
 *     with no rules configured the candidate query returns nothing and there is no second step.
 *
 * ── One rule per cart, and what happens when the winner cannot deliver ──────────────────────
 *
 * Candidates are ordered by `priority DESC, id ASC` — highest wins, ties break by the LOWER id
 * (§3.16.1). Each candidate is then tested in order and the FIRST one that both matches and can
 * actually deliver its rewards wins.
 *
 * **A matched rule whose reward is undeliverable does not block the next one.** If rule A
 * (priority 10) matches but its gift is out of stock, and rule B (priority 5) matches and can be
 * delivered, B applies. The alternative — A wins and grants nothing — would let one empty shelf
 * silently switch off every promotion beneath it, which is the opposite of what a shop wants. A's
 * refusal is still reported in `PromotionOutcome::$skipped`, so the admin sees it.
 *
 * ── What "can deliver" means ────────────────────────────────────────────────────────────────
 *
 * Two checks, and they are deliberately late rather than at authoring time:
 *
 *  - the reward product is **live and visible on the cart's storefront**. Authoring refuses this
 *    too, but that check DECAYS: a data-entry operator can hide the product tomorrow through the
 *    placement screen, and the rule must not break a cart when they do.
 *  - there is **enough stock**, in one bucket. Never checked at authoring: stock moves constantly
 *    and a save-time reading means nothing an hour later.
 *
 * Either failure means the rule silently does not apply. The customer sees the cart they would
 * have had — a cart must never fail because of a promotion.
 */
final class PromotionEngine
{
    /**
     * The winning rule(s) and their rewards, or `PromotionOutcome::none()`.
     *
     * ── M1u (2026-10-30): coupon codes, targeted money, best single discount ────────────────────
     *
     * A rule with a `code` is a candidate ONLY when the shopper typed that code (`CartSnapshot::$code`, matched
     * without regard to case by the column's collation); every other rule applies on its own, as before.
     *
     * Every candidate that matches and can deliver is PRICED, and the winner is the best of them by
     * `priority DESC, discount DESC, id ASC` — so with the default priority (0 everywhere) the best single discount
     * wins, and an operator who wants one rule to win regardless still raises its priority. A matched rule that
     * cannot deliver is still not a blocker for the rules beneath it (the wave-4D property), and its refusal is
     * reported only when it ranked above the winner — the rules below the winner were never in contention.
     *
     * STACKING: rules marked `stackable` (money only) may combine. Their combined discount — each one computed on
     * what the previous ones left — replaces the best single rule when it is larger and the stack is not outranked by
     * a higher-priority rule. Default: nothing stacks.
     *
     * Money is taken off the GOODS at line level ({@see PromotionMoney}): never more than the targeted lines can
     * give above the rule's `unit_floor`, so the order total is goods − discount + delivery and never below zero.
     * A `free_shipping` reward adds the delivery the cart is charged. The free-shipping THRESHOLD is not touched
     * here: it is decided on the goods before any promotion (option A, approved 2026-10-29).
     *
     * @param  \DateTimeInterface|null  $at  the moment to evaluate at; defaults to now. Explicit so
     *                                       a test can prove a rule expiring mid-cart without sleeping.
     */
    public function evaluate(CartSnapshot $cart, ?\DateTimeInterface $at = null): PromotionOutcome
    {
        $now = $at ?? now();
        $code = $cart->code === null ? null : trim($cart->code);
        $code = $code === '' ? null : $code;

        $candidates = $this->candidates($cart->storefrontId, $now, $code);
        if ($candidates === []) {
            // The common case, and it must cost nothing: one indexed query, no second step.
            return new PromotionOutcome(coupon: $code === null ? null : $this->couponRefusal($code, $cart->storefrontId, $now));
        }

        $scopes = new PromotionScopes($cart);
        $codeIsCandidate = false;
        foreach ($candidates as $rule) {
            $codeIsCandidate = $codeIsCandidate || Row::nstr($rule, 'code') !== null;
        }
        $skipped = [];
        $refusedCode = null;            // the typed code's rule matched nothing / was refused — and why
        $grants = [];

        foreach ($candidates as $rule) {
            $ruleId = Row::int($rule, 'id');
            $ruleCode = Row::nstr($rule, 'code');
            $isTypedCode = $ruleCode !== null && $code !== null;

            $gate = $this->gate($rule, $cart, $scopes);
            if ($gate !== null || ! $this->matches($ruleId, $cart, $rule)) {
                if ($isTypedCode) {
                    $refusedCode = $gate ?? CouponStatus::NO_ELIGIBLE_ITEMS;
                }

                continue;                               // not a skip: the cart simply does not qualify
            }

            $grant = $this->deliverable($ruleId, $cart);
            if ($grant['reason'] !== null) {
                // MATCHED and refused — the thing the admin has to be told about.
                $skipped[$ruleId] = ['reason' => $grant['reason'], 'priority' => Row::int($rule, 'priority')];
                if ($isTypedCode) {
                    $refusedCode = CouponStatus::NO_ELIGIBLE_ITEMS;
                }

                continue;
            }

            $money = $this->moneyOf($rule, $grant['money'], $cart, $scopes, []);
            if ($grant['rewards'] === [] && $money['discount'] <= 0.0) {
                // won and delivered nothing at all: reported, never a silent win (wave-4D rule)
                $skipped[$ruleId] = ['reason' => PromotionRules::SKIP_NOT_VISIBLE, 'priority' => Row::int($rule, 'priority')];
                if ($isTypedCode) {
                    $refusedCode = CouponStatus::NO_ELIGIBLE_ITEMS;
                }

                continue;
            }

            $grants[] = ['rule' => $rule, 'id' => $ruleId, 'priority' => Row::int($rule, 'priority'),
                'rewards' => $grant['rewards'], 'money_rows' => $grant['money'], 'money' => $money,
                'stackable' => Row::bool($rule, 'stackable') && $grant['rewards'] === []];
        }

        $refusal = $code === null ? null : ($codeIsCandidate
            ? self::coupon($code, $refusedCode ?? CouponStatus::NO_ELIGIBLE_ITEMS, null, $cart)
            : $this->couponRefusal($code, $cart->storefrontId, $now));
        if ($grants === []) {
            return new PromotionOutcome(skipped: self::skipReasons($skipped, null), coupon: $refusal);
        }

        usort($grants, fn (array $a, array $b): int => [$b['priority'], $b['money']['discount'], $a['id']]
            <=> [$a['priority'], $a['money']['discount'], $b['id']]);
        $best = $grants[0];
        $applied = [$best];

        // the stack: every stackable money rule, in rank order, each on what the previous ones left
        $stackable = array_values(array_filter($grants, fn (array $g): bool => $g['stackable']));
        if (count($stackable) > 1 && $stackable[0]['priority'] >= $best['priority']) {
            $taken = [];
            $stacked = [];
            $stackTotal = 0.0;
            foreach ($stackable as $g) {
                $money = $this->moneyOf($g['rule'], $g['money_rows'], $cart, $scopes, $taken);
                if ($money['discount'] <= 0.0) {
                    continue;
                }
                foreach ($money['lines'] as $index => $amount) {
                    $taken[$index] = round(($taken[$index] ?? 0.0) + $amount, 2);
                }
                $g['money'] = $money;
                $stacked[] = $g;
                $stackTotal += $money['discount'];
            }
            if (count($stacked) > 1 && round($stackTotal, 2) > $best['money']['discount']) {
                $applied = $stacked;
            }
        }

        $discount = 0.0;
        $freeShipping = false;
        $appliedOut = [];
        $linesOut = [];
        foreach ($applied as $g) {
            if ($g['money']['discount'] <= 0.0) {
                continue;
            }
            $discount += $g['money']['discount'];
            $freeShipping = $freeShipping || $g['money']['free_shipping'];
            $appliedOut[] = [
                'rule_id' => $g['id'],
                'code' => Row::nstr($g['rule'], 'code'),
                'label_en' => Row::nstr($g['rule'], 'label_en'),
                'label_ar' => Row::nstr($g['rule'], 'label_ar'),
                'amount' => $g['money']['discount'],
                'free_shipping' => $g['money']['free_shipping'],
            ];
            foreach ($g['money']['lines'] as $index => $amount) {
                $line = $cart->lines[$index];
                $linesOut[] = ['index' => $index, 'rule_id' => $g['id'], 'product_id' => $line->productId,
                    'variant_id' => $line->variantId, 'quantity' => $line->quantity, 'amount' => $amount];
            }
        }

        $coupon = null;
        if ($code !== null) {
            $typed = null;
            foreach ($grants as $g) {
                if (Row::nstr($g['rule'], 'code') !== null) {
                    $typed = $g;
                }
            }
            $won = $typed !== null && in_array($typed['id'], array_column($applied, 'id'), true);
            $coupon = match (true) {
                $won => self::coupon($code, CouponStatus::APPLIED, $typed['id'], $cart),
                $typed !== null => self::coupon($code, CouponStatus::NOT_BEST, $typed['id'], $cart),
                default => $refusal,
            };
        }

        return new PromotionOutcome(
            ruleId: $best['id'],
            rewards: count($applied) === 1 ? $best['rewards'] : [],
            skipped: self::skipReasons($skipped, $best),
            discount: round($discount, 2),
            freeShipping: $freeShipping,
            applied: $appliedOut,
            lines: $linesOut,
            coupon: $coupon,
        );
    }

    /**
     * The skips worth reporting: every one when nothing won; otherwise those that ranked ABOVE the winner by
     * priority (a rule below the winner was never in contention, as in wave 4D's first-match loop).
     *
     * @param  array<int, array{reason: string, priority: int}>  $skipped
     * @param  array{id: int, priority: int}|null  $winner
     * @return array<int, string>
     */
    private static function skipReasons(array $skipped, ?array $winner): array
    {
        $out = [];
        foreach ($skipped as $ruleId => $skip) {
            if ($winner === null || $skip['priority'] > $winner['priority']
                || ($skip['priority'] === $winner['priority'] && $ruleId < $winner['id'])) {
                $out[$ruleId] = $skip['reason'];
            }
        }

        return $out;
    }

    /**
     * Active, in-window rules enabled for THIS storefront, best first — plus, when a code was typed, the rule with
     * that code. A coded rule is never a candidate otherwise.
     *
     * The storefront pivot is an INNER JOIN, not a filter applied afterwards (§3.16.9): a rule not
     * enabled for the cart's storefront is never a candidate, so no later code can forget to
     * exclude it. A NULL window (an M1u draft) cannot pass the two comparisons.
     *
     * @return list<stdClass>
     */
    private function candidates(int $storefrontId, \DateTimeInterface $now, ?string $code): array
    {
        $stamp = $now->format('Y-m-d H:i:s');

        $rows = DB::table('promotion_rules as r')
            ->join('promotion_rule_storefront as rs', 'rs.promotion_rule_id', '=', 'r.id')
            ->where('rs.storefront_id', $storefrontId)
            ->where('r.is_active', 1)
            ->where('r.starts_at', '<=', $stamp)
            ->where('r.ends_at', '>=', $stamp)
            ->where(function (Builder $q) use ($code): void {
                $q->whereNull('r.code');
                if ($code !== null) {
                    $q->orWhere('r.code', $code);
                }
            })
            ->orderByDesc('r.priority')->orderBy('r.id')
            ->get(['r.id', 'r.priority', 'r.code', 'r.label_en', 'r.label_ar', 'r.apply_per', 'r.max_discount',
                'r.min_order_amount', 'r.usage_limit', 'r.usage_limit_per_customer', 'r.include_sale_items',
                'r.stackable', 'r.unit_floor', 'r.qualify_min_quantity', 'r.qualify_min_amount']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = Row::cast($row);
        }

        return $out;
    }

    /**
     * The M1u gates of a rule, in the order a shopper can act on them: the minimum order (goods at the prices
     * charged — the free-shipping basis), the "buy from X" items, the usage limits. Null = the rule may apply.
     */
    private function gate(stdClass $rule, CartSnapshot $cart, PromotionScopes $scopes): ?string
    {
        $minimum = Row::nmoney($rule, 'min_order_amount');
        if ($minimum !== null && (float) $minimum > 0.0 && $cart->subtotal + 0.001 < (float) $minimum) {
            return CouponStatus::MINIMUM_NOT_REACHED;
        }

        $ruleId = Row::int($rule, 'id');
        $qualify = PromotionScopes::of($ruleId)[PromotionScopes::ROLE_QUALIFY] ?? [];
        $minQuantity = Row::nint($rule, 'qualify_min_quantity');
        $minAmount = Row::nmoney($rule, 'qualify_min_amount');
        if ($qualify !== [] || $minQuantity !== null || $minAmount !== null) {
            $within = $scopes->totalsWithin($qualify);
            if ($within['quantity'] < max(1, $minQuantity ?? 1)
                || ($minAmount !== null && $within['amount'] + 0.001 < (float) $minAmount)) {
                return CouponStatus::NO_ELIGIBLE_ITEMS;
            }
        }

        $limit = Row::nint($rule, 'usage_limit');
        if ($limit !== null && $limit > 0 && self::redemptions($ruleId, null) >= $limit) {
            return CouponStatus::USAGE_LIMIT;
        }
        $perCustomer = Row::nint($rule, 'usage_limit_per_customer');
        if ($perCustomer !== null && $perCustomer > 0 && $cart->customerKeys !== []
            && self::redemptions($ruleId, $cart->customerKeys) >= $perCustomer) {
            return CouponStatus::CUSTOMER_LIMIT;
        }

        return null;
    }

    /**
     * Orders that used a rule — a cancelled order gives its use back (an unpaid card order that expired, an order
     * the team cancelled), so a limit counts what was actually bought.
     *
     * @param  list<string>|null  $customerKeys  null = every customer
     */
    public static function redemptions(int $ruleId, ?array $customerKeys): int
    {
        $query = DB::table('promotion_redemptions as pr')
            ->join('orders as o', 'o.id', '=', 'pr.order_id')
            ->where('pr.promotion_rule_id', $ruleId)
            ->where('o.status', '!=', 'cancelled');
        if ($customerKeys !== null) {
            $query->whereIn('pr.customer_key', $customerKeys);
        }

        return (int) $query->count();
    }

    /**
     * The money a rule's money rewards take off this cart, line by line.
     *
     * @param  list<stdClass>  $rewards  the rule's money reward rows
     * @param  array<int, float>  $taken  what stacked rules before it already took per line
     * @return array{discount: float, lines: array<int, float>, free_shipping: bool}
     */
    private function moneyOf(stdClass $rule, array $rewards, CartSnapshot $cart, PromotionScopes $scopes, array $taken): array
    {
        if ($rewards === []) {
            return ['discount' => 0.0, 'lines' => [], 'free_shipping' => false];
        }

        $target = PromotionScopes::of(Row::int($rule, 'id'))[PromotionScopes::ROLE_TARGET] ?? [];
        $includeSale = ! property_exists($rule, 'include_sale_items') || Row::bool($rule, 'include_sale_items');
        $eligible = [];
        foreach ($cart->lines as $index => $line) {
            if ($line->productId === null || ! $scopes->lineMatches($index, $target)) {
                continue;
            }
            if (! $includeSale && $scopes->onSale($index)) {
                continue;
            }
            $eligible[$index] = ['unit' => $line->unitPrice, 'quantity' => $line->quantity];
        }

        $per = Row::nstr($rule, 'apply_per') ?? PromotionMoney::PER_ORDER;
        $per = in_array($per, PromotionMoney::PER, true) ? $per : PromotionMoney::PER_ORDER;
        $cap = Row::nmoney($rule, 'max_discount');
        $floor = (float) (Row::nmoney($rule, 'unit_floor') ?? '0');

        $lines = [];
        $shipping = 0.0;
        $freeShipping = false;
        foreach ($rewards as $reward) {
            $type = Row::str($reward, 'type');
            if ($type === 'free_shipping') {
                $shipping = max($shipping, round($cart->shippingCost, 2));
                $freeShipping = $freeShipping || $cart->shippingCost > 0.0;

                continue;
            }
            $value = (float) (Row::nmoney($reward, 'amount') ?? '0');
            if ($type === 'percent_discount' && $per === PromotionMoney::PER_ORDER) {
                // a percentage of the eligible goods, ONCE, then split — the same total as wave 4D's subtotal × rate
                $base = 0.0;
                foreach ($eligible as $index => $line) {
                    $base += $line['unit'] * $line['quantity'] - ($taken[$index] ?? 0.0) - ($lines[$index] ?? 0.0);
                }
                $share = PromotionMoney::allocate($eligible, self::plus($taken, $lines), 'fixed_discount',
                    round(max(0.0, $base) * max(0.0, min(100.0, $value)) / 100, 2), PromotionMoney::PER_ORDER, null, $floor);
            } else {
                $share = PromotionMoney::allocate($eligible, self::plus($taken, $lines), $type, $value, $per, null, $floor);
            }
            foreach ($share as $index => $amount) {
                $lines[$index] = round(($lines[$index] ?? 0.0) + $amount, 2);
            }
        }

        // the cap is the RULE's: lines scaled down to it, delivery last (a cap on "money off" keeps free delivery)
        if ($cap !== null && (float) $cap >= 0.0 && array_sum($lines) > (float) $cap + 0.001) {
            $scaled = [];
            foreach ($lines as $index => $amount) {
                $scaled[$index] = ['unit' => $amount, 'quantity' => 1];
            }
            $lines = PromotionMoney::allocate($scaled, [], 'fixed_discount', (float) $cap, PromotionMoney::PER_ORDER, null, 0.0);
        }

        $goods = round(array_sum($lines), 2);

        return ['discount' => round($goods + $shipping, 2), 'lines' => $lines, 'free_shipping' => $freeShipping];
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     * @return array<int, float>
     */
    private static function plus(array $a, array $b): array
    {
        foreach ($b as $index => $amount) {
            $a[$index] = round(($a[$index] ?? 0.0) + $amount, 2);
        }

        return $a;
    }

    /**
     * Why a typed code is not a candidate at all — it does not exist here, is switched off, or is outside its window.
     *
     * @return array{code: string, status: string, rule_id: int|null, messages: array{en: string, ar: string}}
     */
    private function couponRefusal(string $code, int $storefrontId, \DateTimeInterface $now): array
    {
        $row = DB::table('promotion_rules as r')
            ->join('promotion_rule_storefront as rs', 'rs.promotion_rule_id', '=', 'r.id')
            ->where('rs.storefront_id', $storefrontId)
            ->where('r.code', $code)
            ->first(['r.id', 'r.is_active', 'r.starts_at', 'r.ends_at']);
        if (! $row instanceof stdClass) {
            return self::coupon($code, CouponStatus::INVALID, null, null);
        }
        $rule = Row::cast($row);
        $stamp = $now->format('Y-m-d H:i:s');
        $status = match (true) {
            ! Row::bool($rule, 'is_active') => CouponStatus::INVALID,
            Row::nstr($rule, 'ends_at') !== null && Row::str($rule, 'ends_at') < $stamp => CouponStatus::EXPIRED,
            Row::nstr($rule, 'starts_at') === null || Row::str($rule, 'starts_at') > $stamp => CouponStatus::NOT_STARTED,
            default => CouponStatus::INVALID,
        };

        return self::coupon($code, $status, Row::int($rule, 'id'), null);
    }

    /** @return array{code: string, status: string, rule_id: int|null, messages: array{en: string, ar: string}} */
    private static function coupon(string $code, string $status, ?int $ruleId, ?CartSnapshot $cart): array
    {
        $minimum = null;
        if ($status === CouponStatus::MINIMUM_NOT_REACHED && $ruleId !== null) {
            $value = DB::table('promotion_rules')->where('id', $ruleId)->value('min_order_amount');
            $minimum = is_numeric($value) ? (float) $value : null;
        }
        if ($status === CouponStatus::MINIMUM_NOT_REACHED && $minimum === null && $cart !== null) {
            // the code's rule is found by its code when the refusal came from the loop
            $value = DB::table('promotion_rules')->where('code', $code)->value('min_order_amount');
            $minimum = is_numeric($value) ? (float) $value : null;
        }

        return ['code' => $code, 'status' => $status, 'rule_id' => $ruleId, 'messages' => CouponStatus::messages($status, $minimum)];
    }

    /**
     * Every condition on the rule must hold. AND, always — an OR is two rules (§3.16.2).
     *
     * A rule with NO conditions is refused unless it is one of M1u's scoped rules — it has a code (the shopper
     * asks for it), item scopes (it is about named items) or a minimum order — because a bare conditionless rule
     * would apply to every cart in the shop.
     */
    private function matches(int $ruleId, CartSnapshot $cart, ?stdClass $rule = null): bool
    {
        $conditions = DB::table('promotion_rule_conditions')
            ->where('promotion_rule_id', $ruleId)
            ->orderBy('id')
            ->get(['type', 'product_id', 'variant_id', 'storefront_category_id', 'brand_id', 'quantity', 'amount', 'methods']);

        if ($conditions->isEmpty()) {
            /*
             * A rule with no conditions would apply to EVERY cart. That is an authoring refusal,
             * but the engine refuses it too rather than trusting the screen: a rule created by a
             * console command or left half-written by a failed save must not start giving stock
             * away to everybody. M1u's scoped rules are the exception, by construction.
             */
            return $rule !== null && self::isScoped($rule);
        }

        foreach ($conditions as $raw) {
            if (! $this->conditionHolds(Row::cast($raw), $cart)) {
                return false;
            }
        }

        return true;
    }

    /** Does the rule limit itself without conditions: a code, item scopes, a minimum order or a "buy from X"? */
    public static function isScoped(stdClass $rule): bool
    {
        if (Row::nstr($rule, 'code') !== null) {
            return true;
        }
        $minimum = Row::nmoney($rule, 'min_order_amount');
        if (($minimum !== null && (float) $minimum > 0.0) || Row::nint($rule, 'qualify_min_quantity') !== null
            || Row::nmoney($rule, 'qualify_min_amount') !== null) {
            return true;
        }

        return DB::table('promotion_rule_scopes')->where('promotion_rule_id', Row::int($rule, 'id'))->exists();
    }

    private function conditionHolds(stdClass $condition, CartSnapshot $cart): bool
    {
        $type = Row::str($condition, 'type');
        $quantity = Row::nint($condition, 'quantity') ?? 0;

        return match ($type) {
            'cart_subtotal_min' => $cart->subtotal + 0.001 >= (float) (Row::nmoney($condition, 'amount') ?? '0'),

            'product_quantity_min' => $quantity > 0
                && $cart->quantityOfProduct(Row::nint($condition, 'product_id') ?? 0) >= $quantity,

            'variant_quantity_min' => $quantity > 0
                && $cart->quantityOfVariant(Row::nint($condition, 'variant_id') ?? 0) >= $quantity,

            'category_quantity_min' => $quantity > 0
                && $cart->quantityOfProducts($this->productsInCategory(
                    Row::nint($condition, 'storefront_category_id') ?? 0,
                    $cart->storefrontId,
                    $cart->productIds(),
                )) >= $quantity,

            'brand_quantity_min' => $quantity > 0
                && $cart->quantityOfProducts($this->productsOfBrand(
                    Row::nint($condition, 'brand_id') ?? 0,
                    $cart->productIds(),
                )) >= $quantity,

            'distinct_lines_min' => $quantity > 0 && $cart->distinctItems() >= $quantity,

            'payment_method_in' => in_array(
                $cart->paymentMethod,
                PromotionRules::methods(Row::nstr($condition, 'methods')),
                true,
            ),

            // An unknown type never matches. A rule carrying one is inert rather than dangerous,
            // which is the right direction for a value that came out of a database row.
            default => false,
        };
    }

    /**
     * Which of the cart's products sit in this category's SUBTREE on this storefront.
     *
     * Subtree by materialised `path` prefix — the same mechanism menu visibility and the family
     * resolver use, so "in this category" means one thing across the application. The root's path
     * is read first and the descendants matched against it: a self-join through a runtime node id
     * would have to go through `DB::raw()`, which is both a level-10 refusal and harder to read
     * than two statements.
     *
     * Narrowed to the cart's own product ids, so the query is bounded by the cart rather than by
     * the catalogue.
     *
     * @param  list<int>  $cartProductIds
     * @return list<int>
     */
    private function productsInCategory(int $nodeId, int $storefrontId, array $cartProductIds): array
    {
        if ($nodeId <= 0 || $cartProductIds === []) {
            return [];
        }

        $path = DB::table('storefront_categories')
            ->where('id', $nodeId)->where('storefront_id', $storefrontId)
            ->value('path');

        if (! is_string($path) || $path === '') {
            // No node, or a node belonging to another storefront. Either way the condition is
            // about nothing, so it matches nothing — never everything.
            return [];
        }

        $ids = DB::table('storefront_category_product as scp')
            ->join('storefront_categories as node', 'node.id', '=', 'scp.storefront_category_id')
            ->where('scp.storefront_id', $storefrontId)
            ->whereIn('scp.product_id', $cartProductIds)
            ->where(function (Builder $q) use ($nodeId, $path): void {
                $q->where('node.id', $nodeId)->orWhere('node.path', 'like', $path.'%');
            })
            ->distinct()
            ->pluck('scp.product_id');

        return Coerce::intList($ids);
    }

    /**
     * @param  list<int>  $cartProductIds
     * @return list<int>
     */
    private function productsOfBrand(int $brandId, array $cartProductIds): array
    {
        if ($brandId <= 0 || $cartProductIds === []) {
            return [];
        }

        return Coerce::intList(
            DB::table('catalog_products')
                ->where('brand_id', $brandId)
                ->whereIn('id', $cartProductIds)
                ->pluck('id')
        );
    }

    /**
     * Can this rule's rewards actually be given? Returns the reward lines, or the reason not.
     *
     * ALL of a rule's rewards must be deliverable or none is: a rule promising two gifts that
     * delivers one is a shop that looks like it is cheating. The refusal names the FIRST reason
     * found, which is the one an admin should act on. The same applies across the two families — a
     * rule granting a gift AND 10% off delivers both or neither, so a cart is never charged the
     * discount for a gift that never arrived.
     *
     * @return array{rewards: list<RewardLine>, reason: string|null, money: list<stdClass>}
     */
    private function deliverable(int $ruleId, CartSnapshot $cart): array
    {
        $rewards = DB::table('promotion_rule_rewards')
            ->where('promotion_rule_id', $ruleId)
            ->orderBy('id')
            ->get(['id', 'type', 'product_id', 'variant_id', 'quantity', 'amount']);

        if ($rewards->isEmpty()) {
            // Same reasoning as a conditionless rule: inert, not dangerous.
            return ['rewards' => [], 'reason' => PromotionRules::SKIP_NOT_VISIBLE, 'money' => []];
        }

        $lines = [];
        $money = [];

        foreach ($rewards as $raw) {
            $reward = Row::cast($raw);
            $type = Row::str($reward, 'type');

            if (! PromotionRules::isRewardAvailableOn($type, $cart->storefrontId)) {
                /*
                 * A money reward on a storefront whose frontend cannot show a promotion-aware
                 * total — or a type that is not a reward at all, from a console caller or a
                 * half-written row. Refused HERE as well as at authoring, because authoring
                 * DECAYS: the switch is per storefront and an operator can turn it off tomorrow
                 * on a rule that was legitimately written today.
                 */
                return ['rewards' => [], 'reason' => PromotionRules::SKIP_NOT_AVAILABLE, 'money' => []];
            }

            if (PromotionRules::isMoneyReward($type)) {
                /*
                 * A money reward moves no stock and produces no order line: it is a number taken
                 * off the total. It cannot be "out of stock" and it has no product to be visible,
                 * so it skips both checks below rather than being made to fail them.
                 */
                $money[] = $reward;             // priced by evaluate() (moneyOf), line by line — M1u

                continue;
            }

            $quantity = max(1, Row::int($reward, 'quantity'));
            $productId = Row::nint($reward, 'product_id');
            $variantId = Row::nint($reward, 'variant_id');

            if (! $this->rewardIsVisible($productId, $variantId, $cart->storefrontId)) {
                return ['rewards' => [], 'reason' => PromotionRules::SKIP_NOT_VISIBLE, 'money' => []];
            }

            $bucket = $this->bucketWithStock($productId, $variantId, $quantity);
            if ($bucket === null) {
                return ['rewards' => [], 'reason' => PromotionRules::SKIP_OUT_OF_STOCK, 'money' => []];
            }

            $lines[] = new RewardLine(
                ruleId: $ruleId,
                rewardId: Row::int($reward, 'id'),
                productId: $productId,
                variantId: $variantId,
                quantity: $quantity,
                typeStock: $bucket,
            );
        }

        /*
         * A rule whose only rewards were money ones and which discounted NOTHING is reported by evaluate() once it
         * has priced them (M1u): only it knows the lines. A rule with no reward at all is inert here.
         */
        if ($lines === [] && $money === []) {
            return ['rewards' => [], 'reason' => PromotionRules::SKIP_NOT_VISIBLE, 'money' => []];
        }

        return ['rewards' => $lines, 'reason' => null, 'money' => $money];
    }

    /**
     * Is the reward's product — AND the named variant — live, active and visible here?
     *
     * The same three conditions the storefront's own visibility scope applies to the product:
     * active, not soft-deleted, `storefront_product.is_visible`. A gift the customer cannot
     * otherwise see on that storefront is a gift that storefront should not be giving.
     *
     * ── The variant half (review 🔴-1, 2026-09-15) ──────────────────────────────────────────
     *
     * This resolved a variant to its PARENT and then checked only the parent. So a size the team
     * had withdrawn could still be granted as a gift, as long as the product it belonged to was
     * still on sale — the likelier arrangement of the two, since deactivating one size is routine.
     *
     * `InventoryService` refuses the movement either way, and that is the guard that matters. But
     * refusing at the DOOR and not at the CHOICE means the engine picks a reward the checkout then
     * cannot reserve: either an exception in the middle of a customer's checkout, or a reward line
     * whose stock never moved. Both are worse than not offering the gift.
     */
    private function rewardIsVisible(?int $productId, ?int $variantId, int $storefrontId): bool
    {
        if ($variantId !== null) {
            $variantActive = DB::table('catalog_product_variants')
                ->where('id', $variantId)->where('is_active', 1)->exists();
            if (! $variantActive) {
                return false;
            }
        }

        $productId = $productId ?? $this->productOfVariant($variantId);
        if ($productId === null) {
            return false;
        }

        return DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId);
            })
            ->where('p.id', $productId)
            ->whereNull('p.deleted_at')
            ->where('p.is_active', 1)
            ->where('sp.is_visible', 1)
            ->exists();
    }

    /**
     * The bucket that can cover `$quantity`, or null for "not enough anywhere".
     *
     * Express first, then Market — the order the checkout itself prefers. A reward is never split
     * across buckets: two ledger movements for one gift would make the order's history harder to
     * read than the gift is worth.
     */
    private function bucketWithStock(?int $productId, ?int $variantId, int $quantity): ?string
    {
        if ($variantId !== null) {
            $row = DB::table('catalog_product_variants')->where('id', $variantId)
                ->first(['stock_express', 'stock_market']);
        } elseif ($productId !== null) {
            $row = DB::table('catalog_products')->where('id', $productId)
                ->first(['stock_express', 'stock_market']);
        } else {
            return null;
        }

        if (! is_object($row)) {
            return null;
        }
        $stock = Row::cast($row);

        if (Row::int($stock, 'stock_express') >= $quantity) {
            return 'Express';
        }
        if (Row::int($stock, 'stock_market') >= $quantity) {
            return 'Market';
        }

        return null;
    }

    private function productOfVariant(?int $variantId): ?int
    {
        if ($variantId === null) {
            return null;
        }

        return Coerce::nint(DB::table('catalog_product_variants')->where('id', $variantId)->value('product_id'));
    }
}
