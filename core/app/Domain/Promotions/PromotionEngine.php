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
     * The winning rule and its rewards, or `PromotionOutcome::none()`.
     *
     * @param  \DateTimeInterface|null  $at  the moment to evaluate at; defaults to now. Explicit so
     *                                       a test can prove a rule expiring mid-cart without sleeping.
     */
    public function evaluate(CartSnapshot $cart, ?\DateTimeInterface $at = null): PromotionOutcome
    {
        $now = $at ?? now();

        $candidates = $this->candidates($cart->storefrontId, $now);
        if ($candidates === []) {
            // The common case, and it must cost nothing: one indexed query, no second step.
            return PromotionOutcome::none();
        }

        $skipped = [];

        foreach ($candidates as $rule) {
            $ruleId = Row::int($rule, 'id');

            if (! $this->matches($ruleId, $cart)) {
                continue;                               // not a skip: the cart simply does not qualify
            }

            $grant = $this->deliverable($ruleId, $cart);

            if ($grant['reason'] !== null) {
                // MATCHED and refused — the thing the admin has to be told about.
                $skipped[$ruleId] = $grant['reason'];

                continue;
            }

            return new PromotionOutcome(
                ruleId: $ruleId,
                rewards: $grant['rewards'],
                skipped: $skipped,
                discount: $grant['discount'],
                freeShipping: $grant['free_shipping'],
            );
        }

        return new PromotionOutcome(skipped: $skipped);
    }

    /**
     * Active, in-window rules enabled for THIS storefront, best first.
     *
     * The storefront pivot is an INNER JOIN, not a filter applied afterwards (§3.16.9): a rule not
     * enabled for the cart's storefront is never a candidate, so no later code can forget to
     * exclude it.
     *
     * @return list<stdClass>
     */
    private function candidates(int $storefrontId, \DateTimeInterface $now): array
    {
        $stamp = $now->format('Y-m-d H:i:s');

        $rows = DB::table('promotion_rules as r')
            ->join('promotion_rule_storefront as rs', 'rs.promotion_rule_id', '=', 'r.id')
            ->where('rs.storefront_id', $storefrontId)
            ->where('r.is_active', 1)
            ->where('r.starts_at', '<=', $stamp)
            ->where('r.ends_at', '>=', $stamp)
            ->orderByDesc('r.priority')->orderBy('r.id')
            ->get(['r.id', 'r.priority']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = Row::cast($row);
        }

        return $out;
    }

    /** Every condition on the rule must hold. AND, always — an OR is two rules (§3.16.2). */
    private function matches(int $ruleId, CartSnapshot $cart): bool
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
             * away to everybody.
             */
            return false;
        }

        foreach ($conditions as $raw) {
            if (! $this->conditionHolds(Row::cast($raw), $cart)) {
                return false;
            }
        }

        return true;
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
     * @return array{rewards: list<RewardLine>, reason: string|null, discount: float, free_shipping: bool}
     */
    private function deliverable(int $ruleId, CartSnapshot $cart): array
    {
        $rewards = DB::table('promotion_rule_rewards')
            ->where('promotion_rule_id', $ruleId)
            ->orderBy('id')
            ->get(['id', 'type', 'product_id', 'variant_id', 'quantity', 'amount']);

        if ($rewards->isEmpty()) {
            // Same reasoning as a conditionless rule: inert, not dangerous.
            return ['rewards' => [], 'reason' => PromotionRules::SKIP_NOT_VISIBLE, 'discount' => 0.0, 'free_shipping' => false];
        }

        $lines = [];
        $discount = 0.0;
        $freeShipping = false;

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
                return ['rewards' => [], 'reason' => PromotionRules::SKIP_NOT_AVAILABLE, 'discount' => 0.0, 'free_shipping' => false];
            }

            if (PromotionRules::isMoneyReward($type)) {
                /*
                 * A money reward moves no stock and produces no order line: it is a number taken
                 * off the total. It cannot be "out of stock" and it has no product to be visible,
                 * so it skips both checks below rather than being made to fail them.
                 */
                $discount += PromotionRules::discountFor($type, Row::nmoney($reward, 'amount'), $cart);
                $freeShipping = $freeShipping || $type === 'free_shipping';

                continue;
            }

            $quantity = max(1, Row::int($reward, 'quantity'));
            $productId = Row::nint($reward, 'product_id');
            $variantId = Row::nint($reward, 'variant_id');

            if (! $this->rewardIsVisible($productId, $variantId, $cart->storefrontId)) {
                return ['rewards' => [], 'reason' => PromotionRules::SKIP_NOT_VISIBLE, 'discount' => 0.0, 'free_shipping' => false];
            }

            $bucket = $this->bucketWithStock($productId, $variantId, $quantity);
            if ($bucket === null) {
                return ['rewards' => [], 'reason' => PromotionRules::SKIP_OUT_OF_STOCK, 'discount' => 0.0, 'free_shipping' => false];
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
         * A rule whose only rewards were money ones and which discounted NOTHING — "10% off" on a
         * cart the conditions let through at a zero subtotal, or "free shipping" on a pickup order
         * that has none. It won, and it delivered nothing at all.
         *
         * Reported as a skip rather than returned as a silent win, for the same reason every other
         * skip exists: an admin whose promotion never does anything has to be told. The customer is
         * unaffected either way — the cart is the one they would have had.
         */
        if ($lines === [] && round($discount, 2) <= 0.0) {
            return ['rewards' => [], 'reason' => PromotionRules::SKIP_NOT_VISIBLE, 'discount' => 0.0, 'free_shipping' => false];
        }

        return [
            'rewards' => $lines,
            'reason' => null,
            /*
             * Clamped ONCE, on the sum (🟡-1, 2026-09-17). It used to be clamped per reward inside
             * `discountFor()` and then added up, so a rule with two rewards could record more than
             * the cart was worth — two "100% off" rewards on a EGP 500 cart recorded EGP 1,100.
             * The shopper was charged correctly either way; the wrong number was the one written
             * into the discount record that finance reconciles against.
             */
            'discount' => PromotionRules::clampToCart($discount, $cart),
            'free_shipping' => $freeShipping,
        ];
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
