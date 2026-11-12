<?php

use App\Console\Commands\PromotionsBlackFridayCommand;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Orders\OrderTotals;
use App\Domain\Promotions\CartLine;
use App\Domain\Promotions\CartSnapshot;
use App\Domain\Promotions\CouponStatus;
use App\Domain\Promotions\PromotionEngine;
use App\Domain\Promotions\PromotionMoney;
use App\Domain\Promotions\PromotionWriter;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\PaymentFixture;
use Tests\Support\PromotionFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutMiddleware;

/*
 * COUPON CODES, TARGETED DISCOUNTS AND LIMITS on the promotions engine (M1u, 2026-10-30) — the engine on its own
 * (the money per line, the statuses a shopper sees, best-single vs stacking, limits, the floor and the cap) and the
 * v2 checkout end to end (the order total, the records per line, the redemption, the free-shipping threshold, COD
 * and card with the Paymob amount, the cart's preview, and a code that does not apply stopping the order).
 *
 * Products are chosen from the data (visible on Watchizer, no variants, in stock), never named; every price, sale
 * and rule is set inside the test transaction.
 */

const CP_CITY_COST = 85.0;

beforeEach(function () {
    withoutMiddleware(ThrottleRequests::class);
    Mail::fake();
    config(['notifications.send.inline' => false]);
    PromotionFixture::moneyRewards(true, 1);
    app(StorefrontCache::class)->forgetStorefront(1, T::str(DB::table('storefronts')->where('id', 1)->value('code')));
    DB::table('shipping_cities')->where('id', cpCity())->update(['shipping_cost' => number_format(CP_CITY_COST, 2, '.', '')]);
});

function cpCity(): int
{
    return T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));
}

/**
 * The n-th visible, variant-less product of a family with stock, given a known price (and no sale) on Watchizer.
 *
 * @return array{id: int, brand: int, price: float}
 */
function cpProduct(string $family, int $nth = 0, float $price = 5000.0, ?int $notBrand = null): array
{
    $query = DB::table('catalog_products as p')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1);
        })
        ->where('sp.is_visible', 1)->whereNull('p.deleted_at')->where('p.is_active', 1)
        ->where('p.family', $family)->whereNotNull('p.brand_id')
        ->whereNotExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id'));
    if ($notBrand !== null) {
        $query->where('p.brand_id', '!=', $notBrand);
    }
    $row = Row::cast(T::row($query->orderBy('p.id')->skip($nth)->first(['p.id', 'p.brand_id'])));
    $id = Row::int($row, 'id');
    cpPrice($id, $price, null);
    // stock SET inside the test, never assumed from the data
    app(InventoryService::class)->set(StockTarget::product($id), 'express', 5, 'adjustment', note: 'coupon test');

    return ['id' => $id, 'brand' => Row::int($row, 'brand_id'), 'price' => $price];
}

function cpPrice(int $productId, float $price, ?float $sale): void
{
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)->update([
        'effective_price' => number_format($price, 2, '.', ''),
        'effective_sale_price' => $sale === null ? null : number_format($sale, 2, '.', ''),
    ]);
    app(StorefrontCache::class)->forgetProduct(1, $productId);
    app(StorefrontCache::class)->forgetCard(1, $productId);
}

/**
 * A rule built with the M1u columns: no conditions, a money reward, item scopes.
 *
 * @param  array<string, mixed>  $columns
 * @param  list<array{role: string, dimension: string, value_id?: int|null, value?: string|null}>  $scopes
 * @param  list<array<string, mixed>>|null  $rewards
 */
function cpRule(array $columns = [], array $scopes = [], ?array $rewards = null, int $priority = 0): int
{
    $id = PromotionFixture::rule([], $rewards ?? [PromotionFixture::fixedDiscount(1000)], [1], $priority);
    DB::table('promotion_rules')->where('id', $id)->update($columns + ['apply_per' => PromotionMoney::PER_ORDER]);
    foreach ($scopes as $scope) {
        DB::table('promotion_rule_scopes')->insert(['promotion_rule_id' => $id, 'role' => $scope['role'], 'dimension' => $scope['dimension'],
            'value_id' => $scope['value_id'] ?? null, 'value' => $scope['value'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
}

/**
 * The Black Friday shape: EGP 1,000 off EACH watch of the brand.
 *
 * @param  array<string, mixed>  $columns
 */
function cpBlack(int $brand, array $columns = []): int
{
    return cpRule($columns + ['code' => 'Black1000', 'apply_per' => PromotionMoney::PER_ITEM, 'label_en' => 'Black Friday', 'label_ar' => 'الجمعة البيضاء'], [
        ['role' => 'target', 'dimension' => 'brand', 'value_id' => $brand],
        ['role' => 'target', 'dimension' => 'family', 'value' => 'watch'],
    ]);
}

/**
 * @param  list<array{0: array{id: int, price: float}, 1: int}>  $lines  product, quantity
 * @param  list<string>  $keys
 */
function cpSnapshot(array $lines, ?string $code, array $keys = [], float $shipping = 50.0): CartSnapshot
{
    $cart = [];
    $subtotal = 0.0;
    foreach ($lines as [$p, $qty]) {
        $cart[] = new CartLine(productId: $p['id'], variantId: null, offerId: null, quantity: $qty, unitPrice: $p['price'], typeStock: 'Express');
        $subtotal += $p['price'] * $qty;
    }

    return new CartSnapshot(storefrontId: 1, lines: $cart, subtotal: round($subtotal, 2), shippingCost: $shipping,
        paymentMethod: 'cash', code: $code, customerKeys: $keys);
}

// ── 1. the money ─────────────────────────────────────────────────────────────────────────────

it('takes EGP 1,000 off EACH eligible watch: two watches are 2,000, recorded per line', function () {
    $watch = cpProduct('watch');
    cpBlack($watch['brand']);

    $out = app(PromotionEngine::class)->evaluate(cpSnapshot([[$watch, 2]], 'black1000'));   // any case

    expect($out->discount)->toBe(2000.0)
        ->and($out->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED)
        ->and($out->lines)->toHaveCount(1)
        ->and($out->lines[0]['amount'])->toBe(2000.0)
        ->and($out->applied[0]['code'])->toBe('Black1000')
        ->and($out->applied[0]['label_ar'])->toBe('الجمعة البيضاء');
});

it('discounts only the eligible lines of a MIXED cart', function () {
    $watch = cpProduct('watch');
    $other = cpProduct('watch', 0, 3000.0, $watch['brand']);       // another brand
    $bag = cpProduct('bag', 0, 2000.0);
    cpBlack($watch['brand']);

    $out = app(PromotionEngine::class)->evaluate(cpSnapshot([[$watch, 1], [$other, 1], [$bag, 1]], 'Black1000'));

    expect($out->discount)->toBe(1000.0)
        ->and(array_column($out->lines, 'product_id'))->toBe([$watch['id']]);
});

it('says NO ELIGIBLE ITEMS when the cart holds none of the brand', function () {
    $watch = cpProduct('watch');
    $other = cpProduct('watch', 0, 3000.0, $watch['brand']);
    cpBlack($watch['brand']);

    $out = app(PromotionEngine::class)->evaluate(cpSnapshot([[$other, 1]], 'Black1000'));

    expect($out->discount)->toBe(0.0)
        ->and($out->coupon['status'] ?? null)->toBe(CouponStatus::NO_ELIGIBLE_ITEMS)
        ->and($out->coupon['messages']['en'] ?? '')->toContain('None of the items')
        ->and($out->coupon['messages']['ar'] ?? '')->not->toBe('');
});

it('includes items already on sale by default, and leaves them out when the rule says so', function () {
    $watch = cpProduct('watch');
    cpPrice($watch['id'], 5000.0, 4500.0);                          // on sale
    $onSale = ['id' => $watch['id'], 'brand' => $watch['brand'], 'price' => 4500.0];
    $rule = cpBlack($watch['brand']);

    expect(app(PromotionEngine::class)->evaluate(cpSnapshot([[$onSale, 1]], 'Black1000'))->discount)->toBe(1000.0);

    DB::table('promotion_rules')->where('id', $rule)->update(['include_sale_items' => 0]);
    $out = app(PromotionEngine::class)->evaluate(cpSnapshot([[$onSale, 1]], 'Black1000'));
    expect($out->discount)->toBe(0.0)->and($out->coupon['status'] ?? null)->toBe(CouponStatus::NO_ELIGIBLE_ITEMS);
});

it('never takes a unit below the floor, nor below zero', function () {
    $cheap = cpProduct('watch', 0, 900.0);
    $rule = cpBlack($cheap['brand']);

    // floor 0: a EGP 900 watch is free, never a refund
    expect(app(PromotionEngine::class)->evaluate(cpSnapshot([[$cheap, 1]], 'Black1000'))->discount)->toBe(900.0);

    DB::table('promotion_rules')->where('id', $rule)->update(['unit_floor' => '250.00']);
    expect(app(PromotionEngine::class)->evaluate(cpSnapshot([[$cheap, 2]], 'Black1000'))->discount)->toBe(1300.0);   // 2 × (900 − 250)
});

it('caps the rule at its maximum discount, split over the lines to the piastre', function () {
    $watch = cpProduct('watch');
    cpBlack($watch['brand'], ['max_discount' => '1500.00']);

    $out = app(PromotionEngine::class)->evaluate(cpSnapshot([[$watch, 3]], 'Black1000'));

    expect($out->discount)->toBe(1500.0)->and(array_sum(array_column($out->lines, 'amount')))->toBe(1500.0);
});

it('takes a percentage of the eligible goods, once per order', function () {
    $watch = cpProduct('watch', 0, 1234.56);
    cpRule(['code' => 'TEN'], [['role' => 'target', 'dimension' => 'brand', 'value_id' => $watch['brand']]], [PromotionFixture::percentDiscount(10)]);

    $out = app(PromotionEngine::class)->evaluate(cpSnapshot([[$watch, 3]], 'TEN'));

    expect($out->discount)->toBe(370.37);                            // round(3703.68 × 10%)
});

// ── 2. what the shopper is told ──────────────────────────────────────────────────────────────

it('names an unknown, a switched-off, an expired and a not-yet-started code apart', function () {
    $watch = cpProduct('watch');
    $rule = cpBlack($watch['brand']);
    $engine = app(PromotionEngine::class);
    $status = fn (): ?string => $engine->evaluate(cpSnapshot([[$watch, 1]], 'Black1000'))->coupon['status'] ?? null;

    expect($engine->evaluate(cpSnapshot([[$watch, 1]], 'NOPE'))->coupon['status'] ?? null)->toBe(CouponStatus::INVALID);

    DB::table('promotion_rules')->where('id', $rule)->update(['ends_at' => now()->subHour()->format('Y-m-d H:i:s')]);
    expect($status())->toBe(CouponStatus::EXPIRED);

    DB::table('promotion_rules')->where('id', $rule)->update(['starts_at' => now()->addDay()->format('Y-m-d H:i:s'), 'ends_at' => now()->addMonth()->format('Y-m-d H:i:s')]);
    expect($status())->toBe(CouponStatus::NOT_STARTED);

    DB::table('promotion_rules')->where('id', $rule)->update(['is_active' => 0, 'starts_at' => now()->subDay()->format('Y-m-d H:i:s')]);
    expect($status())->toBe(CouponStatus::INVALID);

    // a code of ANOTHER storefront is unknown here
    DB::table('promotion_rules')->where('id', $rule)->update(['is_active' => 1]);
    DB::table('promotion_rule_storefront')->where('promotion_rule_id', $rule)->update(['storefront_id' => 2]);
    expect($status())->toBe(CouponStatus::INVALID);
});

it('says the minimum is not reached, with the amount, in both languages', function () {
    $watch = cpProduct('watch', 0, 3000.0);
    cpBlack($watch['brand'], ['min_order_amount' => '15000.00']);

    $out = app(PromotionEngine::class)->evaluate(cpSnapshot([[$watch, 1]], 'Black1000'));

    expect($out->coupon['status'] ?? null)->toBe(CouponStatus::MINIMUM_NOT_REACHED)
        ->and($out->coupon['messages']['en'] ?? '')->toContain('EGP 15,000')
        ->and($out->coupon['messages']['ar'] ?? '')->toContain('١٥٬٠٠٠');
});

it('stops at the usage limit, in total and per customer — a cancelled order gives its use back', function () {
    $watch = cpProduct('watch');
    $rule = cpBlack($watch['brand'], ['usage_limit' => 2, 'usage_limit_per_customer' => 1]);
    $address = T::int(DB::table('addresses')->orderBy('id')->value('id'));
    $order = fn (string $status): int => (int) DB::table('orders')->insertGetId(['storefront_id' => 1, 'status' => $status, 'payment_method' => 'cash',
        'address_id' => $address, 'order_number' => 'CP-'.Str::random(10), 'total_price_for_order' => '1.00', 'created_at' => now(), 'updated_at' => now()]);
    $use = fn (int $orderId, string $key) => DB::table('promotion_redemptions')->insert(['promotion_rule_id' => $rule, 'order_id' => $orderId,
        'storefront_id' => 1, 'code' => 'Black1000', 'customer_key' => $key, 'amount' => '1000.00', 'created_at' => now()]);
    $engine = app(PromotionEngine::class);

    $use($order('pending'), 'e:first@example.invalid');
    expect($engine->evaluate(cpSnapshot([[$watch, 1]], 'Black1000', ['e:first@example.invalid']))->coupon['status'] ?? null)->toBe(CouponStatus::CUSTOMER_LIMIT)
        ->and($engine->evaluate(cpSnapshot([[$watch, 1]], 'Black1000', ['e:second@example.invalid']))->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);

    $use($order('cancelled'), 'e:second@example.invalid');          // does not count
    expect($engine->evaluate(cpSnapshot([[$watch, 1]], 'Black1000', ['e:third@example.invalid']))->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);

    $use($order('delivered'), 'e:second@example.invalid');
    expect($engine->evaluate(cpSnapshot([[$watch, 1]], 'Black1000', ['e:third@example.invalid']))->coupon['status'] ?? null)->toBe(CouponStatus::USAGE_LIMIT);
});

// ── 3. which promotion wins ──────────────────────────────────────────────────────────────────

it('applies the BEST SINGLE discount when nothing stacks — and says so to a code that lost', function () {
    $watch = cpProduct('watch', 0, 10000.0);
    cpRule([], [['role' => 'target', 'dimension' => 'brand', 'value_id' => $watch['brand']]], [PromotionFixture::percentDiscount(20)]);   // automatic: 2,000
    cpBlack($watch['brand']);                                                                                                          // code: 1,000

    $out = app(PromotionEngine::class)->evaluate(cpSnapshot([[$watch, 1]], 'Black1000'));

    expect($out->discount)->toBe(2000.0)
        ->and($out->applied)->toHaveCount(1)
        ->and($out->coupon['status'] ?? null)->toBe(CouponStatus::NOT_BEST);
});

it('combines rules only when both are marked stackable', function () {
    $watch = cpProduct('watch', 0, 10000.0);
    $auto = cpRule(['stackable' => 1], [['role' => 'target', 'dimension' => 'brand', 'value_id' => $watch['brand']]], [PromotionFixture::percentDiscount(10)]);
    cpBlack($watch['brand'], ['stackable' => 1]);

    $out = app(PromotionEngine::class)->evaluate(cpSnapshot([[$watch, 1]], 'Black1000'));
    expect($out->discount)->toBe(2000.0)                             // 1,000 off, then 10% of the 9,000 left
        ->and($out->applied)->toHaveCount(2)
        ->and($out->coupon['status'] ?? null)->toBe(CouponStatus::APPLIED);

    DB::table('promotion_rules')->where('id', $auto)->update(['stackable' => 0]);
    expect(app(PromotionEngine::class)->evaluate(cpSnapshot([[$watch, 1]], 'Black1000'))->discount)->toBe(1000.0);
});

it('runs "buy from X, get a discount on Y": nothing until a qualifying item is in the cart', function () {
    $watch = cpProduct('watch');
    $bag = cpProduct('bag', 0, 2000.0);
    cpRule(['qualify_min_quantity' => 1], [
        ['role' => 'qualify', 'dimension' => 'brand', 'value_id' => $watch['brand']],
        ['role' => 'qualify', 'dimension' => 'family', 'value' => 'watch'],
        ['role' => 'target', 'dimension' => 'family', 'value' => 'bag'],
    ], [PromotionFixture::percentDiscount(50)]);
    $engine = app(PromotionEngine::class);

    expect($engine->evaluate(cpSnapshot([[$bag, 1]], null))->discount)->toBe(0.0)
        ->and($engine->evaluate(cpSnapshot([[$watch, 1], [$bag, 1]], null))->discount)->toBe(1000.0);
});

// ── 4. the v2 checkout, end to end ───────────────────────────────────────────────────────────

/** @param  array{id: int, price: float}  $product */
function cpCart(array $product, int $quantity = 1, ?string $token = null): string
{
    $token ??= (string) Str::uuid();
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $product['id'], 'bucket' => 'Express', 'quantity' => $quantity], ['X-Guest-Token' => $token])->assertOk();

    return $token;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function cpBody(float $total, ?string $coupon, string $method = 'cash', array $extra = []): array
{
    return $extra + array_filter(['total' => $total, 'payment_method' => $method, 'address_line' => '7 Coupon Street, Heliopolis',
        'shipping_city_id' => cpCity(), 'phone' => '01055556666', 'guest_name' => 'Coupon Buyer',
        'guest_email' => 'coupon@example.invalid', 'coupon' => $coupon], fn ($v) => $v !== null);
}

function cpThreshold(float|int $over): void
{
    $raw = DB::table('storefronts')->where('id', 1)->value('settings');
    $settings = is_string($raw) ? (array) json_decode($raw, true) : [];
    $settings['shipping'] = ['free_over' => $over];
    DB::table('storefronts')->where('id', 1)->update(['settings' => json_encode($settings)]);
    app(StorefrontCache::class)->forgetStorefront(1, T::str(DB::table('storefronts')->where('id', 1)->value('code')));
}

it('places a COD order at goods − discount + delivery, and records the rule, the code, each line and the redemption', function () {
    cpThreshold(0);                                                  // delivery charged, whatever the goods
    $watch = cpProduct('watch', 0, 4000.0);
    $rule = cpBlack($watch['brand']);
    $token = cpCart($watch, 2);

    // the cart PREVIEWS the discount for the code it shows; the page never sends an amount
    $preview = T::arr(getJson('/api/v2/watchizer/cart?coupon=Black1000', ['X-Guest-Token' => $token])->assertOk()->json('data.totals.promotion'));
    expect(T::float($preview['discount'] ?? null))->toBe(2000.0)->and(T::arr($preview['coupon'] ?? null)['status'] ?? null)->toBe(CouponStatus::APPLIED);

    $res = postJson('/api/v2/watchizer/orders', cpBody(8000.0 + CP_CITY_COST, 'Black1000'), ['X-Guest-Token' => $token])->assertCreated();
    $order = T::row(DB::table('orders')->where('order_number', T::str($res->json('data.order_number')))->first(['id', 'total_price_for_order']));
    $orderId = T::int($order->id);

    expect(round(T::float($order->total_price_for_order), 2))->toBe(round(8000.0 - 2000.0 + CP_CITY_COST, 2))
        ->and(T::float($res->json('data.total')))->toBe(round(8000.0 - 2000.0 + CP_CITY_COST, 2))
        ->and(DB::table('promotion_order_discounts')->where('order_id', $orderId)->value('code'))->toBe('Black1000')
        ->and(DB::table('promotion_redemptions')->where('order_id', $orderId)->where('promotion_rule_id', $rule)->value('customer_key'))->toBe('e:coupon@example.invalid');
    $line = T::row(DB::table('promotion_order_discount_lines')->where('order_id', $orderId)->first(['order_item_id', 'product_id', 'quantity', 'amount']));
    expect(T::int($line->product_id))->toBe($watch['id'])->and(T::int($line->quantity))->toBe(2)->and(T::float($line->amount))->toBe(2000.0)
        ->and(T::int(DB::table('order_items')->where('id', T::int($line->order_item_id))->value('order_id')))->toBe($orderId);

    $totals = OrderTotals::of($orderId);
    expect($totals?->unexplained())->toBe(0.0);
});

it('keeps free shipping on the goods BEFORE the coupon (option A) and still takes the coupon off', function () {
    $watch = cpProduct('watch', 0, 6000.0);
    cpThreshold(10000);
    cpBlack($watch['brand']);
    $token = cpCart($watch, 2);                                      // goods 12,000 ≥ 10,000 → delivery free; then 2,000 off

    $res = postJson('/api/v2/watchizer/orders', cpBody(12000.0, 'Black1000'), ['X-Guest-Token' => $token])->assertCreated();

    expect(T::float($res->json('data.total')))->toBe(10000.0);
});

it('sends Paymob the stored, discounted total on a CARD order', function () {
    cpThreshold(0);
    $provider = PaymentFixture::paymob();
    DB::table('storefront_payment_providers')->where('id', $provider->getAttribute('id'))->update(['settings' => json_encode(['callback_base' => 'https://api.watchizereg.com'])]);
    Http::fake(['*/intention/' => fn (ClientRequest $r) => Http::response(['client_secret' => 'cs_cp', 'id' => 'intent_cp'], 200)]);
    $method = Row::cast(T::row(DB::table('storefront_payment_methods as m')
        ->join('storefront_payment_providers as pp', 'pp.id', '=', 'm.storefront_payment_provider_id')
        ->where('pp.storefront_id', 1)->where('pp.provider', 'paymob')->where('m.is_enabled', 1)
        ->orderBy('m.sort')->first(['m.id'])));
    $watch = cpProduct('watch', 0, 4000.0);
    cpBlack($watch['brand']);
    $token = cpCart($watch);

    $res = postJson('/api/v2/watchizer/orders', cpBody(4000.0 + CP_CITY_COST, 'Black1000', 'card', ['payment_method_id' => Row::int($method, 'id')]), ['X-Guest-Token' => $token])->assertCreated();
    $stored = T::float(DB::table('orders')->where('order_number', T::str($res->json('data.order_number')))->value('total_price_for_order'));

    $sent = Http::recorded()->last();
    $intention = T::arr(T::arr($sent)[0] instanceof ClientRequest ? T::arr($sent)[0]->data() : []);
    expect($stored)->toBe(round(3000.0 + CP_CITY_COST, 2))
        ->and(T::int($intention['amount'] ?? 0))->toBe((int) round($stored * 100));
});

it('STOPS the order when the typed code does not apply — nothing is written, the shopper is told why', function () {
    cpThreshold(0);
    $watch = cpProduct('watch', 0, 4000.0);
    $other = cpProduct('watch', 0, 3000.0, $watch['brand']);
    cpBlack($watch['brand']);
    $token = cpCart($other);
    $before = DB::table('orders')->count();

    $res = postJson('/api/v2/watchizer/orders', cpBody(3000.0 + CP_CITY_COST, 'Black1000'), ['X-Guest-Token' => $token]);

    expect($res->status())->toBe(422)
        ->and($res->json('error'))->toBe('coupon')
        ->and($res->json('status'))->toBe(CouponStatus::NO_ELIGIBLE_ITEMS)
        ->and(T::str($res->json('messages.ar')))->not->toBe('')
        ->and(DB::table('orders')->count())->toBe($before);
});

it('refuses a tampered total exactly as before, with a coupon live', function () {
    cpThreshold(0);
    $watch = cpProduct('watch', 0, 4000.0);
    cpBlack($watch['brand']);
    $token = cpCart($watch);

    // a page that subtracts the discount itself is sending a total core does not trust
    $res = postJson('/api/v2/watchizer/orders', cpBody(3000.0 + CP_CITY_COST, 'Black1000'), ['X-Guest-Token' => $token]);

    expect($res->status())->toBe(422)->and($res->json('error'))->toBe('total_mismatch');
});

// ── 5. the dashboard's writer ────────────────────────────────────────────────────────────────

/**
 * A coupon as the dashboard form sends it.
 *
 * @param  array<string, mixed>  $over
 * @return array<string, mixed>
 */
function cpForm(array $over = []): array
{
    return $over + [
        'name' => 'Coupon from the screen', 'priority' => 0, 'is_active' => false, 'starts_at' => null, 'ends_at' => null,
        'storefronts' => [1], 'conditions' => [], 'rewards' => [['type' => 'fixed_discount', 'amount' => 500]],
        'code' => 'SCREEN500', 'apply_per' => 'item',
        'scopes' => [['role' => 'target', 'dimension' => 'family', 'value' => 'watch']],
    ];
}

it('saves a coupon as an inactive DRAFT without dates, with no conditions — and refuses to switch it on without them', function () {
    $writer = app(PromotionWriter::class);
    $id = $writer->save(cpForm(), null, null);

    $row = T::row(DB::table('promotion_rules')->where('id', $id)->first(['code', 'starts_at', 'ends_at', 'apply_per', 'is_active']));
    expect($row->code)->toBe('SCREEN500')->and($row->starts_at)->toBeNull()->and($row->apply_per)->toBe('item')
        ->and(DB::table('promotion_rule_scopes')->where('promotion_rule_id', $id)->count())->toBe(1);

    expect(fn () => $writer->save(cpForm(['is_active' => true]), $id, null))->toThrow(ValidationException::class);
});

/**
 * The fields a save refused, or none.
 *
 * @param  array<string, mixed>  $form
 * @return list<string|int>
 */
function cpErrors(PromotionWriter $writer, array $form): array
{
    try {
        $writer->save($form, null, null);
    } catch (ValidationException $e) {
        return array_keys($e->errors());
    }

    return [];
}

it('refuses a code that is taken (in any case) or badly formed, and an unknown scope', function () {
    $writer = app(PromotionWriter::class);
    $writer->save(cpForm(), null, null);

    expect(cpErrors($writer, cpForm(['code' => 'screen500', 'name' => 'Second'])))->toContain('code')
        ->and(cpErrors($writer, cpForm(['code' => 'has space'])))->toContain('code')
        ->and(cpErrors($writer, cpForm(['code' => 'OTHER', 'scopes' => [['role' => 'target', 'dimension' => 'brand', 'value_id' => 999999999]]])))->toContain('scopes.0');
});

it('still refuses an automatic rule with nothing to limit it', function () {
    $form = cpForm(['code' => null, 'scopes' => []]);

    expect(fn () => app(PromotionWriter::class)->save($form, null, null))->toThrow(ValidationException::class);
});

// ── 6. the Black Friday command ──────────────────────────────────────────────────────────────

it('creates Black1000 only with --apply, inactive and dateless, per watch, on sale included — once', function () {
    $before = DB::table('promotion_rules')->count();

    expect(Artisan::call('promotions:black-friday'))->toBe(0)
        ->and(DB::table('promotion_rules')->count())->toBe($before);                     // the dry run writes nothing

    expect(Artisan::call('promotions:black-friday', ['--apply' => true]))->toBe(0);
    $rule = T::row(DB::table('promotion_rules')->where('code', 'Black1000')->first(['id', 'is_active', 'starts_at', 'ends_at', 'apply_per', 'include_sale_items', 'stackable']));
    $brands = DB::table('promotion_rule_scopes')->where('promotion_rule_id', T::int($rule->id))->where('dimension', 'brand')->pluck('value_id')->map(fn ($v) => T::int($v))->sort()->values()->all();
    $expected = DB::table('catalog_brands')->whereIn('slug', array_keys(PromotionsBlackFridayCommand::BRANDS))->pluck('id')->map(fn ($v) => T::int($v))->sort()->values()->all();

    expect(T::int($rule->is_active))->toBe(0)->and($rule->starts_at)->toBeNull()->and($rule->ends_at)->toBeNull()
        ->and($rule->apply_per)->toBe('item')->and(T::int($rule->include_sale_items))->toBe(1)->and(T::int($rule->stackable))->toBe(0)
        ->and($brands)->toBe($expected)
        ->and(DB::table('promotion_rule_scopes')->where('promotion_rule_id', T::int($rule->id))->where('dimension', 'family')->value('value'))->toBe('watch')
        ->and(T::float(DB::table('promotion_rule_rewards')->where('promotion_rule_id', T::int($rule->id))->value('amount')))->toBe(1000.0);

    Artisan::call('promotions:black-friday', ['--apply' => true]);
    expect(DB::table('promotion_rules')->where('code', 'Black1000')->count())->toBe(1);                // idempotent
});

it('refuses Black1000 where money rewards are off, and writes nothing', function () {
    PromotionFixture::moneyRewards(false, 1);

    expect(Artisan::call('promotions:black-friday', ['--apply' => true]))->toBe(1)
        ->and(DB::table('promotion_rules')->where('code', 'Black1000')->exists())->toBeFalse();
});

// ── the EXCLUSION scope (2026-11-12, WR10 / BR10): 10 % off everything EXCEPT these brands' watches ──────────────────

it('takes 10 % off every product EXCEPT the excluded brands\' watches — WR10\'s shape', function () {
    $watch = cpProduct('watch', 0, 5000.0);                            // brand X: excluded
    $other = cpProduct('watch', 0, 3000.0, $watch['brand']);          // another brand: discounted
    $bag = cpProduct('bag', 0, 2000.0);                                 // not a watch: discounted
    cpRule(['code' => 'WR10', 'apply_per' => PromotionMoney::PER_ORDER, 'include_sale_items' => 1], [
        ['role' => 'exclude', 'dimension' => 'brand', 'value_id' => $watch['brand']],
        ['role' => 'exclude', 'dimension' => 'family', 'value' => 'watch'],
    ], [PromotionFixture::percentDiscount(10)]);
    $engine = app(PromotionEngine::class);

    $mixed = $engine->evaluate(cpSnapshot([[$watch, 1], [$other, 1], [$bag, 1]], 'WR10'));
    expect($mixed->discount)->toBe(500.0)                                // 10 % of 3,000 + 2,000 — never of the excluded 5,000
        ->and(array_column($mixed->lines, 'product_id'))->toEqualCanonicalizing([$other['id'], $bag['id']]);

    $only = $engine->evaluate(cpSnapshot([[$watch, 2]], 'WR10'));
    expect($only->discount)->toBe(0.0)
        ->and($only->coupon['status'] ?? null)->toBe(CouponStatus::NO_ELIGIBLE_ITEMS);

    $included = $engine->evaluate(cpSnapshot([[$bag, 1]], 'WR10'));
    expect($included->discount)->toBe(200.0);
});

it('excludes only the brand AND the product type together: a non-watch of an excluded brand keeps the discount', function () {
    $bag = cpProduct('bag', 0, 2000.0);
    cpRule(['code' => 'BR10', 'apply_per' => PromotionMoney::PER_ORDER], [
        ['role' => 'exclude', 'dimension' => 'brand', 'value_id' => $bag['brand']],   // the bag's own brand…
        ['role' => 'exclude', 'dimension' => 'family', 'value' => 'watch'],           // …but only its WATCHES are excluded
    ], [PromotionFixture::percentDiscount(10)]);

    expect(app(PromotionEngine::class)->evaluate(cpSnapshot([[$bag, 1]], 'BR10'))->discount)->toBe(200.0);
});

it('saves exclusion rows from the screen like the other scopes', function () {
    $watch = cpProduct('watch');
    $id = app(PromotionWriter::class)->save(cpForm(['code' => 'EXCL10', 'scopes' => [
        ['role' => 'exclude', 'dimension' => 'brand', 'value_id' => $watch['brand']],
        ['role' => 'exclude', 'dimension' => 'family', 'value' => 'watch'],
    ]]), null, null);

    expect(DB::table('promotion_rule_scopes')->where('promotion_rule_id', $id)->where('role', 'exclude')->count())->toBe(2);
});
