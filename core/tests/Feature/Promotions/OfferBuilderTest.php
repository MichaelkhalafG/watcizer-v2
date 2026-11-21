<?php

use App\Console\Commands\WheelWalletOfferCommand;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\CouponStatus;
use App\Domain\Promotions\PromotionDiscounts;
use App\Domain\Promotions\PromotionEngine;
use App\Domain\Promotions\PromotionMoney;
use App\Domain\Promotions\PromotionScopes;
use App\Domain\Promotions\PromotionWriter;
use App\Domain\Wheel\Wheel;
use App\Domain\Wheel\WheelPrizes;
use App\Domain\Wheel\WheelPrizeWriter;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Support\PromotionFixture;
use Tests\Support\SideDdl;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutMiddleware;

/*
 * ── The offer builder (Michael's approval of 2026-11-19) ───────────────────────────────────────────────────────────
 *
 * The engine's new shapes — one discounted item per QUALIFYING item, the most expensive first (a wallet per watch), a
 * fixed maximum per order, first order only by account and by phone — and the sentence a shopper reads when the offer
 * needs another item. The wheel's row 4 switched by the deploy's data step in its four passes, and the spin, the cart,
 * removing the watch and the order refused by the server. The writer and the wheel's save writing only what changed;
 * the list's one-click actions. Every product is derived from the data, priced and stocked inside the test.
 */
const OB_SHIP = 85.0;

beforeEach(function () {
    // FIRST, before any fixture: the data step's backup table, made on a side connection with a fresh snapshot (a later
    // CREATE would commit this test's transaction; a later fresh snapshot would roll back the fixtures below)
    SideDdl::run(WheelWalletOfferCommand::backupDdl(), freshSnapshot: true);
    withoutMiddleware(ThrottleRequests::class);
    Mail::fake();
    config(['notifications.send.inline' => false]);
    PromotionFixture::moneyRewards(true, 1);
    // the copy's own coded and automatic offers stay out of these sums
    DB::table('promotion_rules')->where('is_active', 1)->update(['is_active' => 0]);
    // both wheels as production has them (the deploy switches the wallet prize of each)
    foreach ([1, 2] as $sf) {
        Wheel::setEnabled($sf, true);
        Wheel::setTerms($sf, Wheel::DEFAULT_TTL_HOURS, null);
        $segments = [];
        foreach (WheelPrizes::DEFAULTS as $prize) {
            $segments[] = [
                'label_en' => $prize['label_en'], 'label_ar' => $prize['label_ar'], 'type' => $prize['type'], 'value' => $prize['value'],
                'odds' => $prize['odds'], 'color' => $prize['color'], 'cap' => $prize['cap'],
                'scope_category_id' => WheelPrizes::seededCategory($sf, $prize['scope_slug']),
                'min_quantity' => $prize['min_quantity'], 'apply_mode' => $prize['apply_mode'], 'gift_product_ids' => [],
            ];
        }
        WheelPrizeWriter::save($sf, $segments, ['ttl_hours' => Wheel::DEFAULT_TTL_HOURS, 'min_order' => null], null);
        app(StorefrontCache::class)->forgetStorefront($sf, T::str(DB::table('storefronts')->where('id', $sf)->value('code')));
    }
    DB::table('shipping_cities')->where('id', obCity())->update(['shipping_cost' => number_format(OB_SHIP, 2, '.', '')]);
    // the delivery price is cached per shop: forget it AFTER the city's cost is set (a stale 95 from another test)
    app(StorefrontCache::class)->flush(1);
});

function obCity(): int
{
    return T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));
}

function obCategory(string $slug): int
{
    return T::int(WheelPrizes::seededCategory(1, $slug));
}

/** @return array{id: int, price: float} the n-th visible, variant-less product of a category on Watchizer, priced and stocked */
function obProduct(string $slug, int $nth, float $price): array
{
    $path = T::str(DB::table('storefront_categories')->where('id', obCategory($slug))->value('path'));
    $row = DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1))
        ->where('sp.is_visible', 1)->whereNull('p.deleted_at')->where('p.is_active', 1)->whereNotNull('p.brand_id')
        ->whereNotExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id'))
        ->whereExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('storefront_category_product as scp')
            ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
            ->whereColumn('scp.product_id', 'p.id')->where('c.path', 'like', $path.'%'))
        ->orderBy('p.id')->skip($nth)->first(['p.id']);
    $id = T::int(Row::cast(T::row($row))->id);
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $id)->update(['effective_price' => number_format($price, 2, '.', ''), 'effective_sale_price' => null]);
    app(StorefrontCache::class)->forgetProduct(1, $id);
    app(StorefrontCache::class)->forgetCard(1, $id);
    app(InventoryService::class)->set(StockTarget::product($id), 'express', 6, 'adjustment', note: 'offer builder test');

    return ['id' => $id, 'price' => $price];
}

/** @param  list<array{id: int, price: float, qty?: int}>  $items */
function obCart(array $items, ?string $code, ?string $phone = null, ?int $userId = null): CartSnapshot
{
    $lines = [];
    $subtotal = 0.0;
    foreach ($items as $item) {
        $qty = $item['qty'] ?? 1;
        $lines[] = new CartLine(productId: $item['id'], variantId: null, offerId: null, quantity: $qty, unitPrice: $item['price'], typeStock: 'Express');
        $subtotal += $item['price'] * $qty;
    }

    return new CartSnapshot(storefrontId: 1, lines: $lines, subtotal: round($subtotal, 2), shippingCost: OB_SHIP, paymentMethod: 'cash',
        code: $code, customerKeys: PromotionDiscounts::customerKeys($userId, null, $phone));
}

/** "30% off a wallet with any watch" as a typed code, written through the writer as the builder would. */
function obWalletRule(string $code = 'WALLETWATCH', ?int $maxUnits = null): int
{
    return app(PromotionWriter::class)->save([
        'name' => '30% off a wallet with any watch', 'priority' => 0, 'is_active' => true, 'code' => $code,
        'label_en' => '30% off a wallet with any watch', 'label_ar' => 'خصم 30% على محفظة مع أي ساعة',
        'starts_at' => now()->subHour()->format('Y-m-d H:i:s'), 'ends_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        'apply_per' => PromotionMoney::PER_PAIRED, 'max_discount' => 1500, 'qualify_min_quantity' => 1, 'max_units' => $maxUnits,
        'include_sale_items' => true, 'storefronts' => [1], 'conditions' => [],
        'rewards' => [['type' => 'percent_discount', 'amount' => 30]],
        'scopes' => [
            ['role' => PromotionScopes::ROLE_TARGET, 'dimension' => 'category', 'value_id' => obCategory('wallets')],
            ['role' => PromotionScopes::ROLE_QUALIFY, 'dimension' => 'category', 'value_id' => obCategory('watches')],
        ],
    ], null, null);
}

/** @return array<int, float> the discount per product id */
function obShares(CartSnapshot $cart, \App\Domain\Promotions\PromotionOutcome $outcome): array
{
    $out = [];
    foreach ($outcome->lines as $line) {
        $out[T::int($line['product_id'])] = round(($out[T::int($line['product_id'])] ?? 0.0) + (float) $line['amount'], 2);
    }

    return $out;
}

it('gives 30% off a wallet only with a watch, one wallet per watch, the most expensive first, within the 1,500 cap', function () {
    obWalletRule();
    $watchA = obProduct('watches', 0, 4000.0);
    $watchB = obProduct('watches', 1, 4500.0);
    $cheap = obProduct('wallets', 0, 1000.0);
    $mid = obProduct('wallets', 1, 1500.0);
    $dear = obProduct('wallets', 2, 2000.0);
    $engine = app(PromotionEngine::class);

    // the wallet alone: nothing off, and the shopper is told what to add — the code is kept
    $alone = $engine->evaluate(obCart([$dear], 'WALLETWATCH'));
    expect($alone->discount)->toBe(0.0)
        ->and(T::str($alone->coupon['status'] ?? null))->toBe(CouponStatus::NEEDS_QUALIFIER)
        ->and(T::str($alone->coupon['messages']['en'] ?? null))->toBe('Add an item from Watches to your cart to use this offer: 30% off a wallet with any watch. Your code is kept.')
        ->and(T::str($alone->coupon['messages']['ar'] ?? null))->toBe('أضف منتجًا من ساعات إلى سلتك لتستخدم هذا العرض: خصم 30% على محفظة مع أي ساعة. كودك محفوظ.');

    // one watch, one wallet: 30% of the wallet
    $one = $engine->evaluate(obCart([$watchA, $dear], 'WALLETWATCH'));
    expect($one->discount)->toBe(600.0)->and(obShares(obCart([$watchA, $dear], 'WALLETWATCH'), $one))->toBe([$dear['id'] => 600.0]);

    // two watches, three wallets: the two dearest wallets, never the cheapest
    $two = $engine->evaluate(obCart([$watchA, $watchB, $cheap, $mid, $dear], 'WALLETWATCH'));
    expect($two->discount)->toBe(1050.0)->and(obShares(obCart([], null), $two))->toBe([$mid['id'] => 450.0, $dear['id'] => 600.0]);

    // one watch, two of the same wallet: one of them
    $pair = $engine->evaluate(obCart([$watchA, ['id' => $dear['id'], 'price' => 2000.0, 'qty' => 2]], 'WALLETWATCH'));
    expect($pair->discount)->toBe(600.0);

    // the cap: two watches, two dear wallets → 2,100 asked, 1,500 given
    $big = obProduct('wallets', 3, 5000.0);
    $capped = $engine->evaluate(obCart([$watchA, $watchB, $big, ['id' => $dear['id'], 'price' => 2000.0, 'qty' => 1]], 'WALLETWATCH'));
    expect($capped->discount)->toBe(1500.0);
});

it('keeps a fixed maximum of discounted items per order, the most expensive first', function () {
    $rule = app(PromotionWriter::class)->save([
        'name' => '20% off up to two wallets', 'priority' => 0, 'is_active' => true, 'code' => 'TWOWALLETS',
        'starts_at' => now()->subHour()->format('Y-m-d H:i:s'), 'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        'apply_per' => PromotionMoney::PER_ITEM, 'max_units' => 2, 'storefronts' => [1], 'conditions' => [],
        'rewards' => [['type' => 'percent_discount', 'amount' => 20]],
        'scopes' => [['role' => PromotionScopes::ROLE_TARGET, 'dimension' => 'category', 'value_id' => obCategory('wallets')]],
    ], null, null);
    expect(DB::table('promotion_rules')->where('id', $rule)->value('max_units'))->toEqual(2);
    $a = obProduct('wallets', 0, 1000.0);
    $b = obProduct('wallets', 1, 3000.0);
    $c = obProduct('wallets', 2, 2000.0);
    $out = app(PromotionEngine::class)->evaluate(obCart([$a, $b, $c], 'TWOWALLETS'));
    expect($out->discount)->toBe(1000.0)->and(obShares(obCart([], null), $out))->toBe([$b['id'] => 600.0, $c['id'] => 400.0]);
});

it('gives a first-order-only offer to a first order, by the account and by the phone number', function () {
    app(PromotionWriter::class)->save([
        'name' => 'First order 10%', 'priority' => 0, 'is_active' => true, 'code' => 'FIRST10',
        'starts_at' => now()->subHour()->format('Y-m-d H:i:s'), 'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        'apply_per' => PromotionMoney::PER_ORDER, 'storefronts' => [1], 'conditions' => [['type' => 'first_order']],
        'rewards' => [['type' => 'percent_discount', 'amount' => 10]],
    ], null, null);
    $watch = obProduct('watches', 0, 4000.0);
    $engine = app(PromotionEngine::class);
    // a phone that ordered before (the order's own phone), an account that ordered before
    $phone = '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $user = T::int(DB::table('users')->orderBy('id')->value('id'));
    DB::table('orders')->insert(['storefront_id' => 1, 'user_id' => null, 'guest_name' => 'Earlier', 'guest_phone' => $phone, 'total_price_for_order' => '100.00',
        'payment_method' => 'cash', 'status' => 'delivered', 'order_number' => '97'.random_int(10000, 99999), 'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth()]);
    DB::table('orders')->insert(['storefront_id' => 1, 'user_id' => $user, 'total_price_for_order' => '100.00',
        'payment_method' => 'cash', 'status' => 'delivered', 'order_number' => '97'.random_int(10000, 99999), 'created_at' => now()->subMonth(), 'updated_at' => now()->subMonth()]);

    $byPhone = $engine->evaluate(obCart([$watch], 'FIRST10', $phone));
    expect(T::str($byPhone->coupon['status'] ?? null))->toBe(CouponStatus::NOT_FIRST_ORDER)->and($byPhone->discount)->toBe(0.0)
        ->and(T::str($byPhone->coupon['messages']['en'] ?? null))->toContain('first order only');
    expect(T::str($engine->evaluate(obCart([$watch], 'FIRST10', null, $user))->coupon['status'] ?? null))->toBe(CouponStatus::NOT_FIRST_ORDER);
    // a new phone: the offer applies
    $fresh = $engine->evaluate(obCart([$watch], 'FIRST10', '01'.random_int(100000000, 199999999)));
    expect(T::str($fresh->coupon['status'] ?? null))->toBe(CouponStatus::APPLIED)->and($fresh->discount)->toBe(400.0);
    // a cancelled earlier order does not count
    DB::table('orders')->where('guest_phone', $phone)->update(['status' => 'cancelled']);
    expect(T::str($engine->evaluate(obCart([$watch], 'FIRST10', $phone))->coupon['status'] ?? null))->toBe(CouponStatus::APPLIED);
});

/** The seeded wheel's wallets prize, switched by the data step. Returns the prize id. */
function obSwitchRow4(): int
{
    $id = T::int(DB::table('storefront_wheel_prizes')->where('storefront_id', 1)->where('label_en', '30% off wallets')->value('id'));
    expect(Artisan::call('promotions:wallet-with-watch', ['--prize' => ["{$id}:30"], '--apply' => true]))->toBe(0);

    return $id;
}

it('switches both wallet prizes in four passes: a dry run that changes nothing, the apply, a second run with nothing to do, an exact undo; only those two rows change', function () {
    $ids = [];
    foreach ([1, 2] as $sf) {
        $ids[$sf] = T::int(DB::table('storefront_wheel_prizes')->where('storefront_id', $sf)->where('label_en', '30% off wallets')->value('id'));
    }
    // as production has it since 18 Nov: Brand Fashion's wallet prize at 20 % (Michael keeps it)
    DB::table('storefront_wheel_prizes')->where('id', $ids[2])->update(['label_en' => '20% off wallets', 'label_ar' => 'خصم 20% على المحافظ', 'value' => '20.00']);
    $percent = [1 => 30, 2 => 20];
    $args = ['--prize' => ["{$ids[1]}:30", "{$ids[2]}:20"]];
    $all = fn (): array => DB::table('storefront_wheel_prizes')->orderBy('id')->get()->map(fn ($r): array => (array) $r)->all();
    $before = $all();

    expect(Artisan::call('promotions:wallet-with-watch', $args))->toBe(0);
    expect(Artisan::output())->toContain("WOULD prize {$ids[1]} (storefront 1, ")->toContain("WOULD prize {$ids[2]} (storefront 2, ")
        ->toContain('DRY RUN: 2 to switch, 0 already switched, 0 stop');
    expect($all())->toBe($before);

    expect(Artisan::call('promotions:wallet-with-watch', $args + ['--apply' => true]))->toBe(0);
    expect(Artisan::output())->toContain('APPLIED: 2 switched, 0 already switched');
    $after = $all();
    $changed = array_values(array_filter(array_keys($after), fn (int $i): bool => $after[$i] != $before[$i]));
    expect(array_map(fn (int $i): int => T::int($after[$i]['id']), $changed))->toBe([min($ids), max($ids)]);
    $templates = [];
    foreach ($changed as $i) {
        $sf = T::int($after[$i]['storefront_id']);
        expect($after[$i]['type'])->toBe('offer')
            ->and($after[$i]['label_en'])->toBe("{$percent[$sf]}% off a wallet with any watch")
            ->and($after[$i]['label_ar'])->toBe("خصم {$percent[$sf]}% على محفظة مع أي ساعة")
            ->and($after[$i]['odds'])->toBe($before[$i]['odds'])->and($after[$i]['color'])->toBe($before[$i]['color']);
        $template = T::int($after[$i]['offer_rule_id']);
        $templates[] = $template;
        expect(DB::table('promotion_rules')->where('id', $template)->first(['issuer', 'is_active', 'apply_per', 'max_discount', 'code']))
            ->toEqual((object) ['issuer' => 'wheel_template', 'is_active' => 0, 'apply_per' => 'paired', 'max_discount' => '1500.00', 'code' => null]);
        // each offer on its own shop, aimed at that shop's own Wallets and Watches
        expect(DB::table('promotion_rule_storefront')->where('promotion_rule_id', $template)->pluck('storefront_id')->all())->toEqual([$sf]);
        expect(T::float(DB::table('promotion_rule_rewards')->where('promotion_rule_id', $template)->value('amount')))->toBe((float) $percent[$sf]);
        $scopes = DB::table('promotion_rule_scopes')->where('promotion_rule_id', $template)->orderBy('role')->get(['role', 'value_id'])->map(fn ($r): array => (array) $r)->all();
        expect($scopes)->toEqual([
            ['role' => 'qualify', 'value_id' => WheelPrizes::seededCategory($sf, 'watches')],
            ['role' => 'target', 'value_id' => WheelPrizes::seededCategory($sf, 'wallets')],
        ]);
    }

    expect(Artisan::call('promotions:wallet-with-watch', $args + ['--apply' => true]))->toBe(0);
    expect(Artisan::output())->toContain('NOTHING TO DO: 0 switched, 2 already switched');
    expect($all())->toBe($after);

    expect(Artisan::call('promotions:wallet-with-watch', ['--undo' => true]))->toBe(0);
    expect(Artisan::output())->toContain('UNDONE: 2 restored, 0 already as before');
    expect($all())->toBe($before)
        ->and(DB::table('promotion_rules')->whereIn('id', $templates)->exists())->toBeFalse();

    // applied again after the undo (the deploy does the same): the same two rows, nothing else
    expect(Artisan::call('promotions:wallet-with-watch', $args + ['--apply' => true]))->toBe(0);
    expect(Artisan::output())->toContain('APPLIED: 2 switched, 0 already switched');
    $again = $all();
    expect(array_values(array_filter(array_keys($again), fn (int $i): bool => $again[$i] != $before[$i])))->toBe($changed);
});

it('stops the data step when the row is not what the guard expects, and writes nothing', function () {
    $id = T::int(DB::table('storefront_wheel_prizes')->where('storefront_id', 1)->where('label_en', '30% off wallets')->value('id'));
    $snapshot = DB::table('storefront_wheel_prizes')->orderBy('id')->get()->all();
    // a prize named without its percent, or with a percent it does not have, is refused before anything is read
    expect(Artisan::call('promotions:wallet-with-watch', ['--prize' => [$id], '--apply' => true]))->toBe(1);
    expect(Artisan::output())->toContain('--prize=<id>:<percent>')->toContain('nothing was written');
    expect(Artisan::call('promotions:wallet-with-watch', ['--prize' => ["{$id}:20"], '--apply' => true]))->toBe(1);
    expect(Artisan::output())->toContain("STOP-ROW prize {$id}")->toContain('label_en')->toContain('percent');
    expect(DB::table('storefront_wheel_prizes')->orderBy('id')->get()->all())->toEqual($snapshot);
    DB::table('storefront_wheel_prizes')->where('id', $id)->update(['cap' => '1000.00']);
    $snapshot = DB::table('storefront_wheel_prizes')->orderBy('id')->get()->all();
    expect(Artisan::call('promotions:wallet-with-watch', ['--prize' => ["{$id}:30"], '--apply' => true]))->toBe(1);
    expect(Artisan::output())->toContain("STOP-ROW prize {$id}")->toContain('cap')->toContain('nothing was written');
    expect(DB::table('storefront_wheel_prizes')->orderBy('id')->get()->all())->toEqual($snapshot);
    // a prize that is not the wallets one at all
    $other = T::int(DB::table('storefront_wheel_prizes')->where('storefront_id', 1)->where('type', 'free_shipping')->value('id'));
    expect(Artisan::call('promotions:wallet-with-watch', ['--prize' => ["{$other}:30"]]))->toBe(1);
});

it('works end to end on the wheel: the spin, the cart with and without the watch, two watches and two wallets, and the server refusing the order once the watch is gone', function () {
    obSwitchRow4();
    $phone = '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    // the seeded wheel's rolls: 66–80 is the wallets segment
    $spin = Wheel::spin(1, $phone, null, '203.0.113.9', [], 'en', 70)['spin'];
    expect(Row::str($spin, 'prize_type'))->toBe('offer')->and(Row::str($spin, 'prize_label_en'))->toBe('30% off a wallet with any watch');
    $code = T::str(Row::nstr($spin, 'code'));
    $rule = T::int(Row::nint($spin, 'promotion_rule_id'));
    expect(DB::table('promotion_rules')->where('id', $rule)->value('apply_per'))->toBe('paired');

    $watchA = obProduct('watches', 0, 4000.0);
    $watchB = obProduct('watches', 1, 4500.0);
    $walletA = obProduct('wallets', 0, 2000.0);
    $walletB = obProduct('wallets', 1, 3000.0);
    $engine = app(PromotionEngine::class);
    $without = $engine->evaluate(obCart([$walletA], $code, $phone));
    expect(T::str($without->coupon['status'] ?? null))->toBe(CouponStatus::NEEDS_QUALIFIER)
        ->and(T::str($without->coupon['messages']['en'] ?? null))->toContain('Add an item from Watches')->toContain('30% off a wallet with any watch');
    expect($engine->evaluate(obCart([$watchA, $walletA], $code, $phone))->discount)->toBe(600.0);
    expect($engine->evaluate(obCart([$watchA, $watchB, $walletA, $walletB], $code, $phone))->discount)->toBe(1500.0);

    // the order: the cart had the watch, then the watch is removed in another tab — the server refuses the code
    $token = (string) Str::uuid();
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $watchA['id'], 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token])->assertOk();
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $walletA['id'], 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token])->assertOk();
    $cart = getJson('/api/v2/watchizer/cart?coupon='.$code.'&phone='.$phone, ['X-Guest-Token' => $token])->assertOk();
    expect(T::float(T::arr($cart->json('data.totals.promotion'))['discount']))->toBe(600.0);
    $watchLine = collect(T::arr($cart->json('data.items')))->first(fn ($i) => T::int(T::arr(T::arr($i)['product'])['id']) === $watchA['id']);
    delete('/api/v2/watchizer/cart/items/'.T::int(T::arr($watchLine)['id']), [], ['X-Guest-Token' => $token, 'Accept' => 'application/json'])->assertOk();
    $body = ['address_line' => '12 Offer Street, Cairo', 'shipping_city_id' => obCity(), 'phone' => $phone, 'guest_name' => 'Offer Buyer',
        'guest_email' => 'offer-'.Str::random(6).'@example.test', 'payment_method' => 'cash', 'total' => number_format(2000.0 + OB_SHIP, 2, '.', ''), 'coupon' => $code];
    $refused = postJson('/api/v2/watchizer/orders', $body, ['X-Guest-Token' => $token])->assertStatus(422);
    expect($refused->json('error'))->toBe('coupon')->and($refused->json('status'))->toBe(CouponStatus::NEEDS_QUALIFIER)
        ->and(T::str($refused->json('messages.ar')))->toContain('أضف منتجًا من ساعات');
    expect(DB::table('storefront_wheel_spins')->where('code', $code)->value('used_order_id'))->toBeNull();      // the code is kept

    // with the watch back, the order is placed and the wallet's 30% recorded
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $watchA['id'], 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token])->assertOk();
    $placed = postJson('/api/v2/watchizer/orders', ['total' => number_format(6000.0 + OB_SHIP, 2, '.', '')] + $body, ['X-Guest-Token' => $token])->assertStatus(201);
    $order = T::int(DB::table('orders')->where('order_number', T::str($placed->json('data.order_number')))->value('id'));
    expect((float) DB::table('promotion_order_discounts')->where('order_id', $order)->sum('amount'))->toBe(600.0);
});

it('writes only what changed: an unchanged save touches no row, a changed scope only its own', function () {
    $id = obWalletRule('DIFFCHECK');
    DB::table('promotion_rules')->where('id', $id)->update(['updated_at' => '2026-01-01 00:00:00']);
    foreach (['promotion_rule_scopes', 'promotion_rule_rewards'] as $t) {
        DB::table($t)->where('promotion_rule_id', $id)->update(['updated_at' => '2026-01-01 00:00:00']);
    }
    $scopes = fn (): array => DB::table('promotion_rule_scopes')->where('promotion_rule_id', $id)->orderBy('id')->get(['id', 'role', 'value_id', 'updated_at'])->map(fn ($r): array => (array) $r)->all();
    $before = $scopes();
    $payload = [
        'name' => '30% off a wallet with any watch', 'priority' => 0, 'is_active' => true, 'code' => 'DIFFCHECK',
        'label_en' => '30% off a wallet with any watch', 'label_ar' => 'خصم 30% على محفظة مع أي ساعة',
        'starts_at' => T::str(DB::table('promotion_rules')->where('id', $id)->value('starts_at')), 'ends_at' => T::str(DB::table('promotion_rules')->where('id', $id)->value('ends_at')),
        'apply_per' => 'paired', 'max_discount' => 1500, 'qualify_min_quantity' => 1, 'include_sale_items' => true, 'storefronts' => [1], 'conditions' => [],
        'rewards' => [['type' => 'percent_discount', 'amount' => 30]],
        'scopes' => [
            ['role' => 'target', 'dimension' => 'category', 'value_id' => obCategory('wallets')],
            ['role' => 'qualify', 'dimension' => 'category', 'value_id' => obCategory('watches')],
        ],
    ];
    app(PromotionWriter::class)->save($payload, $id, null);
    expect(DB::table('promotion_rules')->where('id', $id)->value('updated_at'))->toBe('2026-01-01 00:00:00')
        ->and($scopes())->toBe($before)
        ->and(DB::table('promotion_rule_rewards')->where('promotion_rule_id', $id)->value('updated_at'))->toBe('2026-01-01 00:00:00');

    // the qualifier changes to a brand: that scope row only; the target row is the same row, untouched
    $brand = T::int(DB::table('catalog_brands')->orderBy('id')->value('id'));
    $payload['scopes'][1] = ['role' => 'qualify', 'dimension' => 'brand', 'value_id' => $brand];
    app(PromotionWriter::class)->save($payload, $id, null);
    $after = $scopes();
    expect(count($after))->toBe(2)
        ->and(collect($after)->firstWhere('role', 'target'))->toBe(collect($before)->firstWhere('role', 'target'))
        ->and(T::arr(collect($after)->firstWhere('role', 'qualify'))['value_id'])->toBe($brand);
});

it('keeps every wheel segment\'s id when the wheel is saved, and writes only the segments that changed', function () {
    $rows = fn (): array => DB::table('storefront_wheel_prizes')->where('storefront_id', 1)->orderBy('position')->get()->map(fn ($r): array => (array) $r)->all();
    $before = $rows();
    // the first segment's colour changes; every other field is saved as it is
    $segments = array_map(fn (array $r, int $i): array => [
        'label_en' => $r['label_en'], 'label_ar' => $r['label_ar'], 'type' => $r['type'], 'value' => $r['value'], 'odds' => $r['odds'], 'color' => $i === 0 ? '#123456' : $r['color'],
        'cap' => $r['cap'], 'scope_category_id' => $r['scope_category_id'], 'min_quantity' => $r['min_quantity'], 'apply_mode' => $r['apply_mode'],
        'gift_product_ids' => [], 'offer_rule_id' => $r['offer_rule_id'],
    ], $before, array_keys($before));
    WheelPrizeWriter::save(1, $segments, ['ttl_hours' => Wheel::DEFAULT_TTL_HOURS, 'min_order' => null], null);
    $after = $rows();
    expect(array_column($after, 'id'))->toBe(array_column($before, 'id'))
        ->and($after[0]['color'])->toBe('#123456')
        ->and(array_slice($after, 1))->toBe(array_slice($before, 1));
});

it('pauses, starts again, ends now and duplicates an offer in one click, and filters the list by status', function () {
    actingAs(Staff::admin());
    $id = obWalletRule('ONECLICK');
    post("/manage/promotions/{$id}/pause")->assertSessionHasNoErrors();
    expect(DB::table('promotion_rules')->where('id', $id)->value('is_active'))->toBe(0);
    get('/manage/promotions?filters[status]=off')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('table.data', fn (Collection $rows) => $rows->pluck('id')->contains($id)));
    post("/manage/promotions/{$id}/resume")->assertSessionHasNoErrors();
    expect(DB::table('promotion_rules')->where('id', $id)->value('is_active'))->toBe(1);
    get('/manage/promotions?filters[status]=active')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('table.data', fn (Collection $rows) => $rows->pluck('id')->contains($id)));

    post("/manage/promotions/{$id}/duplicate")->assertRedirect();
    $copy = DB::table('promotion_rules')->where('name', 'like', '30% off a wallet with any watch %')->orderByDesc('id')->first(['id', 'is_active', 'code', 'apply_per']);
    $copy = (array) T::row($copy);
    expect($copy)->toMatchArray(['is_active' => 0, 'code' => null, 'apply_per' => 'paired'])
        ->and(DB::table('promotion_rule_scopes')->where('promotion_rule_id', T::int($copy['id']))->count())->toBe(2);

    post("/manage/promotions/{$id}/end")->assertSessionHasNoErrors();
    expect(T::str(DB::table('promotion_rules')->where('id', $id)->value('ends_at')))->toBeLessThanOrEqual(now()->format('Y-m-d H:i:s'));
    get('/manage/promotions?filters[status]=ended')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('table.data', fn (Collection $rows) => $rows->pluck('id')->contains($id)));
    expect(DB::table('core_activity_log')->where('subject_type', 'promotion_rules')->where('subject_id', $id)->count())->toBeGreaterThanOrEqual(3);
});
