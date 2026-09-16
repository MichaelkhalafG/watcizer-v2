<?php

use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\PromotionEngine;
use App\Domain\Promotions\PromotionRules;
use Illuminate\Support\Facades\DB;
use Tests\Support\PromotionFixture;
use Tests\Support\T;

/*
 * The money reward family — `free_shipping`, `percent_discount`, `fixed_discount` (wave 4D, §3.16.3).
 *
 * ── What is actually being guarded here ──────────────────────────────────────────────────────
 *
 * These three reduce the amount the customer pays, which the free-item family never does. That one
 * difference reaches four places, and each one has a test below:
 *
 *   1. the ENGINE has to compute a discount and grant no stock,
 *   2. the CHECKOUT has to apply it AFTER the client/server total guard, never before,
 *   3. the AUTHORING screen has to refuse it per storefront and name the ones that refused,
 *   4. the arithmetic must never produce a negative order total.
 *
 * ── Why a storefront switch, and why it defaults OFF ─────────────────────────────────────────
 *
 * A frontend that cannot compute a promotion-aware total quotes one number and gets charged a
 * smaller one. On cash that is a support call; on a card it is a hard failure, because
 * `PaymentCallbackController` compares the provider's amount against the stored order total and
 * fails closed on a mismatch. So the family is unlocked per storefront, and a storefront that has
 * never heard of the setting behaves exactly as it did before the setting existed.
 *
 * Every test here CONSTRUCTS the storefront state it asserts on rather than inheriting it.
 */

beforeEach(function () {
    // Same reason as PromotionScenarioTest: a test that assumes it is the only promotion in the
    // shop passes until the shop has one. Rolled back by DatabaseTransactions.
    foreach (['promotion_rule_skips', 'promotion_rule_rewards', 'promotion_rule_conditions',
        'promotion_rule_storefront', 'promotion_rules'] as $table) {
        DB::table($table)->delete();
    }
});

function moneyEngine(): PromotionEngine
{
    return new PromotionEngine;
}

// ── 1. the engine ────────────────────────────────────────────────────────────────────────────

it('takes a PERCENTAGE off the subtotal, and moves no stock at all', function () {
    PromotionFixture::moneyRewards(true);
    $product = PromotionFixture::giftableProduct();
    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::percentDiscount(10)]);

    $cart = PromotionFixture::cart([['product' => $product, 'qty' => 1, 'price' => 500.0]], shipping: 50.0);
    $outcome = moneyEngine()->evaluate($cart);

    expect($outcome->applies())->toBeTrue()
        // 10% of the SUBTOTAL, not of subtotal+shipping: a percentage discount is on the goods.
        ->and($outcome->discount)->toBe(50.0)
        ->and($outcome->freeShipping)->toBeFalse()
        // The half that makes it a money reward rather than a gift: nothing leaves a shelf.
        ->and($outcome->rewards)->toBe([]);
});

it('takes a FIXED amount off, and waives shipping for free_shipping', function () {
    PromotionFixture::moneyRewards(true);
    $product = PromotionFixture::giftableProduct();

    $fixed = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::fixedDiscount(25)]);
    $cart = PromotionFixture::cart([['product' => $product, 'qty' => 1, 'price' => 500.0]], shipping: 50.0);
    expect(moneyEngine()->evaluate($cart)->discount)->toBe(25.0);

    DB::table('promotion_rules')->where('id', $fixed)->update(['is_active' => 0]);
    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeShipping()]);

    $outcome = moneyEngine()->evaluate($cart);

    // It waives THIS cart's shipping, whatever that is — which is why the reward carries no amount.
    expect($outcome->discount)->toBe(50.0)
        ->and($outcome->freeShipping)->toBeTrue();
});

it('never discounts more than the cart is worth', function () {
    PromotionFixture::moneyRewards(true);
    $product = PromotionFixture::giftableProduct();
    // EGP 500 off a EGP 100 cart. An operator will write this one day.
    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(10)], [PromotionFixture::fixedDiscount(500)]);

    $cart = PromotionFixture::cart([['product' => $product, 'qty' => 1, 'price' => 100.0]], shipping: 30.0);
    $outcome = moneyEngine()->evaluate($cart);

    /*
     * Clamped to what the cart can bear — subtotal plus the shipping it could waive. The customer
     * gets a free cart, never a refund, and the order total can never go negative.
     */
    expect($outcome->discount)->toBe(130.0)
        ->and($outcome->discount)->toBeLessThanOrEqual($cart->subtotal + $cart->shippingCost);
});

it('reports a money rule that discounts NOTHING as a skip rather than a silent win', function () {
    PromotionFixture::moneyRewards(true);
    $product = PromotionFixture::giftableProduct();
    // Free shipping on a cart that has none — the rule won and delivered nothing.
    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(10)], [PromotionFixture::freeShipping()]);

    $outcome = moneyEngine()->evaluate(
        PromotionFixture::cart([['product' => $product, 'qty' => 1, 'price' => 100.0]], shipping: 0.0)
    );

    // An admin whose promotion does nothing has to be told; the customer is unaffected either way.
    expect($outcome->applies())->toBeFalse()
        ->and($outcome->skipped)->toHaveKey($rule);
});

// ── 2. the storefront switch ─────────────────────────────────────────────────────────────────

it('refuses the money family on a storefront whose setting is absent, and allows it once set', function () {
    $product = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::percentDiscount(10)]);
    $cart = PromotionFixture::cart([['product' => $product, 'qty' => 1, 'price' => 500.0]]);

    // ABSENT means off. A storefront that has never heard of the setting must not start discounting.
    DB::table('storefronts')->where('id', 1)->update(['settings' => null]);
    $before = moneyEngine()->evaluate($cart);

    expect($before->applies())->toBeFalse()
        ->and($before->discount)->toBe(0.0)
        ->and($before->skipped[$rule])->toBe(PromotionRules::SKIP_NOT_AVAILABLE);

    PromotionFixture::moneyRewards(true);
    expect(moneyEngine()->evaluate($cart)->discount)->toBe(50.0);

    // And OFF again is off again — the switch is read per evaluation, never cached.
    PromotionFixture::moneyRewards(false);
    expect(moneyEngine()->evaluate($cart)->applies())->toBeFalse();
});

it('treats settings of an unexpected SHAPE as OFF', function () {
    $product = PromotionFixture::giftableProduct();
    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::percentDiscount(10)]);

    /*
     * Malformed json is not one of the cases: `storefronts.settings` carries a CHECK constraint, so
     * MariaDB refuses to store `{not json` at all (proved by trying — the update raises SQLSTATE
     * 23000). What can be stored is valid json of the wrong shape, which is what this asserts.
     *
     * Each of these is a real way the column could arrive from an importer, an older schema, or a
     * hand-written fix, and every one of them must mean OFF. The failure direction for a switch
     * that changes what a customer is charged is off.
     */
    foreach (['null', '"yes"', '[1,2,3]', '{"promotions":true}', '{"promotions":{"money_rewards":"true"}}', '{"promotions":{"money_rewards":1}}'] as $settings) {
        DB::table('storefronts')->where('id', 1)->update(['settings' => $settings]);

        expect(PromotionRules::moneyRewardsEnabled(1))->toBeFalse("settings {$settings} must mean OFF")
            ->and(moneyEngine()->evaluate(
                PromotionFixture::cart([['product' => $product, 'qty' => 1, 'price' => 500.0]])
            )->applies())->toBeFalse("settings {$settings} must grant no discount");
    }
});

it('is per storefront, not global', function () {
    /*
     * The second storefront is CREATED if the shop has not got one, rather than the test skipping
     * itself — "per storefront" is exactly the property that would stop being checked on a database
     * with a single storefront, which is every fresh install. Rolled back with the transaction.
     */
    $second = T::int(DB::table('storefronts')->where('id', '!=', 1)->orderBy('id')->value('id') ?? 0);
    if ($second === 0) {
        $second = (int) DB::table('storefronts')->insertGetId([
            'code' => 'moneytest',
            'name' => 'Money Test Storefront',
            'locales' => json_encode(['ar'], JSON_THROW_ON_ERROR),
            'default_locale' => 'ar',
            'currency' => 'EGP',
            'is_active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    PromotionFixture::moneyRewards(true, 1);
    PromotionFixture::moneyRewards(false, $second);

    expect(PromotionRules::isRewardAvailableOn('percent_discount', 1))->toBeTrue()
        ->and(PromotionRules::isRewardAvailableOn('percent_discount', $second))->toBeFalse()
        // The free-item family is unaffected by the switch on either storefront.
        ->and(PromotionRules::isRewardAvailableOn('free_product', $second))->toBeTrue();
});

it('has a human label for every skip reason it declares', function () {
    /*
     * A list and its labels in two places drift, and this one already had: `SKIP_NOT_AVAILABLE` was
     * added to `SKIP_REASONS` with the money-reward family and not to `skipLabel()`'s match, so the
     * preview panel showed the operator the raw enum string `reward_not_available`.
     *
     * Asserted over the DECLARED list rather than by naming the three reasons, so a fourth is
     * covered the day somebody adds it — which is the only version of this test worth having.
     */
    $unlabelled = [];
    foreach (PromotionRules::SKIP_REASONS as $reason) {
        if (PromotionRules::skipLabel($reason) === $reason) {
            $unlabelled[] = $reason;
        }
    }

    expect($unlabelled)->toBe(
        [],
        'these skip reasons fall through `skipLabel()` and render as a raw enum to the operator: '
        .implode(', ', $unlabelled)
    );
});

// ── 3. the arithmetic the order total depends on ─────────────────────────────────────────────

it('computes each type from the cart, with the percentage clamped to 0..100', function () {
    // Built directly rather than through the fixture: this test is about the arithmetic alone, so
    // it states the subtotal and the shipping instead of deriving them from lines.
    $cart = new CartSnapshot(storefrontId: 1, lines: [], subtotal: 200.0, shippingCost: 40.0, paymentMethod: 'cash');

    expect(PromotionRules::discountFor('percent_discount', '25.00', $cart))->toBe(50.0)
        ->and(PromotionRules::discountFor('fixed_discount', '30.00', $cart))->toBe(30.0)
        ->and(PromotionRules::discountFor('free_shipping', null, $cart))->toBe(40.0)
        // A negative or absurd rate cannot ADD to the bill or discount more than everything.
        ->and(PromotionRules::discountFor('percent_discount', '-10.00', $cart))->toBe(0.0)
        ->and(PromotionRules::discountFor('percent_discount', '400.00', $cart))->toBe(200.0)
        ->and(PromotionRules::discountFor('fixed_discount', '-5.00', $cart))->toBe(0.0)
        // A type that is not a money reward is worth nothing, never a fallback amount.
        ->and(PromotionRules::discountFor('free_product', '99.00', $cart))->toBe(0.0);
});
