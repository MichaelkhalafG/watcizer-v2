<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Orders\OrderFulfilment;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The four-step fulfilment flow (developer decision 2026-09-12, M1i).
 *
 * `orders.status` was widened to hold `shipped` and `delivered`, and the flow became
 * **pending → processing → shipped → delivered → completed**, with `completed` meaning CLOSED.
 *
 * ── Why this mattered enough to change a shared enum ─────────────────────────────────────────
 *
 * The legacy dashboard e-mails the customer on EVERY status change, and `completed`'s copy reads
 * "Your order is complete. Thank you for shopping with Watchizer!". Mapping shipment onto
 * `completed` therefore told a customer their order was finished while the watch was still in a
 * van. The template already carried `shipped`/`delivered` copy in both languages; the enum could
 * not hold the values. These tests hold the flow that makes that copy reachable and correct.
 */

/**
 * An order sitting in `$status`, with stock reserved so cancellation has something to return.
 *
 * @return array{order: int, product: int}
 */
function flowOrder(string $status = 'processing'): array
{
    $row = T::one(
        DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->where('stock_express', '>=', 1)
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1');
            })
            ->orderBy('id')
    );
    $productId = Row::int(Row::cast($row), 'id');

    $orderId = PaymentFixture::order(total: 100.0, status: $status);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
        'quantity' => 1, 'piece_price' => '100.00', 'total_price' => '100.00',
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId);

    return ['order' => $orderId, 'product' => $productId];
}

function statusOf(int $orderId): string
{
    return T::str(DB::table('orders')->where('id', $orderId)->value('status'));
}

it('holds the widened enum in the DATABASE, not just in PHP', function () {
    // The claim is about the shared column, so it is read from the column.
    $rows = DB::select("SHOW COLUMNS FROM `orders` LIKE 'status'");
    $column = Row::cast(T::row($rows[0] ?? null));
    $type = Row::str($column, 'Type');

    foreach (OrderFulfilment::STATUSES as $status) {
        expect(str_contains($type, "'".$status."'"))->toBeTrue("the enum must hold {$status}");
    }

    // …and in fulfilment order, because MariaDB sorts an enum by ORDINAL: `ORDER BY status` has to
    // follow the flow, which is why M1i inserted the two values rather than appending them.
    expect($type)->toBe("enum('pending','processing','shipped','delivered','completed','cancelled')")
        // NOT NULL and the default are restated by MODIFY; losing either would be a silent change
        // on a table the legacy app still writes.
        ->and(Row::str($column, 'Null'))->toBe('NO')
        ->and(Row::str($column, 'Default'))->toBe('pending');
});

it('really stores the two new values', function () {
    $f = flowOrder('processing');

    foreach (['shipped', 'delivered'] as $status) {
        DB::table('orders')->where('id', $f['order'])->update(['status' => $status]);
        // A value MariaDB does not know becomes '' (this database is not in strict mode), so
        // reading it back is the check, not the absence of an exception.
        expect(statusOf($f['order']))->toBe($status);
    }
});

it('walks the flow one step at a time, and refuses a skip', function () {
    $entry = Staff::dataEntry();
    $f = flowOrder('pending');

    foreach ([['pending', 'processing'], ['processing', 'shipped'], ['shipped', 'delivered'], ['delivered', 'completed']] as [$from, $to]) {
        expect(statusOf($f['order']))->toBe($from);
        actingAs($entry)->put("/manage/orders/{$f['order']}/status", ['status' => $to])->assertRedirect();
        expect(statusOf($f['order']))->toBe($to, "{$from} → {$to} must be allowed");
    }

    expect(OrderFulfilment::options('completed')['advance'])->toBe([]);
});

it('refuses a skip that would rob the customer of the message they are waiting for', function () {
    $entry = Staff::dataEntry();
    $f = flowOrder('processing');

    // processing → delivered would skip the "on its way" e-mail entirely; processing → completed is
    // the old wrong mapping, which must now be impossible rather than merely discouraged.
    foreach (['delivered', 'completed'] as $skip) {
        actingAs($entry)->put("/manage/orders/{$f['order']}/status", ['status' => $skip])
            ->assertSessionHasErrors('status');
        expect(statusOf($f['order']))->toBe('processing');
    }
});

it('lets DATA-ENTRY make every forward move, including the close', function () {
    // The role split (AGENTS §2.7) gives the shop floor the whole flow. Whether the final close
    // should become admin-only is an OPEN question recorded on `Role::MANAGE_ORDER_FULFILMENT`;
    // this test states the CURRENT rule so a change to it cannot pass unnoticed.
    $entry = Staff::dataEntry();

    foreach ([['processing', 'shipped'], ['shipped', 'delivered'], ['delivered', 'completed']] as [$from, $to]) {
        $f = flowOrder($from);
        actingAs($entry)->put("/manage/orders/{$f['order']}/status", ['status' => $to])->assertRedirect();
        expect(statusOf($f['order']))->toBe($to);
    }
});

it('allows a cancellation up to and including SHIPPED', function () {
    $admin = Staff::admin();

    // A parcel in the van is the ordinary cancellation: the customer changes their mind before it
    // arrives, and the units come back through the service.
    foreach (['pending', 'processing', 'shipped'] as $from) {
        $f = flowOrder($from);
        expect(OrderFulfilment::options($from)['may_cancel'])->toBeTrue("{$from} must still be cancellable");

        actingAs($admin)->post("/manage/orders/{$f['order']}/cancel", ['note' => 'اختبار'])->assertRedirect();

        expect(statusOf($f['order']))->toBe('cancelled')
            ->and(app(InventoryService::class)->isReleased($f['order']))->toBeTrue("{$from} → cancelled must return the stock");
    }
});

it('refuses a cancellation once the customer HAS the goods', function () {
    $admin = Staff::admin();

    // From here on it is a RETURN — a courier collection and different accounting — not a button
    // that silently credits stock back.
    foreach (['delivered', 'completed'] as $from) {
        $f = flowOrder($from);
        expect(OrderFulfilment::options($from)['may_cancel'])->toBeFalse("{$from} must not be cancellable");

        actingAs($admin)->post("/manage/orders/{$f['order']}/cancel")->assertSessionHasErrors('cancel');

        expect(statusOf($f['order']))->toBe($from)
            ->and(app(InventoryService::class)->isReleased($f['order']))->toBeFalse('nothing may be released by a refused cancel');
    }
});

it('labels all six states in Arabic, and stops calling `completed` "done"', function () {
    expect(OrderFulfilment::label('pending'))->toBe('قيد الانتظار')
        ->and(OrderFulfilment::label('processing'))->toBe('قيد التنفيذ')
        ->and(OrderFulfilment::label('shipped'))->toBe('تم الشحن')
        ->and(OrderFulfilment::label('delivered'))->toBe('تم التوصيل')
        // `delivered` is what tells the customer the order arrived, so `completed` had to stop
        // claiming it: it now reads "closed".
        ->and(OrderFulfilment::label('completed'))->toBe('مغلق')
        ->and(OrderFulfilment::label('cancelled'))->toBe('ملغى');

    foreach (OrderFulfilment::STATUSES as $status) {
        expect(OrderFulfilment::label($status))->not->toBe($status, "{$status} has no Arabic label");
    }
});

it('offers the new states as list filters', function () {
    $props = Props::of(actingAs(Staff::admin())->get('/manage/orders')->assertOk());

    $values = [];
    foreach (T::arr(T::arr($props['filters'] ?? [])['statuses'] ?? []) as $option) {
        $values[] = T::str(T::arr($option)['value'] ?? '');
    }

    expect($values)->toBe(OrderFulfilment::STATUSES);
});
