<?php

use App\Compat\CompatCart;
use App\Domain\Payment\CallbackPolicy;
use App\Storefront\StorefrontUrls;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withoutMiddleware;

/*
 * EVERY ONLINE METHOD WATCHIZER OFFERS, THROUGH v2's CHECKOUT, BESIDE COMPAT'S (2026-10-21).
 *
 * The live checkout moved from compat's `add_order` to v2's `orders` with the online path never exercised on
 * either shop (Brand Fashion has only ever taken cash). No Paymob test or sandbox credential exists anywhere
 * in this project — the live keys are rows in the dashboard, never in the repo, and the database copies hold
 * `credentials = NULL` — so this is proved against the RECORDED answer shape (`client_secret`, `id`) on the
 * REAL method rows of storefront 1 with their REAL integration ids, for each of them:
 *
 *   1. the intention v2 sends is BYTE-FOR-BYTE the intention compat sends for the same order — the same
 *      integration id, amount, billing (phone, the governorate's name), callback urls, extras;
 *   2. the answer the page acts on is the same redirect, and it is the unified checkout;
 *   3. the shopper's return — Paymob's signed callback — finds the v2-created order, writes the payment
 *      record, moves the order, and lands the shopper on the confirmation page with the right status,
 *      for paid, failed and pending.
 *
 * Why one intention per row and not one per code path: every method is ONE Paymob intention whose
 * `payment_methods` carries that row's integration id; the wallet number and the Apple Pay sheet live on
 * Paymob's page. The method decides nothing else here.
 */

const COM_API_KEY = 'online-methods-test-key';
const COM_CALLBACK_BASE = 'https://api.watchizereg.com';

beforeEach(function () {
    H::flush();
    config(['compat.api_key' => COM_API_KEY]);
    // eighteen orders in one test from one address: the checkout's rate limit is the one thing not under test
    withoutMiddleware(ThrottleRequests::class);
    // Credentials on the REAL storefront-1 Paymob contract (the method rows point at it), inside the test
    // transaction; and a callback base a provider could reach, since the test request is served on localhost.
    $provider = PaymentFixture::paymob();
    DB::table('storefront_payment_providers')->where('id', $provider->getAttribute('id'))
        ->update(['settings' => json_encode(['callback_base' => COM_CALLBACK_BASE])]);
    // The recorded Paymob answer, registered ONCE (Laravel stacks fakes; the first stub keeps answering): the
    // client secret names the integration id the intention carried, so each method's redirect is its own.
    Http::fake(['*/intention/' => function (ClientRequest $request) {
        $integration = T::str(T::arr($request->data()['payment_methods'] ?? [])[0] ?? 'none');

        return Http::response(['client_secret' => 'cs_'.$integration, 'id' => 'intent_'.$integration], 200);
    }]);
});

/** @return list<array{id: int, method: string, integration: string}> every enabled online row of storefront 1 */
function comOnlineRows(): array
{
    $rows = [];
    foreach (DB::table('storefront_payment_methods as m')
        ->join('storefront_payment_providers as p', 'p.id', '=', 'm.storefront_payment_provider_id')
        ->where('p.storefront_id', 1)->where('p.provider', 'paymob')->where('m.is_enabled', 1)
        ->orderBy('m.sort')->get(['m.id', 'm.method', 'm.integration_id']) as $raw) {
        $r = Row::cast($raw);
        $rows[] = ['id' => Row::int($r, 'id'), 'method' => Row::str($r, 'method'), 'integration' => Row::str($r, 'integration_id')];
    }

    return $rows;
}

/**
 * The $nth visible, in-stock storefront-1 product with no variants. One product per method: each method
 * places two orders of one unit (v2 and compat), and no plain product on the test database holds more than
 * five units.
 *
 * @return array{id: int, price: float}
 */
function comPlain(int $nth = 0): array
{
    $row = T::row(DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)
        ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.stock_express', '>=', 3)
        ->whereNotExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('catalog_product_variants as v')
            ->whereColumn('v.product_id', 'sp.product_id'))
        ->orderBy('sp.product_id')->skip($nth)
        ->first(['sp.product_id', 'sp.effective_price', 'sp.effective_sale_price']));

    return [
        'id' => Row::int($row, 'product_id'),
        'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price')),
    ];
}

/** @return array<string, mixed> the address, phone and guest details both checkouts receive, identically */
function comDetails(string $tag): array
{
    return [
        'address_line' => '5 Parity Street, Smouha',
        'shipping_city_id' => T::int(DB::table('shipping_cities')->orderBy('id')->value('id')),
        'phone' => '01011112222',
        'guest_name' => 'Parity Shopper',
        'guest_email' => 'parity-'.$tag.'@example.invalid',
        'guest_phone' => '01011112222',
        'note' => '',
    ];
}

/**
 * The LAST intention the fake received, and proof that exactly one more was sent since the last look.
 *
 * @return array<mixed>
 */
function comIntention(int $expectedCount): array
{
    $recorded = Http::recorded();
    expect($recorded->count())->toBe($expectedCount);
    /** @var array{0: ClientRequest, 1: mixed} $last */
    $last = $recorded->last();

    return T::arr($last[0]->data());
}

/**
 * A v2 order for one line of $product, paid by $row, as the page posts it: `card` + the row's id. The total is
 * what the server says it is — the first post deliberately sends 1 and reads `server_total` back, which also
 * proves the mismatch refusal on the way.
 *
 * @param  array{id: int, price: float}  $product
 * @param  array{id: int, method: string, integration: string}  $row
 * @return array{order_id: int, order_number: string, total: float, redirect: string|null, body: array<mixed>}
 */
function comV2Order(array $product, array $row, string $tag): array
{
    $token = (string) Str::uuid();
    withHeaders(['X-Guest-Token' => $token])->postJson(H::base('cart/items'), ['product_id' => $product['id'], 'quantity' => 1])->assertOk();

    $details = comDetails($tag);
    // wrong by a pound, not absurd: a total far below a method's minimum trips its LIMIT refusal first
    $probe = withHeaders(['X-Guest-Token' => $token])->postJson(H::base('orders'), $details + ['payment_method' => 'card', 'payment_method_id' => $row['id'], 'total' => $product['price'] + 101]);
    expect($probe->status())->toBe(422, $row['method'].': '.json_encode($probe->json()))
        ->and($probe->json('error'))->toBe('total_mismatch', $row['method'].': '.json_encode($probe->json()));
    $total = T::float($probe->json('server_total'));

    $response = withHeaders(['X-Guest-Token' => $token])->postJson(H::base('orders'), $details + ['payment_method' => 'card', 'payment_method_id' => $row['id'], 'total' => $total]);
    $response->assertStatus(201);
    $data = T::arr($response->json('data'));
    $orderNumber = T::str($data['order_number'] ?? '');
    $orderId = T::int(DB::table('orders')->where('order_number', $orderNumber)->value('id'));

    return ['order_id' => $orderId, 'order_number' => $orderNumber, 'total' => $total, 'redirect' => is_string($data['redirect_url'] ?? null) ? $data['redirect_url'] : null, 'body' => $data];
}

/**
 * The same order through compat's `add_order`, exactly as Watchizer's page posted it until today: the lines in
 * the body, the client's prices, `total_price_for_order`.
 *
 * @param  array{id: int, price: float}  $product
 * @param  array{id: int, method: string, integration: string}  $row
 * @return array{order_number: string, redirect: string|null, body: array<mixed>}
 */
function comCompatOrder(array $product, array $row, float $total, string $tag): array
{
    $token = (string) Str::uuid();
    $response = withHeaders(['Api-Code' => COM_API_KEY, 'X-Guest-Token' => $token])->postJson('/api/add_order', comDetails($tag) + [
        'payment_method' => 'card',
        'payment_method_id' => $row['id'],
        'total_price_for_order' => $total,
        'items' => [[
            'product_id' => $product['id'], 'offer_id' => null, 'quantity' => 1,
            'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express',
            'color_band' => null, 'color_dial' => null,
        ]],
    ]);
    $response->assertOk()->assertJsonPath('success', true);
    $body = T::arr($response->json());

    return ['order_number' => T::str($body['order_number'] ?? ''), 'redirect' => is_string($body['redirect_url'] ?? null) ? $body['redirect_url'] : null, 'body' => $body];
}

it('offers the six online methods the live shop offers, each with its own integration id', function () {
    $rows = comOnlineRows();
    expect(array_column($rows, 'method'))->toBe(['card', 'wallet', 'bank_installment', 'valu', 'apple_pay', 'kiosk']);
    foreach ($rows as $row) {
        expect($row['integration'])->toMatch('/^\d{6,8}$/');
    }
    expect(count(array_unique(array_column($rows, 'integration'))))->toBe(6);
});

it('sends compat\'s intention, byte for byte, for every method — and the page gets the same redirect', function () {
    $cityName = T::str(DB::connection('legacy')->table('shipping_city_translations')
        ->where('shipping_city_id', comDetails('x')['shipping_city_id'])->where('locale', 'en')->value('city_name'));
    $seen = [];

    foreach (comOnlineRows() as $n => $row) {
        $tag = $row['method'];
        $product = comPlain($n);

        $v2 = comV2Order($product, $row, $tag);
        $v2Intention = comIntention(2 * $n + 1);
        $compat = comCompatOrder($product, $row, $v2['total'], $tag);
        $compatIntention = comIntention(2 * $n + 2);

        // ── 2. the answer the page acts on (Checkout.jsx: `data.redirect_url` → window.location) ───────────
        $unified = 'https://accept.paymob.com/unifiedcheckout/?publicKey=test-public-key&clientSecret=cs_'.$row['integration'];
        expect($v2['redirect'])->toBe($unified)
            ->and($compat['redirect'])->toBe($unified)
            ->and($v2['body']['payment_method'] ?? null)->toBe('paymob')
            ->and($v2['body']['status'] ?? null)->toBe('pending');

        // ── 1. the intention: the same arguments reach Paymob from both paths ──────────────────────────────
        $billing = T::arr($v2Intention['billing_data'] ?? []);
        expect($v2Intention['payment_methods'] ?? null)->toBe([(int) $row['integration']])
            ->and($v2Intention['special_reference'] ?? null)->toBe($v2['order_number'])
            ->and($v2Intention['amount'] ?? null)->toBe((int) round($v2['total'] * 100))
            ->and($v2Intention['redirection_url'] ?? null)->toBe(COM_CALLBACK_BASE.'/api/pay/watchizer/paymob/callback')
            ->and($v2Intention['notification_url'] ?? null)->toBe(COM_CALLBACK_BASE.'/api/pay/watchizer/paymob/callback')
            // a mobile wallet pays BY this number
            ->and($billing['phone_number'] ?? null)->toBe('01011112222')
            // the governorate's name, as compat sends it (it said Cairo for every order until 2026-10-21)
            ->and($billing['city'] ?? null)->toBe($cityName)
            ->and(T::arr($v2Intention['extras'] ?? [])['storefront_id'] ?? null)->toBe(1);

        // everything but the order's own identity must be identical between the two paths
        $strip = function (array $i): array {
            unset($i['special_reference']);
            $extras = T::arr($i['extras'] ?? []);
            unset($extras['order_id']);
            $i['extras'] = $extras;

            return $i;
        };
        expect($strip($v2Intention))->toBe($strip($compatIntention));
        expect($compatIntention['special_reference'] ?? null)->toBe($compat['order_number']);

        // the v2 order row, as the dashboard and the callback will find it
        $order = T::row(DB::table('orders')->where('id', $v2['order_id'])->first());
        expect(Row::str($order, 'payment_method'))->toBe('paymob')
            ->and(Row::str($order, 'status'))->toBe('pending')
            ->and(Row::int($order, 'storefront_id'))->toBe(1)
            // the units are reserved at placement, on both paths
            ->and(DB::table('inventory_movements')->where('reference_id', $v2['order_id'])->where('reason', 'order')->count())->toBe(1);
        $seen[] = $tag;
    }
    expect($seen)->toHaveCount(6);
});

it('lands the shopper and settles the v2-created order on the callback — paid, failed and pending', function () {
    $rows = comOnlineRows();
    $byMethod = array_combine(array_column($rows, 'method'), $rows);
    $landing = fn (string $number, string $status): string => StorefrontUrls::paymentLanding(1, $status, $number);

    // PAID — a wallet, the method whose callback must still find the right row by its integration id
    $wallet = $byMethod['wallet'];
    $paid = comV2Order(comPlain(0), $wallet, 'paid');
    $amountMinor = (int) round($paid['total'] * 100);
    $cb = PaymentFixture::callback($paid['order_number'], $amountMinor, success: true, transactionId: 771001, integrationId: $wallet['integration']);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($cb))
        ->assertRedirect($landing($paid['order_number'], 'paid'));
    $order = T::row(DB::table('orders')->where('id', $paid['order_id'])->first());
    expect(Row::str($order, 'status'))->toBe('processing')
        ->and(Row::nstr($order, 'paid_via_provider'))->toBe('paymob')
        ->and(Row::nstr($order, 'paid_via_method'))->toBe('wallet');
    $record = T::row(DB::table('payment_statuses')->where('order_id', $paid['order_id'])->orderByDesc('id')->first());
    expect(Row::str($record, 'success'))->toBe('true')
        ->and(Row::str($record, 'outcome'))->toBe(CallbackPolicy::OUTCOME_SUCCESS)
        ->and(Row::int($record, 'amount_cents'))->toBe($amountMinor)
        ->and(Row::nint($record, 'storefront_payment_method_id'))->toBe($wallet['id'])
        ->and(Row::nstr($record, 'pay_transaction_id'))->toBe('771001')
        // paid: the reserved units stay reserved — no release row
        ->and(DB::table('inventory_movements')->where('reference_id', $paid['order_id'])->where('reason', 'payment_failed')->count())->toBe(0);

    // FAILED — a card declined: cancelled, the units back, the landing says so
    $card = $byMethod['card'];
    $failed = comV2Order(comPlain(1), $card, 'failed');
    $cb = PaymentFixture::callback($failed['order_number'], (int) round($failed['total'] * 100), success: false, transactionId: 771002, integrationId: $card['integration']);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($cb))
        ->assertRedirect($landing($failed['order_number'], 'failed'));
    expect(T::str(DB::table('orders')->where('id', $failed['order_id'])->value('status')))->toBe('cancelled')
        ->and(T::str(DB::table('payment_statuses')->where('order_id', $failed['order_id'])->value('outcome')))->toBe(CallbackPolicy::OUTCOME_FAILED)
        ->and(DB::table('inventory_movements')->where('reference_id', $failed['order_id'])->where('reason', 'payment_failed')->count())->toBe(1);

    // PENDING — a kiosk reference issued, not yet paid: the order waits, the landing says pending
    $kiosk = $byMethod['kiosk'];
    $pending = comV2Order(comPlain(2), $kiosk, 'pending');
    $cb = PaymentFixture::callback($pending['order_number'], (int) round($pending['total'] * 100), success: false, transactionId: 771003, integrationId: $kiosk['integration'], overrides: ['pending' => 'true']);
    get('/api/pay/watchizer/paymob/callback?'.http_build_query($cb))
        ->assertRedirect($landing($pending['order_number'], 'pending'));
    expect(T::str(DB::table('orders')->where('id', $pending['order_id'])->value('status')))->toBe('pending')
        ->and(T::str(DB::table('payment_statuses')->where('order_id', $pending['order_id'])->value('outcome')))->toBe(CallbackPolicy::OUTCOME_PENDING)
        ->and(DB::table('inventory_movements')->where('reference_id', $pending['order_id'])->where('reason', 'payment_failed')->count())->toBe(0);
});
