<?php

use App\Domain\Campaigns\CampaignPrices;
use App\Domain\Campaigns\CampaignWriter;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Promotions\CouponStatus;
use App\Domain\Promotions\PromotionMoney;
use App\Domain\Wheel\Wheel;
use App\Domain\Wheel\WheelPrizes;
use App\Domain\Wheel\WheelPrizeWriter;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PromotionFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutMiddleware;

/*
 * ── Campaign prices (Michael, 2026-11-10) ────────────────────────────────────────────────────────────────
 *
 * A Tommy watch is 3,000 before discount and 2,150 after (its own sale). In a live campaign the team sets a campaign
 * price (1,500) or an extra percentage on the current price (20 % → 1,720). From the start to the end the product
 * sells at that price everywhere — the card, the product page, the listing, the cart, the checkout, the engine's
 * on-sale test, the campaigns answer — and at the end it goes back by itself. These tests hold every one of those,
 * on a MOVED CLOCK at the start and end seconds, and measure what a shopper pays with Black1000, a wheel code and a
 * free-shipping code on top. Every subject is derived from the data, never named.
 */

const CPR_PRICE = 3000.0;
const CPR_SALE = 2150.0;
const CPR_COST = 1200.0;
const CPR_SHIP = 85.0;

beforeEach(function () {
    withoutMiddleware(ThrottleRequests::class);
    Mail::fake();
    config(['notifications.send.inline' => false]);
    DB::table('storefront_campaigns')->delete();
    CampaignPrices::forget(1);
    PromotionFixture::moneyRewards(true, 1);
    // the copy's own offers stay out of the sums; the wheel's prize rules stay as they are (a spin reuses its rule)
    DB::table('promotion_rules')->where('is_active', 1)->where(fn (Builder $q) => $q->whereNull('issuer')->orWhere('issuer', '<>', 'wheel'))->update(['is_active' => 0]);
    DB::table('shipping_cities')->where('id', cprCity())->update(['shipping_cost' => number_format(CPR_SHIP, 2, '.', '')]);
    app(StorefrontCache::class)->flush(1);
});

afterEach(function () {
    Carbon::setTestNow();
});

function cprCity(): int
{
    return T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));
}

/**
 * A visible, in-stock product without variants, priced 3,000 / 2,150 / cost 1,200 on storefront 1 — a watch with a
 * brand, a product of a category, or any. Returns id, slug, brand.
 *
 * @return array{id: int, slug: string, brand: int|null}
 */
function cprProduct(int $nth = 0, ?string $kind = null, ?float $sale = CPR_SALE, float $price = CPR_PRICE): array
{
    $q = DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1))
        ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.stock_express', '>=', 3)
        ->whereNotExists(fn (Builder $s) => $s->select(DB::raw('1'))->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id'));
    if ($kind === 'watch') {
        $q->where('p.family', 'watch')->whereNotNull('p.brand_id');
    } elseif ($kind !== null) {
        $path = T::str(DB::table('storefront_categories')->where('id', T::int(WheelPrizes::seededCategory(1, $kind)))->value('path'));
        $q->whereExists(fn (Builder $s) => $s->select(DB::raw('1'))->from('storefront_category_product as scp')
            ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
            ->whereColumn('scp.product_id', 'p.id')->where('c.path', 'like', $path.'%'));
    }
    $row = Row::cast(T::row($q->orderBy('p.id')->skip($nth)->first(['p.id', 'sp.slug', 'p.brand_id'])));
    $id = Row::int($row, 'id');
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $id)->update([
        'effective_price' => number_format($price, 2, '.', ''), 'effective_sale_price' => $sale === null ? null : number_format($sale, 2, '.', ''),
    ]);
    DB::table('catalog_products')->where('id', $id)->update(['purchase_price' => number_format(CPR_COST, 2, '.', '')]);
    app(InventoryService::class)->set(StockTarget::product($id), 'express', 5, 'adjustment', note: 'campaign price test');
    app(StorefrontCache::class)->flush(1);

    return ['id' => $id, 'slug' => Row::str($row, 'slug'), 'brand' => Row::nint($row, 'brand_id')];
}

/**
 * A campaign through the WRITER, with prices.
 *
 * @param  list<int>  $ids
 * @param  array<int, array{mode: string, value: float}>  $prices
 */
function cprCampaign(array $ids, array $prices, string $presentation = 'hot_deals', ?Carbon $start = null, ?Carbon $end = null): int
{
    return CampaignWriter::create(1, [
        'name' => 'Price test '.$presentation,
        'presentation' => $presentation,
        'starts_at' => ($start ?? now()->subHour())->format('Y-m-d H:i:00'),
        'ends_at' => ($end ?? now()->addDay())->format('Y-m-d H:i:00'),
        'is_active' => true,
        'product_ids' => $ids,
        'prices' => $prices,
        'confirm_prices' => true,
    ], null);
}

/** @return array<mixed> the product page's price object */
function cprPagePrice(string $slug): array
{
    return T::arr(getJson('/api/v2/watchizer/products/'.$slug)->assertOk()->json('product.price'));
}

/** What the v2 cart charges for one unit of the product, read fresh as the cart page and the checkout read it. */
function cprCartUnit(int $productId, ?string $token = null): float
{
    $token ??= (string) Str::uuid();
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $productId, 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token])->assertOk();
    $items = T::rows(getJson('/api/v2/watchizer/cart', ['X-Guest-Token' => $token])->assertOk()->json('data.items'));

    return T::float($items[0]['unit_price'] ?? null);
}

/**
 * Places a v2 guest order for one unit; returns the response (the caller asserts its status).
 *
 * @return TestResponse<Response>
 */
function cprOrder(int $productId, float $goods, ?string $coupon = null, ?string $phone = null)
{
    $token = (string) Str::uuid();
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $productId, 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token])->assertOk();
    $body = [
        'address_line' => '12 Campaign Street, Cairo', 'shipping_city_id' => cprCity(), 'phone' => $phone ?? '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'guest_name' => 'Campaign Buyer', 'guest_email' => 'campaign-'.Str::random(6).'@example.test', 'payment_method' => 'cash',
        'total' => number_format($goods + CPR_SHIP, 2, '.', ''),      // goods + delivery, BEFORE any discount (the price/total contract)
    ] + ($coupon === null ? [] : ['coupon' => $coupon]);

    return postJson('/api/v2/watchizer/orders', $body, ['X-Guest-Token' => $token]);
}

/** @return array{discount: float, total: float, lines: list<float>} what an order charged */
function cprCharged(string $orderNumber): array
{
    $order = Row::cast(T::row(DB::table('orders')->where('order_number', $orderNumber)->first(['id', 'total_price_for_order'])));
    $lines = [];
    foreach (DB::table('order_items')->where('order_id', Row::int($order, 'id'))->where('is_reward', 0)->pluck('piece_price') as $p) {
        $lines[] = T::float($p);
    }
    $discount = (float) DB::table('promotion_order_discounts')->where('order_id', Row::int($order, 'id'))->sum('amount');

    return ['discount' => round($discount, 2), 'total' => (float) Row::money($order, 'total_price_for_order'), 'lines' => $lines];
}

// ── 1. the price itself ─────────────────────────────────────────────────────────────────────────────────

it('sells at the CAMPAIGN PRICE everywhere while the campaign is live: the card, the page, the cart, the campaigns answer; percentages off the current price', function () {
    $fixed = cprProduct(0);
    $pct = cprProduct(1);
    $none = cprProduct(2);
    cprCampaign([$fixed['id'], $pct['id'], $none['id']], [
        $fixed['id'] => ['mode' => 'price', 'value' => 1500.0],
        $pct['id'] => ['mode' => 'percent', 'value' => 20.0],
    ]);

    $page = cprPagePrice($fixed['slug']);
    expect(T::float($page['amount']))->toBe(CPR_PRICE)->and(T::float($page['sale_amount']))->toBe(1500.0)->and($page['discount_pct'])->toBe(50)
        ->and(T::float(T::arr($page['regular'])['sale_amount']))->toBe(CPR_SALE)->and(T::arr($page['campaign'])['presentation'])->toBe('hot_deals');
    // 20 % on top of the current 2,150 → 1,720; "off the original" 3,000 → 43 %
    $pagePct = cprPagePrice($pct['slug']);
    expect(T::float($pagePct['sale_amount']))->toBe(1720.0)->and($pagePct['discount_pct'])->toBe(43);
    // a product left empty keeps its normal sale price during the campaign
    $pageNone = cprPagePrice($none['slug']);
    expect(T::float($pageNone['sale_amount']))->toBe(CPR_SALE)->and($pageNone)->not->toHaveKey('regular');
    // the cart (and so the checkout) charges it
    expect(cprCartUnit($fixed['id']))->toBe(1500.0)->and(cprCartUnit($pct['id']))->toBe(1720.0)->and(cprCartUnit($none['id']))->toBe(CPR_SALE);
    // the campaigns answer names the live prices the storefront follows to the second
    $prices = T::arr(getJson('/api/v2/watchizer/campaigns')->assertOk()->json('data.prices'));
    expect(array_keys($prices))->toEqualCanonicalizing([(string) $fixed['id'], (string) $pct['id']])
        ->and(T::float(T::arr($prices[(string) $fixed['id']])['sale_amount']))->toBe(1500.0);
    // nothing in the product's own price fields changed
    expect(T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $fixed['id'])->value('effective_sale_price')))->toBe('2150.00');
});

it('starts and ends at the EXACT second on a moved clock: the page, the cart and the campaigns answer flip together, and a held order total from before the end is refused, never charged at another price', function () {
    $p = cprProduct(0);
    $start = now()->addHour()->startOfMinute();
    $end = (clone $start)->addHour();
    cprCampaign([$p['id']], [$p['id'] => ['mode' => 'price', 'value' => 1500.0]], 'flash_sale', $start, $end);

    $at = function (Carbon $moment) use ($p): array {
        Carbon::setTestNow($moment);

        return [
            'page' => T::float(cprPagePrice($p['slug'])['sale_amount']),
            'cart' => cprCartUnit($p['id']),
            'answer' => array_key_exists((string) $p['id'], T::arr(getJson('/api/v2/watchizer/campaigns')->assertOk()->json('data.prices'))),
        ];
    };
    expect($at((clone $start)->subSecond()))->toBe(['page' => CPR_SALE, 'cart' => CPR_SALE, 'answer' => false])
        ->and($at($start))->toBe(['page' => 1500.0, 'cart' => 1500.0, 'answer' => true])
        ->and($at((clone $end)->subSecond()))->toBe(['page' => 1500.0, 'cart' => 1500.0, 'answer' => true])
        ->and($at($end))->toBe(['page' => CPR_SALE, 'cart' => CPR_SALE, 'answer' => false]);

    // a shopper who saw 1,500 in the cart a second before the end and submits at the end: refused by the total guard
    // (the server prices 2,150 now) — told, never charged a price they did not see
    Carbon::setTestNow($end);
    $late = cprOrder($p['id'], 1500.0);
    expect($late->status())->toBe(422);
});

it('keeps the price an order was placed at after the campaign ends', function () {
    $p = cprProduct(0);
    $end = now()->addMinutes(30)->startOfMinute();
    cprCampaign([$p['id']], [$p['id'] => ['mode' => 'price', 'value' => 1500.0]], 'hot_deals', now()->subHour(), $end);
    $placed = cprOrder($p['id'], 1500.0);
    Assert::assertSame(201, $placed->status(), (string) $placed->getContent());
    $number = T::str($placed->json('data.order_number'));
    Carbon::setTestNow((clone $end)->addMinute());
    $charged = cprCharged($number);
    expect($charged['lines'])->toBe([1500.0])->and($charged['total'])->toBe(1500.0 + CPR_SHIP)
        ->and(cprCartUnit($p['id']))->toBe(CPR_SALE);                                     // a new cart is back at the normal price
});

it('forgets a product page under EVERY campaign state, so an edit during a campaign is not undone at its end', function () {
    $p = cprProduct(0);
    $start = now()->addHour()->startOfMinute();
    $end = (clone $start)->addMinutes(30);
    cprCampaign([$p['id']], [$p['id'] => ['mode' => 'price', 'value' => 1500.0]], 'flash_sale', $start, $end);
    $cache = app(StorefrontCache::class);
    $held = fn (string $built): mixed => $cache->remember(1, 'product', (string) $p['id'], 3600, fn () => $built);

    Carbon::setTestNow((clone $start)->subMinute());
    expect($held('before the campaign'))->toBe('before the campaign');      // held for an hour, past the end
    Carbon::setTestNow((clone $start)->addMinute());
    expect($held('during'))->toBe('during');
    $cache->forgetProduct(1, $p['id']);                                      // the product edited during the campaign
    expect($held('during, edited'))->toBe('during, edited');
    Carbon::setTestNow((clone $end)->addMinute());
    expect($held('after, edited'))->toBe('after, edited');                  // not the copy from before the edit
});

it('lets the Flash Sale price win over the Hot Deals price; a product priced in only one takes that one', function () {
    $both = cprProduct(0);
    $hotOnly = cprProduct(1);
    cprCampaign([$both['id'], $hotOnly['id']], [$both['id'] => ['mode' => 'price', 'value' => 1800.0], $hotOnly['id'] => ['mode' => 'price', 'value' => 1700.0]], 'hot_deals');
    cprCampaign([$both['id'], $hotOnly['id']], [$both['id'] => ['mode' => 'price', 'value' => 1600.0]], 'flash_sale');
    expect(T::float(cprPagePrice($both['slug'])['sale_amount']))->toBe(1600.0)->and(cprCartUnit($both['id']))->toBe(1600.0)
        ->and(T::float(cprPagePrice($hotOnly['slug'])['sale_amount']))->toBe(1700.0);
});

it('filters, sorts and bounds the listing by the campaign price; a product under one is ON SALE for the listing and the engine', function () {
    $cheapNow = cprProduct(0, null, null);                                            // 3,000, no sale of its own, 1,500 in the campaign
    $other = cprProduct(1, null, null, 2000.0);                                       // 2,000, no sale, no campaign price
    cprCampaign([$cheapNow['id'], $other['id']], [$cheapNow['id'] => ['mode' => 'price', 'value' => 1500.0]], 'flash_sale');
    $ids = fn (string $query): array => array_map(fn (array $c): int => T::int($c['id']), T::rows(getJson('/api/v2/watchizer/products?campaign=flash_sale&per_page=96&'.$query)->assertOk()->json('data')));
    expect($ids('sort=price_asc'))->toBe([$cheapNow['id'], $other['id']])
        ->and($ids('sort=price_desc'))->toBe([$other['id'], $cheapNow['id']])
        ->and($ids('price_min=1400&price_max=1600'))->toBe([$cheapNow['id']])
        ->and($ids('on_sale=1'))->toBe([$cheapNow['id']])
        ->and($ids('min_discount=50'))->toBe([$cheapNow['id']]);
    $facets = T::arr(getJson('/api/v2/watchizer/products?campaign=flash_sale&facets=1')->assertOk()->json('facets.price'));
    expect(T::float($facets['min']))->toBe(1500.0)->and(T::float($facets['max']))->toBe(2000.0);
});

it('sorts EVERY product by the price the shopper pays — a valid sale price too, not only a campaign price', function () {
    $saleCheap = cprProduct(0, null, 1000.0, 3000.0);                                 // 3,000, on sale at 1,000: pays 1,000
    $plain = cprProduct(1, null, null, 2000.0);                                       // 2,000, no sale
    $middle = cprProduct(2, null, null, 1500.0);                                      // 1,500, no sale
    cprCampaign([$saleCheap['id'], $plain['id'], $middle['id']], [], 'hot_deals');    // a campaign WITHOUT prices: the list only
    $ids = fn (string $query): array => array_map(fn (array $c): int => T::int($c['id']), T::rows(getJson('/api/v2/watchizer/products?campaign=hot_deals&per_page=96&'.$query)->assertOk()->json('data')));
    expect($ids('sort=price_asc'))->toBe([$saleCheap['id'], $middle['id'], $plain['id']])
        ->and($ids('sort=price_desc'))->toBe([$plain['id'], $middle['id'], $saleCheap['id']]);
});

// ── 2. the dashboard: validation, confirmations, the activity log ──────────────────────────────────────

it('refuses a campaign price that is not lower, asks to confirm one under the purchase price or above 50 % extra, and logs who set which price', function () {
    $p = cprProduct(0);
    $q = cprProduct(1);
    actingAs(Staff::admin());
    $payload = fn (array $prices, bool $confirm = false): array => [
        'name' => 'Priced', 'presentation' => 'hot_deals', 'starts_at' => now()->subHour()->format('Y-m-d\TH:i'), 'ends_at' => now()->addDay()->format('Y-m-d\TH:i'),
        'is_active' => true, 'product_ids' => [$p['id'], $q['id']], 'prices' => $prices, 'confirm_prices' => $confirm,
    ];
    // not lower than the current 2,150: refused on the product's own field
    post('/manage/storefronts/1/campaigns', $payload([$p['id'] => ['mode' => 'price', 'value' => 2150]]))->assertSessionHasErrors('prices.'.$p['id']);
    // a percentage above the ceiling: refused
    post('/manage/storefronts/1/campaigns', $payload([$p['id'] => ['mode' => 'percent', 'value' => 95]]))->assertSessionHasErrors('prices.'.$p['id']);
    // under the purchase price (1,200) or more than 50 % extra: ASKED, not refused
    post('/manage/storefronts/1/campaigns', $payload([$p['id'] => ['mode' => 'price', 'value' => 1000], $q['id'] => ['mode' => 'percent', 'value' => 60]]))->assertSessionHasErrors('confirm_prices');
    expect(DB::table('storefront_campaigns')->count())->toBe(0);
    post('/manage/storefronts/1/campaigns', $payload([$p['id'] => ['mode' => 'price', 'value' => 1000], $q['id'] => ['mode' => 'percent', 'value' => 60]], true))->assertSessionHasNoErrors();
    $id = T::int(DB::table('storefront_campaigns')->value('id'));
    expect(CampaignWriter::prices($id))->toBe([$p['id'] => ['mode' => 'price', 'value' => 1000.0], $q['id'] => ['mode' => 'percent', 'value' => 60.0]]);

    // a change of price, by whom: the activity log carries the before and the after
    put('/manage/storefronts/1/campaigns/'.$id, $payload([$p['id'] => ['mode' => 'price', 'value' => 1500]], true))->assertSessionHasNoErrors();
    expect(CampaignWriter::prices($id))->toBe([$p['id'] => ['mode' => 'price', 'value' => 1500.0]]);       // the other product's price was cleared
    $log = Row::cast(T::row(DB::table('core_activity_log')->where('subject_type', 'storefront_campaigns')->where('subject_id', $id)->where('action', 'updated')->orderByDesc('id')->first()));
    expect(T::str(json_encode($log)))->toContain('prices')->and(Row::nint($log, 'user_id'))->toBe(Staff::admin()->getKey());
    // the table's facts: cost only for whom the product form shows cost
    $facts = T::arr(getJson('/manage/storefronts/1/campaigns/price-facts?ids='.$p['id'])->assertOk()->json('data.'.$p['id']));
    expect(T::float($facts['current']))->toBe(CPR_SALE)->and(T::float($facts['purchase']))->toBe(CPR_COST);
    // removing the product from the campaign takes its price away at once
    put('/manage/storefronts/1/campaigns/'.$id, ['product_ids' => [$q['id']]] + $payload([], true))->assertSessionHasNoErrors();
    expect(T::float(cprPagePrice($p['slug'])['sale_amount']))->toBe(CPR_SALE);
});

// ── 3. what a shopper pays with the other offers on top ────────────────────────────────────────────────

/** The wheel as the client's (empty gift lists), switched on. */
function cprWheel(): void
{
    Wheel::setEnabled(1, true);
    Wheel::setTerms(1, Wheel::DEFAULT_TTL_HOURS, null);
    $segments = [];
    foreach (WheelPrizes::DEFAULTS as $prize) {
        $segments[] = ['label_en' => $prize['label_en'], 'label_ar' => $prize['label_ar'], 'type' => $prize['type'], 'value' => $prize['value'], 'odds' => $prize['odds'],
            'color' => $prize['color'], 'cap' => $prize['cap'], 'scope_category_id' => WheelPrizes::seededCategory(1, $prize['scope_slug']),
            'min_quantity' => $prize['min_quantity'], 'apply_mode' => $prize['apply_mode'], 'gift_product_ids' => []];
    }
    WheelPrizeWriter::save(1, $segments, ['ttl_hours' => Wheel::DEFAULT_TTL_HOURS, 'min_order' => null], null);
}

/**
 * A fresh guest cart holding one unit of each product; returns its token.
 *
 * @param  list<int>  $productIds
 */
function cprCartWith(array $productIds): string
{
    $token = (string) Str::uuid();
    foreach ($productIds as $id) {
        postJson('/api/v2/watchizer/cart/items', ['product_id' => $id, 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token])->assertOk();
    }

    return $token;
}

/**
 * The cart's answer for a typed code, as the cart page and the checkout read it.
 *
 * @return array<mixed>
 */
function cprCoupon(string $token, string $code, ?string $phone = null): array
{
    return T::arr(getJson('/api/v2/watchizer/cart?coupon='.urlencode($code).($phone === null ? '' : '&phone='.$phone), ['X-Guest-Token' => $token])
        ->assertOk()->json('data.totals.promotion.coupon'));
}

/**
 * A v2 guest order of one unit of each product, with an optional code.
 *
 * @param  list<int>  $productIds
 * @return TestResponse<Response>
 */
function cprOrderOf(array $productIds, float $goods, ?string $coupon = null, ?string $phone = null)
{
    $token = cprCartWith($productIds);
    $body = [
        'address_line' => '12 Campaign Street, Cairo', 'shipping_city_id' => cprCity(), 'phone' => $phone ?? '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
        'guest_name' => 'Campaign Buyer', 'guest_email' => 'campaign-'.Str::random(6).'@example.test', 'payment_method' => 'cash',
        'total' => number_format($goods + CPR_SHIP, 2, '.', ''),
    ] + ($coupon === null ? [] : ['coupon' => $coupon]);

    return postJson('/api/v2/watchizer/orders', $body, ['X-Guest-Token' => $token]);
}

const CPR_ON_OFFER = [
    'en' => 'Discount codes can\'t be combined with items already on offer (Hot Deals / Flash Sale).',
    // Michael's words; the Latin names isolated and unbroken — invisible, for the Arabic page
    'ar' => "لا يمكن استخدام كود الخصم مع منتجات عليها عرض (\u{2066}Hot\u{A0}Deals / Flash\u{A0}Sale\u{2069}).",
];

/**
 * A code refused for the whole order: told at the code field, refused again at the order, nothing written.
 *
 * @param  list<int>  $productIds
 */
function cprRefused(array $productIds, float $goods, string $code, ?string $phone = null): void
{
    $coupon = cprCoupon(cprCartWith($productIds), $code, $phone);
    expect($coupon['status'])->toBe(CouponStatus::ON_OFFER)->and($coupon['messages'])->toBe(CPR_ON_OFFER);
    $orders = (int) DB::table('orders')->count();
    $res = cprOrderOf($productIds, $goods, $code, $phone);
    Assert::assertSame(422, $res->status(), (string) $res->getContent());
    expect($res->json('status'))->toBe(CouponStatus::ON_OFFER)
        ->and(T::arr($res->json('messages')))->toBe(CPR_ON_OFFER)
        ->and((int) DB::table('orders')->count())->toBe($orders);
}

it('ONE OFFER PER ORDER: an item at a campaign price takes no other offer — no automatic promotion, no discount code, no wheel, invite or free-shipping code; the shopper pays 1,500 + delivery', function () {
    cprWheel();
    $watch = cprProduct(0, 'watch');
    $wallet = cprProduct(0, 'wallets');
    cprCampaign([$watch['id'], $wallet['id']], [$watch['id'] => ['mode' => 'price', 'value' => 1500.0], $wallet['id'] => ['mode' => 'price', 'value' => 1500.0]]);
    $paysCampaignPrice = function (TestResponse $res): void {
        Assert::assertSame(201, $res->status(), (string) $res->getContent());
        expect(cprCharged(T::str($res->json('data.order_number'))))->toBe(['discount' => 0.0, 'total' => 1500.0 + CPR_SHIP, 'lines' => [1500.0]]);
    };

    // (a) Black1000 as an AUTOMATIC promotion — EGP 1,000 off each watch of the brand — "include sale items" ON, then OFF
    $black = PromotionFixture::rule([], [PromotionFixture::fixedDiscount(1000)], [1]);
    DB::table('promotion_rules')->where('id', $black)->update(['apply_per' => PromotionMoney::PER_ITEM, 'include_sale_items' => 1, 'label_en' => 'Black Friday', 'label_ar' => 'الجمعة البيضاء']);
    DB::table('promotion_rule_scopes')->insert([
        ['promotion_rule_id' => $black, 'role' => 'target', 'dimension' => 'brand', 'value_id' => $watch['brand'], 'value' => null, 'created_at' => now(), 'updated_at' => now()],
        ['promotion_rule_id' => $black, 'role' => 'target', 'dimension' => 'family', 'value_id' => null, 'value' => 'watch', 'created_at' => now(), 'updated_at' => now()],
    ]);
    $paysCampaignPrice(cprOrder($watch['id'], 1500.0));
    DB::table('promotion_rules')->where('id', $black)->update(['include_sale_items' => 0]);
    $paysCampaignPrice(cprOrder($watch['id'], 1500.0));

    // (b) the same rule as a typed CODE — "include sale items" ON and OFF: refused for the whole order
    foreach ([1, 0] as $include) {
        DB::table('promotion_rules')->where('id', $black)->update(['code' => 'BLACK1000', 'include_sale_items' => $include]);
        cprRefused([$watch['id']], 1500.0, 'BLACK1000');
    }
    DB::table('promotion_rules')->where('id', $black)->update(['is_active' => 0]);

    // (c) a wheel code — 30 % off wallets — on the wallet's campaign price
    $phone = '010'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $wallets = Wheel::present(Wheel::spin(1, $phone, null, '203.0.113.9', [], 'en', 70)['spin']);
    expect(T::arr($wallets['prize'])['label'])->toBe(['en' => '30% off wallets', 'ar' => 'خصم ٣٠٪ على المحافظ']);
    cprRefused([$wallet['id']], 1500.0, T::str($wallets['code']), $phone);

    // (d) a free-shipping code (the wheel's) on the watch
    $phone2 = '011'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $ship = Wheel::present(Wheel::spin(1, $phone2, null, '203.0.113.10', [], 'en', 10)['spin']);
    cprRefused([$watch['id']], 1500.0, T::str($ship['code']), $phone2);

    // (e) an invite's FRIEND code
    $winner = '012'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    Wheel::spin(1, $winner, null, '203.0.113.11', [], 'en', 85);
    $invite = T::str(DB::table(Wheel::TABLE)->where('phone_key', substr($winner, -10))->where('campaign', Wheel::campaign(1))->value('invite_code'));
    expect($invite)->toStartWith('FRIEND-');
    expect(cprCoupon(cprCartWith([$watch['id']]), $invite)['status'])->toBe(CouponStatus::ON_OFFER);

    // and without any code: the campaign price, nothing else (the automatic rule is off now)
    $paysCampaignPrice(cprOrder($watch['id'], 1500.0));
});

it('ONE OFFER PER ORDER: a MIXED cart — one campaign item and one normal item — refuses the code for the whole order; without the campaign item the code applies', function () {
    $onOffer = cprProduct(0, null, null);                                             // 3,000 → 1,500 in the campaign
    $normal = cprProduct(1, null, null, 2000.0);                                      // 2,000, no campaign price
    cprCampaign([$onOffer['id']], [$onOffer['id'] => ['mode' => 'price', 'value' => 1500.0]]);
    $code = PromotionFixture::rule([], [PromotionFixture::percentDiscount(10)], [1]);
    DB::table('promotion_rules')->where('id', $code)->update(['code' => 'TENOFF', 'include_sale_items' => 1]);

    cprRefused([$onOffer['id'], $normal['id']], 3500.0, 'TENOFF');

    // the normal item alone: the code is an ordinary code again
    expect(cprCoupon(cprCartWith([$normal['id']]), 'TENOFF')['status'])->toBe(CouponStatus::APPLIED);
    $res = cprOrderOf([$normal['id']], 2000.0, 'TENOFF');
    Assert::assertSame(201, $res->status(), (string) $res->getContent());
    expect(cprCharged(T::str($res->json('data.order_number'))))->toBe(['discount' => 200.0, 'total' => 1800.0 + CPR_SHIP, 'lines' => [2000.0]]);
});

it('ONE OFFER PER ORDER: a code applied BEFORE the campaign starts is refused at the order once it has started, and works again after the campaign ends', function () {
    $p = cprProduct(0, null, null);
    $start = now()->addHour()->startOfMinute();
    $end = (clone $start)->addHour();
    cprCampaign([$p['id']], [$p['id'] => ['mode' => 'price', 'value' => 1500.0]], 'flash_sale', $start, $end);
    $code = PromotionFixture::rule([], [PromotionFixture::percentDiscount(10)], [1], 0, true, now()->subDay()->format('Y-m-d H:i:s'), now()->addDays(3)->format('Y-m-d H:i:s'));
    DB::table('promotion_rules')->where('id', $code)->update(['code' => 'TENOFF', 'include_sale_items' => 1]);

    // before the start: the code applies to the product's own price (3,000)
    Carbon::setTestNow((clone $start)->subMinute());
    $token = cprCartWith([$p['id']]);
    expect(cprCoupon($token, 'TENOFF')['status'])->toBe(CouponStatus::APPLIED);

    // the campaign starts while the code sits in the cart: the order is refused, nothing charged at a stacked price
    Carbon::setTestNow((clone $start)->addMinute());
    expect(cprCoupon($token, 'TENOFF')['status'])->toBe(CouponStatus::ON_OFFER);
    $orders = (int) DB::table('orders')->count();
    $res = postJson('/api/v2/watchizer/orders', [
        'address_line' => '12 Campaign Street, Cairo', 'shipping_city_id' => cprCity(), 'phone' => '01012345670',
        'guest_name' => 'Campaign Buyer', 'guest_email' => 'campaign-'.Str::random(6).'@example.test', 'payment_method' => 'cash',
        'total' => number_format(1500.0 + CPR_SHIP, 2, '.', ''), 'coupon' => 'TENOFF',
    ], ['X-Guest-Token' => $token]);
    Assert::assertSame(422, $res->status(), (string) $res->getContent());
    expect($res->json('status'))->toBe(CouponStatus::ON_OFFER)->and((int) DB::table('orders')->count())->toBe($orders);

    // the campaign ends with the code still in the cart: recomputed normally, the code applies again
    Carbon::setTestNow((clone $end)->addMinute());
    expect(cprCoupon($token, 'TENOFF')['status'])->toBe(CouponStatus::APPLIED);
});

it('ONE OFFER PER ORDER: a regular sale price (the product\'s own strikethrough) is NOT an offer — a code applies to it as before', function () {
    $struck = cprProduct(0, null, CPR_SALE);                                          // 3,000 struck through, 2,150 — no campaign
    $code = PromotionFixture::rule([], [PromotionFixture::percentDiscount(10)], [1]);
    DB::table('promotion_rules')->where('id', $code)->update(['code' => 'TENOFF', 'include_sale_items' => 1]);
    expect(cprCoupon(cprCartWith([$struck['id']]), 'TENOFF')['status'])->toBe(CouponStatus::APPLIED);
    $res = cprOrderOf([$struck['id']], CPR_SALE, 'TENOFF');
    Assert::assertSame(201, $res->status(), (string) $res->getContent());
    expect(cprCharged(T::str($res->json('data.order_number'))))->toBe(['discount' => 215.0, 'total' => CPR_SALE - 215.0 + CPR_SHIP, 'lines' => [CPR_SALE]]);
});
