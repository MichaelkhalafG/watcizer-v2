<?php

use App\Compat\CompatCart;
use App\Domain\Orders\OrderTotals;
use App\Domain\Shipping\FreeShipping;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PaymentFixture;
use Tests\Support\PromotionFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withoutMiddleware;

/*
 * FREE SHIPPING OVER A THRESHOLD, per storefront (2026-10-29) — App\Domain\Shipping\FreeShipping, through v2's
 * checkout end to end, rolled back with each test: just under, exactly at and above the threshold; switched off;
 * the default when the storefront names none; the cart's own answer; a promotion that discounts the goods; cash and
 * card — and the amount sent to Paymob is the stored order total.
 *
 * The threshold is SET per test relative to the product's real price (never assumed), on storefront 1, and the
 * governorate's price is set to a known figure — both inside the test transaction.
 */

const FS_CITY_COST = 85.0;

beforeEach(function () {
    withoutMiddleware(ThrottleRequests::class);
    DB::table('shipping_cities')->where('id', fsCity())->update(['shipping_cost' => number_format(FS_CITY_COST, 2, '.', '')]);
});

function fsCity(): int
{
    return T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));
}

/**
 * A visible, in-stock storefront-1 product with no variants, and its charged price.
 *
 * @return array{id: int, price: float}
 */
function fsProduct(int $nth = 0): array
{
    $row = T::row(DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
        ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.stock_express', '>=', 2)
        ->whereNotExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('catalog_product_variants as v')->whereColumn('v.product_id', 'sp.product_id'))
        ->orderBy('sp.product_id')->skip($nth)
        ->first(['sp.product_id', 'sp.effective_price', 'sp.effective_sale_price']));

    return ['id' => Row::int($row, 'product_id'), 'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price'))];
}

/** Storefront 1's threshold: a figure, 0 (off), or null (the key absent: the default). */
function fsThreshold(float|int|null $over): void
{
    $raw = DB::table('storefronts')->where('id', 1)->value('settings');
    $settings = is_string($raw) ? (array) json_decode($raw, true) : [];
    if ($over === null) {
        unset($settings['shipping']);
    } else {
        $settings['shipping'] = ['free_over' => $over];
    }
    DB::table('storefronts')->where('id', 1)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    // as the dashboard's save does: the cart reads the threshold from the resolved (cached) storefront row
    app(StorefrontCache::class)->forgetStorefront(1, T::str(DB::table('storefronts')->where('id', 1)->value('code')));
}

/**
 * A guest cart with one unit of the product; the token.
 *
 * @param  array{id: int, price: float}  $product
 */
function fsCart(array $product): string
{
    $token = (string) Str::uuid();
    postJson('/api/v2/watchizer/cart/items', ['product_id' => $product['id'], 'bucket' => 'Express', 'quantity' => 1], ['X-Guest-Token' => $token])->assertOk();

    return $token;
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function fsBody(float $total, string $method = 'cash', array $extra = []): array
{
    return $extra + ['total' => $total, 'payment_method' => $method, 'address_line' => '3 Threshold Street, Dokki', 'shipping_city_id' => fsCity(), 'phone' => '01022223333', 'guest_name' => 'Threshold Buyer', 'guest_email' => 'threshold@example.invalid'];
}

function fsOrder(string $number): stdClass
{
    return T::row(DB::table('orders')->where('order_number', $number)->first(['id', 'total_price_for_order', 'free_shipping_over', 'storefront_id']));
}

it('charges delivery JUST UNDER the threshold', function () {
    $p = fsProduct();
    fsThreshold(round($p['price'] + 0.01, 2));
    $token = fsCart($p);

    $number = T::str(postJson('/api/v2/watchizer/orders', fsBody(round($p['price'] + FS_CITY_COST, 2)), ['X-Guest-Token' => $token])->assertCreated()->json('data.order_number'));
    $order = fsOrder($number);
    $totals = OrderTotals::of(T::int($order->id));

    expect(round(T::float($order->total_price_for_order), 2))->toBe(round($p['price'] + FS_CITY_COST, 2))
        ->and($order->free_shipping_over)->toBeNull()
        ->and($totals?->freeShipping)->toBeFalse()
        ->and($totals?->shipping)->toBe(FS_CITY_COST)
        ->and($totals?->unexplained())->toBe(0.0);
});

it('ships free EXACTLY AT the threshold, records it, and refuses the old total', function () {
    $p = fsProduct();
    fsThreshold($p['price']);
    $token = fsCart($p);

    // the storefront must send the waived total: the old one (goods + delivery) is a mismatch, answered with the right one
    $refused = postJson('/api/v2/watchizer/orders', fsBody(round($p['price'] + FS_CITY_COST, 2)), ['X-Guest-Token' => $token]);
    expect($refused->status())->toBe(422)->and($refused->json('error'))->toBe('total_mismatch')
        ->and(T::float($refused->json('server_total')))->toBe(round($p['price'], 2));

    $number = T::str(postJson('/api/v2/watchizer/orders', fsBody($p['price']), ['X-Guest-Token' => $token])->assertCreated()->json('data.order_number'));
    $order = fsOrder($number);
    $totals = OrderTotals::of(T::int($order->id));

    expect(round(T::float($order->total_price_for_order), 2))->toBe(round($p['price'], 2))
        ->and(round(T::float($order->free_shipping_over), 2))->toBe(round($p['price'], 2))
        ->and($totals?->freeShipping)->toBeTrue()
        ->and($totals?->shipping)->toBe(0.0)
        ->and($totals?->expectedShipping)->toBe(0.0)
        ->and($totals?->unexplained())->toBe(0.0);
});

it('ships free ABOVE the threshold', function () {
    $p = fsProduct();
    fsThreshold(round($p['price'] - 1, 2));
    $token = fsCart($p);

    $number = T::str(postJson('/api/v2/watchizer/orders', fsBody($p['price']), ['X-Guest-Token' => $token])->assertCreated()->json('data.order_number'));
    expect(round(T::float(fsOrder($number)->total_price_for_order), 2))->toBe(round($p['price'], 2));
});

it('charges delivery when the storefront switches it OFF (0), whatever the amount', function () {
    $p = fsProduct();
    fsThreshold(0);
    $token = fsCart($p);

    expect(FreeShipping::threshold(1))->toBeNull();
    $number = T::str(postJson('/api/v2/watchizer/orders', fsBody(round($p['price'] + FS_CITY_COST, 2)), ['X-Guest-Token' => $token])->assertCreated()->json('data.order_number'));
    expect(fsOrder($number)->free_shipping_over)->toBeNull();
});

it('uses the client\'s 10,000 when a storefront names no threshold, and compares in cents', function () {
    fsThreshold(null);
    expect(FreeShipping::threshold(1))->toBe(10000.0)
        ->and(FreeShipping::applies(10000.0, 9999.99))->toBeFalse()
        ->and(FreeShipping::applies(10000.0, 10000.0))->toBeTrue()
        ->and(FreeShipping::applies(10000.0, 0.1 + 0.2 + 9999.7))->toBeTrue()   // a float sum that lands on 10,000
        ->and(FreeShipping::applies(null, 99999.0))->toBeFalse();
});

it('tells the storefront, in the cart\'s answer, whether the cart ships free and how much more it needs', function () {
    $p = fsProduct();
    fsThreshold(round($p['price'] + 500, 2));
    $token = fsCart($p);

    $under = getJson('/api/v2/watchizer/cart', ['X-Guest-Token' => $token])->assertOk()->json('data.totals.shipping');
    expect(T::arr($under))->toMatchArray(['free' => false])
        ->and(round(T::float(T::arr($under)['remaining']), 2))->toBe(500.0)
        ->and(round(T::float(T::arr($under)['free_over']), 2))->toBe(round($p['price'] + 500, 2));

    fsThreshold(round($p['price'], 2));
    $at = T::arr(getJson('/api/v2/watchizer/cart', ['X-Guest-Token' => $token])->assertOk()->json('data.totals.shipping'));
    expect($at['free'])->toBeTrue()->and(T::float($at['remaining']))->toBe(0.0);
});

it('checks the GOODS at the prices charged, before a promotion\'s discount (the rule Michael may switch)', function () {
    $p = fsProduct();
    PromotionFixture::moneyRewards(true, 1);
    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(1)], [PromotionFixture::fixedDiscount(50)], priority: 1000);
    fsThreshold($p['price']);                 // the goods reach it; goods minus the discount do not
    $token = fsCart($p);

    $number = T::str(postJson('/api/v2/watchizer/orders', fsBody($p['price']), ['X-Guest-Token' => $token])->assertCreated()->json('data.order_number'));
    $order = fsOrder($number);
    // free delivery, AND the promotion's 50 off the goods: total = goods − 50
    expect(round(T::float($order->total_price_for_order), 2))->toBe(round($p['price'] - 50, 2))
        ->and($order->free_shipping_over)->not->toBeNull();
});

it('never waives delivery twice: a free-shipping promotion over a waived delivery gives nothing more', function () {
    $p = fsProduct();
    PromotionFixture::moneyRewards(true, 1);
    PromotionFixture::rule([PromotionFixture::subtotalAtLeast(1)], [PromotionFixture::freeShipping()], priority: 1000);
    fsThreshold($p['price']);
    $token = fsCart($p);

    $number = T::str(postJson('/api/v2/watchizer/orders', fsBody($p['price']), ['X-Guest-Token' => $token])->assertCreated()->json('data.order_number'));
    expect(round(T::float(fsOrder($number)->total_price_for_order), 2))->toBe(round($p['price'], 2));
});

it('sends Paymob the stored order total on a CARD order that ships free — and cash takes the same total', function () {
    $provider = PaymentFixture::paymob();
    DB::table('storefront_payment_providers')->where('id', $provider->getAttribute('id'))->update(['settings' => json_encode(['callback_base' => 'https://api.watchizereg.com'])]);
    Http::fake(['*/intention/' => fn (ClientRequest $r) => Http::response(['client_secret' => 'cs_fs', 'id' => 'intent_fs'], 200)]);
    $row = Row::cast(T::row(DB::table('storefront_payment_methods as m')
        ->join('storefront_payment_providers as pp', 'pp.id', '=', 'm.storefront_payment_provider_id')
        ->where('pp.storefront_id', 1)->where('pp.provider', 'paymob')->where('m.is_enabled', 1)
        ->orderBy('m.sort')->first(['m.id'])));

    $p = fsProduct(1);
    fsThreshold($p['price']);
    $card = fsCart($p);
    $res = postJson('/api/v2/watchizer/orders', fsBody($p['price'], 'card', ['payment_method_id' => Row::int($row, 'id')]), ['X-Guest-Token' => $card])->assertCreated();
    $order = fsOrder(T::str($res->json('data.order_number')));

    $sent = Http::recorded()->last();
    expect($sent)->not->toBeNull();
    $intention = T::arr(T::arr($sent)[0] instanceof ClientRequest ? T::arr($sent)[0]->data() : []);
    expect(T::int($intention['amount'] ?? 0))->toBe((int) round(T::float($order->total_price_for_order) * 100))
        ->and(round(T::float($order->total_price_for_order), 2))->toBe(round($p['price'], 2));

    $cash = fsCart($p);
    $cashNumber = T::str(postJson('/api/v2/watchizer/orders', fsBody($p['price']), ['X-Guest-Token' => $cash])->assertCreated()->json('data.order_number'));
    expect(round(T::float(fsOrder($cashNumber)->total_price_for_order), 2))->toBe(round($p['price'], 2));
});
