<?php

use App\Compat\CompatCart;
use App\Domain\Payment\CheckoutMethods;
use App\Models\Storefront\StorefrontPaymentProvider;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/*
 * ── The payment method is checked before the order exists (batch 1, 2026-09-26) ───────────────
 *
 * Two ways a card order used to go wrong AFTER an order had been written:
 *
 *   • naming the cash row's id: the order was created, handed to the offline provider, and
 *     cancelled with a bare English "Payment session failed" — the screenshot a customer sends;
 *   • with the storefront's Paymob contract live and its card row DISABLED: the order fell through
 *     to the wave-3 path and the global `.env` account, instead of being refused.
 *
 * Both are now refused with nothing written — no order, no line, no stock movement, no address —
 * in both languages, saying the order was not placed and what to do next. Cash stays unchecked.
 */

const PM_API_KEY = 'checkout-methods-test-key';

beforeEach(function () {
    config(['compat.api_key' => PM_API_KEY]);
    DB::table('storefront_payment_providers')->delete();
    Http::preventStrayRequests();
});

/** @return array{id: int, price: float} */
function pmProduct(): array
{
    $row = T::row(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('cp.deleted_at')->where('cp.stock_express', '>=', 3)
        ->orderBy('cp.id')->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']));

    return [
        'id' => Row::int($row, 'id'),
        'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
    ];
}

/**
 * A guest order body whose total is right, so only the payment method decides the answer.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function pmOrder(string $method, array $extra = []): array
{
    $product = pmProduct();
    $city = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));

    return array_merge([
        'address_line' => 'Test Street 1',
        'shipping_city_id' => Row::int($city, 'id'),
        'phone' => '01000000000',
        'guest_name' => 'Payment Methods',
        'guest_phone' => '01000000000',
        'total_price_for_order' => round($product['price'] + (float) Row::money($city, 'shipping_cost'), 2),
        'payment_method' => $method,
        'items' => [['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
            'total_price' => $product['price'], 'type_stock' => 'Express']],
    ], $extra);
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function pmPost(array $body, string $language = 'en'): TestResponse
{
    return withHeaders(['Api-Code' => PM_API_KEY, 'X-Guest-Token' => (string) Str::uuid(), 'Accept-Language' => $language])
        ->postJson('https://api.watchizereg.com/api/add_order', $body);
}

/** @return array<string, int> */
function pmWrites(): array
{
    return [
        'orders' => T::int(DB::table('orders')->count()),
        'order_items' => T::int(DB::table('order_items')->count()),
        'movements' => T::int(DB::table('inventory_movements')->count()),
        'addresses' => T::int(DB::table('addresses')->count()),
        'attempts' => T::int(DB::table('payment_statuses')->count()),
    ];
}

function codContract(): StorefrontPaymentProvider
{
    return StorefrontPaymentProvider::query()->updateOrCreate(
        ['storefront_id' => 1, 'provider' => 'cod'],
        ['is_enabled' => true, 'credentials' => null, 'settings' => null],
    );
}

function setLimits(int $methodId, ?float $min, ?float $max): void
{
    DB::table('storefront_payment_methods')->where('id', $methodId)
        ->update(['settings' => json_encode(array_filter(['min_total' => $min, 'max_total' => $max]))]);
}

it('REFUSES a card order when the contract is live and the card row is disabled — before any order exists', function () {
    // The developer's case, verbatim: a contract present, the card row switched off.
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001', enabled: false);
    $before = pmWrites();

    $response = pmPost(pmOrder('card'));

    $response->assertStatus(422)->assertJsonPath('code', CheckoutMethods::UNAVAILABLE);
    expect(pmWrites())->toBe($before);
    Http::assertNothingSent();
});

it('REFUSES a card order naming the CASH row, instead of creating and cancelling it', function () {
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001');
    $cod = PaymentFixture::method(codContract(), 'cod', null, sort: 5, labelAr: 'الدفع عند الاستلام', labelEn: 'Cash on delivery');
    $before = pmWrites();

    $response = pmPost(pmOrder('card', ['payment_method_id' => (string) T::int($cod->getKey())]));

    $response->assertStatus(422)->assertJsonPath('code', CheckoutMethods::UNAVAILABLE);
    expect(pmWrites())->toBe($before)
        ->and(T::str($response->json('message')))->not->toContain('Payment session failed');
    Http::assertNothingSent();
});

it('REFUSES an unknown, a disabled and another storefront\'s method id', function () {
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001');
    $wallet = PaymentFixture::method($paymob, 'wallet', '5943059', sort: 1, enabled: false);
    $fashion = T::int(DB::table('storefronts')->where('code', 'brandfashion')->value('id'));
    $other = PaymentFixture::method(PaymentFixture::paymob($fashion), 'card', '7001');
    $before = pmWrites();

    foreach (['999999', (string) T::int($wallet->getKey()), (string) T::int($other->getKey()), 'abc'] as $id) {
        pmPost(pmOrder('card', ['payment_method_id' => $id]))
            ->assertStatus(422)->assertJsonPath('code', CheckoutMethods::UNAVAILABLE);
    }

    expect(pmWrites())->toBe($before);
});

it('refuses outside a method\'s limits in both languages, and says the order was not placed', function () {
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001');
    $bank = PaymentFixture::method($paymob, 'bank_installment', '5943061', sort: 1, labelAr: 'تقسيط البنوك', labelEn: 'Bank installments');
    setLimits(T::int($bank->getKey()), 1000000, null);
    $before = pmWrites();

    $en = pmPost(pmOrder('card', ['payment_method_id' => (string) T::int($bank->getKey())]));
    $ar = pmPost(pmOrder('card', ['payment_method_id' => (string) T::int($bank->getKey())]), 'ar');

    $en->assertStatus(422)->assertJsonPath('code', CheckoutMethods::BELOW_MINIMUM)
        ->assertJsonPath('message', "Paying with Bank installments is available on orders of EGP 1,000,000 or more. Your order hasn't been placed and nothing was charged — choose another payment method to complete it.");
    $ar->assertStatus(422)
        ->assertJsonPath('message', 'الدفع عبر تقسيط البنوك متاح للطلبات من 1,000,000 ج.م فأكثر. لم يُسجَّل طلبك ولم يُخصم أي مبلغ — اختر طريقة دفع أخرى وأكمل الطلب.')
        ->assertJsonPath('messages.en', T::str($en->json('message')));
    expect(pmWrites())->toBe($before);

    setLimits(T::int($bank->getKey()), null, 1);
    pmPost(pmOrder('card', ['payment_method_id' => (string) T::int($bank->getKey())]))
        ->assertStatus(422)->assertJsonPath('code', CheckoutMethods::ABOVE_MAXIMUM);
});

it('lets a usable method inside its limits through to the provider, with that method\'s integration id', function () {
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001');
    $wallet = PaymentFixture::method($paymob, 'wallet', '5943059', sort: 1);
    setLimits(T::int($wallet->getKey()), 1, 1000000);

    Http::fake(['*/intention/' => Http::response(['client_secret' => 'cs_test', 'id' => 'intent_1'], 200)]);

    pmPost(pmOrder('card', ['payment_method_id' => (string) T::int($wallet->getKey())]))
        ->assertOk()->assertJsonPath('success', true);

    Http::assertSent(fn (Request $r): bool => ($r->data()['payment_methods'] ?? null) === [5943059]);
});

it('leaves cash alone: always accepted, whatever the method rows say', function () {
    // Live contract, card disabled, NO cash row at all — cash on delivery still goes through.
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001', enabled: false);

    pmPost(pmOrder('cash'))->assertOk()->assertJsonPath('success', true);
});

it('serves v2 only the methods a shopper can pay with, both labels, their limits, and no provider', function () {
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001');
    $wallet = PaymentFixture::method($paymob, 'wallet', '5943059', sort: 1, labelAr: 'محفظة إلكترونية', labelEn: 'Mobile wallet');
    setLimits(T::int($wallet->getKey()), 100, 20000);
    PaymentFixture::method($paymob, 'apple_pay', '5943068', sort: 2, enabled: false);   // the switch
    PaymentFixture::method($paymob, 'cagg', null, sort: 3);                             // unusable: no id

    $response = get('https://api.watchizereg.com/api/v2/watchizer/payment-methods')->assertOk();

    expect(array_column(T::arr($response->json('data')), 'method'))->toBe(['card', 'wallet'])
        ->and($response->json('data.1.label'))->toBe(['ar' => 'محفظة إلكترونية', 'en' => 'Mobile wallet'])
        ->and($response->json('data.1.min_total'))->toEqual(100)
        ->and($response->json('data.1.max_total'))->toEqual(20000)
        ->and((string) $response->getContent())->not->toContain('paymob')
        ->and($response->headers->get('Cache-Control'))->toContain('s-maxage=60')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=0');
});

it('offers nothing through a contract that is not live', function () {
    // Credentials not pasted yet: the rows exist, but nobody can pay through them.
    PaymentFixture::method(PaymentFixture::paymobWithoutCredentials(), 'card', '4001');

    get('https://api.watchizereg.com/api/v2/watchizer/payment-methods')->assertOk()->assertExactJson(['data' => []]);
});

it('sends bank installments and CAGG each their OWN integration id', function () {
    // Reported 2026-09-26: both land on CAGG's Paymob page. This pins what core sends for each.
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001');
    $cagg = PaymentFixture::method($paymob, 'cagg', '5943060', sort: 1);
    $bank = PaymentFixture::method($paymob, 'bank_installment', '5943061', sort: 2);

    Http::fake(['*/intention/' => Http::response(['client_secret' => 'cs_test', 'id' => 'intent_1'], 200)]);

    pmPost(pmOrder('card', ['payment_method_id' => (string) T::int($cagg->getKey())]))->assertOk();
    pmPost(pmOrder('card', ['payment_method_id' => (string) T::int($bank->getKey())]))->assertOk();

    $sent = [];
    Http::assertSent(function (Request $r) use (&$sent): bool {
        $sent[] = $r->data()['payment_methods'] ?? null;

        return true;
    });
    expect($sent)->toBe([[5943060], [5943061]]);
});

it('sends Paymob the real phone of the shopper — a mobile wallet pays BY that number', function () {
    // Before 2026-09-26 the billing carried `phone_number` and the provider read `phone`, so every
    // intention went out with '-'.
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001');
    $wallet = PaymentFixture::method($paymob, 'wallet', '5943059', sort: 1);

    Http::fake(['*/intention/' => Http::response(['client_secret' => 'cs_test', 'id' => 'intent_1'], 200)]);

    pmPost(pmOrder('card', ['payment_method_id' => (string) T::int($wallet->getKey()), 'phone' => '01012345678', 'guest_phone' => '01012345678']))
        ->assertOk();

    Http::assertSent(fn (Request $r): bool => data_get($r->data(), 'billing_data.phone_number') === '01012345678');
});
