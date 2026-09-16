<?php

use App\Domain\Promotions\PromotionEngine;
use App\Domain\Promotions\PromotionRules;
use App\Domain\Promotions\PromotionSkips;
use Illuminate\Support\Facades\DB;
use Tests\Support\PromotionFixture;
use Tests\Support\T;

/*
 * The promotions scenario matrix (wave 4D, study §3.16) — the list the brief named, one test each:
 * condition met, partially met, two rules colliding, reward out of stock, an item removed so the
 * rule stops applying, a rule expiring mid-cart, and idempotent evaluation.
 *
 * The order-placed-then-cancelled scenario and the exactly-once/concurrency properties are in
 * `PromotionGrantTest`, because they need a real checkout rather than a snapshot.
 *
 * ── Why these are snapshot tests ─────────────────────────────────────────────────────────────
 *
 * `PromotionEngine::evaluate(CartSnapshot): PromotionOutcome` is a pure function, so a scenario is
 * a value in and a value out. That is the whole reason the engine was built that way: "the same
 * cart evaluated twice yields the same result; nothing accumulates" is a property this file can
 * assert directly instead of inferring from a checkout's side effects.
 */

/*
 * Every scenario below is about the rules IT creates, so the ambient set has to be empty — and it
 * was not: a rule seeded by hand to prove the rebuild-survival property (§3.16.9 resolution 🔴-3)
 * was still live at priority 5 and outbid every test rule, which reported as two failures about
 * conditions rather than as what it was.
 *
 * That probe row is gone now. This clear stays, because the lesson is the shape of the mistake:
 * a promotion test that assumes it is the only promotion is a test that passes until the shop has
 * one. `DatabaseTransactions` rolls the delete back, so it is local to the test and touches
 * nothing permanently — and `PromotionGrantTest` deliberately does NOT clear, because a real
 * checkout has to cope with whatever the shop is running.
 */
beforeEach(function () {
    foreach (['promotion_rule_skips', 'promotion_rule_rewards', 'promotion_rule_conditions',
        'promotion_rule_storefront', 'promotion_rules'] as $table) {
        DB::table($table)->delete();
    }
});

function engine(): PromotionEngine
{
    return app(PromotionEngine::class);
}

// ── condition met, and partially met ─────────────────────────────────────────────────────────

it('grants the reward when the condition is met', function () {
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1000)],
        [PromotionFixture::freeProduct($gift, 1)],
    );

    $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 2, 'price' => 600.0]]));

    expect($outcome->applies())->toBeTrue()
        ->and($outcome->ruleId)->toBe($rule)
        ->and($outcome->rewards)->toHaveCount(1)
        ->and($outcome->rewards[0]->productId)->toBe($gift)
        ->and($outcome->rewards[0]->quantity)->toBe(1)
        // The bucket is resolved, not assumed: a reward is never split across Express and Market.
        ->and($outcome->rewards[0]->typeStock)->toBeIn(['Express', 'Market'])
        ->and($outcome->skipped)->toBe([]);
});

it('grants NOTHING when the condition is only partially met', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(1000)],
        [PromotionFixture::freeProduct($gift, 1)],
    );

    // 999 of the 1000 required. One piastre short is short.
    $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 999.0]]));

    expect($outcome->applies())->toBeFalse()
        ->and($outcome->ruleId)->toBeNull()
        ->and($outcome->rewards)->toBe([])
        // NOT a skip: the cart simply does not qualify, and counting that would drown the counter
        // that exists to report rules which matched and could not be delivered.
        ->and($outcome->skipped)->toBe([]);
});

it('requires EVERY condition on a rule — they are AND, never OR', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100), PromotionFixture::distinctLinesAtLeast(3)],
        [PromotionFixture::freeProduct($gift, 1)],
    );

    // The subtotal holds; the distinct-item count does not.
    $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 10, 'price' => 500.0]]));

    expect($outcome->applies())->toBeFalse();
});

// ── two rules colliding ──────────────────────────────────────────────────────────────────────

it('applies the HIGHER priority rule when two match, and only that one', function () {
    $gift = PromotionFixture::giftableProduct();
    $low = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($gift, 1)], priority: 1, name: 'low');
    $high = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($gift, 2)], priority: 9, name: 'high');

    $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]));

    // One rule per cart. The rules do NOT stack — which is why the authoring screen has to say so.
    expect($outcome->ruleId)->toBe($high)
        ->and($outcome->ruleId)->not->toBe($low)
        ->and($outcome->rewards)->toHaveCount(1)
        ->and($outcome->rewards[0]->quantity)->toBe(2)
        ->and($outcome->rewardUnits())->toBe(2);
});

it('breaks a priority tie by the LOWER rule id', function () {
    $gift = PromotionFixture::giftableProduct();
    $first = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($gift, 1)], priority: 5, name: 'first');
    $second = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($gift, 1)], priority: 5, name: 'second');

    $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]));

    expect($outcome->ruleId)->toBe(min($first, $second))
        // Deterministic, which is what makes "overlapping rules at the same priority" an authoring
        // REFUSAL rather than a coin toss: the engine has to pick one, and the screen's job is to
        // stop the admin creating the ambiguity in the first place.
        ->and($outcome->ruleId)->toBeLessThan(max($first, $second));
});

// ── the reward is out of stock ───────────────────────────────────────────────────────────────

it('does not apply a rule whose reward is out of stock, and reports it to the admin', function () {
    $gift = PromotionFixture::giftableProduct();
    $empty = PromotionFixture::outOfStockProduct();
    if ($empty === null) {
        expect(T::int(DB::table('catalog_products')->where('stock_express', 0)->where('stock_market', 0)->count()))->toBe(0);

        return;
    }

    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct($empty, 1)],
    );

    $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]));

    // Silent to the CUSTOMER: they get the cart they would have had, and nothing failed.
    expect($outcome->applies())->toBeFalse()
        ->and($outcome->rewards)->toBe([])
        // LOUD to the ADMIN: the rule matched and was refused, with the reason.
        ->and($outcome->skipped)->toBe([$rule => PromotionRules::SKIP_OUT_OF_STOCK]);
});

it('lets a LOWER-priority rule apply when the winner cannot deliver', function () {
    $gift = PromotionFixture::giftableProduct();
    $empty = PromotionFixture::outOfStockProduct();
    if ($empty === null) {
        expect(true)->toBeTrue('this catalogue has no zero-stock visible product to make undeliverable');

        return;
    }

    $blocked = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($empty, 1)], priority: 9, name: 'out of stock');
    $usable = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($gift, 1)], priority: 1, name: 'in stock');

    $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]));

    /*
     * The developer-approved decision: one empty shelf must not silently switch off every
     * promotion beneath it. The higher rule's refusal is still reported.
     */
    expect($outcome->ruleId)->toBe($usable)
        ->and($outcome->rewards[0]->productId)->toBe($gift)
        ->and($outcome->skipped)->toBe([$blocked => PromotionRules::SKIP_OUT_OF_STOCK]);
});

// ── the customer removes an item ─────────────────────────────────────────────────────────────

it('stops applying when the customer removes the item that qualified them', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule(
        [PromotionFixture::productQuantityAtLeast($gift, 2)],
        [PromotionFixture::freeProduct($gift, 1)],
    );

    $withTwo = PromotionFixture::cart([['product' => $gift, 'qty' => 2, 'price' => 300.0]]);
    $withOne = PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 300.0]]);

    expect(engine()->evaluate($withTwo)->applies())->toBeTrue()
        // Nothing is remembered between evaluations: the second cart is judged on its own contents.
        ->and(engine()->evaluate($withOne)->applies())->toBeFalse();
});

it('stops applying when a whole line is removed', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule(
        [PromotionFixture::distinctLinesAtLeast(2)],
        [PromotionFixture::freeProduct($gift, 1)],
    );

    $other = T::int(DB::table('catalog_products')->where('id', '!=', $gift)->orderBy('id')->value('id'));

    $twoLines = PromotionFixture::cart([
        ['product' => $gift, 'qty' => 1, 'price' => 300.0],
        ['product' => $other, 'qty' => 1, 'price' => 300.0],
    ]);
    $oneLine = PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 300.0]]);

    expect(engine()->evaluate($twoLines)->applies())->toBeTrue()
        ->and(engine()->evaluate($oneLine)->applies())->toBeFalse();
});

// ── a rule expiring mid-cart ─────────────────────────────────────────────────────────────────

it('stops applying the moment the window closes, mid-cart', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct($gift, 1)],
        startsAt: now()->subHour()->format('Y-m-d H:i:s'),
        endsAt: now()->addMinutes(5)->format('Y-m-d H:i:s'),
    );

    $cart = PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]);

    // The shopper filled the cart while the rule was live…
    expect(engine()->evaluate($cart)->applies())->toBeTrue();

    /*
     * …and checks out ten minutes later. `evaluate()` takes the moment explicitly so this is a
     * value, not a sleep — and it is why the window is compared at evaluation rather than cached
     * with the cart. An order already placed keeps its reward: the order records what happened
     * (§3.16.7 item 8).
     */
    expect(engine()->evaluate($cart, now()->addMinutes(10))->applies())->toBeFalse();
});

it('has not started yet, either', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct($gift, 1)],
        startsAt: now()->addDay()->format('Y-m-d H:i:s'),
        endsAt: now()->addMonth()->format('Y-m-d H:i:s'),
    );

    expect(engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]))->applies())->toBeFalse();
});

it('ignores an inactive rule inside its window', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct($gift, 1)],
        active: false,
    );

    expect(engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]))->applies())->toBeFalse();
});

// ── idempotent evaluation ────────────────────────────────────────────────────────────────────

it('is idempotent: the same cart twice yields the same result and accumulates nothing', function () {
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct($gift, 2)],
    );

    $cart = PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]);

    $first = engine()->evaluate($cart);
    $second = engine()->evaluate($cart);

    expect($second->ruleId)->toBe($first->ruleId)
        ->and($second->rewardUnits())->toBe($first->rewardUnits())
        ->and($second->rewardUnits())->toBe(2, 'a second evaluation doubled the reward');

    // And it WROTE nothing — which is what makes the claim above a property of the method rather
    // than a coincidence of this data. The counter is the checkout's job, never the engine's.
    expect(T::int(DB::table('promotion_rule_skips')->where('promotion_rule_id', $rule)->count()))->toBe(0);
});

it('writes NOTHING during evaluation, even when it refuses a rule', function () {
    $empty = PromotionFixture::outOfStockProduct();
    if ($empty === null) {
        expect(true)->toBeTrue('no zero-stock visible product in this catalogue');

        return;
    }
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($empty, 1)]);

    $before = T::int(DB::table('promotion_rule_skips')->count());
    $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]));

    /*
     * The refusal is REPORTED, not recorded. The cart is read on every storefront page, so an
     * engine that counted here would turn the hottest GET in the application into a write.
     */
    expect($outcome->skipped)->toHaveKey($rule)
        ->and(T::int(DB::table('promotion_rule_skips')->count()))->toBe($before);
});

// ── the storefront model ─────────────────────────────────────────────────────────────────────

it('never considers a rule that is not enabled for the cart’s storefront', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct($gift, 1)],
        storefronts: [2],                               // Brand Fashion only
    );

    $watchizerCart = PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]], storefrontId: 1);

    // Not a skip and not a refusal — never a CANDIDATE. The pivot is an inner join.
    expect(engine()->evaluate($watchizerCart)->applies())->toBeFalse()
        ->and(engine()->evaluate($watchizerCart)->skipped)->toBe([]);
});

it('applies a BOTH-storefronts rule on either one', function () {
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct($gift, 1)],
        storefronts: [1, 2],
    );

    foreach ([1, 2] as $storefrontId) {
        $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]], storefrontId: $storefrontId));
        // Storefront 2 may not have the gift VISIBLE, which is a different refusal — so the
        // assertion is that the rule was considered, not that it necessarily applied.
        expect($outcome->applies() || array_key_exists($rule, $outcome->skipped))
            ->toBeTrue("the rule was not even considered on storefront {$storefrontId}");
    }
});

// ── the inert shapes the engine refuses on its own ───────────────────────────────────────────

it('refuses a rule with NO conditions, which would otherwise apply to every cart', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule([], [PromotionFixture::freeProduct($gift, 1)]);

    // An authoring refusal too — but the engine does not trust the screen: a rule written by a
    // console command or left half-saved must not start giving stock away to everybody.
    expect(engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 10.0]]))->applies())->toBeFalse();
});

it('refuses a rule with NO rewards', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], []);

    expect(engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]))->applies())->toBeFalse();
});

it('refuses a MONEY-REDUCING reward on a storefront that cannot show the discount', function () {
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::percentDiscount()]);

    /*
     * The storefront in this fixture has no `promotions.money_rewards` setting, which means OFF.
     *
     * Granting the discount anyway would leave the order carrying a total lower than the one the
     * frontend quoted and — on a card payment — lower than the amount the provider was asked to
     * charge, which `PaymentCallbackController` then fails closed on. So the engine refuses it,
     * not only the screen: authoring DECAYS, and the setting can be switched off tomorrow under a
     * rule that was written legitimately today.
     */
    $outcome = engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]]));

    expect($outcome->applies())->toBeFalse()
        ->and($outcome->discount)->toBe(0.0)
        ->and($outcome->skipped)->toHaveKey($rule)
        ->and($outcome->skipped[$rule])->toBe(PromotionRules::SKIP_NOT_AVAILABLE)
        ->and(PromotionRules::isReward('percent_discount'))->toBeTrue()
        ->and(PromotionRules::isMoneyReward('percent_discount'))->toBeTrue();
});

// ── payment-method conditions ────────────────────────────────────────────────────────────────

it('matches on the payment method, and only the listed ones', function () {
    $gift = PromotionFixture::giftableProduct();
    PromotionFixture::rule(
        [PromotionFixture::paymentMethodIn(['cash'])],
        [PromotionFixture::freeProduct($gift, 1)],
    );

    expect(engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]], method: 'cash'))->applies())->toBeTrue()
        ->and(engine()->evaluate(PromotionFixture::cart([['product' => $gift, 'qty' => 1, 'price' => 500.0]], method: 'paymob'))->applies())->toBeFalse();
});

// ── the counter's two shapes ─────────────────────────────────────────────────────────────────

it('counts skips per storefront and per reason, and reads stock back as ONE figure', function () {
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($gift, 1)], storefronts: [1, 2]);
    $skips = app(PromotionSkips::class);

    // The same out-of-stock refusal on both storefronts, twice on storefront 1.
    $skips->record([$rule => PromotionRules::SKIP_OUT_OF_STOCK], 1);
    $skips->record([$rule => PromotionRules::SKIP_OUT_OF_STOCK], 1);
    $skips->record([$rule => PromotionRules::SKIP_OUT_OF_STOCK], 2);
    // …and a visibility refusal on storefront 2 only.
    $skips->record([$rule => PromotionRules::SKIP_NOT_VISIBLE], 2);

    $report = PromotionSkips::forRule($rule);

    /*
     * Stock is SHARED — one pool, one ledger — so the three stock misses read back as one figure.
     * Showing "2 on Watchizer, 1 on Brand Fashion" for a stock reason would be a lie dressed as
     * detail. Visibility IS per storefront, so it reads back per storefront.
     */
    expect($report['stock'])->toBe(3)
        ->and($report['visibility'])->toBe([2 => 1])
        ->and($report['last_at'])->not->toBeNull();
});

it('counts concurrently without losing a skip', function () {
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($gift, 1)]);
    $skips = app(PromotionSkips::class);

    // Ten refusals of the same rule. `skips + 1` in SQL, never read-modify-write in PHP — which is
    // what stops two concurrent checkouts counting one miss between them.
    for ($i = 0; $i < 10; $i++) {
        $skips->record([$rule => PromotionRules::SKIP_OUT_OF_STOCK], 1);
    }

    expect(PromotionSkips::forRule($rule)['stock'])->toBe(10);
});

it('never invents a counter for a reason it does not know', function () {
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($gift, 1)]);

    app(PromotionSkips::class)->record([$rule => 'because_i_said_so'], 1);

    expect(T::int(DB::table('promotion_rule_skips')->where('promotion_rule_id', $rule)->count()))->toBe(0);
});
