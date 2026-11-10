<?php

use App\Compat\Diff\HarnessJwt;
use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Orders\OrderFulfilment;
use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\CouponStatus;
use App\Domain\Promotions\PromotionDiscounts;
use App\Domain\Promotions\PromotionEngine;
use App\Domain\Promotions\PromotionMoney;
use App\Domain\Wheel\Wheel;
use App\Domain\Wheel\WheelPrizes;
use App\Domain\Wheel\WheelPrizeWriter;
use App\Domain\Wheel\WheelReferrals;
use App\Domain\Wheel\WheelRefused;
use App\Mail\WheelReferralReward;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use PHPUnit\Framework\Assert;
use Tests\Support\PromotionFixture;
use Tests\Support\Props;
use Tests\Support\Shops;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutMiddleware;

/*
 * "SPIN THE WHEEL" (2026-11-07, Watchizer; the team's prizes 2026-11-08; the client's final prize list 2026-11-09):
 * the seven prizes and their editor, the draw (odds spread over what can be drawn), the spin, the code's gates on the
 * promotions engine, the scoped prizes (wallets, the 2nd watch), the gift, the invite-a-friend flow end to end (the
 * winner's own number, a returning customer, a cancelled order, the same friend twice, delivery → reward → mail),
 * "better luck", the v2 checkout, the switch, the rounds, the dashboard page.
 *
 * Every product is derived from the data (visible on Watchizer, no variants, in its category), its price and stock set
 * inside the test; every rule the wheel makes is made inside the transaction. The wheel's switch and its prizes are set
 * per test. The seeded wheel's rolls: 1–25 free shipping · 26–45 the 2nd watch · 46–65 better luck · 66–80 wallets ·
 * 81–90 invite a friend · the two gifts (91–100) only once their lists hold a product in stock.
 */

const WH_CITY_COST = 85.0;
const WH_CAP = 1500.0;

beforeEach(function () {
    withoutMiddleware(ThrottleRequests::class);
    Mail::fake();
    config(['notifications.send.inline' => false]);
    PromotionFixture::moneyRewards(true, 1);
    Wheel::setEnabled(1, true);
    Wheel::setTerms(1, Wheel::DEFAULT_TTL_HOURS, null);
    whSeed(1);
    app(StorefrontCache::class)->forgetStorefront(1, T::str(DB::table('storefronts')->where('id', 1)->value('code')));
    DB::table('shipping_cities')->where('id', whCity())->update(['shipping_cost' => number_format(WH_CITY_COST, 2, '.', '')]);
});

afterEach(function () {
    Carbon::setTestNow();
});

function whCity(): int
{
    return T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));
}

function whCategory(string $slug): int
{
    return T::int(WheelPrizes::seededCategory(1, $slug));
}

/**
 * The client's seven prizes written through the writer, with the categories resolved on the storefront and the gift
 * lists as given (empty by default — those prizes are then not drawn).
 *
 * @param  array<int, list<int>>  $gifts  position => product ids
 */
function whSeed(int $storefrontId, array $gifts = []): void
{
    $segments = [];
    foreach (WheelPrizes::DEFAULTS as $position => $prize) {
        $segments[] = [
            'label_en' => $prize['label_en'], 'label_ar' => $prize['label_ar'], 'type' => $prize['type'], 'value' => $prize['value'],
            'odds' => $prize['odds'], 'color' => $prize['color'], 'cap' => $prize['cap'],
            'scope_category_id' => WheelPrizes::seededCategory($storefrontId, $prize['scope_slug']),
            'min_quantity' => $prize['min_quantity'], 'apply_mode' => $prize['apply_mode'],
            'gift_product_ids' => $gifts[$position] ?? [],
        ];
    }
    WheelPrizeWriter::save($storefrontId, $segments, ['ttl_hours' => Wheel::DEFAULT_TTL_HOURS, 'min_order' => null], null);
}

/** A fresh Egyptian mobile number no other test's spin can hold. */
function whPhone(): string
{
    return '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
}

/**
 * The n-th visible, variant-less Watchizer product — in a category when one is named (the node or anything below it) —
 * priced and stocked by the test.
 *
 * @return array{id: int, price: float}
 */
function whProduct(int $nth = 0, float $price = 5000.0, ?string $categorySlug = null): array
{
    $query = DB::table('catalog_products as p')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1);
        })
        ->where('sp.is_visible', 1)->whereNull('p.deleted_at')->where('p.is_active', 1)->whereNotNull('p.brand_id')
        ->whereNotExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id'));
    if ($categorySlug !== null) {
        $path = T::str(DB::table('storefront_categories')->where('id', whCategory($categorySlug))->value('path'));
        $query->whereExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('storefront_category_product as scp')
            ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
            ->whereColumn('scp.product_id', 'p.id')->where('c.path', 'like', $path.'%'));
    }
    $id = T::int(Row::cast(T::row($query->orderBy('p.id')->skip($nth)->first(['p.id'])))->id);
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $id)->update([
        'effective_price' => number_format($price, 2, '.', ''), 'effective_sale_price' => null,
    ]);
    app(StorefrontCache::class)->forgetProduct(1, $id);
    app(StorefrontCache::class)->forgetCard(1, $id);
    app(InventoryService::class)->set(StockTarget::product($id), 'express', 5, 'adjustment', note: 'wheel test');

    return ['id' => $id, 'price' => $price];
}

/** @param  list<array{id: int, price: float, qty?: int}>  $items */
function whCart(array $items, ?string $code, ?string $phone, float $shipping = WH_CITY_COST): CartSnapshot
{
    $lines = [];
    $subtotal = 0.0;
    foreach ($items as $item) {
        $qty = $item['qty'] ?? 1;
        $lines[] = new CartLine(productId: $item['id'], variantId: null, offerId: null, quantity: $qty, unitPrice: $item['price'], typeStock: 'Express');
        $subtotal += $item['price'] * $qty;
    }

    return new CartSnapshot(storefrontId: 1, lines: $lines, subtotal: round($subtotal, 2), shippingCost: $shipping, paymentMethod: 'cash',
        code: $code, customerKeys: PromotionDiscounts::customerKeys(null, null, $phone));
}

/** @return array<string, mixed> the spin's presentation (Wheel::present) */
function whSpin(string $phone, ?int $roll = null, ?string $device = null): array
{
    $result = Wheel::spin(1, $phone, $device, '203.0.113.7', ['source' => 'facebook', 'campaign' => 'nov-wheel'], 'en', $roll);
    expect($result['status'])->toBe('new');

    return Wheel::present($result['spin'], null, $result['index']);
}

/** @param  array<string, mixed>  $spin */
function whCode(array $spin): string
{
    return T::str($spin['code']);
}

/**
 * @param  array<string, mixed>  $spin
 * @return array<mixed>
 */
function whPrize(array $spin): array
{
    return T::arr($spin['prize']);
}

/** @return array<string, mixed> */
function whOrderBody(float $total, string $code, string $phone): array
{
    return [
        'address_line' => '12 Wheel Street, Cairo', 'shipping_city_id' => whCity(), 'phone' => $phone, 'guest_name' => 'Wheel Buyer',
        'guest_email' => 'wheel-'.Str::random(6).'@example.test', 'payment_method' => 'cash', 'total' => number_format($total, 2, '.', ''), 'coupon' => $code,
    ];
}

/**
 * Places a v2 guest order for one product with a code; returns the order id.
 *
 * @param  array{id: int, price: float}  $product
 */
function whOrder(array $product, string $code, string $phone, ?float $total = null): int
{
    $token = (string) Str::uuid();
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $product['id'], 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token])->assertOk();
    $body = whOrderBody($total ?? $product['price'] + WH_CITY_COST, $code, $phone);
    if ($code === '') {
        unset($body['coupon']);
    }
    $res = postJson('/api/v2/watchizer/orders', $body, ['X-Guest-Token' => $token]);
    Assert::assertSame(201, $res->status(), 'the order was refused: '.(string) $res->getContent());

    return T::int(DB::table('orders')->where('order_number', T::str($res->json('data.order_number')))->value('id'));
}

// ── the prizes ─────────────────────────────────────────────────────────────────────────────────────────

it('seeds the seven prizes with odds summing to 100, draws only what can be drawn, and maps a roll onto the odds in order', function () {
    $prizes = WheelPrizes::forStorefront(1);
    expect(WheelPrizes::oddsTotal($prizes))->toBe(100)->and($prizes)->toHaveCount(7)
        ->and(array_column($prizes, 'type'))->toBe(['free_shipping', 'percent_discount', 'none', 'percent_discount', 'referral', 'gift', 'gift'])
        ->and(array_column($prizes, 'odds'))->toBe([25, 20, 20, 15, 10, 5, 5])
        ->and($prizes[1]['scope_category_id'])->toBe(whCategory('watches'))->and($prizes[1]['min_quantity'])->toBe(2)->and($prizes[1]['apply_mode'])->toBe('cheapest')
        ->and($prizes[3]['scope_category_id'])->toBe(whCategory('wallets'))->and($prizes[3]['cap'])->toBe(WH_CAP);
    // the gift lists are empty until the team fills them: those two are not drawn and their odds spread over the others
    $drawable = WheelPrizes::drawable($prizes);
    expect($drawable)->toHaveCount(5)->and(WheelPrizes::oddsTotal($drawable))->toBe(90);
    foreach ([1 => 0, 25 => 0, 26 => 1, 45 => 1, 46 => 2, 65 => 2, 66 => 3, 80 => 3, 81 => 4, 90 => 4] as $roll => $index) {
        expect(WheelPrizes::pick($drawable, $roll)['index'])->toBe($index);
    }
    expect(fn () => WheelPrizes::pick($drawable, 91))->toThrow(InvalidArgumentException::class);
    // the storefront's segments are the DRAWABLE prizes only (nobody is shown a prize they cannot win), without the odds
    $segments = WheelPrizes::segments(1);
    expect($segments)->toHaveCount(5)->and(array_column($segments, 'type'))->toBe(['free_shipping', 'percent_discount', 'none', 'percent_discount', 'referral']);
    foreach ($segments as $segment) {
        expect($segment)->not->toHaveKey('odds');
    }
    // with a gift list in stock, that gift is on the wheel again, and a spin's index counts the published list
    whSeed(1, [5 => [whProduct(2, 900.0)['id']]]);
    expect(WheelPrizes::segments(1))->toHaveCount(6);
    $gift = whSpin(whPhone(), roll: 93);
    expect(T::arr($gift['prize'])['index'])->toBe(5)->and(T::arr($gift['prize'])['type'])->toBe('gift');
});

it('decides the prize on the server and snapshots its terms: the request may name any segment and gets the one the roll gave', function () {
    $phone = whPhone();
    $res = postJson('/api/v2/watchizer/wheel/spin', ['phone' => $phone, 'device' => 'dev-'.Str::random(8), 'prize' => 'gift', 'segment' => 6, 'utm' => ['source' => 'facebook', 'campaign' => 'nov']])
        ->assertOk();
    $spin = T::arr($res->json('data.spin'));
    $prize = T::arr($spin['prize']);
    $ids = array_column(WheelPrizes::forStorefront(1), 'id');
    expect($res->json('data.status'))->toBe('new')
        ->and($ids)->toContain(T::int($prize['id']))
        ->and(T::int($prize['index']))->toBeGreaterThanOrEqual(0)->toBeLessThan(5)      // never a gift: their lists are empty
        ->and(WheelPrizes::forStorefront(1)[T::int($prize['index'])]['id'])->toBe(T::int($prize['id']))
        ->and(T::str($spin['phone_hint']))->toBe(substr($phone, 0, 3).'•••••'.substr($phone, -3));
    $row = Row::cast(T::row(DB::table(Wheel::TABLE)->where('phone_key', substr($phone, -10))->first()));
    expect(Row::str($row, 'phone'))->toBe($phone)
        ->and(Row::str($row, 'campaign'))->toBe(Wheel::campaign(1))
        ->and(Row::str($row, 'utm_source'))->toBe('facebook');
    expect(Row::nstr($row, 'device_hash'))->not->toBeNull();
    expect(Row::str($row, 'device_hash'))->not->toContain('dev-');
    expect(Row::nstr($row, 'ip_hash'))->not->toBeNull();
    if ($prize['type'] !== 'none') {
        // every prize that gives something is a rule the wheel owns — made through the promotions writer, marked as the wheel's
        expect(T::str(DB::table('promotion_rules')->where('id', Row::int($row, 'promotion_rule_id'))->value('issuer')))->toBe(Wheel::ISSUER);
    } else {
        expect(Row::nstr($row, 'code'))->toBeNull()->and(Row::nint($row, 'promotion_rule_id'))->toBeNull();
    }
});

it('lets a phone spin ONCE per round: the second spin, however spelled, gets the first result back', function () {
    $phone = whPhone();
    $first = T::arr(postJson('/api/v2/watchizer/wheel/spin', ['phone' => $phone])->assertOk()->json('data'));
    $firstPrize = T::arr(T::arr($first['spin'])['prize']);
    $arabic = strtr($phone, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
    foreach (['+20 '.substr($phone, 1), $arabic, ' '.$phone.' '] as $spelling) {
        $again = T::arr(postJson('/api/v2/watchizer/wheel/spin', ['phone' => $spelling])->assertOk()->json('data'));
        expect($again['status'])->toBe('already_spun')
            ->and(T::arr(T::arr($again['spin'])['prize'])['id'])->toBe($firstPrize['id']);
    }
    expect((int) DB::table(Wheel::TABLE)->where('phone_key', substr($phone, -10))->where('campaign', Wheel::campaign(1))->count())->toBe(1)
        ->and(getJson('/api/v2/watchizer/wheel/spin?phone='.$phone)->assertOk()->json('data.spin.phone_hint'))->toBe(Wheel::mask($phone));
    getJson('/api/v2/watchizer/wheel/spin?phone='.whPhone())->assertNotFound();
});

it('refuses a second phone from the same device, a malformed number, and a spin while the wheel is off', function () {
    $device = 'device-'.Str::random(12);
    postJson('/api/v2/watchizer/wheel/spin', ['phone' => whPhone(), 'device' => $device])->assertOk();
    $res = postJson('/api/v2/watchizer/wheel/spin', ['phone' => whPhone(), 'device' => $device])->assertStatus(422);
    expect($res->json('data.error'))->toBe(WheelRefused::DEVICE_LIMIT);
    expect(postJson('/api/v2/watchizer/wheel/spin', ['phone' => '0123'])->assertStatus(422)->json('data.error'))->toBe(WheelRefused::INVALID_PHONE);

    Wheel::setEnabled(1, false);
    app(StorefrontCache::class)->forgetStorefront(1, 'watchizer');
    expect(getJson('/api/v2/watchizer/wheel')->assertOk()->json('data.enabled'))->toBeFalse();
    expect(postJson('/api/v2/watchizer/wheel/spin', ['phone' => whPhone()])->assertStatus(410)->json('data.error'))->toBe(WheelRefused::WHEEL_OFF);
});

it('publishes the segments without the odds, with the round, the validity and the minimum', function () {
    $data = T::arr(getJson('/api/v2/watchizer/wheel')->assertOk()->json('data'));
    expect($data['enabled'])->toBeTrue()
        ->and($data['campaign'])->toBe(Wheel::campaign(1))
        ->and(T::int($data['ttl_hours']))->toBe(Wheel::DEFAULT_TTL_HOURS)
        ->and($data['min_order'])->toBeNull()
        ->and(T::arr($data['segments']))->toHaveCount(5);                            // the two gift lists are empty here: not drawn, not shown
    foreach (T::arr($data['segments']) as $segment) {
        expect(T::arr($segment))->not->toHaveKey('odds')->and(T::arr($segment)['color'])->toMatch('/^#[0-9A-F]{6}$/');
    }
});

// ── better luck ─────────────────────────────────────────────────────────────────────────────────────────

it('gives "better luck" no code, and neither that phone nor that device spins again this round', function () {
    $phone = whPhone();
    $device = 'device-'.Str::random(12);
    $result = Wheel::spin(1, $phone, $device, '203.0.113.7', [], 'en', 50);         // 46–65 → none
    $shown = Wheel::present($result['spin'], null, $result['index']);
    expect($shown['code'])->toBeNull()->and($shown['state'])->toBe(Wheel::STATE_NONE)->and(whPrize($shown)['type'])->toBe('none')->and(whPrize($shown)['index'])->toBe(2);
    expect(Wheel::spin(1, $phone, $device, '203.0.113.7', [], 'en', 1)['status'])->toBe('already_spun');
    expect(fn () => Wheel::spin(1, whPhone(), $device, '203.0.113.7', [], 'en', 1))->toThrow(WheelRefused::class);
    // the dashboard counts it as a spin, not as a code
    actingAs(Staff::admin());
    $props = Props::of(get('/manage/storefronts/1/wheel')->assertOk());
    $mine = null;
    foreach (T::arr($props['spins'] ?? []) as $row) {
        if ((T::arr($row)['phone_hint'] ?? null) === Wheel::mask($phone)) {
            $mine = T::arr($row);
        }
    }
    expect($mine)->not->toBeNull()->and($mine['state'] ?? null)->toBe(Wheel::STATE_NONE)->and(array_key_exists('code', $mine ?? []) ? $mine['code'] : 'missing')->toBeNull();
});

// ── the scoped prizes on the engine ─────────────────────────────────────────────────────────────────────

it('takes 30 % off the wallets only, capped at EGP 1,500, and says what it needs when there is no wallet', function () {
    $phone = whPhone();
    $spin = whSpin($phone, roll: 70);                                                 // 66–80 → wallets
    expect(whPrize($spin)['value'])->toBe(30.0)->and(T::arr(whPrize($spin)['needs'])['en'])->toContain('Wallets');
    $engine = app(PromotionEngine::class);
    $wallet = whProduct(0, 2000.0, 'wallets');
    $watch = whProduct(0, 5000.0, 'watches');

    // a wallet and a watch: 30 % of the wallet alone
    $out = $engine->evaluate(whCart([$wallet, $watch], whCode($spin), $phone));
    expect($out->discount)->toBe(600.0)->and($out->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);
    $walletLines = array_filter($out->lines, fn (array $l): bool => $l['product_id'] === $wallet['id']);
    expect(array_sum(array_map(fn (array $l): float => $l['amount'], $walletLines)))->toBe(600.0);
    // an expensive wallet: the cap
    $dear = whProduct(1, 9000.0, 'wallets');
    expect($engine->evaluate(whCart([$dear], whCode($spin), $phone))->discount)->toBe(WH_CAP);
    // no wallet in the cart: the sentence names what the code needs
    $none = $engine->evaluate(whCart([$watch], whCode($spin), $phone));
    expect($none->discount)->toBe(0.0)->and($none->coupon['status'] ?? null)->toBe(CouponStatus::NO_ELIGIBLE_ITEMS)
        ->and(T::str($none->coupon['messages']['en'] ?? ''))->toContain('works on Wallets only')
        ->and(T::str($none->coupon['messages']['ar'] ?? ''))->toContain('فقط');
});

it('takes 10 % off the CHEAPEST of two or more watches, and says it needs two when there is one', function () {
    $phone = whPhone();
    $spin = whSpin($phone, roll: 30);                                                 // 26–45 → the 2nd watch
    expect(whPrize($spin)['apply_mode'])->toBe('cheapest')->and(whPrize($spin)['min_quantity'])->toBe(2);
    $engine = app(PromotionEngine::class);
    $a = whProduct(0, 5000.0, 'watches');
    $b = whProduct(1, 3000.0, 'watches');
    $wallet = whProduct(0, 2000.0, 'wallets');

    // two watches (and a wallet, which does not count): 10 % of the cheaper watch, on that line only
    $out = $engine->evaluate(whCart([$a, $b, $wallet], whCode($spin), $phone));
    expect($out->discount)->toBe(300.0)->and($out->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED)
        ->and(count($out->lines))->toBe(1)->and($out->lines[0]['product_id'])->toBe($b['id']);
    // two of the SAME watch: one unit's 10 %
    expect($engine->evaluate(whCart([$a + ['qty' => 2]], whCode($spin), $phone))->discount)->toBe(500.0);
    // one watch: refused by name
    $one = $engine->evaluate(whCart([$a], whCode($spin), $phone));
    expect($one->discount)->toBe(0.0)->and($one->coupon['status'] ?? null)->toBe(CouponStatus::NO_ELIGIBLE_ITEMS)
        ->and(T::str($one->coupon['messages']['en'] ?? ''))->toContain('2 or more')->toContain('Watches');
    // the rule the wheel made says `cheapest`
    $ruleId = T::int(DB::table(Wheel::TABLE)->where('code', whCode($spin))->value('promotion_rule_id'));
    expect(T::str(DB::table('promotion_rules')->where('id', $ruleId)->value('apply_per')))->toBe(PromotionMoney::PER_CHEAPEST);
});

it('waives the delivery for free shipping — also in the cart before a city is chosen — and never applies to another phone', function () {
    $engine = app(PromotionEngine::class);
    $phone = whPhone();
    $ship = whSpin($phone, roll: 10);                                                 // 1–25 → free shipping
    $watch = whProduct(0, 5000.0, 'watches');
    $priced = $engine->evaluate(whCart([$watch], whCode($ship), $phone, shipping: 120.0));
    expect($priced->freeShipping)->toBeTrue()->and($priced->discount)->toBe(120.0);
    $unknown = $engine->evaluate(whCart([$watch], whCode($ship), $phone, shipping: 0.0));
    expect($unknown->freeShipping)->toBeTrue()->and($unknown->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);
    // no phone in the cart passes; another number is refused by name
    expect($engine->evaluate(whCart([$watch], whCode($ship), null))->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);
    $other = whPhone();
    $refused = $engine->evaluate(whCart([$watch], whCode($ship), $other));
    expect($refused->coupon['status'] ?? null)->toBe(CouponStatus::PHONE_MISMATCH)
        ->and(T::str($refused->coupon['messages']['en'] ?? ''))->toContain(Wheel::mask($phone))->toContain(Wheel::mask($other));
    // the prize rule's OWN code typed directly is nothing
    $ruleId = T::int(DB::table(Wheel::TABLE)->where('code', whCode($ship))->value('promotion_rule_id'));
    $ruleCode = T::str(DB::table('promotion_rules')->where('id', $ruleId)->value('code'));
    expect($engine->evaluate(whCart([$watch], $ruleCode, $phone))->coupon['status'] ?? null)->toBe(CouponStatus::INVALID);
});

it('expires a code after the validity, and does not stack: a bigger automatic offer wins and says so', function () {
    $engine = app(PromotionEngine::class);
    $phone = whPhone();
    $spin = whSpin($phone, roll: 70);                                                 // wallets 30 %
    $wallet = whProduct(0, 2000.0, 'wallets');
    $createdAt = Carbon::parse(T::str(DB::table(Wheel::TABLE)->where('code', whCode($spin))->value('created_at')));
    $expired = $engine->evaluate(whCart([$wallet], whCode($spin), $phone), $createdAt->copy()->addHours(Wheel::DEFAULT_TTL_HOURS)->addMinute());
    expect($expired->discount)->toBe(0.0)->and($expired->coupon['status'] ?? null)->toBe(CouponStatus::EXPIRED);

    $black = PromotionFixture::rule([], [['type' => 'fixed_discount', 'amount' => 1000]], [1], 0);
    DB::table('promotion_rules')->where('id', $black)->update(['apply_per' => PromotionMoney::PER_ITEM, 'min_order_amount' => 1]);
    $out = $engine->evaluate(whCart([$wallet], whCode($spin), $phone));
    expect($out->discount)->toBe(1000.0)->and($out->ruleId)->toBe($black)->and($out->coupon['status'] ?? null)->toBe(CouponStatus::NOT_BEST)
        ->and(T::str($out->coupon['messages']['en'] ?? ''))->toContain('bigger discount (EGP 1,000)')->toContain('not applied')->toContain('valid until')
        ->and(T::str($out->coupon['messages']['ar'] ?? ''))->toContain('خصم أكبر');
    DB::table('promotion_rules')->where('id', $black)->update(['is_active' => 0]);
    $alone = $engine->evaluate(whCart([$wallet], whCode($spin), $phone));
    expect($alone->discount)->toBe(600.0)->and(count($alone->applied))->toBe(1)
        ->and(Coerce::bool(DB::table('promotion_rules')->where('id', $alone->ruleId)->value('stackable')))->toBeFalse();
});

// ── the gifts ───────────────────────────────────────────────────────────────────────────────────────────

it('adds one gift from the list that is in stock, spends the code on the order, says when the gift ran out, and never draws an empty list', function () {
    $gift = whProduct(2, 900.0, 'wallets');                                           // any stocked product will do as a gift
    whSeed(1, [5 => [$gift['id']]]);                                                   // the perfume list: one product
    $prizes = WheelPrizes::forStorefront(1);
    expect(WheelPrizes::drawable($prizes))->toHaveCount(6)->and($prizes[5]['gift_available'])->toBe([$gift['id']])->and($prizes[6]['gift_available'])->toBe([]);
    // the perfume list is in stock, the sunglasses list is not: the page gets SIX segments (the sunglasses are not shown)
    expect(T::arr(getJson('/api/v2/watchizer/wheel')->assertOk()->json('data.segments')))->toHaveCount(6);

    $phone = whPhone();
    $spin = whSpin($phone, roll: 93);                                                 // 91–95 over the six drawable → the perfume gift
    expect(whPrize($spin)['type'])->toBe('gift')->and(whPrize($spin)['index'])->toBe(5)
        ->and(T::arr($spin['gift'])['id'])->toBe($gift['id'])->and(T::arr($spin['gift'])['in_stock'])->toBeTrue();

    // the code on a cart: the gift line comes with the order, at no money
    $engine = app(PromotionEngine::class);
    $watch = whProduct(0, 5000.0, 'watches');
    $out = $engine->evaluate(whCart([$watch], whCode($spin), $phone));
    expect($out->applies())->toBeTrue()->and($out->discount)->toBe(0.0)->and($out->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED)
        ->and(count($out->rewards))->toBe(1)->and($out->rewards[0]->productId)->toBe($gift['id']);

    // the v2 order: the gift is a reward line at 0, the code is spent, the stock moved
    $stockBefore = T::int(DB::table('catalog_products')->where('id', $gift['id'])->value('stock_express'));
    $orderId = whOrder($watch, whCode($spin), $phone);
    expect((int) DB::table('order_items')->where('order_id', $orderId)->where('is_reward', 1)->where('product_id', $gift['id'])->count())->toBe(1)
        ->and(T::float(DB::table('order_items')->where('order_id', $orderId)->where('is_reward', 1)->value('piece_price')))->toBe(0.0)
        ->and(T::int(DB::table('catalog_products')->where('id', $gift['id'])->value('stock_express')))->toBe($stockBefore - 1)
        ->and(Wheel::present(Row::cast(T::row(DB::table(Wheel::TABLE)->where('code', whCode($spin))->first())))['state'])->toBe(Wheel::STATE_USED);
    $again = $engine->evaluate(whCart([$watch], whCode($spin), $phone));
    expect($again->coupon['status'] ?? null)->toBe(CouponStatus::WHEEL_USED);

    // another winner's gift ran out at checkout: told so, the code stays
    $phone2 = whPhone();
    $spin2 = whSpin($phone2, roll: 93);
    app(InventoryService::class)->set(StockTarget::product($gift['id']), 'express', 0, 'adjustment', note: 'wheel test');
    app(InventoryService::class)->set(StockTarget::product($gift['id']), 'market', 0, 'adjustment', note: 'wheel test');
    $outOf = $engine->evaluate(whCart([$watch], whCode($spin2), $phone2));
    expect($outOf->applies())->toBeFalse()->and($outOf->coupon['status'] ?? null)->toBe(CouponStatus::GIFT_OUT_OF_STOCK)
        ->and(T::str($outOf->coupon['messages']['en'] ?? ''))->toContain('out of stock')->toContain('stays valid');
    // and with the whole list out of stock the prize is not drawn: a roll past the drawable total is refused, the five others stand
    expect(WheelPrizes::drawable(WheelPrizes::forStorefront(1)))->toHaveCount(5);
    expect(fn () => Wheel::spin(1, whPhone(), null, '203.0.113.7', [], 'en', 93))->toThrow(InvalidArgumentException::class);
});

// ── invite a friend ─────────────────────────────────────────────────────────────────────────────────────

it('runs the invite end to end: dormant until the winner signs in to activate it, the friend’s first order, the winner’s own number, a returning customer, the same friend twice, a cancellation, the delivery that earns the reward and its mail to the account', function () {
    $winner = whPhone();
    $spin = whSpin($winner, roll: 85);                                                // 81–90 → invite a friend
    $invite = T::arr($spin['invite']);
    // the invite is DORMANT until the winner signs in (2026-11-09 evening): no code on the card, and the code refused in a cart
    expect($spin['code'])->toBeNull()->and($invite['active'])->toBeFalse()->and($invite['code'])->toBeNull()->and(T::arr($invite['friends']))->toBe(['placed' => 0, 'delivered' => 0])
        ->and(Carbon::parse(T::str($invite['expires_at']))->diffInHours(Carbon::parse(T::str($spin['created_at'])), true))->toBeGreaterThan(24 * 7 - 1);
    $row = Row::cast(T::row(DB::table(Wheel::TABLE)->where('phone_key', substr($winner, -10))->where('campaign', Wheel::campaign(1))->first()));
    $inviteCode = Row::str($row, 'invite_code');
    expect($inviteCode)->toStartWith('FRIEND-');
    $engine = app(PromotionEngine::class);
    $watch = whProduct(0, 5000.0, 'watches');
    // the reward rows THIS invite earned (the scratch copy may hold other invites' rewards)
    $ownRewards = fn (): int => (int) DB::table(Wheel::TABLE)->whereIn('source_referral_id', DB::table(WheelReferrals::TABLE)->where('spin_id', Row::int($row, 'id'))->pluck('id'))->count();
    $stranger = whPhone();
    expect($engine->evaluate(whCart([$watch], $inviteCode, $stranger))->coupon['status'] ?? null)->toBe(CouponStatus::INVITE_INACTIVE);
    // activation: no token → 401; a token → the spin is the account's, the code shows, the account's e-mail is named; again → already; another account → 409
    $account = Row::cast(T::row(DB::table('users')->where('type', '!=', 'SuperAdmin')->whereNotNull('email')->where('email', 'like', '%@%')->orderBy('id')->first(['id', 'email'])));
    $other = Row::cast(T::row(DB::table('users')->where('type', '!=', 'SuperAdmin')->where('id', '!=', Row::int($account, 'id'))->orderBy('id')->first(['id'])));
    postJson('/api/v2/watchizer/wheel/claim', ['phone' => $winner])->assertStatus(401);
    $auth = ['Authorization' => 'Bearer '.HarnessJwt::mint(Row::int($account, 'id'))];
    postJson('/api/v2/watchizer/wheel/claim', ['phone' => whPhone()], $auth)->assertStatus(404);
    $activated = T::arr(postJson('/api/v2/watchizer/wheel/claim', ['phone' => $winner], $auth)->assertOk()->json('data'));
    $card0 = T::arr(T::arr($activated['spin'])['invite']);
    expect($activated['status'])->toBe('saved')->and($card0['active'])->toBeTrue()->and($card0['code'])->toBe($inviteCode)->and(T::arr($activated['spin'])['saved'])->toBeTrue()
        ->and(T::str($card0['email_hint']))->toContain('@')->and(T::str($card0['email_hint']))->toContain('•');
    expect(postJson('/api/v2/watchizer/wheel/claim', ['phone' => $winner], $auth)->assertOk()->json('data.status'))->toBe('already');
    postJson('/api/v2/watchizer/wheel/claim', ['phone' => $winner], ['Authorization' => 'Bearer '.HarnessJwt::mint(Row::int($other, 'id'))])->assertStatus(409);
    // the account's own list
    getJson('/api/v2/watchizer/wheel/mine')->assertStatus(401);
    $mine = T::arr(getJson('/api/v2/watchizer/wheel/mine', $auth)->assertOk()->json('data.spins'));
    expect($mine)->toHaveCount(1)->and(T::arr(T::arr(T::arr($mine[0])['invite']))['code'])->toBe($inviteCode);
    // the lookup by phone shows the active invite too (the card after the sign-in)
    expect(getJson('/api/v2/watchizer/wheel/spin?phone='.$winner)->assertOk()->json('data.spin.invite.active'))->toBeTrue();

    // the winner's own number: refused by name
    $own = $engine->evaluate(whCart([$watch], $inviteCode, $winner));
    expect($own->coupon['status'] ?? null)->toBe(CouponStatus::INVITE_OWN)->and(T::str($own->coupon['messages']['en'] ?? ''))->toContain('yours to share');
    // a number that has ordered before: refused
    $returning = whPhone();
    whOrder($watch, '', $returning);
    expect($engine->evaluate(whCart([$watch], $inviteCode, $returning))->coupon['status'] ?? null)->toBe(CouponStatus::INVITE_NOT_FIRST);
    // a friend's first order: 15 % off, capped
    $friend = whPhone();
    $ok = $engine->evaluate(whCart([$watch], $inviteCode, $friend));
    expect($ok->discount)->toBe(750.0)->and($ok->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);
    $dear = whProduct(1, 20000.0, 'watches');
    expect($engine->evaluate(whCart([$dear], $inviteCode, $friend))->discount)->toBe(WH_CAP);

    // the friend orders: the referral is PLACED; the same friend again is refused; no reward yet
    $orderId = whOrder($watch, $inviteCode, $friend);                                // the client sends the total BEFORE the discount (the price/total contract)
    $referral = Row::cast(T::row(DB::table(WheelReferrals::TABLE)->where('order_id', $orderId)->first()));
    expect(Row::str($referral, 'status'))->toBe(WheelReferrals::PLACED)->and(Row::str($referral, 'friend_phone'))->toBe($friend)
        ->and($engine->evaluate(whCart([$watch], $inviteCode, $friend))->coupon['status'] ?? null)->toBe(CouponStatus::INVITE_USED)
        ->and((int) DB::table(Wheel::TABLE)->where('phone_key', substr($winner, -10))->where('campaign', 'like', 'referral-%')->count())->toBe(0);
    $summary = Wheel::present(Row::cast(T::row(DB::table(Wheel::TABLE)->where('invite_code', $inviteCode)->first())));
    expect(T::arr(T::arr($summary['invite'])['friends']))->toBe(['placed' => 1, 'delivered' => 0]);

    // a cancelled order earns nothing, ever
    app(OrderFulfilment::class)->cancel($orderId, Actor::system());
    expect(T::str(DB::table(WheelReferrals::TABLE)->where('id', Row::int($referral, 'id'))->value('status')))->toBe(WheelReferrals::CANCELLED)
        ->and($ownRewards())->toBe(0);
    // (a cancelled first order leaves the friend a first order to place: the invite works for them again)
    expect($engine->evaluate(whCart([$watch], $inviteCode, $friend))->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);

    // a second friend, delivered: the winner gets their reward — 15 %, 48 hours, their phone — and the mail, since the shop knows an address for the number
    DB::table('orders')->where('id', $orderId)->update(['guest_phone' => $winner, 'guest_email' => 'winner-'.Str::random(5).'@example.test']);
    $friend2 = whPhone();
    $order2 = whOrder($watch, $inviteCode, $friend2);
    $fulfilment = app(OrderFulfilment::class);
    foreach (['shipped', 'delivered'] as $to) {                                       // a cash order starts at processing
        $fulfilment->advance($order2, $to, Actor::system());
    }
    $ref2 = Row::cast(T::row(DB::table(WheelReferrals::TABLE)->where('order_id', $order2)->first()));
    expect(Row::str($ref2, 'status'))->toBe(WheelReferrals::DELIVERED)->and(Row::nint($ref2, 'reward_spin_id'))->not->toBeNull();
    $reward = Row::cast(T::row(DB::table(Wheel::TABLE)->where('id', Row::int($ref2, 'reward_spin_id'))->first()));
    expect(Row::str($reward, 'phone'))->toBe($winner)->and(Row::nfloat($reward, 'prize_value'))->toBe(15.0)->and(Row::str($reward, 'campaign'))->toBe('referral-'.Row::int($ref2, 'id'))
        ->and(Row::nint($reward, 'user_id'))->toBe(Row::int($account, 'id'))                                                  // the reward is the account's too
        ->and(Carbon::parse(Row::str($reward, 'expires_at'))->diffInMinutes(Carbon::parse(Row::str($reward, 'created_at'))->addHours(48), true))->toBeLessThan(2);
    Mail::assertSent(WheelReferralReward::class, 1);
    Mail::assertSent(WheelReferralReward::class, fn (WheelReferralReward $mail) => $mail->hasTo(Row::str($account, 'email'))); // the ACCOUNT's e-mail, not the order's
    // the reward applies for the winner, once
    $won = $engine->evaluate(whCart([$watch], Row::str($reward, 'code'), $winner));
    expect($won->discount)->toBe(750.0)->and($won->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED)
        ->and($engine->evaluate(whCart([$watch], Row::str($reward, 'code'), whPhone()))->coupon['status'] ?? null)->toBe(CouponStatus::PHONE_MISMATCH);
    // the winner's card: one friend delivered, one reward
    $card = Wheel::present(Row::cast(T::row(DB::table(Wheel::TABLE)->where('invite_code', $inviteCode)->first())));
    expect(T::arr(T::arr($card['invite'])['friends']))->toBe(['placed' => 0, 'delivered' => 1])
        ->and(T::arr(T::arr($card['invite'])['rewards']))->toHaveCount(1)
        ->and(T::arr(T::arr(T::arr($card['invite'])['rewards'])[0])['code'])->toBe(Row::str($reward, 'code'));
    // closing the order afterwards earns nothing more: the same friend is counted ONCE
    $fulfilment->advance($order2, 'completed', Actor::system());
    expect($ownRewards())->toBe(1);
});

it('saves any code to an account: the signed-in customer claims this round’s spin, the account lists it, and a sign-in with the same phone attaches the spins won before it', function () {
    $phone = whPhone();
    $spin = whSpin($phone, roll: 10);                                                 // free shipping
    expect($spin['saved'])->toBeFalse();
    $account = Row::cast(T::row(DB::table('users')->where('type', '!=', 'SuperAdmin')->whereNotNull('email')->orderBy('id')->first(['id', 'email'])));
    $auth = ['Authorization' => 'Bearer '.HarnessJwt::mint(Row::int($account, 'id'))];
    $saved = T::arr(postJson('/api/v2/watchizer/wheel/claim', ['phone' => $phone], $auth)->assertOk()->json('data'));
    expect($saved['status'])->toBe('saved')->and(T::arr($saved['spin'])['saved'])->toBeTrue()->and(T::arr($saved['spin'])['code'])->toBe(whCode($spin));
    $mine = T::arr(getJson('/api/v2/watchizer/wheel/mine', $auth)->assertOk()->json('data.spins'));
    expect(array_column($mine, 'code'))->toContain(whCode($spin));
    // the lookup by phone says so too — the card after the sign-in
    expect(getJson('/api/v2/watchizer/wheel/spin?phone='.$phone)->assertOk()->json('data.spin.saved'))->toBeTrue();

    // a code won with the account's OWN phone number before signing in: attached at the sign-in, on every storefront
    $owner = Row::cast(T::row(DB::table('users')->where('type', '!=', 'SuperAdmin')->where('id', '!=', Row::int($account, 'id'))->orderBy('id')->first(['id'])));
    $ownPhone = whPhone();
    $earlier = whSpin($ownPhone, roll: 85);                                           // an invite, dormant
    expect(T::arr($earlier['invite'])['active'])->toBeFalse();
    expect(Wheel::attachAccount(Row::int($owner, 'id'), $ownPhone))->toBe(1)->and(Wheel::attachAccount(Row::int($owner, 'id'), $ownPhone))->toBe(0)->and(Wheel::attachAccount(Row::int($owner, 'id'), null))->toBe(0);
    $card = T::arr(getJson('/api/v2/watchizer/wheel/spin?phone='.$ownPhone)->assertOk()->json('data.spin'));
    expect($card['saved'])->toBeTrue()->and(T::arr($card['invite'])['active'])->toBeTrue()->and(T::arr($card['invite'])['code'])->toStartWith('FRIEND-');
    // another account cannot take it
    postJson('/api/v2/watchizer/wheel/claim', ['phone' => $ownPhone], $auth)->assertStatus(409);
});

// ── the checkout end to end ─────────────────────────────────────────────────────────────────────────────

it('is single use through the v2 checkout: the preview says whose it is, the wrong phone is refused by name, the order takes it, the second order is refused, a cancellation gives it back', function () {
    $phone = whPhone();
    $spin = whSpin($phone, roll: 70);                                                 // wallets 30 %
    $wallet = whProduct(0, 2000.0, 'wallets');
    $token = (string) Str::uuid();
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $wallet['id'], 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token])->assertOk();

    $cart = getJson('/api/v2/watchizer/cart?coupon='.whCode($spin).'&phone='.$phone, ['X-Guest-Token' => $token])->assertOk();
    expect($cart->json('data.totals.promotion.coupon.status'))->toBe(CouponStatus::APPLIED)
        ->and($cart->json('data.totals.promotion.coupon.wheel.phone_hint'))->toBe(Wheel::mask($phone));
    $wrong = getJson('/api/v2/watchizer/cart?coupon='.whCode($spin).'&phone=01299998888', ['X-Guest-Token' => $token])->assertOk();
    expect($wrong->json('data.totals.promotion.coupon.status'))->toBe(CouponStatus::PHONE_MISMATCH);

    $before = (int) DB::table('orders')->count();
    $refused = postJson('/api/v2/watchizer/orders', whOrderBody(2000.0 + WH_CITY_COST, whCode($spin), '01299998888'), ['X-Guest-Token' => $token])->assertStatus(422);
    expect($refused->json('status'))->toBe(CouponStatus::PHONE_MISMATCH)->and((int) DB::table('orders')->count())->toBe($before);

    $res = postJson('/api/v2/watchizer/orders', whOrderBody(2000.0 + WH_CITY_COST, whCode($spin), $phone), ['X-Guest-Token' => $token])->assertCreated();
    $orderId = T::int(DB::table('orders')->where('order_number', T::str($res->json('data.order_number')))->value('id'));
    expect(T::float(DB::table('orders')->where('id', $orderId)->value('total_price_for_order')))->toBe(1400.0 + WH_CITY_COST);
    $row = Row::cast(T::row(DB::table(Wheel::TABLE)->where('code', whCode($spin))->first()));
    expect(Row::nint($row, 'used_order_id'))->toBe($orderId)->and(Wheel::present($row)['state'])->toBe(Wheel::STATE_USED);

    $token2 = (string) Str::uuid();
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $wallet['id'], 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token2])->assertOk();
    expect(postJson('/api/v2/watchizer/orders', whOrderBody(2000.0 + WH_CITY_COST, whCode($spin), $phone), ['X-Guest-Token' => $token2])->assertStatus(422)->json('status'))->toBe(CouponStatus::WHEEL_USED);

    DB::table('orders')->where('id', $orderId)->update(['status' => 'cancelled']);
    expect(Wheel::present($row)['state'])->toBe(Wheel::STATE_ACTIVE);
});

// ── rounds, the switch, the dashboard ───────────────────────────────────────────────────────────────────

it('starts a new round from the dashboard: everybody spins again, the old code keeps its terms and expiry, the answers name the round', function () {
    $phone = whPhone();
    $device = 'device-'.Str::random(12);
    $first = T::arr(postJson('/api/v2/watchizer/wheel/spin', ['phone' => $phone, 'device' => $device])->assertOk()->json('data'));
    expect($first['status'])->toBe('new')->and(Wheel::settings(1)['round'])->toBe(1)
        ->and(getJson('/api/v2/watchizer/campaigns')->assertOk()->json('data.wheel.campaign'))->toBe('round-1');
    expect(T::arr(postJson('/api/v2/watchizer/wheel/spin', ['phone' => $phone, 'device' => $device])->assertOk()->json('data'))['status'])->toBe('already_spun');

    actingAs(Staff::dataEntry());
    post('/manage/storefronts/1/wheel/round')->assertForbidden();
    actingAs(Staff::admin());
    $logs = (int) DB::table('core_activity_log')->where('subject_type', 'storefronts')->where('subject_id', 1)->count();
    post('/manage/storefronts/1/wheel/round')->assertRedirect();
    expect(Wheel::settings(1)['round'])->toBe(2)
        ->and((int) DB::table('core_activity_log')->where('subject_type', 'storefronts')->where('subject_id', 1)->count())->toBe($logs + 1)
        ->and(getJson('/api/v2/watchizer/wheel')->assertOk()->json('data.campaign'))->toBe('round-2');

    $second = T::arr(postJson('/api/v2/watchizer/wheel/spin', ['phone' => $phone, 'device' => $device])->assertOk()->json('data'));
    expect($second['status'])->toBe('new')->and(T::arr($second['spin'])['campaign'])->toBe('round-2');
    $oldRow = Row::cast(T::row(DB::table(Wheel::TABLE)->where('phone_key', substr($phone, -10))->where('campaign', 'round-1')->first()));
    expect(Row::str($oldRow, 'campaign'))->toBe('round-1');
    $props = Props::of(get('/manage/storefronts/1/wheel')->assertOk());
    expect(T::int($props['round'] ?? null))->toBe(2)->and(array_column(T::arr($props['by_round'] ?? []), 'campaign'))->toContain('round-1')->toContain('round-2');
});

it('keeps an existing code working after the wheel is switched off', function () {
    $phone = whPhone();
    $spin = whSpin($phone, roll: 70);
    Wheel::setEnabled(1, false);
    app(StorefrontCache::class)->forgetStorefront(1, 'watchizer');
    $out = app(PromotionEngine::class)->evaluate(whCart([whProduct(0, 2000.0, 'wallets')], whCode($spin), $phone));
    expect($out->discount)->toBe(600.0)->and($out->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);
});

it('shows the admin the editor with the new types, the categories and the gift lists, a list with MASKED phones, and the switch; data-entry is refused', function () {
    $phone = whPhone();
    whSpin($phone, roll: 70);
    actingAs(Staff::dataEntry());
    get('/manage/storefronts/1/wheel')->assertForbidden();
    actingAs(Staff::admin());
    $props = Props::of(get('/manage/storefronts/1/wheel')->assertOk());
    expect($props['enabled'] ?? null)->toBeTrue()
        ->and(T::arr($props['prizes'] ?? null))->toHaveCount(7)
        ->and(T::arr($props['types'] ?? null))->toBe(WheelPrizes::TYPES)
        ->and(T::arr($props['modes'] ?? null))->toBe(WheelPrizes::MODES)
        ->and(array_column(T::arr($props['categories'] ?? []), 'id'))->toContain(whCategory('wallets'))->toContain(whCategory('watches'))
        // the invites' totals of this storefront, as the database holds them (the scratch copy may carry a browser pass's)
        ->and(T::arr($props['referrals'] ?? null))->toBe([
            'placed' => (int) DB::table(WheelReferrals::TABLE)->where('storefront_id', 1)->where('status', WheelReferrals::PLACED)->count(),
            'delivered' => (int) DB::table(WheelReferrals::TABLE)->where('storefront_id', 1)->where('status', WheelReferrals::DELIVERED)->count(),
            'cancelled' => (int) DB::table(WheelReferrals::TABLE)->where('storefront_id', 1)->where('status', WheelReferrals::CANCELLED)->count(),
        ]);
    expect(json_encode($props))->not->toContain($phone);
    post('/manage/storefronts/1/wheel/switch', ['enabled' => 0])->assertRedirect();
    expect(Wheel::enabled(1))->toBeFalse();
    post('/manage/storefronts/1/wheel/switch', ['enabled' => 1])->assertRedirect();
    expect(Wheel::enabled(1))->toBeTrue();
});

it('writes the spin under the storefront of its path, never the other', function () {
    $phone1 = whPhone();
    postJson('/api/v2/watchizer/wheel/spin', ['phone' => $phone1])->assertOk();
    expect(T::int(DB::table(Wheel::TABLE)->where('phone_key', substr($phone1, -10))->value('storefront_id')))->toBe(1);
    PromotionFixture::moneyRewards(true, 2);
    Wheel::setEnabled(2, true);
    whSeed(2);
    app(StorefrontCache::class)->forgetStorefront(2, T::str(DB::table('storefronts')->where('id', 2)->value('code')));
    $phone = whPhone();
    postJson('/api/v2/brandfashion/wheel/spin', ['phone' => $phone], ['Host' => Shops::domain('brandfashion')])->assertOk();
    expect(T::int(DB::table(Wheel::TABLE)->where('phone_key', substr($phone, -10))->value('storefront_id')))->toBe(2);
});

// ── the prize editor ────────────────────────────────────────────────────────────────────────────────────

it('saves the segments with their scopes and gift lists, activity-logged; a spin after the save uses the new odds and terms; an old code keeps its terms', function () {
    $phone = whPhone();
    $old = whSpin($phone, roll: 70);                                                  // wallets 30 %, as seeded
    $wallet = whProduct(0, 2000.0, 'wallets');
    $engine = app(PromotionEngine::class);
    expect($engine->evaluate(whCart([$wallet], whCode($old), $phone))->discount)->toBe(600.0);

    actingAs(Staff::admin());
    $before = (int) DB::table('core_activity_log')->where('subject_type', WheelPrizes::TABLE)->where('subject_id', 1)->count();
    $gift = whProduct(1, 900.0, 'wallets');
    $segments = [
        ['label_en' => 'Free shipping', 'label_ar' => 'شحن مجاني', 'type' => 'free_shipping', 'odds' => 40, 'color' => '#0F9D6E'],
        ['label_en' => '20% off wallets', 'label_ar' => 'خصم ٢٠٪ على المحافظ', 'type' => 'percent_discount', 'value' => 20, 'odds' => 30, 'color' => '#C8102E', 'cap' => 300, 'scope_category_id' => whCategory('wallets')],
        ['label_en' => 'Better luck', 'label_ar' => 'حظًا أوفر', 'type' => 'none', 'odds' => 20, 'color' => '#6B7280'],
        ['label_en' => 'A gift', 'label_ar' => 'هدية', 'type' => 'gift', 'odds' => 10, 'color' => '#E0457B', 'gift_product_ids' => (string) $gift['id']],
    ];
    put('/manage/storefronts/1/wheel/prizes', ['segments' => $segments, 'ttl_hours' => 24, 'min_order' => null])->assertSessionHasNoErrors()->assertRedirect();
    $prizes = WheelPrizes::forStorefront(1);
    expect($prizes)->toHaveCount(4)->and(array_column($prizes, 'odds'))->toBe([40, 30, 20, 10])
        ->and($prizes[1]['scope_category_id'])->toBe(whCategory('wallets'))->and($prizes[1]['cap'])->toBe(300.0)
        ->and($prizes[3]['gift_product_ids'])->toBe([$gift['id']])->and($prizes[3]['gift_available'])->toBe([$gift['id']])
        ->and((int) DB::table('core_activity_log')->where('subject_type', WheelPrizes::TABLE)->where('subject_id', 1)->count())->toBe($before + 1);

    // a spin now: roll 50 → the new wallets prize (20 %, cap 300), 24 hours
    $phone2 = whPhone();
    $spin = whSpin($phone2, roll: 50);
    expect(whPrize($spin)['value'])->toBe(20.0)->and(whPrize($spin)['cap'])->toBe(300.0)
        ->and(Carbon::parse(T::str($spin['expires_at']))->diffInMinutes(Carbon::parse(T::str($spin['created_at']))->addHours(24), true))->toBeLessThan(2);
    expect($engine->evaluate(whCart([$wallet], whCode($spin), $phone2))->discount)->toBe(300.0);   // 20 % of 2,000 = 400 → 300
    // the old code: still 30 %, still 48 hours
    expect($engine->evaluate(whCart([$wallet], whCode($old), $phone))->discount)->toBe(600.0);
});

it('refuses a wheel whose odds are not 100, a scoped mode without its category, a gift without products, a bad type, colour or value — and saves nothing of it', function () {
    actingAs(Staff::admin());
    $seeded = WheelPrizes::forStorefront(1);
    $good = [
        ['label_en' => 'Free shipping', 'label_ar' => 'شحن مجاني', 'type' => 'free_shipping', 'odds' => 50, 'color' => '#0F9D6E'],
        ['label_en' => '20% off wallets', 'label_ar' => 'خصم', 'type' => 'percent_discount', 'value' => 20, 'odds' => 30, 'color' => '#C8102E', 'scope_category_id' => whCategory('wallets')],
        ['label_en' => 'Better luck', 'label_ar' => 'حظًا أوفر', 'type' => 'none', 'odds' => 15, 'color' => '#6B7280'],
        ['label_en' => 'Invite', 'label_ar' => 'دعوة', 'type' => 'referral', 'value' => 15, 'odds' => 5, 'color' => '#7B3FE4'],
    ];
    $odds = $good;
    $odds[0]['odds'] = 51;
    put('/manage/storefronts/1/wheel/prizes', ['segments' => $odds, 'ttl_hours' => 48])->assertSessionHasErrors(['odds_total']);
    $bag = session('errors');
    expect($bag)->toBeInstanceOf(ViewErrorBag::class);
    expect($bag instanceof ViewErrorBag ? $bag->first('odds_total') : '')->toContain('101');

    $cheapest = $good;
    $cheapest[1]['apply_mode'] = 'cheapest';                                            // needs a minimum of 2
    put('/manage/storefronts/1/wheel/prizes', ['segments' => $cheapest, 'ttl_hours' => 48])->assertSessionHasErrors(['segments.1.apply_mode']);
    $gift = $good;
    $gift[3] = ['label_en' => 'Gift', 'label_ar' => 'هدية', 'type' => 'gift', 'odds' => 5, 'color' => '#E0457B', 'gift_product_ids' => '999999'];
    put('/manage/storefronts/1/wheel/prizes', ['segments' => $gift, 'ttl_hours' => 48])->assertSessionHasErrors(['segments.3.gift_product_ids']);
    $foreign = $good;
    $foreign[1]['scope_category_id'] = 999999;
    put('/manage/storefronts/1/wheel/prizes', ['segments' => $foreign, 'ttl_hours' => 48])->assertSessionHasErrors(['segments.1.scope_category_id']);
    $bad = $good;
    $bad[0]['label_ar'] = '';
    $bad[1]['value'] = 150;
    $bad[2]['color'] = 'grey';
    $bad[3]['type'] = 'try_again';
    put('/manage/storefronts/1/wheel/prizes', ['segments' => $bad, 'ttl_hours' => 0])
        ->assertSessionHasErrors(['segments.0.label_ar', 'segments.1.value', 'segments.2.color', 'segments.3.type', 'ttl_hours']);
    put('/manage/storefronts/1/wheel/prizes', ['segments' => array_slice($good, 0, 3), 'ttl_hours' => 48])->assertSessionHasErrors(['segments']);

    expect(WheelPrizes::forStorefront(1))->toBe($seeded);
    actingAs(Staff::dataEntry());
    put('/manage/storefronts/1/wheel/prizes', ['segments' => $good, 'ttl_hours' => 48])->assertForbidden();
});

it('keeps the three wheel tables on the never-dropped list, and its routes under /api/v2 (the api host allow-list is unchanged)', function () {
    foreach ([Wheel::TABLE, WheelPrizes::TABLE, WheelReferrals::TABLE] as $table) {
        expect(CoreChecksumCommand::DASHBOARD_TABLES)->toContain($table)
            ->and(CoreChecksumCommand::CLEAN_TABLES)->not->toContain($table);
    }
    foreach (['/api/v2/watchizer/wheel', '/api/v2/watchizer/wheel/spin'] as $path) {
        expect($path)->toStartWith('/api/v2/');
    }
});
