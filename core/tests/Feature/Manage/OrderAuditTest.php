<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Inventory\Actor;
use App\Domain\Orders\OrderFulfilment;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;
use Tests\Support\VariantFixture;

use function Pest\Laravel\actingAs;

/*
 * B-GUARD-2 — order status changes and cancellations were audited NOWHERE.
 *
 * ── What the review found ───────────────────────────────────────────────────────────────────
 *
 * It read every writer in the application. `ActivityLog::record()` is called by the product, stock,
 * category, payment-settings, placement, promotion and role writers — and by nothing on the order
 * path. So the one action that moves money and stock, and is admin-only, left no answer to "who did
 * this".
 *
 * The stock half was never invisible: a cancellation writes `inventory_movements` rows carrying the
 * actor. But that answers "which units came back", not "who cancelled order 1234, when, and why".
 * The note the operator typed existed only as an argument passed to the ledger.
 */

/**
 * Rows the activity log holds for one order.
 *
 * @return array<int, stdClass>
 */
function auditRows(int $orderId): array
{
    return DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'orders')
        ->where('subject_id', $orderId)
        ->orderBy('id')
        ->get()
        ->all();
}

it('records WHO advanced an order, and from what to what', function () {
    $orderId = PaymentFixture::order(total: 500.0, status: 'pending');
    $admin = Staff::admin();
    actingAs($admin);

    app(OrderFulfilment::class)->advance($orderId, 'processing', Actor::user($admin->id));

    $rows = auditRows($orderId);
    expect($rows)->toHaveCount(1);

    $row = T::row($rows[0]);
    expect(T::int($row->user_id))->toBe($admin->id)
        ->and(T::str($row->action))->toBe(ActivityLog::UPDATED)
        // Named by the order NUMBER, which is what the operator knows it as.
        ->and(T::str($row->subject_label))->not->toBe('');

    $changes = T::str($row->changes);
    expect($changes)->toContain('pending')
        ->and($changes)->toContain('processing');
});

it('records a CANCELLATION with its note and how much stock came back', function () {
    /*
     * The note is the part that existed nowhere before: it was passed to the ledger as a movement
     * note and never surfaced against the order itself, so "why was this cancelled" had no answer
     * on the activity screen.
     */
    $orderId = PaymentFixture::order(total: 500.0, status: 'pending');
    $admin = Staff::admin();
    actingAs($admin);

    app(OrderFulfilment::class)->cancel($orderId, Actor::user($admin->id), 'customer changed their mind');

    $rows = auditRows($orderId);
    expect($rows)->toHaveCount(1);

    $row = T::row($rows[0]);
    $changes = T::str($row->changes);

    expect(T::int($row->user_id))->toBe($admin->id)
        ->and($changes)->toContain('cancelled')
        ->and($changes)->toContain('customer changed their mind');
});

it('audits a cancellation ONCE, however many times it is asked for', function () {
    /*
     * A second cancellation must not produce a second row: one order was cancelled once, and two
     * rows in the place a dispute is settled from would say otherwise.
     *
     * Note which refusal actually fires here. A SEQUENTIAL second call is caught by the
     * `$from === 'cancelled'` guard at the top of `cancel()` and throws. The `already_cancelled`
     * RETURN is a different path — it needs the status to change between that guard and the
     * conditional UPDATE, which is a genuine race two processes have to run into. (The first draft of
     * this test asserted the return value and failed on the throw; they are two distinct outcomes and
     * only one of them is reachable from a single process.)
     *
     * Either way the audit count is the thing that must hold, and it is asserted for the path this
     * test can actually reach.
     */
    $orderId = PaymentFixture::order(total: 500.0, status: 'pending');
    $admin = Staff::admin();
    actingAs($admin);

    $fulfilment = app(OrderFulfilment::class);
    $fulfilment->cancel($orderId, Actor::user($admin->id), 'first');

    expect(auditRows($orderId))->toHaveCount(1);

    $refused = false;
    try {
        $fulfilment->cancel($orderId, Actor::user($admin->id), 'second');
    } catch (RuntimeException) {
        $refused = true;
    }

    expect($refused)->toBeTrue('a second cancellation must be refused')
        ->and(auditRows($orderId))->toHaveCount(1, 'a refused cancellation must not be audited');
});

it('leaves NO audit row when the cancellation itself rolls back', function () {
    /*
     * THE reason the record lives inside the transaction. An audit trail that holds cancellations
     * which never happened is worse than one that holds none — it is the source a dispute is settled
     * from, and it would be confidently wrong.
     *
     * The failure is a REAL domain refusal rather than a mock: the order carries a line for a product
     * that has SIZES, with no `variant_id` on the line. `InventoryService` refuses a product-level
     * movement on a variant product (§2.5 — the same rule item 3 surfaced), and it refuses from
     * inside the transaction, which is exactly where a rollback has to be survived.
     *
     * Two false starts are worth recording, because both looked right:
     *
     *   • pointing the line at a non-existent product id proved nothing — `PaymentFixture::order()`
     *     creates no line items at all, so there was nothing to repoint and the release completed
     *     happily with zero movements;
     *   • `VariantFixture::synthetic()` builds a `catalog_products` row only, and
     *     `order_items.product_id` keys to the LEGACY `products` table, so the line could not be
     *     inserted at all. `converted()` is the one that works: it turns a REAL transformed product
     *     — present in both tables, ids preserved — into a variant product.
     */
    $orderId = PaymentFixture::order(total: 500.0, status: 'pending');
    $admin = Staff::admin();
    actingAs($admin);

    $fixture = VariantFixture::converted(count: 2, express: 5);

    DB::table('order_items')->insert([
        'order_id' => $orderId,
        'product_id' => $fixture['product'],
        'variant_id' => null,          // the line predates the sizes — the shape the service refuses
        'quantity' => 1,
        'piece_price' => '500.00',
        'total_price' => '500.00',
        'type_stock' => 'Express',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $before = count(auditRows($orderId));

    try {
        app(OrderFulfilment::class)->cancel($orderId, Actor::user($admin->id), 'should roll back');
        $threw = false;
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue('the fixture must actually make the release fail');

    // Neither the status nor the audit moved.
    expect(auditRows($orderId))->toHaveCount($before, 'a rolled-back cancellation must leave no audit row')
        ->and(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('pending');
});

it('shows the order rows on the activity SCREEN, which is where anybody would look', function () {
    /*
     * A row in the table nobody can reach is not an audit trail. The screen is admin-only and filters
     * by subject type, so the new rows have to be reachable through the same filter as every other
     * subject.
     */
    $orderId = PaymentFixture::order(total: 500.0, status: 'pending');
    $admin = Staff::admin();
    actingAs($admin);

    app(OrderFulfilment::class)->advance($orderId, 'processing', Actor::user($admin->id));

    $body = T::str(json_encode(
        Props::of(actingAs($admin)->get('/manage/activity?per_page=100&filters[subject_type]=orders')),
        JSON_UNESCAPED_UNICODE,
    ));

    expect($body)->toContain('"subject_id":'.$orderId);
});
