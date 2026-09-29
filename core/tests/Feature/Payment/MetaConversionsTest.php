<?php

use App\Domain\Analytics\MetaConversions;
use App\Domain\Orders\OrderCustomer;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\postJson;

/*
 * B2 — the Meta Conversions API `Purchase` for a CARD order (2026-09-29).
 *
 * The card shopper leaves for Paymob and the browser never learns the payment cleared (runbook
 * §11.1), so the payment callback sends the Purchase server-side. What these hold:
 *   - one Purchase per paid order, from BOTH callback paths, and none for a declined card;
 *   - the shopper's details hashed (never a raw e-mail or phone in the outbox or on the wire);
 *   - a token Meta rejects fails loudly and at once, a Meta outage retries;
 *   - `meta:capi-check` tells "the token is wrong" apart from "the events are not firing".
 */

const CAPI_TOKEN = 'EAAtesttokenABCdef0123456789';

beforeEach(function () {
    config([
        'services.meta_capi.token' => CAPI_TOKEN,
        'services.meta_capi.pixel_id' => '1614877760150035',
        'services.meta_capi.graph_version' => 'v23.0',
        'services.meta_capi.test_event_code' => '',
        'services.meta_capi.send_inside_transaction' => true,
    ]);
});

/** A card order for a known guest, with one product line and its browser signals. */
function capiOrder(float $total = 1500.0): int
{
    $orderId = PaymentFixture::order(total: $total);
    DB::table('orders')->where('id', $orderId)->update([
        'guest_name' => 'Mona Adel', 'guest_email' => ' Mona.Adel@Example.com ', 'guest_phone' => '0101 234 5678',
    ]);
    $product = T::int(DB::table('catalog_products')->min('id'));
    DB::table('order_items')->insert(['order_id' => $orderId, 'product_id' => $product, 'quantity' => 2, 'piece_price' => '750.00', 'total_price' => '1500.00', 'type_stock' => 'Market']);
    $request = Request::create('/api/add_order', 'POST', ['fbp' => 'fb.1.1700000000000.123456789'], [], [], [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (iPhone) Safari', 'REMOTE_ADDR' => '156.204.134.241',
    ]);
    MetaConversions::recordSignals($orderId, $request);

    return $orderId;
}

/** @return list<array<array-key, mixed>> */
function metaRows(int $orderId): array
{
    $rows = [];
    foreach (DB::table('integration_outbox')->where('channel', 'meta')->where('aggregate_id', $orderId)
        ->get(['status', 'attempts', 'last_error', 'payload', 'available_at']) as $r) {
        $rows[] = (array) $r;
    }

    return $rows;
}

/** @param  array<string, mixed>  $body */
function fakeMeta(int $status, array $body): void
{
    Http::fake(['graph.facebook.com/*' => Http::response($body, $status)]);
}

it('builds a hashed Purchase from the order: value, contents, the browser signals — never a raw e-mail or phone', function () {
    $orderId = capiOrder();
    $event = app(MetaConversions::class)->purchaseEvent($orderId);
    $json = json_encode($event);
    $user = T::arr(T::arr($event)['user_data']);
    $custom = T::arr(T::arr($event)['custom_data']);

    expect(T::arr($event)['event_name'])->toBe('Purchase')
        ->and(T::arr($event)['event_id'])->toBe('purchase-'.PaymentFixture::orderNumber($orderId))
        ->and(T::arr($event)['action_source'])->toBe('website')
        ->and($user['em'])->toBe([hash('sha256', 'mona.adel@example.com')])
        // OrderCustomer's phone rule (the order address's phone first), normalised to 20XXXXXXXXXX.
        ->and($user['ph'])->toBe([hash('sha256', T::str(MetaConversions::normPhone(OrderCustomer::of($orderId)?->phone)))])
        ->and($user['fn'])->toBe([hash('sha256', 'mona')])
        ->and($user['ln'])->toBe([hash('sha256', 'adel')])
        ->and($user['client_user_agent'])->toBe('Mozilla/5.0 (iPhone) Safari')
        ->and($user['client_ip_address'])->toBe('156.204.134.241')
        ->and($user['fbp'])->toBe('fb.1.1700000000000.123456789')
        ->and($custom['value'])->toEqual(1500.0)
        ->and($custom['currency'])->toBe('EGP')
        ->and($custom['num_items'])->toBe(2);
    expect(T::str($json))->not->toContain('Mona');
    expect(T::str($json))->not->toContain('example.com');
});

it('enqueues ONE Purchase per order, and none while the token is empty', function () {
    $orderId = capiOrder();
    $meta = app(MetaConversions::class);

    expect($meta->purchase($orderId))->toHaveCount(1)
        ->and($meta->purchase($orderId))->toBe([])
        ->and(metaRows($orderId))->toHaveCount(1);

    config(['services.meta_capi.token' => '']);
    $other = capiOrder();
    expect($meta->purchase($other))->toBe([])->and(metaRows($other))->toBe([]);
});

it('sends to the new pixel with the token and the test code, and marks the row sent', function () {
    config(['services.meta_capi.test_event_code' => 'TEST24178']);
    fakeMeta(200, ['events_received' => 1, 'fbtrace_id' => 'abc']);
    $orderId = capiOrder();
    $meta = app(MetaConversions::class);

    $meta->flush($meta->purchase($orderId));

    Http::assertSent(function (HttpRequest $r): bool {
        $d = T::arr($r->data());

        return str_contains($r->url(), 'graph.facebook.com/v23.0/1614877760150035/events')
            && $d['access_token'] === CAPI_TOKEN && $d['test_event_code'] === 'TEST24178'
            && T::arr(T::arr($d['data'])[0])['event_name'] === 'Purchase';
    });
    expect(metaRows($orderId)[0]['status'])->toBe('sent');
});

it('fails at once, naming the token, when Meta rejects the token', function () {
    fakeMeta(400, ['error' => ['message' => 'Invalid OAuth access token - Cannot parse access token', 'type' => 'OAuthException', 'code' => 190, 'fbtrace_id' => 'X1']]);
    $orderId = capiOrder();
    $meta = app(MetaConversions::class);

    $meta->flush($meta->purchase($orderId));

    $row = metaRows($orderId)[0];
    expect($row['status'])->toBe('failed')
        ->and(T::int($row['attempts']))->toBe(1)
        ->and(T::str($row['last_error']))->toContain('THE TOKEN IS WRONG OR EXPIRED')->toContain('code 190');
});

it('keeps the event pending and retries later when Meta is down', function () {
    fakeMeta(503, ['error' => ['message' => 'Service temporarily unavailable', 'code' => 2]]);
    $orderId = capiOrder();
    $meta = app(MetaConversions::class);

    $meta->flush($meta->purchase($orderId));

    $row = metaRows($orderId)[0];
    expect($row['status'])->toBe('pending')
        ->and(T::int($row['attempts']))->toBe(1)
        ->and(strtotime(T::str($row['available_at'])))->toBeGreaterThan(time());
});

it('sends a Purchase when a card payment clears — on the scoped callback and on the alias — and none for a decline', function () {
    fakeMeta(200, ['events_received' => 1]);
    PaymentFixture::paymob();

    $scoped = capiOrder(100.0);
    $cb = PaymentFixture::processedCallback(PaymentFixture::orderNumber($scoped), 10000, transactionId: 771001);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$cb['hmac'], $cb['body'])->assertOk();

    $alias = capiOrder(100.0);
    $cb2 = PaymentFixture::processedCallback(PaymentFixture::orderNumber($alias), 10000, transactionId: 771002);
    postJson('/api/callback_payment?hmac='.$cb2['hmac'], $cb2['body'])->assertOk();

    $declined = capiOrder(100.0);
    $cb3 = PaymentFixture::processedCallback(PaymentFixture::orderNumber($declined), 10000, success: false, transactionId: 771003);
    postJson('/api/pay/watchizer/paymob/callback?hmac='.$cb3['hmac'], $cb3['body'])->assertOk();

    expect(array_column(metaRows($scoped), 'status'))->toBe(['sent'])
        ->and(array_column(metaRows($alias), 'status'))->toBe(['sent'])
        ->and(metaRows($declined))->toBe([]);
});

/** Run meta:capi-check and return its output. */
/** @param  array<string, mixed>  $args */
function capiCheck(array $args = []): string
{
    Artisan::call('meta:capi-check', $args);

    return Artisan::output();
}

it('meta:capi-check names a look-alike Cyrillic letter in the token, by position', function () {
    config(['services.meta_capi.token' => 'EAAabc'."\u{0445}".'yz']); // Cyrillic small letter ha where an "x" belongs
    fakeMeta(400, ['error' => ['message' => 'Invalid OAuth access token', 'type' => 'OAuthException', 'code' => 190]]);

    $out = capiCheck();

    expect($out)->toContain('CHARS   FAIL', 'position 7: U+0445', 'TOKEN   FAIL', 'THE TOKEN IS WRONG OR EXPIRED');
    expect($out)->not->toContain('EAAabc');   // the token is never printed
});

it('meta:capi-check says TOKEN OK when Meta accepts it, and sends a test event only with a test code', function () {
    Http::fake([
        'graph.facebook.com/v23.0/1614877760150035/events' => Http::response(['events_received' => 1], 200),
        'graph.facebook.com/v23.0/1614877760150035*' => Http::response(['id' => '1614877760150035', 'name' => 'Watchizer new'], 200),
    ]);

    $withoutCode = capiCheck(['--send-test' => true]);
    config(['services.meta_capi.test_event_code' => 'TEST24178']);
    $withCode = capiCheck(['--send-test' => true]);

    expect($withoutCode)->toContain('CHARS   OK', 'TOKEN   OK', 'EVENTS  REFUSED');
    expect($withCode)->toContain('EVENTS  OK', 'TEST24178');
    expect($withCode)->not->toContain(CAPI_TOKEN);
});

it('meta:capi-check says TOKEN OK, not FAIL, for a valid token that may only send events', function () {
    // What the production token got on 2026-09-29: the pixel read refused, yet the test Purchase
    // reached Events Manager → Test events. A refused READ is not a rejected TOKEN.
    // ONE fake with a swappable answer: Http::fake() stacks, and the first match would win.
    $answer = [400, []];
    Http::fake(['graph.facebook.com/*' => function () use (&$answer) {
        return Http::response($answer[1], $answer[0]);
    }]);
    foreach ([10, 100, 200, 294] as $code) {
        $answer = [400, ['error' => [
            'message' => 'Unsupported get request. Object does not exist, cannot be loaded due to missing permissions',
            'type' => 'GraphMethodException', 'code' => $code, 'error_subcode' => 33,
        ]]];

        $out = capiCheck();

        expect($out)->toContain('TOKEN   OK', 'may not read pixel', "code {$code}")
            ->and($out)->not->toContain('TOKEN   FAIL')
            ->and(Artisan::call('meta:capi-check'))->toBe(0);
    }

    // …while a rejected token is still a loud FAIL, and a Meta outage is not waved through.
    $answer = [400, ['error' => ['message' => 'Error validating access token', 'type' => 'OAuthException', 'code' => 190]]];
    expect(capiCheck())->toContain('TOKEN   FAIL', 'THE TOKEN IS WRONG OR EXPIRED');
    $answer = [500, ['error' => ['message' => 'An unknown error occurred', 'type' => 'OAuthException', 'code' => 1]]];
    expect(capiCheck())->toContain('TOKEN   FAIL');
});

it('records the browser signals once per order and ignores a malformed _fbp', function () {
    $orderId = PaymentFixture::order();
    $r = Request::create('/api/add_order', 'POST', ['fbp' => '<script>'], [], [], ['HTTP_USER_AGENT' => 'UA-1']);
    MetaConversions::recordSignals($orderId, $r);
    MetaConversions::recordSignals($orderId, Request::create('/api/add_order', 'POST', [], [], [], ['HTTP_USER_AGENT' => 'UA-2']));

    $row = (array) DB::table('core_order_signals')->where('order_id', $orderId)->first();
    expect($row['user_agent'])->toBe('UA-1')->and($row['fbp'])->toBeNull();
});
