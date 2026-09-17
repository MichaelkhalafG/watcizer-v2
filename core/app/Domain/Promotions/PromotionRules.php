<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Support\ManageText;
use Illuminate\Support\Facades\DB;

/**
 * The declared vocabulary of a promotion — condition types, reward types, and which of them this
 * platform can actually deliver today (wave 4D, study §3.16).
 *
 * ── One home for the lists, because three things read them ───────────────────────────────────
 *
 * The authoring screen offers them, the validator refuses anything outside them, and the engine
 * matches on them. A fourth copy in a migration enum would mean a schema change to add a type on a
 * shared host, which is why `promotion_rule_conditions.type` and `promotion_rule_rewards.type` are
 * plain strings validated here instead.
 */
final class PromotionRules
{
    /**
     * The seven condition types (§3.16.2). All conditions on a rule are AND.
     *
     * An OR is two rules with the same reward at different priorities — which is also why
     * "overlapping rules at the same priority" is an authoring refusal: with one-rule-per-cart and
     * ties broken by id, two same-priority rules that can both match make the winner an accident
     * of insertion order.
     *
     * @var list<string>
     */
    public const CONDITIONS = [
        'cart_subtotal_min',
        'product_quantity_min',
        'variant_quantity_min',
        'category_quantity_min',
        'brand_quantity_min',
        'distinct_lines_min',
        'payment_method_in',
    ];

    /**
     * The five reward types (§3.16.3).
     *
     * @var list<string>
     */
    public const REWARDS = [
        'free_product',
        'free_variant',
        'free_shipping',
        'percent_discount',
        'fixed_discount',
    ];

    /**
     * The three reward types that REDUCE what the customer pays.
     *
     * Grouped because they share one property that decides everything below: the order total they
     * produce is LOWER than the total the customer's cart computed. The free-item family has the
     * opposite property — a gift at `piece_price = 0` leaves the total alone — and that difference,
     * not the shape of the reward, is why the two families are gated separately.
     *
     * @var list<string>
     */
    public const REWARDS_MONEY = ['free_shipping', 'percent_discount', 'fixed_discount'];

    /**
     * The reward types every storefront can deliver, whatever its frontend is.
     *
     * ── Why the money family is NOT here ─────────────────────────────────────────────────────
     *
     * `CheckoutCompatController::addOrder()` compares the client's `total_price_for_order` with the
     * server's own and answers **422 "Order total mismatch"** on a disagreement of one piastre.
     * That guard is wave-3 behaviour, it is in the 126-case harness, and it exists because a
     * tampered client total was a real hazard.
     *
     * A free item at `piece_price = 0` adds nothing to the total, so the client's number still
     * matches and the two types below are invisible to the guard. Every reward that REDUCES money
     * makes the server total lower than the client's.
     *
     * The guard is NOT relaxed, and the discount is not smuggled past it. `addOrder()` still
     * compares the client's number with the server's own UNDISCOUNTED total and still 422s on a
     * disagreement; the promotion is applied to the order total only after that comparison has
     * passed. So a tampered client total is refused exactly as before, and the discount is computed
     * from rules the client never sees and can never influence.
     *
     * What remains is a DISCLOSURE problem on cash, and a HARD FAILURE on card:
     *
     *  - **cash on delivery**: the frontend quotes one number and the courier collects a smaller
     *    one. Not wrong, but a shop that silently charges something other than what it displayed is
     *    a support call even when the difference is in the customer's favour.
     *  - **paymob**: the frontend builds the payment intent from the total IT computed, so the
     *    provider charges the undiscounted amount. `PaymentCallbackController` then compares the
     *    provider's minor-unit amount against `orders.total_price_for_order` — the discounted one —
     *    and fails closed on the mismatch: the attempt is recorded as a failure, the order is
     *    neither paid nor cancelled, and a human has to resolve it. Every discounted card order
     *    would land in the reconciliation list.
     *
     * So the gate is not cosmetic. The money family is unlocked PER STOREFRONT, and the thing that
     * unlocks it is a frontend that computes the promotion-aware total itself — which is what makes
     * both the quote and the payment intent right. See {@see isRewardAvailableOn()}.
     *
     * @var list<string>
     */
    public const REWARDS_ALWAYS = ['free_product', 'free_variant'];

    /**
     * The `storefronts.settings` path that unlocks the money family for one storefront.
     *
     * In `settings` rather than a column of its own: it is a per-storefront switch that a storefront
     * screen owns, the table already carries a `settings` json for exactly this, and adding it there
     * costs no DDL on a shared host. ABSENT MEANS OFF — a storefront that has never heard of this
     * setting must behave precisely as it did before it existed.
     */
    public const MONEY_REWARDS_SETTING = 'promotions.money_rewards';

    /** The leaf of the path above, for the writer that sets it. One spelling, two readers. */
    public const MONEY_REWARDS_KEY = 'money_rewards';

    /** Why a rule was not applied, as stored in `promotion_rule_skips.reason`. */
    public const SKIP_OUT_OF_STOCK = 'reward_out_of_stock';

    public const SKIP_NOT_VISIBLE = 'reward_not_visible';

    /**
     * The rule earned a reward this storefront's frontend cannot display.
     *
     * A third reason rather than reusing `reward_not_visible`, because it is a different fact and
     * leads to a different action: "not visible" is fixed on the placement screen, this one is
     * fixed on the storefront screen — or by not writing the rule for that storefront at all.
     */
    public const SKIP_NOT_AVAILABLE = 'reward_not_available';

    /** @var list<string> */
    public const SKIP_REASONS = [self::SKIP_OUT_OF_STOCK, self::SKIP_NOT_VISIBLE, self::SKIP_NOT_AVAILABLE];

    /** The ledger reason a reward reservation carries. Declared in `InventoryService::REASONS`. */
    public const LEDGER_REASON = 'promotion_reward';

    /**
     * The payment methods a checkout can carry, and therefore the only ones a `payment_method_in`
     * condition may name. Same three the checkout controller accepts.
     *
     * @var list<string>
     */
    public const PAYMENT_METHODS = ['cash', 'paymob', 'whatsapp'];

    /**
     * The payment methods a `payment_method_in` condition names, parsed from its stored comma list.
     *
     * One parser, because two would drift: the ENGINE asks this to decide whether a cart's method
     * is named, and the WRITER asks it to refuse a condition that names nothing — and a condition
     * naming nothing can never match, so it is a rule the admin believes is running and which is
     * not. Unknown names are dropped rather than kept: a typo ("cach") would otherwise look like a
     * filled-in condition while matching no checkout in the shop.
     *
     * @return list<string>
     */
    public static function methods(?string $methods): array
    {
        if ($methods === null || trim($methods) === '') {
            return [];
        }

        $out = [];
        foreach (explode(',', $methods) as $method) {
            $method = trim($method);
            if ($method !== '' && in_array($method, self::PAYMENT_METHODS, true) && ! in_array($method, $out, true)) {
                $out[] = $method;
            }
        }

        return $out;
    }

    public static function isCondition(string $type): bool
    {
        return in_array($type, self::CONDITIONS, true);
    }

    public static function isReward(string $type): bool
    {
        return in_array($type, self::REWARDS, true);
    }

    public static function isMoneyReward(string $type): bool
    {
        return in_array($type, self::REWARDS_MONEY, true);
    }

    /**
     * Does this reward type take units off a shelf?
     *
     * The question a stock reader asks, kept separate from "is it available here" even though the
     * two sets happen to coincide. They coincide by accident of what shipped first; conflating them
     * is how a money reward on an enabled storefront would start being asked for its stock level.
     */
    public static function isItemReward(string $type): bool
    {
        return in_array($type, self::REWARDS_ALWAYS, true);
    }

    /**
     * Can THIS storefront deliver this reward type?
     *
     * The free-item family: always. The money family: only where the storefront's own settings say
     * its frontend can show a promotion-aware total.
     *
     * Absent, malformed or non-true settings all mean NO. A storefront row written before this
     * setting existed, or one whose json is unreadable, must behave exactly as it did — the failure
     * direction for a money switch is off.
     */
    public static function isRewardAvailableOn(string $type, int $storefrontId): bool
    {
        if (in_array($type, self::REWARDS_ALWAYS, true)) {
            return true;
        }

        if (! in_array($type, self::REWARDS_MONEY, true)) {
            return false;                   // not a reward type at all
        }

        return self::moneyRewardsEnabled($storefrontId);
    }

    /**
     * Does this storefront's frontend show promotion-aware totals?
     *
     * Read straight from `storefronts.settings`, not cached: it is one indexed primary-key lookup on
     * a table of single digits, it is asked once per checkout rather than per cart line, and a
     * cached money switch that lags an operator turning it OFF is the exact failure this is meant
     * to avoid.
     */
    public static function moneyRewardsEnabled(int $storefrontId): bool
    {
        $settings = DB::table('storefronts')->where('id', $storefrontId)->value('settings');

        if (! is_string($settings) || $settings === '') {
            return false;
        }

        try {
            $decoded = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // Unreadable settings is not a reason to start discounting.
            return false;
        }

        if (! is_array($decoded)) {
            return false;
        }

        foreach (explode('.', self::MONEY_REWARDS_SETTING) as $segment) {
            if (! is_array($decoded) || ! array_key_exists($segment, $decoded)) {
                return false;
            }
            $decoded = $decoded[$segment];
        }

        return $decoded === true;
    }

    /**
     * What one money reward takes off this cart, in EGP.
     *
     * ONE reward's worth, UNCLAMPED against the cart — see {@see clampToCart()} (🟡-1, 2026-09-17).
     *
     * This used to clamp each reward to `subtotal + shipping` on its own, which is the wrong place
     * for the ceiling: a rule granting two rewards clamped each to the cart's value and then SUMMED
     * them, so two "100% off" rewards on a EGP 500 cart produced a recorded discount of EGP 1,100.
     * The customer was charged correctly — the checkout floors the order total at zero — so nothing
     * on screen was wrong; what was wrong was the NUMBER WRITTEN DOWN, in the discount record the
     * settlement export reconciles against.
     *
     * The per-reward bounds stay (a percentage is 0–100, a fixed amount is never negative), because
     * those are facts about the reward. The cart's ceiling is a fact about the CART, so it is
     * applied once, to the total.
     */
    public static function discountFor(string $type, ?string $amount, CartSnapshot $cart): float
    {
        $value = (float) ($amount ?? '0');

        $discount = match ($type) {
            'free_shipping' => $cart->shippingCost,
            'percent_discount' => $cart->subtotal * (max(0.0, min(100.0, $value)) / 100),
            'fixed_discount' => max(0.0, $value),
            default => 0.0,
        };

        return round($discount, 2);
    }

    /**
     * The SUMMED discount, capped at what the cart can bear.
     *
     * Applied once, after every reward on the rule has been added up, so a rule with two rewards
     * cannot record more than the cart was worth. Never negative, so no arithmetic here can produce
     * a surcharge; never more than `subtotal + shipping`, so no order total can go below zero.
     *
     * An operator who writes "EGP 500 off" and meets a EGP 300 cart gives a free cart, not a refund.
     */
    public static function clampToCart(float $discount, CartSnapshot $cart): float
    {
        return round(max(0.0, min($discount, $cart->subtotal + $cart->shippingCost)), 2);
    }

    /**
     * Why a money reward is refused, naming the storefronts that refuse it.
     *
     * Arabic because an admin reads it on the authoring screen; the English half is for whoever
     * reads a log or writes a script.
     *
     * The storefronts are NAMED because that is the only part the operator can act on: the fix is
     * either to drop that storefront from the rule or to turn the setting on for it, and neither is
     * reachable from "this reward type is not available".
     *
     * @param  list<string>  $storefronts  the names that cannot deliver it
     */
    public static function unavailableReason(string $type, array $storefronts = []): string
    {
        /*
         * TWO WHOLE SENTENCES on the seam, not one with a clause spliced into the middle. The
         * storefront list sits in a different place in English than it does in Arabic, so a
         * translator handed the fragment `' على: '` has nothing to translate and nowhere to put it.
         */
        $sentence = $storefronts === []
            ? ManageText::t('promotions.money_reward_unavailable', 'هذا النوع من المكافآت يغيّر المبلغ المستحق، ولا يعمل إلا على متجر تعرض واجهته الخصم. فعّل الإعداد لهذا المتجر، أو استبعده من هذه القاعدة.')
            : ManageText::t('promotions.money_reward_unavailable_on', 'هذا النوع من المكافآت يغيّر المبلغ المستحق، ولا يعمل إلا على متجر تعرض واجهته الخصم على: :names. فعّل الإعداد لهذا المتجر، أو استبعده من هذه القاعدة.', [
                'names' => implode(ManageText::t('common.list_separator', '، '), $storefronts),
            ]);

        /*
         * ── The English suffix is GONE (🟡-5, 2026-09-17) ─────────────────────────────────────
         *
         * This used to append `" [{$type}: money reward; the storefront's frontend must compute a
         * promotion-aware total]"` to every refusal, in every locale. The intent was a note for
         * whoever reads a log — but this string is a VALIDATION MESSAGE: it goes into the error bag
         * and onto the authoring screen, beside the field, where an Arabic operator read a sentence
         * in their own language with a line of English developer jargon stapled to the end of it.
         *
         * Nothing reads it from a log, either: the writer throws a `ValidationException`, which is
         * rendered, not logged. So the suffix served nobody and was noise to everybody, and the
         * reward TYPE it carried is already implied by the field the error is attached to
         * (`rewards.<index>.type`).
         */
        return $sentence;
    }

    /** The Arabic label of a condition type, for the wizard and the rule summary. */
    public static function conditionLabel(string $type): string
    {
        return match ($type) {
            'cart_subtotal_min' => ManageText::t('promotions.condition_cart_subtotal_min', 'إجمالي السلة لا يقل عن'),
            'product_quantity_min' => ManageText::t('promotions.condition_product_quantity_min', 'كمية من منتج معيّن لا تقل عن'),
            'variant_quantity_min' => ManageText::t('promotions.condition_variant_quantity_min', 'كمية من مقاس/لون معيّن لا تقل عن'),
            'category_quantity_min' => ManageText::t('promotions.condition_category_quantity_min', 'كمية من تصنيف معيّن لا تقل عن'),
            'brand_quantity_min' => ManageText::t('promotions.condition_brand_quantity_min', 'كمية من ماركة معيّنة لا تقل عن'),
            'distinct_lines_min' => ManageText::t('promotions.condition_distinct_lines_min', 'عدد المنتجات المختلفة في السلة لا يقل عن'),
            'payment_method_in' => ManageText::t('promotions.condition_payment_method_in', 'طريقة الدفع واحدة من'),
            default => $type,
        };
    }

    /** The Arabic label of a reward type. */
    public static function rewardLabel(string $type): string
    {
        return match ($type) {
            'free_product' => ManageText::t('promotions.reward_free_product', 'منتج مجاني'),
            'free_variant' => ManageText::t('promotions.reward_free_variant', 'مقاس/لون مجاني'),
            'free_shipping' => ManageText::t('promotions.reward_free_shipping', 'شحن مجاني'),
            'percent_discount' => ManageText::t('promotions.reward_percent_discount', 'خصم بنسبة'),
            'fixed_discount' => ManageText::t('promotions.reward_fixed_discount', 'خصم بمبلغ ثابت'),
            default => $type,
        };
    }

    /** The Arabic label of a skip reason, for the rule's "currently inactive" state. */
    public static function skipLabel(string $reason): string
    {
        return match ($reason) {
            self::SKIP_OUT_OF_STOCK => ManageText::t('promotions.skip_out_of_stock', 'مخزون الهدية صفر'),
            self::SKIP_NOT_VISIBLE => ManageText::t('promotions.skip_not_visible', 'الهدية غير معروضة على هذا المتجر'),
            /*
             * Added with the money-reward family and missed here — the preview panel rendered the
             * raw enum `reward_not_available` to the operator. `SKIP_REASONS` gained the third
             * value and this `match` did not, which is the failure mode of keeping a list and its
             * labels in two places; `PromotionSkipLabelTest` now asserts every declared reason has
             * an arm, so the next one cannot be forgotten the same way.
             */
            self::SKIP_NOT_AVAILABLE => ManageText::t(
                'promotions.skip_not_available',
                'نوع المكافأة لا يعمل على هذا المتجر: واجهته لا تعرض الخصم',
            ),
            default => $reason,
        };
    }
}
