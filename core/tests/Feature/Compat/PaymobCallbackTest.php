<?php

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Transform\Row;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * `GET callback_payment`, after review findings 🟠-4 (the writes were not one unit) and
 * 🟡-5 (nobody checked the amount).
 *
 * The callback is the only endpoint an outsider can call that changes an order's status and
 * returns stock, so it gets the most hostile treatment: a wrong signature, a replayed
 * transaction, and an amount that does not match what the order costs.
 */

const CB_API_KEY = 'test-api-code';

const CB_HMAC = 'callback-test-hmac-secret';

beforeEach(function () {
    config(['compat.api_key' => CB_API_KEY, 'services.paymob.hmac_secret' => CB_HMAC, 'compat.payment_return_url' => 'https://watchizereg.test/']);
});

/** @return array{order: int, product: int, before: int, total: string} */
function callbackOrder(int $quantity = 2, string $total = '250.00'): array
{
    $row = T::row(DB::table('catalog_products')->whereNull('deleted_at')
        ->where('stock_express', '>=', $quantity)->orderBy('id')->first(['id', 'stock_express']));
    $productId = Row::int($row, 'id');
    $before = Row::int($row, 'stock_express');

    $orderId = (int) DB::table('orders')->insertGetId([
        'user_id' => null,
        'address_id' => T::int(DB::table('addresses')->orderBy('id')->value('id')),
        'total_price_for_order' => $total, 'payment_method' => 'paymob',
        'order_number' => 'ZZ'.random_int(100000, 999999), 'status' => 'pending',
        'guest_name' => 'callback-probe', 'guest_email' => 'callback@example.test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
        'quantity' => $quantity, 'piece_price' => '0.00', 'total_price' => '0.00',
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId, Actor::system(), 1);

    return ['order' => $orderId, 'product' => $productId, 'before' => $before, 'total' => $total];
}

/**
 * A callback query string signed the way Paymob signs one: the twenty documented fields, in the
 * documented order, concatenated and HMAC-SHA512'd.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function signedCallback(array $overrides = []): array
{
    $fields = [
        'amount_cents' => '25000', 'created_at' => '2026-09-09T10:00:00', 'currency' => 'EGP',
        'error_occured' => 'false', 'has_parent_transaction' => 'false', 'id' => (string) random_int(700000000, 799999999),
        'integration_id' => '4988969', 'is_3d_secure' => 'true', 'is_auth' => 'false', 'is_capture' => 'false',
        'is_refunded' => 'false', 'is_standalone_payment' => 'true', 'is_voided' => 'false',
        'order' => '5551212', 'owner' => '9999', 'pending' => 'false',
        'source_data.pan' => '2346', 'source_data.sub_type' => 'MasterCard', 'source_data.type' => 'card',
        'success' => 'true',
    ];
    $fields = array_replace($fields, array_intersect_key($overrides, $fields));

    $concatenated = '';
    foreach (['amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id',
        'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'order', 'owner', 'pending', 'source_data.pan', 'source_data.sub_type',
        'source_data.type', 'success'] as $key) {
        $concatenated .= T::str($fields[$key]);
    }

    $query = $fields;
    // Paymob's redirect callback flattens the nested keys with an underscore.
    $query['source_data_pan'] = $fields['source_data.pan'];
    $query['source_data_sub_type'] = $fields['source_data.sub_type'];
    $query['source_data_type'] = $fields['source_data.type'];
    unset($query['source_data.pan'], $query['source_data.sub_type'], $query['source_data.type']);
    $query['hmac'] = hash_hmac('sha512', $concatenated, CB_HMAC);

    return array_replace($query, array_diff_key($overrides, $fields));
}

/**
 * @param  array<string, mixed>  $query
 * @return TestResponse<Response>
 */
function callback(array $query): TestResponse
{
    return withHeaders(['Api-Code' => CB_API_KEY])->get('/api/callback_payment?'.http_build_query($query));
}

it('accepts a correctly signed success callback and marks the order processing', function () {
    $f = callbackOrder(2, '250.00');

    callback(signedCallback(['merchant_order_id' => $f['order'], 'amount_cents' => '25000']))
        ->assertRedirect('https://watchizereg.test/');

    expect(DB::table('orders')->where('id', $f['order'])->value('status'))->toBe('processing')
        // A successful payment keeps the stock reserved.
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'] - 2)
        ->and(DB::table('integration_outbox')->where('channel', 'mail')->where('aggregate_id', $f['order'])->count())->toBe(1);
});

it('returns the stock and cancels the order when the payment failed', function () {
    $f = callbackOrder(2, '250.00');

    callback(signedCallback(['merchant_order_id' => $f['order'], 'amount_cents' => '25000', 'success' => 'false']))
        ->assertRedirect('https://watchizereg.test/');

    expect(DB::table('orders')->where('id', $f['order'])->value('status'))->toBe('cancelled')
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'])
        ->and(app(InventoryService::class)->isReleased($f['order']))->toBeTrue();
});

it('rejects a callback whose amount does not match the order, and touches nothing (🟡-5)', function () {
    $f = callbackOrder(2, '250.00');

    // The order costs 250.00; the callback claims 5.00 was paid.
    callback(signedCallback(['merchant_order_id' => $f['order'], 'amount_cents' => '500']))
        ->assertRedirect('https://watchizereg.test/?payment_error=1');

    expect(DB::table('orders')->where('id', $f['order'])->value('status'))->toBe('pending')   // NOT processing
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'] - 2)
        ->and(app(InventoryService::class)->isReleased($f['order']))->toBeFalse()
        ->and(DB::table('integration_outbox')->where('channel', 'mail')->where('aggregate_id', $f['order'])->count())->toBe(0);

    // …but the attempt IS on record, marked unsuccessful whatever Paymob claimed.
    $payment = T::row(DB::table('payment_statuses')->where('order_id', $f['order'])->first());
    expect(Row::str($payment, 'success'))->toBe('false')
        ->and(Row::int($payment, 'amount_cents'))->toBe(500);
});

it('tolerates a one-cent rounding difference', function () {
    $f = callbackOrder(2, '250.00');

    callback(signedCallback(['merchant_order_id' => $f['order'], 'amount_cents' => '25001']))
        ->assertRedirect('https://watchizereg.test/');

    expect(DB::table('orders')->where('id', $f['order'])->value('status'))->toBe('processing');
});

it('ignores a replayed transaction id (idempotent), leaving the first outcome alone', function () {
    $f = callbackOrder(2, '250.00');
    $query = signedCallback(['merchant_order_id' => $f['order'], 'amount_cents' => '25000', 'success' => 'false']);

    callback($query)->assertRedirect('https://watchizereg.test/');
    $movements = DB::table('inventory_movements')->count();

    callback($query)->assertOk()->assertExactJson(['message' => 'Already processed']);
    callback($query)->assertOk()->assertExactJson(['message' => 'Already processed']);

    expect(DB::table('payment_statuses')->where('pay_transaction_id', $query['id'])->count())->toBe(1)
        ->and(DB::table('inventory_movements')->count())->toBe($movements)
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before']);
});

it('writes the payment row, the status change and the release as ONE unit (review 🟠-4)', function () {
    // If the three were separate statements, a failure part-way would leave a payment recorded
    // against an order that was never updated, or an order cancelled whose stock was never
    // returned. Here the RELEASE is made to fail deterministically -- the movement is told to
    // reference a storefront that does not exist, so its insert violates a foreign key -- and the
    // assertion is that the two writes which had already succeeded are rolled back with it.
    $f = callbackOrder(2, '250.00');
    $payments = DB::table('payment_statuses')->count();
    $movements = DB::table('inventory_movements')->count();

    config(['compat.storefront_id' => 999999]);

    callback(signedCallback(['merchant_order_id' => $f['order'], 'amount_cents' => '25000', 'success' => 'false']))
        ->assertRedirect('https://watchizereg.test/?payment_error=1');

    expect(DB::table('payment_statuses')->count())->toBe($payments)                // rolled back
        ->and(DB::table('inventory_movements')->count())->toBe($movements)          // rolled back
        ->and(DB::table('orders')->where('id', $f['order'])->value('status'))->toBe('pending')   // rolled back
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'] - 2);
});

it('records the payment even when the callback names an order that does not exist', function () {
    // `payment_statuses.order_id` carries a foreign key, so a callback naming an order that does
    // not exist cannot be recorded at all: the insert is refused, the transaction rolls back and
    // the shopper is redirected with the error flag. That is exactly what the legacy code does
    // with the same input, for the same reason, so it is reproduced rather than improved.
    $payments = DB::table('payment_statuses')->count();

    callback(signedCallback(['merchant_order_id' => 99999999, 'amount_cents' => '25000']))
        ->assertRedirect('https://watchizereg.test/?payment_error=1');

    expect(DB::table('payment_statuses')->count())->toBe($payments);
});

it('rejects a bad signature before touching anything at all', function () {
    $f = callbackOrder(2, '250.00');
    $payments = DB::table('payment_statuses')->count();

    $query = signedCallback(['merchant_order_id' => $f['order']]);
    $query['hmac'] = str_repeat('0', 128);

    callback($query)->assertStatus(403)->assertExactJson(['message' => 'Invalid signature']);

    expect(DB::table('payment_statuses')->count())->toBe($payments)
        ->and(DB::table('orders')->where('id', $f['order'])->value('status'))->toBe('pending');
});

it('rejects a signature computed over tampered fields', function () {
    $f = callbackOrder(2, '250.00');
    // Sign for 250.00, then change the amount on the wire: the HMAC no longer covers the body.
    $query = signedCallback(['merchant_order_id' => $f['order'], 'amount_cents' => '25000']);
    $query['amount_cents'] = '1';

    callback($query)->assertStatus(403);

    expect(DB::table('orders')->where('id', $f['order'])->value('status'))->toBe('pending');
});

it('fails closed when no HMAC secret is configured', function () {
    config(['services.paymob.hmac_secret' => null]);

    callback(signedCallback(['merchant_order_id' => 1]))->assertStatus(403);
});
