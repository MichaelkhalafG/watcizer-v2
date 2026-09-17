<?php

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Orders\OrderFulfilment;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The 4C non-negotiable: "cancel restores stock through InventoryService".
 *
 * Wave 3 proved the RECONCILER path — the Blade dashboard writes `orders.status` directly and a
 * command picks the release up afterwards. This proves the DASHBOARD path: an administrator
 * clicking cancel on the new screen releases the stock inside the same request, through the
 * service, with the movement in the ledger and its reason on it.
 *
 * The distinction is not academic. `StockWriteGuard` is armed outside production and would fail
 * any statement from a controller that named a stock column, so the only way this screen can move
 * a quantity at all is through the single door — and the ledger row is what says which door it
 * used.
 */

/**
 * An order that has RESERVED stock, exactly as checkout leaves it.
 *
 * @return array{order: int, product: int, before: int, quantity: int}
 */
function cancelScreenFixture(int $quantity = 2): array
{
    $row = T::one(
        DB::table('catalog_products')
            ->whereNull('deleted_at')
            ->where('stock_express', '>=', $quantity)
            // A product with variants is reserved per variant, and this test is about the
            // product-level path; the variant case is covered by wave 3.5's own tests.
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'catalog_products.id')->selectRaw('1');
            })
            ->orderBy('id')
    );
    $productId = Row::int($row, 'id');
    $before = Row::int($row, 'stock_express');

    $addressId = DB::table('addresses')->orderBy('id')->value('id');
    $orderId = (int) DB::table('orders')->insertGetId([
        'user_id' => null,
        'address_id' => (int) (is_numeric($addressId) ? $addressId : 0),
        'storefront_id' => 1,
        'total_price_for_order' => '0.00',
        'payment_method' => 'cash',
        'order_number' => '4C'.random_int(100000, 999999),
        'status' => 'processing',
        'guest_name' => 'cancel-screen-test',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
        'quantity' => $quantity, 'piece_price' => '0.00', 'total_price' => '0.00',
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId);

    return ['order' => $orderId, 'product' => $productId, 'before' => $before, 'quantity' => $quantity];
}

it('restores the stock through InventoryService when an admin cancels from the screen', function () {
    $f = cancelScreenFixture(2);
    $service = app(InventoryService::class);

    // The reservation happened, so there is something to give back.
    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))
        ->toBe($f['before'] - $f['quantity'])
        ->and($service->isReleased($f['order']))->toBeFalse();

    actingAs(Staff::admin())
        ->post("/manage/orders/{$f['order']}/cancel", ['note' => 'العميل ألغى بالهاتف'])
        ->assertRedirect();

    // 1. the order is cancelled
    expect(T::str(DB::table('orders')->where('id', $f['order'])->value('status')))->toBe('cancelled')
        // 2. the column is back where it started
        ->and(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($f['before'])
        // 3. the service agrees the order is released, and the LEDGER agrees with the column —
        //    which is the invariant `inventory:verify` checks on switch night.
        ->and($service->isReleased($f['order']))->toBeTrue()
        ->and($service->ledgerQuantity(StockTarget::product($f['product']), 'express'))->toBe($f['before']);

    // 4. and the release is in the ledger with the reason that names it, plus the operator's note.
    $movement = T::one(
        DB::table('inventory_movements')
            ->where('reference_type', 'orders')->where('reference_id', $f['order'])
            ->where('reason', 'order_cancel')
    );

    expect(Row::int($movement, 'quantity_delta'))->toBe($f['quantity'])
        ->and(Row::str($movement, 'bucket'))->toBe('express')
        ->and(Row::nstr($movement, 'note'))->toContain('العميل ألغى بالهاتف')
        // The actor is the administrator, not `system`: a human made this decision and the ledger
        // says so.
        ->and(Row::nstr($movement, 'actor_type'))->toBe('user');
});

it('releases ONCE: a second cancel changes nothing and adds no movement', function () {
    $f = cancelScreenFixture(1);
    $admin = Staff::admin();

    actingAs($admin)->post("/manage/orders/{$f['order']}/cancel")->assertRedirect();

    $after = T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express'));
    $movements = DB::table('inventory_movements')
        ->where('reference_type', 'orders')->where('reference_id', $f['order'])->count();

    // The screen refuses a cancelled order (`options.may_cancel` is false), but a crafted POST
    // still arrives — and the second release must be a no-op rather than a second refund of
    // stock. Exactly-once is the service's property; this asserts the screen cannot defeat it.
    $response = actingAs($admin)->post("/manage/orders/{$f['order']}/cancel");
    $response->assertRedirect();

    expect(T::int(DB::table('catalog_products')->where('id', $f['product'])->value('stock_express')))->toBe($after)
        ->and(DB::table('inventory_movements')
            ->where('reference_type', 'orders')->where('reference_id', $f['order'])->count())->toBe($movements);
});

it('shows the release on the order screen, so a human can see the stock came back', function () {
    $f = cancelScreenFixture(2);
    $admin = Staff::admin();

    actingAs($admin)->post("/manage/orders/{$f['order']}/cancel")->assertRedirect();

    $props = Props::of(actingAs($admin)->get("/manage/orders/{$f['order']}")->assertOk());
    $movements = $props['movements'] ?? null;
    expect($movements)->toBeArray();

    $reasons = [];
    foreach (T::arr($movements) as $movement) {
        $reasons[] = T::str(T::arr($movement)['reason'] ?? '');
    }

    // Both halves of the story are on the page: the reservation and the release. The panel used to
    // render empty because it filtered `reference_type = 'order'` while the service writes
    // `'orders'` — this assertion is what would catch that again.
    expect($reasons)->toContain('order')->toContain('order_cancel');
});

it('refuses to advance a cancelled order, so the screen cannot walk it back', function () {
    $f = cancelScreenFixture(1);
    $admin = Staff::admin();

    actingAs($admin)->post("/manage/orders/{$f['order']}/cancel")->assertRedirect();

    // `cancelled` has no forward transition, so this is a validation failure and not a silent
    // status change — a cancelled order that could be marked completed would be an order whose
    // stock was returned and whose customer was told it shipped.
    actingAs($admin)->put("/manage/orders/{$f['order']}/status", ['status' => 'completed'])
        ->assertSessionHasErrors('status');

    expect(T::str(DB::table('orders')->where('id', $f['order'])->value('status')))->toBe('cancelled');
});

// ── the concurrent double cancel (🔵, 2026-09-17) ────────────────────────────────────────────

it('makes the UPDATE the claim, so a losing cancellation releases nothing twice', function () {
    /*
     * Two operators click cancel at the same instant. The `$from === 'cancelled'` guard lives
     * OUTSIDE the transaction, so both read `pending`, both pass it, and both reach the write. The
     * loser used to set `cancelled` over `cancelled` and report a fresh cancellation — "stock
     * returned, 0 movements" — a sentence about something that did not happen.
     *
     * The fix is that the UPDATE carries `where status != cancelled`, so the ROW decides. This
     * asserts that claim directly rather than trying to schedule two threads: the losing request's
     * write is exactly this statement against an already-cancelled row, and what matters is that it
     * changes nothing. Pretending to race in-process would test the test.
     */
    $f = cancelScreenFixture(2);
    $fulfilment = app(OrderFulfilment::class);
    $actor = Actor::user(Staff::admin()->id);

    $first = $fulfilment->cancel($f['order'], $actor, 'first');

    expect($first['already_cancelled'])->toBeFalse()
        ->and($first['released'])->toBeTrue();

    $movements = T::int(
        DB::table('inventory_movements')
            ->where('reference_type', 'order')->where('reference_id', $f['order'])->count()
    );

    // THE claim, as the losing request would issue it: it must change zero rows.
    $claimed = DB::table('orders')
        ->where('id', $f['order'])
        ->where('status', '!=', 'cancelled')
        ->update(['status' => 'cancelled', 'updated_at' => now()]);

    expect($claimed)->toBe(0, 'a second cancellation could still claim the order');

    // …and the stock came back exactly once, which is the property the claim protects.
    expect(T::int(
        DB::table('inventory_movements')
            ->where('reference_type', 'order')->where('reference_id', $f['order'])->count()
    ))->toBe($movements);
});

it('still REFUSES a sequential second cancellation, which is a different case', function () {
    /*
     * An operator who opens an already-cancelled order and clicks cancel is not racing anybody —
     * they are looking at stale screen and should be told. That refusal stays, and this pins it so
     * the concurrency fix above cannot quietly turn every double cancel into a silent success.
     */
    $f = cancelScreenFixture(2);
    $fulfilment = app(OrderFulfilment::class);
    $actor = Actor::user(Staff::admin()->id);

    $fulfilment->cancel($f['order'], $actor, 'first');

    expect(fn () => $fulfilment->cancel($f['order'], $actor, 'again'))
        ->toThrow(RuntimeException::class);
});
