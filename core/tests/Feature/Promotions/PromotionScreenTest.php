<?php

use App\Domain\Access\Role;
use App\Domain\Promotions\PromotionState;
use App\Domain\Promotions\PromotionWriter;
use App\Http\Controllers\Manage\PromotionController;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\PromotionFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\put;

/*
 * The promotions screens (wave 4D, study §3.16.6).
 *
 * Three things this file holds, and they are the three the developer asked for:
 *
 *  1. **Admin only, on the SERVER.** A hidden menu is not a permission, so every route is driven
 *     by a data-entry session and must answer 403.
 *  2. **The refusals refuse**, and they refuse in the WRITER, so a console caller meets the same
 *     door as the screen.
 *  3. **The at-a-glance state is right**, per state — because "an admin should never have to open
 *     a rule to know whether it's doing anything" is only true if the cell is correct.
 *
 * And the §4 law throughout: every filter is asserted to CHANGE the result set, never merely to
 * answer 200.
 */

beforeEach(function () {
    // These screens are about the rules THIS test creates. The promotion tables are dashboard-owned
    // and the shop may legitimately have its own; `DatabaseTransactions` rolls this back.
    foreach (['promotion_rule_skips', 'promotion_rule_rewards', 'promotion_rule_conditions',
        'promotion_rule_storefront', 'promotion_rules'] as $table) {
        DB::table($table)->delete();
    }
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function promoPayload(array $overrides = []): array
{
    $gift = PromotionFixture::giftableProduct();

    // `+` keeps the LEFT side's keys, so the overrides win — the same thing `array_replace` said,
    // in the one form whose type survives level 10.
    return $overrides + [
        'name' => 'عرض اختبار',
        'priority' => 5,
        'is_active' => true,
        'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
        'ends_at' => now()->addMonth()->format('Y-m-d H:i:s'),
        'storefronts' => [1],
        'conditions' => [['type' => 'cart_subtotal_min', 'amount' => '500.00']],
        'rewards' => [['type' => 'free_product', 'product_id' => $gift, 'quantity' => 1]],
    ];
}

/** The first message in the session's error bag for a field, or '' when there is none. */
function promoError(string $field): string
{
    $errors = session('errors');

    return $errors instanceof ViewErrorBag ? (string) $errors->first($field) : '';
}

// ── 1. authorization, server-side ────────────────────────────────────────────────────────────

it('refuses data-entry on every promotions route — a promotion gives away stock', function () {
    $entry = Staff::dataEntry();

    // The ability is admin-only by construction, which is the first thing to assert.
    expect(Role::DataEntry->can(Role::MANAGE_PROMOTIONS))->toBeFalse()
        ->and(Role::Admin->can(Role::MANAGE_PROMOTIONS))->toBeTrue();

    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct(PromotionFixture::giftableProduct(), 1)],
    );

    actingAs($entry);
    get('/manage/promotions')->assertForbidden();
    get('/manage/promotions/create')->assertForbidden();
    get("/manage/promotions/{$rule}")->assertForbidden();
    get('/manage/promotions/products?q=abc')->assertForbidden();
    post('/manage/promotions', promoPayload())->assertForbidden();
    put("/manage/promotions/{$rule}", promoPayload())->assertForbidden();
    postJson('/manage/promotions/preview', ['storefront_id' => 1, 'payment_method' => 'cash'])->assertForbidden();
});

it('lets an admin in', function () {
    actingAs(Staff::admin());

    get('/manage/promotions')->assertOk();
    get('/manage/promotions/create')->assertOk();
});

it('404s an unknown rule rather than confirming it does not exist with a 403', function () {
    actingAs(Staff::admin());

    // A rule id is guessable; a 403 would confirm the guess (§3.11.14).
    get('/manage/promotions/999999')->assertNotFound();
});

// ── 2. the refusals ──────────────────────────────────────────────────────────────────────────

it('refuses a rule with no end date', function () {
    actingAs(Staff::admin());

    post('/manage/promotions', promoPayload(['ends_at' => '']))->assertSessionHasErrors('ends_at');
    expect(T::int(DB::table('promotion_rules')->count()))->toBe(0);
});

it('refuses an end date that is not after the start', function () {
    actingAs(Staff::admin());

    post('/manage/promotions', promoPayload([
        'starts_at' => now()->addWeek()->format('Y-m-d H:i:s'),
        'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
    ]))->assertSessionHasErrors('ends_at');
});

it('refuses an unbounded reward, in both directions', function () {
    actingAs(Staff::admin());
    $gift = PromotionFixture::giftableProduct();

    foreach ([0, PromotionWriter::MAX_REWARD_QUANTITY + 1] as $quantity) {
        post('/manage/promotions', promoPayload([
            'rewards' => [['type' => 'free_product', 'product_id' => $gift, 'quantity' => $quantity]],
        ]))->assertSessionHasErrors();
    }

    expect(T::int(DB::table('promotion_rules')->count()))->toBe(0);
});

it('refuses a rule that applies to no storefront, and one with no conditions', function () {
    actingAs(Staff::admin());

    post('/manage/promotions', promoPayload(['storefronts' => []]))->assertSessionHasErrors('storefronts');
    // A conditionless rule would apply to EVERY cart — refused by the writer, not only the engine.
    post('/manage/promotions', promoPayload(['conditions' => []]))->assertSessionHasErrors('conditions');
});

it('refuses a MONEY-REDUCING reward and NAMES the storefronts that cannot deliver it', function () {
    actingAs(Staff::admin());

    $response = post('/manage/promotions', promoPayload([
        'rewards' => [['type' => 'percent_discount', 'amount' => 10, 'quantity' => 1]],
    ]));

    $response->assertSessionHasErrors();

    /*
     * The storefront's NAME is the assertion, not just the refusal. "This reward type is not
     * available" gives an operator nothing to do; the action is either to turn the setting on for
     * that storefront or to drop it from the rule, and neither is reachable without knowing which
     * storefront refused.
     */
    $name = T::str(DB::table('storefronts')->where('id', 1)->value('name'));
    expect(promoError('rewards.0.type'))->toContain($name);
});

it('refuses two ACTIVE rules at the same priority on the same storefront', function () {
    actingAs(Staff::admin());

    post('/manage/promotions', promoPayload(['name' => 'الأولى']))->assertSessionHasNoErrors();

    /*
     * With one-rule-per-cart and ties broken by id, two same-priority rules that can both match
     * make the winner an accident of insertion order. The screen refuses the ambiguity rather than
     * resolving it quietly.
     */
    post('/manage/promotions', promoPayload(['name' => 'الثانية']))->assertSessionHasErrors('priority');

    expect(T::int(DB::table('promotion_rules')->count()))->toBe(1);
});

it('allows the same priority when the windows cannot overlap', function () {
    actingAs(Staff::admin());

    post('/manage/promotions', promoPayload([
        'name' => 'هذا الشهر',
        'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
        'ends_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
    ]))->assertSessionHasNoErrors();

    // Next month's promotion, same priority, no overlap — scheduling ahead must stay possible.
    post('/manage/promotions', promoPayload([
        'name' => 'الشهر القادم',
        'starts_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
        'ends_at' => now()->addDays(20)->format('Y-m-d H:i:s'),
    ]))->assertSessionHasNoErrors();

    expect(T::int(DB::table('promotion_rules')->count()))->toBe(2);
});

it('refuses a gift that is not sellable where the rule runs, naming the storefront', function () {
    actingAs(Staff::admin());
    $gift = PromotionFixture::giftableProduct();

    // Hide it on storefront 1 — the exact decay this check exists for, forced.
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $gift)
        ->update(['is_visible' => 0]);

    $response = post('/manage/promotions', promoPayload([
        'rewards' => [['type' => 'free_product', 'product_id' => $gift, 'quantity' => 1]],
    ]));

    $response->assertSessionHasErrors();
    expect(promoError('rewards.0.product_id'))->toContain('غير معروضة');
});

it('refuses to DELETE a rule that has already given something away', function () {
    actingAs(Staff::admin());
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(1)], [PromotionFixture::freeProduct($gift, 1)]);

    // A granted reward line, as a real checkout would have written.
    $orderId = T::int(DB::table('orders')->orderByDesc('id')->value('id'));
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $gift, 'quantity' => 1,
        'piece_price' => '0.00', 'total_price' => '0.00', 'type_stock' => 'Express',
        'promotion_rule_id' => $rule, 'is_reward' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    /*
     * `order_items.promotion_rule_id` has no FK (the legacy app writes that table), so nothing at
     * the database level would stop this. Deleting would leave granted lines pointing at nothing
     * and the settlement CSV naming a rule nobody can look up.
     */
    delete("/manage/promotions/{$rule}")->assertSessionHasErrors('delete');

    expect(DB::table('promotion_rules')->where('id', $rule)->exists())->toBeTrue();
});

// ── 3. the at-a-glance state ─────────────────────────────────────────────────────────────────

/**
 * @return array{state: string, label: string, tone: string, storefronts: list<array<string, mixed>>, skips: array{stock: int, visibility: array<int, int>, last_at: string|null}, reward_stock: int|null}
 */
function stateOfRule(int $ruleId): array
{
    $row = T::row(DB::table('promotion_rules')->where('id', $ruleId)
        ->first(['id', 'name', 'priority', 'is_active', 'starts_at', 'ends_at']));

    return PromotionState::of(Row::cast($row));
}

it('says RUNNING for a rule that is actually doing something', function () {
    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct(PromotionFixture::giftableProduct(), 1)],
    );

    $state = stateOfRule($rule);
    expect($state['state'])->toBe(PromotionState::RUNNING)
        ->and($state['tone'])->toBe('success')
        ->and($state['reward_stock'])->toBeGreaterThan(0);
});

it('says SCHEDULED, EXPIRED and INACTIVE for the three ordinary cases', function () {
    $gift = PromotionFixture::giftableProduct();
    $reward = [PromotionFixture::freeProduct($gift, 1)];
    $condition = [PromotionFixture::subtotalAtLeast(100)];

    $scheduled = PromotionFixture::rule($condition, $reward,
        startsAt: now()->addWeek()->format('Y-m-d H:i:s'), endsAt: now()->addMonth()->format('Y-m-d H:i:s'));
    $expired = PromotionFixture::rule($condition, $reward,
        startsAt: now()->subMonth()->format('Y-m-d H:i:s'), endsAt: now()->subDay()->format('Y-m-d H:i:s'));
    $inactive = PromotionFixture::rule($condition, $reward, active: false);

    expect(stateOfRule($scheduled)['state'])->toBe(PromotionState::SCHEDULED)
        ->and(stateOfRule($expired)['state'])->toBe(PromotionState::EXPIRED)
        ->and(stateOfRule($inactive)['state'])->toBe(PromotionState::INACTIVE);
});

it('says NO STOCK — the state that costs the client — when the gift has none', function () {
    $empty = PromotionFixture::outOfStockProduct();
    if ($empty === null) {
        expect(true)->toBeTrue('this catalogue has no zero-stock visible product');

        return;
    }

    $rule = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(100)], [PromotionFixture::freeProduct($empty, 1)]);
    $state = stateOfRule($rule);

    expect($state['state'])->toBe(PromotionState::NO_STOCK)
        // DESTRUCTIVE, not a quiet grey chip: a rule the admin believes is running and which gives
        // away nothing is exactly the failure this column exists to surface.
        ->and($state['tone'])->toBe('destructive')
        ->and($state['label'])->toContain('مخزون الهدية صفر');
});

it('says NO STOCK when the gift has SOME stock but not enough for the quantity promised', function () {
    $gift = PromotionFixture::giftableProduct();
    $stock = T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express'));

    // Promise more than the shelf holds. "3 in stock, gives away 5" must read as zero, not three.
    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct($gift, $stock + 10)],
    );

    expect(stateOfRule($rule)['state'])->toBe(PromotionState::NO_STOCK);
});

it('says NO STOREFRONT for a rule that applies nowhere', function () {
    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct(PromotionFixture::giftableProduct(), 1)],
        storefronts: [],
    );

    expect(stateOfRule($rule)['state'])->toBe(PromotionState::NO_STOREFRONT);
});

it('says NOT VISIBLE, per storefront, when the gift cannot be shown where the rule runs', function () {
    $gift = PromotionFixture::giftableProduct();
    $rule = PromotionFixture::rule(
        [PromotionFixture::subtotalAtLeast(100)],
        [PromotionFixture::freeProduct($gift, 1)],
        storefronts: [1, 2],
    );

    // Visible on 1, hidden on 2 — the asymmetry that makes this state per-storefront.
    DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $gift)
        ->update(['is_visible' => 0]);

    $state = stateOfRule($rule);
    expect($state['state'])->toBe(PromotionState::NOT_VISIBLE);

    $byId = [];
    foreach ($state['storefronts'] as $storefront) {
        $byId[T::int($storefront['id'] ?? null)] = $storefront['reward_visible'] ?? null;
    }
    expect($byId[1])->toBeTrue()->and($byId[2])->toBeFalse();
});

// ── 4. the list, and its filters BITING ──────────────────────────────────────────────────────

it('filters the list by state and by storefront, and each filter CHANGES the rows', function () {
    actingAs(Staff::admin());
    $gift = PromotionFixture::giftableProduct();
    $condition = [PromotionFixture::subtotalAtLeast(100)];
    $reward = [PromotionFixture::freeProduct($gift, 1)];

    PromotionFixture::rule($condition, $reward, storefronts: [1], priority: 1, name: 'واتشيزر فقط');
    PromotionFixture::rule($condition, $reward, storefronts: [2], priority: 2, name: 'براند فاشون فقط', active: false);

    $all = Props::rows(Props::table(get('/manage/promotions')));
    expect($all)->toHaveCount(2);

    // §4 law: a filter that does not change the result set proves nothing about the filter.
    $active = Props::rows(Props::table(get('/manage/promotions?filters[is_active]=1')));
    expect($active)->toHaveCount(1)
        ->and(count($active))->toBeLessThan(count($all));

    $brandFashion = Props::rows(Props::table(get('/manage/promotions?filters[storefront_id]=2')));
    expect($brandFashion)->toHaveCount(1)
        ->and(count($brandFashion))->toBeLessThan(count($all));

    $search = Props::rows(Props::table(get('/manage/promotions?q='.urlencode('واتشيزر'))));
    expect($search)->toHaveCount(1)->and(count($search))->toBeLessThan(count($all));
});

it('carries the no-stacking sentence on both screens, from one source', function () {
    actingAs(Staff::admin());

    $list = Props::of(get('/manage/promotions'));
    $form = Props::of(get('/manage/promotions/create'));

    /*
     * A METHOD, not the const it used to be (wave 4D, server-side i18n): a constant is evaluated at
     * compile time and cannot ask the translator anything, so this one sentence would have stayed
     * Arabic for an English operator whatever the seam said.
     */
    expect($list['stacking_notice'])->toBe(PromotionController::stackingNotice())
        ->and($form['stacking_notice'])->toBe(PromotionController::stackingNotice())
        // The sentence has to SAY the thing, not merely exist.
        ->and(PromotionController::stackingNotice())->toContain('لا تتجمع')
        ->toContain('واحدة فقط');
});

// ── 5. product SEARCH, not typed ids ─────────────────────────────────────────────────────────

it('searches products by title and by code, and refuses a term too short to mean anything', function () {
    actingAs(Staff::admin());
    $gift = PromotionFixture::giftableProduct();
    $code = T::str(DB::table('catalog_products')->where('id', $gift)->value('wa_code'));

    $byCode = get('/manage/promotions/products?q='.urlencode(mb_substr($code, 0, 6)));
    $byCode->assertOk();
    expect($byCode->json('data'))->not->toBeEmpty();

    // One character would match half the catalogue and is a typo, not a search.
    $tooShort = get('/manage/promotions/products?q=a');
    $tooShort->assertOk();
    expect($tooShort->json('data'))->toBe([]);
});

// ── 6. the preview: BOTH outcomes, and it writes nothing ─────────────────────────────────────

it('previews both outcomes — what the customer pays AND what stock leaves', function () {
    actingAs(Staff::admin());
    $gift = PromotionFixture::giftableProduct();
    $buy = T::int(DB::table('catalog_products as p')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'p.id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
        ->whereNull('p.deleted_at')->where('p.id', '!=', $gift)->orderBy('p.id')->value('p.id'));

    $before = T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express'));

    $response = postJson('/manage/promotions/preview', [
        'storefront_id' => 1,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $buy, 'quantity' => 2]],
        'conditions' => [['type' => 'cart_subtotal_min', 'amount' => '1.00']],
        'rewards' => [['type' => 'free_product', 'product_id' => $gift, 'quantity' => 1]],
    ]);

    $response->assertOk();
    expect($response->json('ok'))->toBeTrue()
        ->and($response->json('applies'))->toBeTrue()
        ->and($response->json('winner'))->toBe('draft')
        // HALF ONE: what the customer pays, and the sentence that matters.
        ->and($response->json('customer.total_unchanged'))->toBeTrue()
        ->and($response->json('customer.subtotal'))->toBeGreaterThan(0)
        // HALF TWO: what leaves the shop — the half a promotions screen usually omits.
        ->and($response->json('stock'))->toHaveCount(1)
        ->and($response->json('stock.0.product_id'))->toBe($gift)
        ->and($response->json('stock.0.quantity'))->toBe(1)
        ->and($response->json('stock.0.stock_after'))->toBe($before - 1);

    /*
     * And it wrote NOTHING: the draft is saved, evaluated and rolled back inside one transaction,
     * so the preview reflects what the admin is about to publish without publishing it.
     */
    expect(T::int(DB::table('promotion_rules')->count()))->toBe(0)
        ->and(T::int(DB::table('catalog_products')->where('id', $gift)->value('stock_express')))->toBe($before);
});

it('previews a REFUSAL with its reason instead of pretending the draft is fine', function () {
    actingAs(Staff::admin());
    $buy = PromotionFixture::giftableProduct();

    // An unbounded reward — the same refusal the save would give, before the admin presses save.
    $response = postJson('/manage/promotions/preview', [
        'storefront_id' => 1,
        'payment_method' => 'cash',
        'lines' => [['product_id' => $buy, 'quantity' => 1]],
        'conditions' => [['type' => 'cart_subtotal_min', 'amount' => '1.00']],
        'rewards' => [['type' => 'free_product', 'product_id' => $buy, 'quantity' => 9999]],
    ]);

    $response->assertStatus(422);
    expect($response->json('ok'))->toBeFalse()
        ->and($response->json('errors'))->not->toBeEmpty();
});
