<?php

use App\Compat\CompatCart;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Orders\UnpaidOrders;
use App\Domain\Payment\PaymobProvider;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * ── A shopper who leaves the payment page does not lose the item (2026-09-26) ─────────────────
 *
 * Reported on production: open Paymob's page, come back without paying, choose cash — "Insufficient
 * stock". The card order had reserved the unit and nothing ever gave it back: Paymob sends no
 * callback for a page that was simply left. Two releases now, both through the inventory service:
 * the SAME shopper's next order supersedes their unpaid one, and `orders:expire-unpaid` cancels any
 * card order still unpaid after the window.
 */

const UO_API_KEY = 'unpaid-orders-test-key';

beforeEach(function () {
    config(['compat.api_key' => UO_API_KEY, 'compat.unpaid.expire_after_minutes' => 60]);
    DB::table('storefront_payment_providers')->delete();
    PaymentFixture::method(PaymentFixture::paymob(), 'card', '4001');
    Http::preventStrayRequests();
    Http::fake(['*/intention/' => Http::response(['client_secret' => 'cs_test', 'id' => 'intent_1'], 200)]);
});

/**
 * A simple product (no variants) on Watchizer, set to exactly ONE express unit.
 *
 * @return array{id: int, price: float}
 */
function uoLastUnit(): array
{
    $row = T::row(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('cp.deleted_at')
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'cp.id'))
        ->orderBy('cp.id')->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']));
    $id = Row::int($row, 'id');
    app(InventoryService::class)->set(StockTarget::product($id), 'express', 1, 'adjustment', note: 'unpaid-orders test');

    return ['id' => $id, 'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price'))];
}

function uoStock(int $productId): int
{
    return T::int(DB::table('catalog_products')->where('id', $productId)->value('stock_express'));
}

/**
 * @param  array{id: int, price: float}  $product
 * @return TestResponse<Response>
 */
function uoOrder(array $product, string $method, string $guest): TestResponse
{
    $city = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));

    return withHeaders(['Api-Code' => UO_API_KEY, 'X-Guest-Token' => $guest])
        ->postJson('https://api.watchizereg.com/api/add_order', [
            'address_line' => 'Test Street 1', 'shipping_city_id' => Row::int($city, 'id'), 'phone' => '01000000000',
            'guest_name' => 'Unpaid Orders', 'guest_phone' => '01000000000',
            'total_price_for_order' => round($product['price'] + (float) Row::money($city, 'shipping_cost'), 2),
            'payment_method' => $method,
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
                'total_price' => $product['price'], 'type_stock' => 'Express']],
        ]);
}

function uoOrderId(string $orderNumber): int
{
    return T::int(DB::table('orders')->where('order_number', $orderNumber)->value('id'));
}

function uoAge(int $orderId, int $minutes): void
{
    DB::table('orders')->where('id', $orderId)->update(['created_at' => now()->subMinutes($minutes)]);
}

it('lets the SAME shopper who left the payment page pay by cash for the last unit', function () {
    // The developer's reproduction, verbatim.
    $product = uoLastUnit();
    $guest = (string) Str::uuid();

    $card = uoOrder($product, 'card', $guest)->assertOk();
    $cardId = uoOrderId(T::str($card->json('order_number')));
    expect(uoStock($product['id']))->toBe(0);

    // Back from Paymob without paying; now cash.
    uoOrder($product, 'cash', $guest)->assertOk()->assertJsonPath('success', true);

    expect(DB::table('orders')->where('id', $cardId)->value('status'))->toBe('cancelled')
        ->and(uoStock($product['id']))->toBe(0)
        ->and(DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $cardId)
            ->where('reason', 'payment_failed')->value('note'))->toBe(UnpaidOrders::SUPERSEDED);
});

it('does NOT hand ANOTHER shopper the unit an unpaid order is still holding', function () {
    // Within the window the first shopper may still be paying; only their own next order, or the
    // expiry, gives the unit back.
    $product = uoLastUnit();
    $card = uoOrder($product, 'card', (string) Str::uuid())->assertOk();

    uoOrder($product, 'cash', (string) Str::uuid())->assertStatus(422);

    expect(DB::table('orders')->where('id', uoOrderId(T::str($card->json('order_number'))))->value('status'))->toBe('pending');
});

it('expires a card order unpaid past the window and gives the unit back', function () {
    $product = uoLastUnit();
    $cardId = uoOrderId(T::str(uoOrder($product, 'card', (string) Str::uuid())->assertOk()->json('order_number')));
    uoAge($cardId, 61);

    expect(Artisan::call('orders:expire-unpaid'))->toBe(0);

    expect(DB::table('orders')->where('id', $cardId)->value('status'))->toBe('cancelled')
        ->and(uoStock($product['id']))->toBe(1)
        ->and(DB::table('core_activity_log')->where('subject_type', 'orders')->where('subject_id', $cardId)->exists())->toBeTrue();

    // A second tick changes nothing: the order is no longer pending, and the ledger holds one release.
    Artisan::call('orders:expire-unpaid');
    expect(uoStock($product['id']))->toBe(1)
        ->and(DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $cardId)
            ->whereIn('reason', InventoryService::RELEASE_REASONS)->count())->toBe(1);
});

it('leaves alone: a card order inside the window, a paid one, a cash one, a WhatsApp one and a legacy one', function () {
    $product = uoLastUnit();
    app(InventoryService::class)->set(StockTarget::product($product['id']), 'express', 10, 'adjustment', note: 'unpaid-orders test');

    $young = uoOrderId(T::str(uoOrder($product, 'card', (string) Str::uuid())->assertOk()->json('order_number')));
    uoAge($young, 30);

    $paid = uoOrderId(T::str(uoOrder($product, 'card', (string) Str::uuid())->assertOk()->json('order_number')));
    DB::table('orders')->where('id', $paid)->update(['status' => 'processing']);
    uoAge($paid, 120);

    $cash = uoOrderId(T::str(uoOrder($product, 'cash', (string) Str::uuid())->assertOk()->json('order_number')));
    uoAge($cash, 120);

    // WhatsApp and pre-switch (legacy) orders: pending, but core reserved nothing / waits for a person.
    $whatsapp = PaymentFixture::order(100.0);
    DB::table('orders')->where('id', $whatsapp)->update(['payment_method' => 'whatsapp', 'created_at' => now()->subDay()]);
    $legacy = PaymentFixture::order(100.0);
    DB::table('orders')->where('id', $legacy)->update(['payment_method' => 'paymob', 'created_at' => now()->subDays(3)]);

    $before = uoStock($product['id']);
    Artisan::call('orders:expire-unpaid');

    expect(DB::table('orders')->whereIn('id', [$young, $paid, $cash, $whatsapp, $legacy])->pluck('status', 'id')->all())
        ->toBe([$young => 'pending', $paid => 'processing', $cash => 'processing', $whatsapp => 'pending', $legacy => 'pending'])
        ->and(uoStock($product['id']))->toBe($before);
});

it('changes nothing on a dry run, and refuses a window shorter than a slow 3-D Secure', function () {
    $product = uoLastUnit();
    $cardId = uoOrderId(T::str(uoOrder($product, 'card', (string) Str::uuid())->assertOk()->json('order_number')));
    uoAge($cardId, 61);

    expect(Artisan::call('orders:expire-unpaid', ['--dry-run' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('#'.$cardId)
        ->and(DB::table('orders')->where('id', $cardId)->value('status'))->toBe('pending')
        ->and(Artisan::call('orders:expire-unpaid', ['--minutes' => 5]))->toBe(1);
});

it('does not cancel an order a payment reached first', function () {
    // The callback marked it paid between the selection and the claim: the claim loses, nothing moves.
    $product = uoLastUnit();
    $cardId = uoOrderId(T::str(uoOrder($product, 'card', (string) Str::uuid())->assertOk()->json('order_number')));
    DB::table('orders')->where('id', $cardId)->update(['status' => 'processing']);

    expect(app(UnpaidOrders::class)->cancel($cardId, UnpaidOrders::EXPIRED))->toBeFalse()
        ->and(uoStock($product['id']))->toBe(0);
});

it('sends NO intention expiry until one is configured — its unit is not documented', function () {
    config(['compat.unpaid.intention_expiration_seconds' => null]);
    uoOrder(uoLastUnit(), 'card', (string) Str::uuid())->assertOk();

    Http::assertSent(fn (Request $r): bool => ! array_key_exists('expiration', $r->data()));
});

it('sends a configured expiry only when it is shorter than the order window', function () {
    config(['compat.unpaid.intention_expiration_seconds' => 1800]);   // 30 min < 60 min
    uoOrder(uoLastUnit(), 'card', (string) Str::uuid())->assertOk();
    Http::assertSent(fn (Request $r): bool => ($r->data()['expiration'] ?? null) === 1800);

    // As long as the window or longer: refused rather than sent — the page would outlive the order.
    config(['compat.unpaid.intention_expiration_seconds' => 3600]);
    expect(PaymobProvider::intentionExpiration())->toBeNull();
});
