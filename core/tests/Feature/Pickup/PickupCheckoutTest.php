<?php

use App\Domain\Notifications\OrderEmailData;
use App\Domain\Pickup\Pickup;
use App\Domain\Pickup\PickupCheckout;
use App\Domain\Pickup\PickupStores;
use App\Domain\Promotions\CouponStatus;
use App\Domain\Promotions\PromotionMoney;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\PromotionFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

/*
 * "Pick up in store" (click & collect, 2026-11-14) — Part 2: the order path. A pickup order is written with NO address
 * and NO delivery, its reservation beside it (the store as the customer was told, a 4-digit code, the ready window the
 * slowest line decides, how it is paid), its stock held like any order; every refusal comes BEFORE anything is written
 * (the switch, the store, the phone, a payment that does not suit a pickup, the pay-at-store cap and the open-reservation
 * limit); a free-shipping code is refused with its own sentence and NOT spent; the e-mails say "pick up at".
 */

const PK_PHONE = '01012345678';

/**
 * Pickup on for Brand Fashion and its pay-at-store method enabled; the first active store and that method's id.
 *
 * @return array{store: int, method: int}
 */
function pkcSetup(): array
{
    DB::table(PickupStores::TABLE)->where('storefront_id', 2)->update(['hours_en' => 'Daily 11:00–23:00', 'hours_ar' => 'يوميًا ١١ ص – ١١ م']);
    Pickup::setEnabled(2, true, 'Brand Fashion');
    $method = T::int(DB::table('storefront_payment_methods as m')
        ->join('storefront_payment_providers as p', 'p.id', '=', 'm.storefront_payment_provider_id')
        ->where('p.storefront_id', 2)->where('p.provider', 'store')->where('m.method', 'store')->value('m.id'));
    DB::table('storefront_payment_methods')->where('id', $method)->update(['is_enabled' => true]);

    return ['store' => PickupStores::forStorefront(2, activeOnly: true)[0]['id'], 'method' => $method];
}

/** A plain product visible on the shop with at least 3 units in the bucket's stock column. */
function pkcProduct(int $storefrontId, string $bucket = 'Express'): int
{
    $column = $bucket === 'Express' ? 'p.stock_express' : 'p.stock_market';

    return T::int(DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId)->where('sp.is_visible', '=', 1))
        ->where('p.is_active', 1)->whereNull('p.deleted_at')->where($column, '>=', 3)
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id')->where('v.is_active', 1))
        ->orderBy('p.id')->value('p.id'));
}

/**
 * A guest's cart with the given lines; the token and the SUBTOTAL (a pickup order's whole total).
 *
 * @param  list<array<string, int|string>>  $lines
 * @return array{token: string, subtotal: float}
 */
function pkcCart(string $code, array $lines): array
{
    $token = null;
    $res = null;
    foreach ($lines as $line) {
        $res = postJson("/api/v2/{$code}/cart/items", $line + ['quantity' => 1], $token === null ? [] : ['X-Guest-Token' => $token])->assertOk();
        $token ??= T::str($res->headers->get('X-Guest-Token'));
    }

    return ['token' => T::str($token), 'subtotal' => round(T::float($res?->json('data.totals.subtotal')), 2)];
}

/**
 * @param  array{store: int, method: int}  $setup
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function pkcBody(array $setup, float $total, array $extra = []): array
{
    return $extra + ['total' => $total, 'payment_method' => 'cash', 'payment_method_id' => $setup['method'], 'fulfilment' => 'pickup',
        'pickup_store_id' => $setup['store'], 'phone' => PK_PHONE, 'guest_name' => 'Pickup Buyer', 'guest_email' => 'pickup.buyer@example.com'];
}

it('places a pay-at-store pickup with no address and no delivery, reserves it with the store, a code and the express window, and holds its stock', function () {
    $setup = pkcSetup();
    $product = pkcProduct(2);
    $stock = T::int(DB::table('catalog_products')->where('id', $product)->value('stock_express'));
    $cart = pkcCart('brandfashion', [['product_id' => $product, 'bucket' => 'Express']]);

    $res = postJson('/api/v2/brandfashion/orders', pkcBody($setup, $cart['subtotal']), ['X-Guest-Token' => $cart['token']])->assertCreated();
    $number = T::str($res->json('data.order_number'));
    $order = T::row(DB::table('orders')->where('order_number', $number)->first());
    expect(T::int($order->storefront_id))->toBe(2)
        ->and($order->address_id)->toBeNull()
        ->and(T::str($order->fulfilment))->toBe('pickup')
        ->and(T::str($order->payment_method))->toBe('cash')
        ->and(T::str($order->status))->toBe('processing')
        ->and(T::str($order->guest_phone))->toBe(PK_PHONE)
        ->and(round(T::float($order->total_price_for_order), 2))->toBe($cart['subtotal']);

    $pickup = T::row(DB::table('order_pickups')->where('order_id', $order->id)->first());
    expect(T::int($pickup->store_id))->toBe($setup['store'])
        ->and(T::str($pickup->store_name_en))->toBe('Arkadia Mall')
        ->and(T::str($pickup->status))->toBe('reserved')
        ->and(T::str($pickup->code))->toMatch('/^\d{4}$/')
        ->and(T::int($pickup->pay_at_store))->toBe(1)
        ->and(T::str($pickup->phone_key))->toBe(substr(PK_PHONE, -10))
        ->and(T::str($pickup->stock_kind))->toBe('express')
        ->and([T::int($pickup->ready_min_days), T::int($pickup->ready_max_days)])->toBe([1, 2]);

    // the stock is held exactly as for a delivery: the column and the ledger
    expect(T::int(DB::table('catalog_products')->where('id', $product)->value('stock_express')))->toBe($stock - 1)
        ->and(T::int(DB::table('inventory_movements')->where('reference_id', $order->id)->where('product_id', $product)->value('quantity_delta')))->toBe(-1);

    // the confirmation is told the store, the code and the window
    expect(T::str($res->json('data.fulfilment')))->toBe('pickup')
        ->and(T::str($res->json('data.pickup.code')))->toBe(T::str($pickup->code))
        ->and(T::str($res->json('data.pickup.store.name.ar')))->toBe('أركاديا مول')
        ->and(T::str($res->json('data.pickup.ready.sentence.en')))->toBe('Ready for pickup in 1–2 business days')
        ->and($res->json('data.pickup.pay_at_store'))->toBeTrue();
});

it('promises the market window when a line is sourced first', function () {
    $setup = pkcSetup();
    $cart = pkcCart('brandfashion', [
        ['product_id' => pkcProduct(2), 'bucket' => 'Express'],
        ['product_id' => pkcProduct(2, 'Market'), 'bucket' => 'Market'],
    ]);
    $res = postJson('/api/v2/brandfashion/orders', pkcBody($setup, $cart['subtotal']), ['X-Guest-Token' => $cart['token']])->assertCreated();
    expect(T::str($res->json('data.pickup.ready.kind')))->toBe('market')
        ->and(T::str($res->json('data.pickup.ready.sentence.ar')))->toBe('جاهز للاستلام خلال ٤–٧ أيام عمل');
});

it('refuses BEFORE writing: pickup off, another shop\'s or a switched-off store, no phone, cash on delivery or WhatsApp, and pay-at-store on a delivery', function () {
    $setup = pkcSetup();
    $cart = pkcCart('brandfashion', [['product_id' => pkcProduct(2), 'bucket' => 'Express']]);
    $h = ['X-Guest-Token' => $cart['token']];
    $before = DB::table('orders')->count();
    $post = fn (array $body) => postJson('/api/v2/brandfashion/orders', $body, $h)->assertStatus(422);

    $foreign = PickupStores::forStorefront(1)[0]['id'];
    expect(T::str($post(pkcBody($setup, $cart['subtotal'], ['pickup_store_id' => $foreign]))->json('error')))->toBe(PickupCheckout::STORE);
    $other = PickupStores::forStorefront(2)[1]['id'];
    DB::table(PickupStores::TABLE)->where('id', $other)->update(['is_active' => false]);
    expect(T::str($post(pkcBody($setup, $cart['subtotal'], ['pickup_store_id' => $other]))->json('error')))->toBe(PickupCheckout::STORE);

    expect($post(pkcBody($setup, $cart['subtotal'], ['phone' => '123']))->json('fields'))->toBe(['phone']);
    expect(T::str($post(pkcBody($setup, $cart['subtotal'], ['payment_method_id' => null]))->json('error')))->toBe('payment_method_unavailable');
    expect(T::str($post(pkcBody($setup, $cart['subtotal'], ['payment_method' => 'whatsapp', 'payment_method_id' => null]))->json('error')))->toBe('payment_method_unavailable');
    $cod = T::int(DB::table('storefront_payment_methods as m')->join('storefront_payment_providers as p', 'p.id', '=', 'm.storefront_payment_provider_id')
        ->where('p.storefront_id', 2)->where('m.method', 'cod')->value('m.id'));
    expect(T::str($post(pkcBody($setup, $cart['subtotal'], ['payment_method_id' => $cod]))->json('error')))->toBe('payment_method_unavailable');

    // a DELIVERY may never name the pay-at-store row
    $delivery = ['total' => $cart['subtotal'] + 500, 'payment_method' => 'cash', 'payment_method_id' => $setup['method'], 'address_line' => '12 Test Street',
        'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')), 'phone' => PK_PHONE, 'guest_name' => 'X'];
    expect(T::str($post($delivery)->json('error')))->toBe('payment_method_unavailable');

    Pickup::setEnabled(2, false, 'Brand Fashion');
    expect(T::str($post(pkcBody($setup, $cart['subtotal']))->json('error')))->toBe(PickupCheckout::UNAVAILABLE);

    expect(DB::table('orders')->count())->toBe($before)->and(DB::table('order_pickups')->count())->toBe(0);
});

it('caps pay-at-store like cash on delivery, and a phone holds at most two OPEN pay-at-store reservations', function () {
    $setup = pkcSetup();
    $product = pkcProduct(2);
    $place = function () use ($setup, $product) {
        $cart = pkcCart('brandfashion', [['product_id' => $product, 'bucket' => 'Express']]);

        return postJson('/api/v2/brandfashion/orders', pkcBody($setup, $cart['subtotal']), ['X-Guest-Token' => $cart['token']]);
    };
    // the method's own amount limit (the Payments screen's, as for cash on delivery)
    DB::table('storefront_payment_methods')->where('id', $setup['method'])->update(['settings' => json_encode(['max_total' => 1])]);
    expect(T::str($place()->assertStatus(422)->json('error')))->toBe('payment_method_above_maximum');
    DB::table('storefront_payment_methods')->where('id', $setup['method'])->update(['settings' => null]);

    $first = T::str($place()->assertCreated()->json('data.order_number'));
    $place()->assertCreated();
    expect(T::str($place()->assertStatus(422)->json('error')))->toBe(PickupCheckout::LIMIT);
    // once one is collected, the phone may reserve again
    DB::table('order_pickups')->where('order_id', DB::table('orders')->where('order_number', $first)->value('id'))->update(['status' => 'collected']);
    $place()->assertCreated();
});

it('refuses a free-shipping code on a pickup with its own sentence and does not spend it; a money code applies', function () {
    $setup = pkcSetup();
    PromotionFixture::moneyRewards(true, 2);
    $ship = PromotionFixture::rule([], [PromotionFixture::freeShipping()], [2]);
    DB::table('promotion_rules')->where('id', $ship)->update(['code' => 'SHIPFREE', 'apply_per' => PromotionMoney::PER_ORDER]);
    $ten = PromotionFixture::rule([], [PromotionFixture::percentDiscount(10)], [2]);
    DB::table('promotion_rules')->where('id', $ten)->update(['code' => 'TENOFF', 'apply_per' => PromotionMoney::PER_ORDER]);
    $cart = pkcCart('brandfashion', [['product_id' => pkcProduct(2), 'bucket' => 'Express']]);
    $h = ['X-Guest-Token' => $cart['token']];

    // the cart preview: refused when the checkout says pickup, applied for a delivery
    expect(getJson('/api/v2/brandfashion/cart?coupon=SHIPFREE&fulfilment=pickup', $h)->assertOk()->json('data.totals.promotion.coupon.status'))->toBe(CouponStatus::PICKUP_NO_DELIVERY)
        ->and(getJson('/api/v2/brandfashion/cart?coupon=SHIPFREE', $h)->json('data.totals.promotion.coupon.status'))->toBe(CouponStatus::APPLIED);

    $refused = postJson('/api/v2/brandfashion/orders', pkcBody($setup, $cart['subtotal'], ['coupon' => 'SHIPFREE']), $h)->assertStatus(422);
    expect(T::str($refused->json('error')))->toBe('coupon')->and(T::str($refused->json('status')))->toBe(CouponStatus::PICKUP_NO_DELIVERY)
        ->and(T::str($refused->json('messages.en')))->toContain('pickup order has no delivery fee')
        ->and(DB::table('promotion_redemptions')->where('code', 'SHIPFREE')->exists())->toBeFalse();

    $res = postJson('/api/v2/brandfashion/orders', pkcBody($setup, $cart['subtotal'], ['coupon' => 'TENOFF']), $h)->assertCreated();
    expect(round(T::float($res->json('data.total')), 2))->toBe(round($cart['subtotal'] * 0.9, 2));
});

it('says "pick up at" in both placement e-mails: the store, the code, the window, how it is paid', function () {
    $setup = pkcSetup();
    $cart = pkcCart('brandfashion', [['product_id' => pkcProduct(2), 'bucket' => 'Express']]);
    $number = T::str(postJson('/api/v2/brandfashion/orders', pkcBody($setup, $cart['subtotal']), ['X-Guest-Token' => $cart['token']])->assertCreated()->json('data.order_number'));
    $orderId = T::int(DB::table('orders')->where('order_number', $number)->value('id'));
    $code = T::str(DB::table('order_pickups')->where('order_id', $orderId)->value('code'));
    $data = OrderEmailData::for($orderId);
    expect($data)->not->toBeNull();

    $customer = view('emails.order-confirmation', $data ?? [])->render();
    expect($customer)->toContain('Pick Up At')->toContain('Arkadia Mall')->toContain('أركاديا مول')->toContain($code)
        ->toContain('Ready for pickup in 1–2 business days')->toContain('Pay at the store');
    expect(str_contains($customer, 'Shipping To'))->toBeFalse();
    $admin = view('emails.admin-order-notification', $data ?? [])->render();
    expect($admin)->toContain('PICKUP IN STORE')->toContain($code)->toContain('At the store, on collection');
});

it('tells the checkout page the switch, the active stores and both windows — nothing while it is off', function () {
    $off = getJson('/api/v2/brandfashion/pickup')->assertOk();
    expect($off->json('data.enabled'))->toBeFalse()->and($off->json('data.stores'))->toBe([]);

    pkcSetup();
    $on = getJson('/api/v2/brandfashion/pickup')->assertOk();
    expect($on->json('data.enabled'))->toBeTrue()
        ->and(array_column(T::arr($on->json('data.stores')), 'id'))->toHaveCount(2)
        ->and(T::str($on->json('data.stores.0.name.en')))->toBe('Arkadia Mall')
        ->and(T::int($on->json('data.hold_hours')))->toBe(48)
        ->and(T::str($on->json('data.windows.market.sentence.en')))->toBe('Ready for pickup in 4–7 business days');
    // the other shop is untouched
    expect(getJson('/api/v2/watchizer/pickup')->json('data.enabled'))->toBeFalse();
});
