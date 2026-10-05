<?php

use App\Compat\CompatCart;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\OrderRecovery;
use App\Mail\PaymentExpired;
use App\Mail\PaymentExpiredAdmin;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

/*
 * ── Bringing back a card order that expired unpaid (2026-10-05, developer: version B) ─────────────
 *
 * When `orders:expire-unpaid` cancels a card order, the customer gets an e-mail with a link that
 * rebuilds the order at today's prices and stock, and the admins get an immediate copy to call while
 * it is fresh — unless the shopper already came back and ordered (never mail them: a TEST, as the
 * developer asked, not a comment). The link exposes exactly OrderRecovery::payload(), asserted key
 * by key below.
 */

const OR_API_KEY = 'order-recovery-test-key';

beforeEach(function () {
    config([
        'compat.api_key' => OR_API_KEY,
        'compat.unpaid.expire_after_minutes' => 60,
        'notifications.admin_emails' => ['ops@example.test'],
        'notifications.send.inline' => true,
        'notifications.send.inside_transaction' => true,       // the suite never commits; see config
        'notifications.send.park' => false,
    ]);
    DB::table('storefront_payment_providers')->delete();
    PaymentFixture::method(PaymentFixture::paymob(), 'card', '4001');
    Http::preventStrayRequests();
    Http::fake(['*/intention/' => Http::response(['client_secret' => 'cs_test', 'id' => 'intent_1'], 200)]);
    Mail::fake();
});

/** @return array{id: int, price: float} a simple Watchizer product with 5 express units */
function orProduct(int $skip = 0): array
{
    $row = T::row(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1);
        })
        ->whereNull('cp.deleted_at')->where('cp.is_active', 1)
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'cp.id'))
        ->orderBy('cp.id')->skip($skip)->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']));
    $id = Row::int($row, 'id');
    app(InventoryService::class)->set(StockTarget::product($id), 'express', 5, 'adjustment', note: 'order-recovery test');

    return ['id' => $id, 'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price'))];
}

/**
 * A guest order through the real endpoint; returns its id.
 *
 * @param  list<array{id: int, price: float, qty?: int}>  $products
 */
function orOrder(array $products, string $method, string $guest, ?string $email, string $phone = '01012345678'): int
{
    $city = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));
    $items = array_map(fn (array $p): array => ['product_id' => $p['id'], 'quantity' => $p['qty'] ?? 1, 'piece_price' => $p['price'],
        'total_price' => $p['price'] * ($p['qty'] ?? 1), 'type_stock' => 'Express'], $products);
    $total = array_sum(array_map(fn (array $p): float => $p['price'] * ($p['qty'] ?? 1), $products)) + (float) Row::money($city, 'shipping_cost');
    $response = withHeaders(['Api-Code' => OR_API_KEY, 'X-Guest-Token' => $guest])
        ->postJson('https://api.watchizereg.com/api/add_order', array_filter([
            'address_line' => 'Corniche El Nil 12', 'shipping_city_id' => Row::int($city, 'id'), 'phone' => $phone,
            'guest_name' => 'Sara Ahmed', 'guest_phone' => $phone, 'guest_email' => $email,
            'total_price_for_order' => round($total, 2), 'payment_method' => $method, 'items' => $items,
        ], fn ($v) => $v !== null))->assertOk();

    return T::int(DB::table('orders')->where('order_number', T::str($response->json('order_number')))->value('id'));
}

function orExpire(int $orderId): void
{
    DB::table('orders')->where('id', $orderId)->update(['created_at' => now()->subMinutes(61)]);
    expect(Artisan::call('orders:expire-unpaid'))->toBe(0);
    expect(DB::table('orders')->where('id', $orderId)->value('status'))->toBe('cancelled');
}

/** @return list<string> the kinds of mail rows this order caused */
function orKinds(int $orderId): array
{
    return array_map(fn (array $row): string => T::str($row['kind']), OrderMailer::forOrder($orderId));
}

it('e-mails the customer a link that brings the order back, and the admins an immediate copy', function () {
    $orderId = orOrder([orProduct()], 'card', (string) Str::uuid(), 'sara@example.test');
    expect(orKinds($orderId))->toBe([]);                              // a card order says nothing at checkout

    orExpire($orderId);

    expect(orKinds($orderId))->toEqualCanonicalizing([OrderMailer::KIND_EXPIRED, OrderMailer::KIND_EXPIRED_ADMIN]);
    Mail::assertSent(PaymentExpired::class, fn (PaymentExpired $m) => $m->hasTo('sara@example.test'));
    Mail::assertSent(PaymentExpiredAdmin::class, fn (PaymentExpiredAdmin $m) => $m->hasTo('ops@example.test'));

    // The e-mail's link names this order, on its own storefront, and verifies.
    $html = '';
    Mail::assertSent(PaymentExpired::class, function (PaymentExpired $m) use (&$html) {
        $html = $m->render();

        return true;
    });
    expect(preg_match('#https://watchizereg\.com/cart/recover\?t=([0-9]+\.[0-9]+\.[a-f0-9]{32})#', $html, $m))->toBe(1)
        ->and(OrderRecovery::verify($m[1] ?? ''))->toBe(['order' => $orderId]);

    // Once per order: a second tick sends nothing more.
    Artisan::call('orders:expire-unpaid');
    expect(count(orKinds($orderId)))->toBe(2);
});

it('NEVER mails a shopper who already came back and ordered — same e-mail, same phone, same account', function (string $how) {
    $product = orProduct();
    $first = orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test', '01012345678');
    match ($how) {
        // Back on another device (a new guest token) with the same e-mail, paying cash.
        'email' => orOrder([$product], 'cash', (string) Str::uuid(), 'SARA@example.test', '01199999999'),
        // Same phone written internationally, no e-mail this time.
        'phone' => orOrder([$product], 'cash', (string) Str::uuid(), null, '+20 101 234 5678'),
        // Signed in on the way back: the later order belongs to the same account.
        'account' => (function () use ($first): void {
            $userId = T::int(DB::table('users')->orderBy('id')->value('id'));
            DB::table('orders')->where('id', $first)->update(['user_id' => $userId]);
            DB::table('orders')->insert(['storefront_id' => 1, 'user_id' => $userId, 'status' => 'pending', 'payment_method' => 'cash',
                'address_id' => DB::table('orders')->where('id', $first)->value('address_id'),
                'total_price_for_order' => 1, 'order_number' => 'TEST-LATER', 'created_at' => now(), 'updated_at' => now()]);
        })(),
        default => throw new LogicException("unknown case {$how}"),
    };

    orExpire($first);

    expect(orKinds($first))->toBe([]);
    Mail::assertNotSent(PaymentExpired::class);
    Mail::assertNotSent(PaymentExpiredAdmin::class);
})->with(['email', 'phone', 'account']);

it('sends nothing for an order the same shopper superseded by ordering again', function () {
    $product = orProduct();
    $guest = (string) Str::uuid();
    $first = orOrder([$product], 'card', $guest, 'sara@example.test');
    orOrder([$product], 'cash', $guest, 'sara@example.test');          // back from Paymob, chose cash

    expect(DB::table('orders')->where('id', $first)->value('status'))->toBe('cancelled');
    expect(array_diff(orKinds($first), [OrderMailer::KIND_CUSTOMER, OrderMailer::KIND_ADMIN]))->toBe([]);
    Mail::assertNotSent(PaymentExpired::class);
});

it('still tells the admins when the customer left no e-mail address — the phone is what they need', function () {
    $orderId = orOrder([orProduct()], 'card', (string) Str::uuid(), null);
    orExpire($orderId);

    $rows = collect(OrderMailer::forOrder($orderId))->keyBy('kind');
    expect(T::arr($rows[OrderMailer::KIND_EXPIRED])['status'])->toBe(OrderMailer::STATUS_SKIPPED)
        ->and(T::arr($rows[OrderMailer::KIND_EXPIRED_ADMIN])['status'])->toBe(OrderMailer::STATUS_SENT);
    Mail::assertSent(PaymentExpiredAdmin::class, fn (PaymentExpiredAdmin $m) => str_contains($m->render(), 'tel:+201012345678'));
});

it('accepts only an intact, unexpired token for that order', function () {
    $token = OrderRecovery::token(42);
    [$id, $expires, $mac] = explode('.', $token);

    expect(OrderRecovery::verify($token))->toBe(['order' => 42])
        ->and(OrderRecovery::verify("43.{$expires}.{$mac}"))->toBe(['refused' => 'invalid'])          // another order
        ->and(OrderRecovery::verify('42.'.((int) $expires + 86400).".{$mac}"))->toBe(['refused' => 'invalid']) // a longer life
        ->and(OrderRecovery::verify("42.{$expires}.".str_repeat('0', 32)))->toBe(['refused' => 'invalid'])
        ->and(OrderRecovery::verify('nonsense'))->toBe(['refused' => 'invalid']);

    travel(OrderRecovery::DAYS + 1)->days();
    expect(OrderRecovery::verify($token))->toBe(['refused' => 'expired']);
});

it('exposes exactly the order\'s lines and the details typed on it — nothing else', function () {
    // The developer ACCEPTED this exposure for exactly these keys (2026-10-05, OrderRecovery's
    // docblock). A new key fails here on purpose: take it back to the developer, do not widen the list.
    $a = orProduct();
    $b = orProduct(1);
    $orderId = orOrder([$a, $b], 'card', (string) Str::uuid(), 'sara@example.test');
    orExpire($orderId);

    $r = withHeaders(['Api-Code' => OR_API_KEY])->postJson('https://api.watchizereg.com/api/cart/recover', ['token' => OrderRecovery::token($orderId)])
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $recovery = T::arr($r->json('recovery'));

    expect(array_keys($recovery))->toBe(['order_number', 'lines', 'details'])
        ->and(array_keys(T::arr($recovery['details'])))->toBe(['name', 'email', 'phone', 'address_line', 'shipping_city_id'])
        ->and(T::arr($recovery['details'])['email'])->toBe('sara@example.test')
        ->and(T::arr($recovery['details'])['address_line'])->toBe('Corniche El Nil 12')
        ->and(array_keys(T::arr(T::arr($recovery['lines'])[0])))->toBe(['product_id', 'name', 'quantity', 'quoted_price', 'color_band', 'color_dial', 'state', 'price', 'type_stock', 'available'])
        ->and(array_column(T::rows($recovery['lines']), 'state'))->toBe([['ok'], ['ok']]);

    // No API key: the API host's key, like every compat read. (withHeaders() above persists for the
    // rest of the test, so the key is overwritten with nothing rather than left out.)
    withHeaders(['Api-Code' => ''])->postJson('https://api.watchizereg.com/api/cart/recover', ['token' => OrderRecovery::token($orderId)])->assertUnauthorized();
});

it('says plainly what changed: sold out, fewer left, a new price, slower delivery', function () {
    $a = orProduct();
    $b = orProduct(1);
    $c = orProduct(2);
    $d = orProduct(3) + ['qty' => 2];
    $orderId = orOrder([$a, $b, $c, $d], 'card', (string) Str::uuid(), 'sara@example.test');
    orExpire($orderId);                                                 // stock back to 5 each

    $inv = app(InventoryService::class);
    $inv->set(StockTarget::product($a['id']), 'express', 0, 'adjustment', note: 'test');              // sold out: both kinds
    $inv->set(StockTarget::product($a['id']), 'market', 0, 'adjustment', note: 'test');
    $inv->set(StockTarget::product($b['id']), 'express', 0, 'adjustment', note: 'test');              // only market left
    $inv->set(StockTarget::product($c['id']), 'market', 0, 'adjustment', note: 'test');
    $inv->set(StockTarget::product($b['id']), 'market', 3, 'adjustment', note: 'test');
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $c['id'])
        ->update(['effective_price' => $c['price'] + 250, 'effective_sale_price' => null]);           // new price
    $inv->set(StockTarget::product($d['id']), 'express', 1, 'adjustment', note: 'test');              // 2 ordered, 1 left

    $lines = T::rows(withHeaders(['Api-Code' => OR_API_KEY])->postJson('https://api.watchizereg.com/api/cart/recover', ['token' => OrderRecovery::token($orderId)])
        ->assertOk()->json('recovery.lines'));

    expect($lines[0]['state'])->toBe(['sold_out'])
        ->and($lines[1]['state'])->toBe(['slower_delivery'])
        ->and($lines[1]['type_stock'])->toBe('Market')
        ->and($lines[2]['state'])->toBe(['price_changed'])
        ->and(T::float($lines[2]['price']))->toBe(round($c['price'] + 250, 2))
        ->and($lines[3]['state'])->toBe(['reduced'])
        ->and($lines[3]['available'])->toBe(1);
});

it('refuses: a link of another storefront\'s order, an order no longer cancelled, an expired link', function () {
    $orderId = orOrder([orProduct()], 'card', (string) Str::uuid(), 'sara@example.test');
    $ask = fn () => withHeaders(['Api-Code' => OR_API_KEY])->postJson('https://api.watchizereg.com/api/cart/recover', ['token' => OrderRecovery::token($orderId)]);

    $ask()->assertNotFound()->assertJsonPath('refused', 'invalid');      // still pending: nothing to bring back
    orExpire($orderId);
    $ask()->assertOk();

    DB::table('orders')->where('id', $orderId)->update(['storefront_id' => 2]);
    $ask()->assertNotFound()->assertJsonPath('refused', 'invalid');      // another shop's order on Watchizer's host
    DB::table('orders')->where('id', $orderId)->update(['storefront_id' => 1]);

    $token = OrderRecovery::token($orderId);
    travel(OrderRecovery::DAYS + 1)->days();
    withHeaders(['Api-Code' => OR_API_KEY])->postJson('https://api.watchizereg.com/api/cart/recover', ['token' => $token])
        ->assertStatus(410)->assertJsonPath('refused', 'expired');
});

/*
 * ── One recovery e-mail per shopper per week (developer, 2026-10-05) ──────────────────────────────
 *
 * Measured first (journeys J1–J6): attempts under an hour apart already collapse to one e-mail —
 * the next order supersedes the last, or `shouldRemind` sees it. Attempts MORE than an hour apart
 * each expired on their own and each mailed (J3, J4). The ceiling: at most one per shopper per
 * DAYS days, the admin copy following it; the window slides from the last e-mail, so a new visit
 * weeks later is heard from again. The developer's "only from the second attempt" rule was
 * PROPOSED AND WITHDRAWN: it would have silenced order 000024, a single attempt.
 */

/** @return list<string> the recovery kinds of mail this order caused, sorted */
function orRecoveryKinds(int $orderId): array
{
    $kinds = array_values(array_intersect(orKinds($orderId), [OrderMailer::KIND_EXPIRED, OrderMailer::KIND_EXPIRED_ADMIN]));
    sort($kinds);

    return $kinds;
}

/** @return list<string> both recovery kinds, sorted — what one mailed expiry leaves */
function orBoth(): array
{
    $both = [OrderMailer::KIND_EXPIRED, OrderMailer::KIND_EXPIRED_ADMIN];
    sort($both);

    return $both;
}

it('J3 — back an hour later in a new tab, abandons again: one e-mail that week, not two', function () {
    $product = orProduct();
    $guest = (string) Str::uuid();                                     // a new tab keeps the guest token
    $first = orOrder([$product], 'card', $guest, 'sara@example.test');
    orExpire($first);                                                  // the hour passed: mailed
    $second = orOrder([$product], 'card', $guest, 'sara@example.test');
    orExpire($second);

    expect(orRecoveryKinds($first))->toBe(orBoth())
        ->and(orRecoveryKinds($second))->toBe([]);                     // nor the admin copy
    Mail::assertSent(PaymentExpired::class, 1);
    Mail::assertSent(PaymentExpiredAdmin::class, 1);
});

it('J4 — two days later on another device (new guest token, same e-mail): still one e-mail that week', function () {
    $product = orProduct();
    $first = orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test', '01012345678');
    orExpire($first);
    travel(2)->days();
    $second = orOrder([$product], 'card', (string) Str::uuid(), 'SARA@example.test', '01012345678');
    orExpire($second);

    expect(orRecoveryKinds($second))->toBe([]);
    Mail::assertSent(PaymentExpired::class, 1);
    Mail::assertSent(PaymentExpiredAdmin::class, 1);
});

it('J4 by phone alone — another device, no e-mail typed, the same number written differently', function () {
    $product = orProduct();
    $first = orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test', '01012345678');
    orExpire($first);
    travel(2)->days();
    $second = orOrder([$product], 'card', (string) Str::uuid(), null, '+20 101 234 5678');
    orExpire($second);

    expect(orRecoveryKinds($second))->toBe([]);
    Mail::assertSent(PaymentExpiredAdmin::class, 1);
});

it('J6 — three attempts in 22 seconds send ONE e-mail and ONE admin copy', function (string $how) {
    $product = orProduct();
    $guest = (string) Str::uuid();
    $ids = [];
    foreach ([0, 1, 2] as $i) {
        // The same browser (one guest token), or a script (a new token each time, the same e-mail).
        $ids[] = orOrder([$product], 'card', $how === 'same browser' ? $guest : (string) Str::uuid(), 'sara@example.test');
    }
    DB::table('orders')->whereIn('id', $ids)->update(['created_at' => now()->subMinutes(61)]);
    expect(Artisan::call('orders:expire-unpaid'))->toBe(0);

    expect(array_map(fn (int $id): array => orRecoveryKinds($id), $ids))->toBe([[], [], orBoth()]);
    Mail::assertSent(PaymentExpired::class, 1);
    Mail::assertSent(PaymentExpiredAdmin::class, 1);
})->with(['same browser', 'a script']);

it('a new visit three weeks later IS e-mailed again — the ceiling is a week, not for ever', function () {
    $product = orProduct();
    $first = orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test');
    orExpire($first);
    travel(21)->days();
    $later = orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test');
    orExpire($later);

    expect(orRecoveryKinds($later))->toBe(orBoth());
    Mail::assertSent(PaymentExpired::class, 2);
});

it('the week slides from the last e-mail SENT: day 6 is quiet, day 8 is mailed', function () {
    $product = orProduct();
    orExpire(orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test'));   // mailed, day 0
    travel(6)->days();
    $day6 = orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test');
    orExpire($day6);                                                   // inside the week: quiet
    travel(2)->days();
    $day8 = orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test');
    orExpire($day8);                                                   // 8 days after the e-mail

    expect(orRecoveryKinds($day6))->toBe([])
        ->and(orRecoveryKinds($day8))->toBe(orBoth());
});

it('another shopper is not silenced by someone else\'s e-mail', function () {
    $product = orProduct();
    orExpire(orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test', '01012345678'));
    $other = orOrder([$product], 'card', (string) Str::uuid(), 'mona@example.test', '01199999999');
    orExpire($other);

    expect(orRecoveryKinds($other))->toBe(orBoth());
});

/*
 * ── J5b: the link of an order the shopper has since bought (developer, 2026-10-05) ────────────────
 *
 * Rebuilding a cart for someone who already paid is how the same thing gets ordered twice. Refused
 * with `reordered` (409) once a LATER order of the same shopper went through; a card order still
 * waiting at Paymob has not gone through, and a cancelled one never did.
 */
it('J5b — refuses the old link once the same shopper\'s later order went through', function (string $later, int $status) {
    $product = orProduct();
    $first = orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test');
    orExpire($first);
    $second = orOrder([$product], $later === 'cash' ? 'cash' : 'card', (string) Str::uuid(), 'sara@example.test');
    if ($later === 'card, paid') {
        DB::table('orders')->where('id', $second)->update(['status' => 'processing']);   // the callback's paid state
    }
    if ($later === 'card, cancelled') {
        orExpire($second);
    }

    $r = withHeaders(['Api-Code' => OR_API_KEY])->postJson('https://api.watchizereg.com/api/cart/recover', ['token' => OrderRecovery::token($first)])
        ->assertStatus($status)->assertHeader('Cache-Control', 'no-store, private');
    if ($status === 409) {
        expect($r->json())->toBe(['refused' => 'reordered']);         // and nothing of the order
    }
})->with([
    'a paid card order' => ['card, paid', 409],
    'a cash order' => ['cash', 409],
    'a card order still at Paymob' => ['card, pending', 200],
    'a card order that expired too' => ['card, cancelled', 200],
]);

it('J5b — another shopper\'s later order does not refuse the link', function () {
    $product = orProduct();
    $first = orOrder([$product], 'card', (string) Str::uuid(), 'sara@example.test', '01012345678');
    orExpire($first);
    orOrder([$product], 'cash', (string) Str::uuid(), 'mona@example.test', '01199999999');

    withHeaders(['Api-Code' => OR_API_KEY])->postJson('https://api.watchizereg.com/api/cart/recover', ['token' => OrderRecovery::token($first)])
        ->assertOk();
});
