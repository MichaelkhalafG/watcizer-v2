<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one door for authoring a promotion rule (wave 4D, study §3.16.6).
 *
 * ── The refusals are here, not in the controller ─────────────────────────────────────────────
 *
 * Four of them are the developer's, one is mine, and all five live in the WRITER so a console
 * command or a future importer meets the same door — the same reasoning that put
 * `PreSwitch::assertMayCreate()` inside `ProductWriter::create()` rather than in a form request.
 *
 *   1. **an unbounded reward** — a quantity below 1 or above the cap. A promotion that gives away
 *      "as many as you like" is a promotion that empties a shelf overnight.
 *   2. **no end date** — every rule is a window. A promotion nobody remembers to switch off is how
 *      a shop gives away stock in January. (The column is NOT NULL as well, so this is the
 *      readable refusal in front of a database error.)
 *   3. **overlapping rules at the same priority** — with one-rule-per-cart and ties broken by id,
 *      two same-priority rules that can both match make the winner an accident of insertion order.
 *   4. **a reward that is not sellable where the rule runs** — soft-deleted, inactive, or not
 *      visible on a chosen storefront. Refused at SAVE because it is a typo-class error the admin
 *      can fix while they are looking at the screen; also skipped at EVALUATION, because this
 *      check decays the moment somebody hides that product (approved 2026-09-13).
 *   5. **a money-reducing reward type** — `free_shipping`, `percent_discount`, `fixed_discount`
 *      are declared but unavailable until the storefront moves to v2, because `addOrder()` would
 *      422 every checkout that earned one (§3.16.9).
 *
 *   6. **a reward or a condition with nothing in it** — `free_product` without a product,
 *      `cart_subtotal_min` without an amount. Found 2026-09-13 while proving refusal 4: the
 *      screen's payload was arriving stripped of every parameter and the writer took it, so a
 *      gift that pointed at no product saved without complaint, and a subtotal condition with no
 *      amount reads in the engine as `subtotal >= 0` — a rule that gives a gift away on EVERY
 *      cart. The payload bug is fixed in the controller; this refusal is here so the same shape
 *      can never be saved again from anywhere, which is what the one-door rule is for.
 *
 * And two shapes the engine already refuses, refused here too so they cannot be SAVED rather than
 * merely ignored: a rule with no conditions (it would match every cart) and a rule with no
 * storefronts (it applies nowhere, so it is a draft whatever the switch says).
 */
final class PromotionWriter
{
    /** The most units one reward may give away. "Unbounded" is the refusal; this is the bound. */
    public const MAX_REWARD_QUANTITY = 50;

    /**
     * One sentence for every empty-condition refusal: the operator needs the WHAT, not the column.
     *
     * A METHOD rather than the `const` it used to be, because a constant is evaluated once at
     * compile time and cannot ask the translator anything — so the English operator would have read
     * this one refusal in Arabic whatever the seam said.
     */
    public static function emptyConditionMessage(): string
    {
        return ManageText::t('promotions.condition_incomplete', 'أكمل بيانات الشرط. شرط ناقص إمّا لا ينطبق أبدًا أو ينطبق على كل سلة.');
    }

    /**
     * Create or update a rule and its children. Returns the rule id.
     *
     * @param  array<string, mixed>  $data  the validated screen payload
     *
     * @throws ValidationException with the field the operator must fix, in Arabic
     */
    public function save(array $data, ?int $ruleId, ?int $actorId): int
    {
        $conditions = self::rows($data, 'conditions');
        $rewards = self::rows($data, 'rewards');
        $storefronts = Coerce::intList($data['storefronts'] ?? []);

        $this->assertSavable($data, $conditions, $rewards, $storefronts, $ruleId);

        return DB::transaction(function () use ($data, $conditions, $rewards, $storefronts, $ruleId, $actorId): int {
            $row = [
                'name' => trim(Coerce::str($data['name'] ?? '')),
                'priority' => Coerce::int($data['priority'] ?? null),
                'is_active' => Coerce::bool($data['is_active'] ?? null),
                'starts_at' => Coerce::str($data['starts_at'] ?? ''),
                'ends_at' => Coerce::str($data['ends_at'] ?? ''),
                'updated_by' => $actorId,
                'updated_at' => now(),
            ];

            if ($ruleId === null) {
                /*
                 * No `PreSwitch` gate, deliberately: it guards TRANSFORM-OUTPUT tables, because a
                 * row typed into one before the switch is deleted by the rebuild. `promotion_rules`
                 * is dashboard-owned and never dropped (§2.20), so a promotion written today
                 * survives switch night — which is why it is safe to author one now.
                 */
                $row['created_by'] = $actorId;
                $row['created_at'] = now();
                $ruleId = (int) DB::table('promotion_rules')->insertGetId($row);
            } else {
                DB::table('promotion_rules')->where('id', $ruleId)->update($row);
                // Children are REPLACED, which is safe because they have no identity of their own:
                // a condition is a line of the rule, not a thing an order can point at. The reward
                // lines already granted point at the RULE, and that id never moves.
                DB::table('promotion_rule_conditions')->where('promotion_rule_id', $ruleId)->delete();
                DB::table('promotion_rule_rewards')->where('promotion_rule_id', $ruleId)->delete();
                DB::table('promotion_rule_storefront')->where('promotion_rule_id', $ruleId)->delete();
            }

            foreach ($conditions as $condition) {
                DB::table('promotion_rule_conditions')->insert(self::conditionRow($condition) + [
                    'promotion_rule_id' => $ruleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach ($rewards as $reward) {
                DB::table('promotion_rule_rewards')->insert(self::rewardRow($reward) + [
                    'promotion_rule_id' => $ruleId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (array_unique($storefronts) as $storefrontId) {
                DB::table('promotion_rule_storefront')->insert([
                    'promotion_rule_id' => $ruleId,
                    'storefront_id' => $storefrontId,
                ]);
            }

            return $ruleId;
        });
    }

    /**
     * Delete a rule — but never one that has already given something away.
     *
     * `order_items.promotion_rule_id` has no foreign key (the legacy app writes that table), so
     * nothing at the database level would stop this. A deleted rule would leave granted reward
     * lines pointing at nothing and the settlement CSV naming a rule that cannot be looked up.
     * Deactivating is what the admin actually wants, and the message says so.
     *
     * @return array{deleted: bool, reason: string}
     */
    public function delete(int $ruleId): array
    {
        $granted = DB::table('order_items')->where('promotion_rule_id', $ruleId)->count();

        if ($granted > 0) {
            return [
                'deleted' => false,
                'reason' => ManageText::t('promotions.delete_blocked_granted', 'لا يمكن حذف قاعدة منحت هدايا بالفعل (:count سطر في طلبات سابقة). أوقفها بدل حذفها حتى يبقى سجل الطلبات مفهومًا.', ['count' => $granted]),
            ];
        }

        DB::transaction(function () use ($ruleId): void {
            foreach (['promotion_rule_skips', 'promotion_rule_rewards', 'promotion_rule_conditions',
                'promotion_rule_storefront'] as $table) {
                DB::table($table)->where('promotion_rule_id', $ruleId)->delete();
            }
            DB::table('promotion_rules')->where('id', $ruleId)->delete();
        });

        return ['deleted' => true, 'reason' => ManageText::t('promotions.deleted', 'تم حذف القاعدة.')];
    }

    // ── the refusals ─────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $conditions
     * @param  list<array<string, mixed>>  $rewards
     * @param  list<int>  $storefronts
     */
    private function assertSavable(array $data, array $conditions, array $rewards, array $storefronts, ?int $ruleId): void
    {
        $errors = [];

        // ── the window ──────────────────────────────────────────────────────────────────────
        $startsAt = trim(Coerce::str($data['starts_at'] ?? ''));
        $endsAt = trim(Coerce::str($data['ends_at'] ?? ''));

        if ($endsAt === '') {
            $errors['ends_at'] = ManageText::t('promotions.needs_end_date', 'كل عرض لازم له تاريخ انتهاء. عرض بدون نهاية هو عرض ينسى أحد إيقافه.');
        } elseif ($startsAt !== '' && $endsAt <= $startsAt) {
            $errors['ends_at'] = ManageText::t('promotions.end_before_start', 'تاريخ الانتهاء لازم يكون بعد تاريخ البداية.');
        }

        // ── applies somewhere, to something ─────────────────────────────────────────────────
        if ($storefronts === []) {
            $errors['storefronts'] = ManageText::t('promotions.needs_storefront', 'اختر متجرًا واحدًا على الأقل. قاعدة بدون متاجر لا تنطبق في أي مكان.');
        }
        if ($conditions === []) {
            $errors['conditions'] = ManageText::t('promotions.needs_condition', 'أضف شرطًا واحدًا على الأقل. قاعدة بدون شروط تنطبق على كل سلة.');
        }
        if ($rewards === []) {
            $errors['rewards'] = ManageText::t('promotions.needs_reward', 'أضف مكافأة واحدة على الأقل.');
        }

        // ── the rewards themselves ──────────────────────────────────────────────────────────
        foreach ($rewards as $index => $reward) {
            $type = Coerce::str($reward['type'] ?? '');

            if (! PromotionRules::isReward($type)) {
                $errors["rewards.{$index}.type"] = ManageText::t('promotions.unknown_reward_type', 'نوع مكافأة غير معروف.');

                continue;
            }
            if (PromotionRules::isMoneyReward($type)) {
                /*
                 * A money reward is refused for the STOREFRONTS it would run on, not globally.
                 * Naming them is the whole point of the message: "not available" tells an operator
                 * nothing they can act on, and the action here is either to drop that storefront
                 * from the rule or to turn the setting on for it.
                 */
                $blocked = self::withoutMoneyRewards($storefronts);
                if ($blocked !== []) {
                    $errors["rewards.{$index}.type"] = PromotionRules::unavailableReason($type, $blocked);

                    continue;
                }

                $error = self::moneyAmountError($type, $reward);
                if ($error !== null) {
                    $errors["rewards.{$index}.amount"] = $error;
                }

                // No product, no stock, no quantity — nothing below this applies to a discount.
                continue;
            }

            $quantity = Coerce::int($reward['quantity'] ?? null);
            if ($quantity < 1 || $quantity > self::MAX_REWARD_QUANTITY) {
                // The cap is a PLACEHOLDER, not a concatenation: the number sits in a different
                // place in English, and a sentence split around it cannot be moved.
                $errors["rewards.{$index}.quantity"] = ManageText::t('promotions.reward_quantity_range', 'الكمية لازم تكون بين 1 و :max. مكافأة بلا حد أقصى تفرّغ الرف في ليلة.', ['max' => self::MAX_REWARD_QUANTITY]);

                continue;
            }

            /*
             * A gift has to BE something. `notSellableOn()` returns an empty list for a reward with
             * no product — deliberately, because "points at nothing" is not "not visible there" —
             * so without this the reward saved silently and the rule gave away nothing forever.
             */
            $target = $type === 'free_variant'
                ? Coerce::nint($reward['variant_id'] ?? null)
                : Coerce::nint($reward['product_id'] ?? null);

            if ($target === null || $target < 1) {
                $errors["rewards.{$index}.".($type === 'free_variant' ? 'variant_id' : 'product_id')]
                    = ManageText::t('promotions.reward_needs_product', 'اختر الهدية. مكافأة بدون منتج لا تُسلَّم لأحد.');

                continue;
            }

            $notSellable = self::notSellableOn($reward, $storefronts);
            if ($notSellable !== []) {
                $errors["rewards.{$index}.product_id"] = ManageText::t('promotions.reward_not_visible_on', 'الهدية غير معروضة على: :names. اعرضها هناك أو اختر هدية أخرى.', [
                    'names' => implode(ManageText::t('common.list_separator', '، '), $notSellable),
                ]);
            }
        }

        // ── the conditions themselves ───────────────────────────────────────────────────────
        foreach ($conditions as $index => $condition) {
            $field = self::emptyConditionField($condition);
            if ($field !== null) {
                $errors["conditions.{$index}.{$field}"] = self::emptyConditionMessage();
            }
        }

        // ── the ambiguity refusal ───────────────────────────────────────────────────────────
        $clash = self::samePriorityClash($data, $storefronts, $ruleId);
        if ($clash !== null) {
            /*
             * The rule's name is quoted with «» rather than the straight " it carried before: a
             * double quote inside the fallback ends the literal for every scanner that reads this
             * seam, and «» is what the rest of the dashboard's Arabic already uses for a quoted name.
             */
            $errors['priority'] = ManageText::t('promotions.same_priority_clash', 'توجد قاعدة أخرى بنفس الأولوية على نفس المتجر («:name»). القواعد لا تتجمع، والأعلى أولوية هي التي تُطبَّق — فلو تساوت، أيّهما يفوز يصبح مصادفة. غيّر الأولوية.', ['name' => $clash]);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The field of a condition that is EMPTY, or null when the condition carries what it needs.
     *
     * Every condition type means "at least this much of that". Without the *that*, the type is not
     * a weaker condition — it is a different one. `cart_subtotal_min` with no amount reads in the
     * engine as `subtotal >= 0`, which matches every cart in the shop, so an admin who forgot to
     * type the figure would be giving a gift away with every order. The rest are inert instead of
     * dangerous (the engine refuses a quantity of 0), but an inert rule the admin believes is
     * running is the failure the whole state column exists to prevent — so all of them are refused
     * at the door rather than only the one that costs money.
     *
     * @param  array<string, mixed>  $condition
     */
    private static function emptyConditionField(array $condition): ?string
    {
        $type = Coerce::str($condition['type'] ?? '');
        $quantity = Coerce::int($condition['quantity'] ?? null);
        $has = static fn (string $key): bool => (Coerce::nint($condition[$key] ?? null) ?? 0) > 0;

        return match ($type) {
            // Strictly ABOVE zero: "a cart of at least nothing" is every cart.
            'cart_subtotal_min' => (float) Coerce::str($condition['amount'] ?? '0') > 0 ? null : 'amount',
            'product_quantity_min' => ! $has('product_id') ? 'product_id' : ($quantity < 1 ? 'quantity' : null),
            'variant_quantity_min' => ! $has('variant_id') ? 'variant_id' : ($quantity < 1 ? 'quantity' : null),
            'category_quantity_min' => ! $has('storefront_category_id')
                ? 'storefront_category_id'
                : ($quantity < 1 ? 'quantity' : null),
            'brand_quantity_min' => ! $has('brand_id') ? 'brand_id' : ($quantity < 1 ? 'quantity' : null),
            'distinct_lines_min' => $quantity < 1 ? 'quantity' : null,
            'payment_method_in' => PromotionRules::methods(Coerce::str($condition['methods'] ?? '')) === []
                ? 'methods'
                : null,
            // An unknown type is refused by the controller's `Rule::in`; here it is simply not our
            // business to invent a parameter for it.
            default => null,
        };
    }

    /**
     * The storefronts (by name) where this reward is not sellable.
     *
     * @param  array<string, mixed>  $reward
     * @param  list<int>  $storefronts
     * @return list<string>
     */
    private static function notSellableOn(array $reward, array $storefronts): array
    {
        $productId = Coerce::nint($reward['product_id'] ?? null);
        $variantId = Coerce::nint($reward['variant_id'] ?? null);

        if ($variantId !== null) {
            $productId = Coerce::nint(DB::table('catalog_product_variants')->where('id', $variantId)->value('product_id'));
        }
        if ($productId === null) {
            return [];                                  // the missing-product case is its own error
        }

        $out = [];
        foreach ($storefronts as $storefrontId) {
            $sellable = DB::table('catalog_products as p')
                ->join('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                    $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId);
                })
                ->where('p.id', $productId)
                ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('sp.is_visible', 1)
                ->exists();

            if (! $sellable) {
                $name = DB::table('storefronts')->where('id', $storefrontId)->value('name');
                $out[] = is_scalar($name) ? (string) $name : ('#'.$storefrontId);
            }
        }

        return $out;
    }

    /**
     * Another ACTIVE rule at the same priority on a shared storefront, or null.
     *
     * Only active rules and only overlapping windows: two rules at priority 5 that can never be
     * live at the same moment are not ambiguous, and refusing them would stop an admin scheduling
     * next month's promotion while this month's is running.
     *
     * @param  array<string, mixed>  $data
     * @param  list<int>  $storefronts
     */
    private static function samePriorityClash(array $data, array $storefronts, ?int $ruleId): ?string
    {
        if (! Coerce::bool($data['is_active'] ?? null) || $storefronts === []) {
            return null;                                // an inactive rule cannot be in a race
        }

        $starts = trim(Coerce::str($data['starts_at'] ?? ''));
        $ends = trim(Coerce::str($data['ends_at'] ?? ''));

        $query = DB::table('promotion_rules as r')
            ->join('promotion_rule_storefront as rs', 'rs.promotion_rule_id', '=', 'r.id')
            ->whereIn('rs.storefront_id', $storefronts)
            ->where('r.is_active', 1)
            ->where('r.priority', Coerce::int($data['priority'] ?? null));

        if ($ruleId !== null) {
            $query->where('r.id', '!=', $ruleId);
        }
        if ($starts !== '' && $ends !== '') {
            // Overlap: the other rule starts before this one ends, and ends after this one starts.
            $query->where('r.starts_at', '<=', $ends)->where('r.ends_at', '>=', $starts);
        }

        $name = $query->orderBy('r.id')->value('r.name');

        return is_scalar($name) ? (string) $name : null;
    }

    // ── row shaping ──────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private static function rows(array $data, string $key): array
    {
        $out = [];
        foreach (Coerce::arr($data[$key] ?? null) as $row) {
            if (is_array($row)) {
                /** @var array<string, mixed> $row */
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private static function conditionRow(array $condition): array
    {
        $type = Coerce::str($condition['type'] ?? '');

        return [
            'type' => PromotionRules::isCondition($type) ? $type : 'cart_subtotal_min',
            'product_id' => Coerce::nint($condition['product_id'] ?? null),
            'variant_id' => Coerce::nint($condition['variant_id'] ?? null),
            'storefront_category_id' => Coerce::nint($condition['storefront_category_id'] ?? null),
            'brand_id' => Coerce::nint($condition['brand_id'] ?? null),
            'quantity' => Coerce::nint($condition['quantity'] ?? null),
            'amount' => ($amount = Coerce::nfloat($condition['amount'] ?? null)) === null ? null : number_format($amount, 2, '.', ''),
            'methods' => Coerce::nstr($condition['methods'] ?? null),
        ];
    }

    /**
     * The chosen storefronts whose frontend cannot show a promotion-aware total, by NAME.
     *
     * @param  list<int>  $storefronts
     * @return list<string>
     */
    private static function withoutMoneyRewards(array $storefronts): array
    {
        $blocked = [];
        foreach (array_unique($storefronts) as $storefrontId) {
            if (! PromotionRules::moneyRewardsEnabled($storefrontId)) {
                $name = DB::table('storefronts')->where('id', $storefrontId)->value('name');
                $blocked[] = is_string($name) && $name !== '' ? $name : "#{$storefrontId}";
            }
        }

        return $blocked;
    }

    /**
     * Why this money reward's amount is unusable, or null when it is fine.
     *
     * `free_shipping` takes no amount at all — it waives whatever the cart's shipping happens to be.
     * The other two are refused at ZERO as well as at nonsense values: a "0% off" rule is one the
     * admin believes is running and which gives nothing, the same failure the empty-condition
     * refusal exists to catch.
     *
     * @param  array<string, mixed>  $reward
     */
    private static function moneyAmountError(string $type, array $reward): ?string
    {
        if ($type === 'free_shipping') {
            return null;
        }

        $amount = Coerce::nfloat($reward['amount'] ?? null);

        if ($amount === null || $amount <= 0) {
            return $type === 'percent_discount'
                ? ManageText::t('promotions.percent_must_exceed_zero', 'اكتب نسبة أكبر من صفر. خصم بصفر بالمئة لا يعطي العميل شيئًا.')
                : ManageText::t('promotions.amount_must_exceed_zero', 'اكتب مبلغًا أكبر من صفر. خصم بصفر جنيه لا يعطي العميل شيئًا.');
        }

        if ($type === 'percent_discount' && $amount > 100) {
            return ManageText::t('promotions.percent_max_hundred', 'النسبة لا تزيد عن 100%.');
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $reward
     * @return array<string, mixed>
     */
    private static function rewardRow(array $reward): array
    {
        $type = Coerce::str($reward['type'] ?? 'free_product');
        $money = PromotionRules::isMoneyReward($type);
        $amount = Coerce::nfloat($reward['amount'] ?? null);

        return [
            'type' => $type,
            /*
             * A money reward has no product, no variant and no quantity that means anything. They
             * are NULLED rather than carried through, so a type changed from `free_product` to
             * `percent_discount` on the authoring screen cannot leave a stale product id behind for
             * a later reader to act on.
             */
            'product_id' => $money ? null : Coerce::nint($reward['product_id'] ?? null),
            'variant_id' => $money ? null : Coerce::nint($reward['variant_id'] ?? null),
            'quantity' => $money ? 1 : max(1, Coerce::int($reward['quantity'] ?? null, 1)),
            // `free_shipping` waives whatever the cart's shipping is, so it carries no amount.
            'amount' => ($money && $type !== 'free_shipping' && $amount !== null)
                ? number_format($amount, 2, '.', '')
                : null,
        ];
    }
}
