<?php

use App\Domain\Notifications\OrderEmailData;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Notifications\PickupWhatsApp;
use App\Domain\Pickup\Pickup;
use App\Domain\Pickup\PickupLifecycle;
use App\Domain\Pickup\PickupStores;
use App\Mail\OrderStatusUpdate;
use App\Mail\PickupUpdate;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\put;

/*
 * "Pick up in store" (click & collect, 2026-11-14) — Part 3: the lifecycle. Reserved → READY (the team, the hold clock
 * starts, the customer is e-mailed) → COLLECTED against the 4-digit code (the order becomes delivered), with ONE
 * extension by the team; a cancel by any path closes the reservation; the expiry job releases a pay-at-the-store
 * reservation past its collect-by time exactly once and leaves a prepaid one for a person; the queue filters by
 * delivery/pickup, store and pickup state; the order page carries the panel, the WhatsApp message and the slip.
 */

const PKL_PHONE = '01098765432';

/** Pickup on for Brand Fashion with its pay-at-store method enabled; places one pay-at-store pickup and returns its id. */
function pklPlace(): int
{
    DB::table(PickupStores::TABLE)->where('storefront_id', 2)->update(['hours_en' => 'Daily 10:00–24:00', 'hours_ar' => 'يوميًا']);
    Pickup::setEnabled(2, true, 'Brand Fashion');
    $method = T::int(DB::table('storefront_payment_methods as m')
        ->join('storefront_payment_providers as p', 'p.id', '=', 'm.storefront_payment_provider_id')
        ->where('p.storefront_id', 2)->where('p.provider', 'store')->where('m.method', 'store')->value('m.id'));
    DB::table('storefront_payment_methods')->where('id', $method)->update(['is_enabled' => true]);
    $store = PickupStores::forStorefront(2, activeOnly: true)[0]['id'];

    $product = T::int(DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 2)->where('sp.is_visible', '=', 1))
        ->where('p.is_active', 1)->whereNull('p.deleted_at')->where('p.stock_express', '>=', 3)
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id')->where('v.is_active', 1))
        ->orderBy('p.id')->value('p.id'));
    $res = postJson('/api/v2/brandfashion/cart/items', ['product_id' => $product, 'bucket' => 'Express', 'quantity' => 1])->assertOk();
    $token = T::str($res->headers->get('X-Guest-Token'));
    $total = round(T::float($res->json('data.totals.subtotal')), 2);

    $order = postJson('/api/v2/brandfashion/orders', [
        'total' => $total, 'payment_method' => 'cash', 'payment_method_id' => $method, 'fulfilment' => 'pickup',
        'pickup_store_id' => $store, 'phone' => PKL_PHONE, 'guest_name' => 'Lifecycle Buyer', 'guest_email' => 'lifecycle.buyer@example.com',
    ], ['X-Guest-Token' => $token])->assertCreated();

    return T::int(DB::table('orders')->where('order_number', T::str($order->json('data.order_number')))->value('id'));
}

/** @return array<string, mixed> the order's e-mail data */
function pklData(int $orderId): array
{
    $data = OrderEmailData::for($orderId);
    expect($data)->not->toBeNull();

    return $data ?? [];
}

function pklPickup(int $orderId): stdClass
{
    return T::row(DB::table('order_pickups')->where('order_id', $orderId)->first());
}

/** @return list<string> the outbox mail kinds for an order, oldest first */
function pklMailKinds(int $orderId): array
{
    $kinds = [];
    foreach (DB::table('integration_outbox')->where('channel', 'mail')->where('aggregate_id', $orderId)->orderBy('id')->pluck('payload') as $payload) {
        $kinds[] = T::str(T::arr(json_decode(T::str($payload), true))['kind'] ?? null).':'.T::str(T::arr(json_decode(T::str($payload), true))['pickup_event'] ?? T::arr(json_decode(T::str($payload), true))['status'] ?? '');
    }

    return $kinds;
}

it('makes a pickup ready once: the hold clock starts at the shop\'s hours, the customer is e-mailed, the order stays processing', function () {
    $id = pklPlace();
    actingAs(Staff::dataEntry());
    Carbon::setTestNow(now()->startOfMinute());

    post("/manage/orders/{$id}/pickup/ready")->assertRedirect()->assertSessionHasNoErrors();
    $p = pklPickup($id);
    expect(T::str($p->status))->toBe('ready')
        ->and(Carbon::parse(T::str($p->expires_at))->equalTo(now()->addHours(48)))->toBeTrue()
        ->and($p->ready_by)->not->toBeNull()
        ->and(T::str(DB::table('orders')->where('id', $id)->value('status')))->toBe('processing')
        ->and(pklMailKinds($id))->toContain(OrderMailer::KIND_PICKUP.':ready');

    // a second press is refused and sends nothing
    post("/manage/orders/{$id}/pickup/ready")->assertSessionHasErrors('pickup');
    expect(array_count_values(pklMailKinds($id))[OrderMailer::KIND_PICKUP.':ready'])->toBe(1);

    // the e-mail: ready, the store, the code, the collect-by time on the Cairo clock
    $data = pklData($id);
    $html = (new PickupUpdate($data + ['pickupEvent' => 'ready']))->render();
    expect($html)->toContain('Ready for pickup')->toContain('جاهز للاستلام')->toContain('Arkadia Mall')
        ->toContain(T::str($p->code))->toContain(T::str(T::arr($data['pickupCollectBy'])['en']));
    Carbon::setTestNow();
});

it('refuses ready for an order not yet paid online', function () {
    $id = pklPlace();
    DB::table('orders')->where('id', $id)->update(['status' => 'pending']);
    actingAs(Staff::admin());
    post("/manage/orders/{$id}/pickup/ready")->assertSessionHasErrors('pickup');
    expect(T::str(pklPickup($id)->status))->toBe('reserved');
});

it('hands over only against the right code; the order becomes delivered, the customer is told "collected", and it can no longer be cancelled', function () {
    $id = pklPlace();
    actingAs(Staff::dataEntry());
    post("/manage/orders/{$id}/pickup/collect", ['code' => T::str(pklPickup($id)->code)])->assertSessionHasErrors('code');   // not ready yet
    post("/manage/orders/{$id}/pickup/ready")->assertSessionHasNoErrors();

    $code = T::str(pklPickup($id)->code);
    $wrong = str_pad((string) ((((int) $code) + 1) % 10000), 4, '0', STR_PAD_LEFT);
    post("/manage/orders/{$id}/pickup/collect", ['code' => $wrong])->assertSessionHasErrors('code');
    post("/manage/orders/{$id}/pickup/collect", ['code' => '12a'])->assertSessionHasErrors('code');
    expect(T::str(pklPickup($id)->status))->toBe('ready');

    post("/manage/orders/{$id}/pickup/collect", ['code' => $code])->assertSessionHasNoErrors();
    $p = pklPickup($id);
    expect(T::str($p->status))->toBe('collected')->and($p->collected_at)->not->toBeNull()->and($p->closed_at)->not->toBeNull()
        ->and(T::str(DB::table('orders')->where('id', $id)->value('status')))->toBe('delivered')
        ->and(pklMailKinds($id))->toContain(OrderMailer::KIND_STATUS.':delivered');

    $html = (new OrderStatusUpdate(pklData($id)))->render();
    expect($html)->toContain('You collected your order from Arkadia Mall')->toContain('Collected');
    expect(str_contains($html, 'has been delivered'))->toBeFalse();

    actingAs(Staff::admin());
    post("/manage/orders/{$id}/cancel")->assertSessionHasErrors('cancel');
    // delivered → completed closes it, as for any order
    put("/manage/orders/{$id}/status", ['status' => 'completed'])->assertSessionHasNoErrors();
});

it('extends once, by the shop\'s extension hours, and e-mails the customer', function () {
    $id = pklPlace();
    actingAs(Staff::dataEntry());
    post("/manage/orders/{$id}/pickup/extend")->assertSessionHasErrors('pickup');            // the clock has not started
    post("/manage/orders/{$id}/pickup/ready")->assertSessionHasNoErrors();
    $before = Carbon::parse(T::str(pklPickup($id)->expires_at));

    post("/manage/orders/{$id}/pickup/extend")->assertSessionHasNoErrors();
    $p = pklPickup($id);
    expect(Carbon::parse(T::str($p->expires_at))->equalTo($before->copy()->addHours(24)))->toBeTrue()
        ->and($p->extended_at)->not->toBeNull()
        ->and(pklMailKinds($id))->toContain(OrderMailer::KIND_PICKUP.':extended');
    post("/manage/orders/{$id}/pickup/extend")->assertSessionHasErrors('pickup');
    expect(Carbon::parse(T::str(pklPickup($id)->expires_at))->equalTo($before->copy()->addHours(24)))->toBeTrue();

    $html = (new PickupUpdate(pklData($id) + ['pickupEvent' => 'extended']))->render();
    expect($html)->toContain('Held for you longer')->toContain('تم تمديد الحجز');
});

it('never ships a pickup order: shipped is refused and not offered', function () {
    $id = pklPlace();
    actingAs(Staff::admin());
    put("/manage/orders/{$id}/status", ['status' => 'shipped'])->assertSessionHasErrors('status');
    expect(T::str(DB::table('orders')->where('id', $id)->value('status')))->toBe('processing');
    $props = Props::of(get("/manage/orders/{$id}")->assertOk());
    expect(T::arr($props['options'] ?? null)['advance'] ?? null)->toBe([]);
});

it('closes the reservation when the team cancels, and the stock comes back', function () {
    $id = pklPlace();
    $product = T::int(DB::table('order_items')->where('order_id', $id)->value('product_id'));
    $stock = T::int(DB::table('catalog_products')->where('id', $product)->value('stock_express'));
    actingAs(Staff::admin());
    post("/manage/orders/{$id}/cancel", ['note' => 'customer called'])->assertSessionHasNoErrors();
    $p = pklPickup($id);
    expect(T::str($p->status))->toBe('cancelled')->and($p->closed_at)->not->toBeNull()
        ->and(T::int(DB::table('catalog_products')->where('id', $product)->value('stock_express')))->toBe($stock + 1);
    post("/manage/orders/{$id}/pickup/ready")->assertSessionHasErrors('pickup');
});

it('releases a pay-at-the-store reservation past its collect-by time exactly once; a prepaid one waits for a person', function () {
    $id = pklPlace();
    $prepaid = pklPlace();
    $product = T::int(DB::table('order_items')->where('order_id', $id)->value('product_id'));
    actingAs(Staff::admin());
    post("/manage/orders/{$id}/pickup/ready")->assertSessionHasNoErrors();
    post("/manage/orders/{$prepaid}/pickup/ready")->assertSessionHasNoErrors();
    DB::table('order_pickups')->where('order_id', $prepaid)->update(['pay_at_store' => false]);
    $stock = T::int(DB::table('catalog_products')->where('id', $product)->value('stock_express'));

    // not yet due: nothing happens
    expect(Artisan::call('orders:expire-pickups'))->toBe(0);
    expect(T::str(pklPickup($id)->status))->toBe('ready');

    DB::table('order_pickups')->whereIn('order_id', [$id, $prepaid])->update(['expires_at' => now()->subMinute()]);
    expect(Artisan::call('orders:expire-pickups', ['--dry-run' => true]))->toBe(0);
    expect(T::str(pklPickup($id)->status))->toBe('ready');

    expect(Artisan::call('orders:expire-pickups'))->toBe(0);
    expect(Artisan::call('orders:expire-pickups'))->toBe(0);          // twice: nothing twice
    expect(T::str(pklPickup($id)->status))->toBe('expired')
        ->and(T::str(DB::table('orders')->where('id', $id)->value('status')))->toBe('cancelled')
        ->and(DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $id)->where('reason', 'order_cancel')->count())->toBe(1)
        // the prepaid one is untouched, and the order page flags it overdue
        ->and(T::str(pklPickup($prepaid)->status))->toBe('ready')
        ->and(T::str(DB::table('orders')->where('id', $prepaid)->value('status')))->toBe('processing');
    // both orders hold the same product; one unit came back
    expect(T::int(DB::table('catalog_products')->where('id', $product)->value('stock_express')))->toBe($stock + 1);

    $html = (new OrderStatusUpdate(pklData($id)))->render();
    expect($html)->toContain('was not collected from Arkadia Mall in time')->toContain('Order Again')->toContain('انتهى الحجز');

    $panel = T::arr(Props::of(get("/manage/orders/{$prepaid}")->assertOk())['pickup'] ?? null);
    expect($panel['overdue'] ?? null)->toBeTrue()->and(T::arr($panel['actions'] ?? null)['extend'] ?? null)->toBeTrue();
});

it('filters the queue by pickup, store and state, and badges a pickup order', function () {
    $id = pklPlace();
    $store = T::int(pklPickup($id)->store_id);
    actingAs(Staff::admin());
    post("/manage/orders/{$id}/pickup/ready")->assertSessionHasNoErrors();

    $ids = fn (string $query): array => array_column(T::arr(T::arr(Props::of(get('/manage/orders?'.$query)->assertOk())['table'] ?? null)['data'] ?? null), 'id');
    expect($ids('filters[fulfilment]=pickup'))->toContain($id)
        ->and($ids('filters[fulfilment]=delivery'))->not->toContain($id)
        ->and($ids("filters[pickup_store]={$store}"))->toContain($id)
        ->and($ids('filters[pickup_state]=ready'))->toContain($id)
        ->and($ids('filters[pickup_state]=reserved'))->not->toContain($id)
        ->and($ids('filters[pickup_state]=expiring_today'))->not->toContain($id)
        ->and($ids('filters[pickup_state]=overdue'))->not->toContain($id);
    DB::table('order_pickups')->where('order_id', $id)->update(['expires_at' => now()->subMinute()]);
    expect($ids('filters[pickup_state]=overdue'))->toContain($id);

    $rows = T::arr(T::arr(Props::of(get('/manage/orders?filters[fulfilment]=pickup')->assertOk())['table'] ?? null)['data'] ?? null);
    $row = T::arr(collect($rows)->firstWhere('id', $id));
    expect(T::arr($row['pickup'] ?? null)['store_en'] ?? null)->toBe('Arkadia Mall')
        ->and(T::arr($row['pickup'] ?? null)['overdue'] ?? null)->toBeTrue();
});

it('gives the order page its panel, a WhatsApp message to the customer and a printable slip — inside the grant\'s shops only', function () {
    $id = pklPlace();
    actingAs(Staff::admin());
    post("/manage/orders/{$id}/pickup/ready")->assertSessionHasNoErrors();
    $p = pklPickup($id);

    $panel = T::arr(Props::of(get("/manage/orders/{$id}")->assertOk())['pickup'] ?? null);
    expect($panel['code'] ?? null)->toBe(T::str($p->code))
        ->and(T::arr($panel['actions'] ?? null))->toBe(['ready' => false, 'extend' => true, 'collect' => true]);
    $wa = T::str($panel['whatsapp_url'] ?? null);
    expect($wa)->toStartWith('https://wa.me/201098765432?text=')
        ->and(rawurldecode(substr($wa, strpos($wa, '=') + 1)))->toContain('كود الاستلام: '.T::str($p->code))->toContain('ready for pickup at Arkadia Mall');
    expect(PickupWhatsApp::waDigits('+20 109 876 5432'))->toBe('201098765432')->and(PickupWhatsApp::waDigits('123'))->toBeNull();

    $slip = get("/manage/orders/{$id}/pickup/slip")->assertOk()->getContent();
    expect($slip)->toContain(T::str($p->code))->toContain('أركاديا مول')->toContain('To collect at the store')->toContain(PKL_PHONE);

    // another shop's scoped admin: the order does not exist for them, at every pickup verb
    actingAs(Staff::adminFor(1));
    get("/manage/orders/{$id}/pickup/slip")->assertNotFound();
    post("/manage/orders/{$id}/pickup/extend")->assertNotFound();
    // and a customer account has no business here at all
    actingAs(Staff::customer());
    post("/manage/orders/{$id}/pickup/collect", ['code' => T::str($p->code)])->assertForbidden();
    expect(T::str(pklPickup($id)->status))->toBe('ready');
});

it('a delivery order has no pickup panel and the pickup verbs refuse it', function () {
    actingAs(Staff::admin());
    $delivery = T::int(DB::table('orders')->where('fulfilment', 'delivery')->whereIn('status', ['processing', 'pending'])->orderByDesc('id')->value('id'));
    $props = Props::of(get("/manage/orders/{$delivery}")->assertOk());
    expect(array_key_exists('pickup', $props))->toBeTrue()->and($props['pickup'])->toBeNull();
    post("/manage/orders/{$delivery}/pickup/ready")->assertSessionHasErrors('pickup');
    expect(PickupLifecycle::row($delivery))->toBeNull();
});
