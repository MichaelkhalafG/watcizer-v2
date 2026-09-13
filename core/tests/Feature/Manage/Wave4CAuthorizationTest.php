<?php

use App\Domain\Access\Role;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\PaymentFixture;
use Tests\Support\Routes;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The wave-4C authorisation matrix, proven by DIRECT HTTP.
 *
 * The brief's words: "data-entry restrictions proven by direct HTTP, not hidden menus". So no test
 * here renders a sidebar or reads an `abilities` prop. Each one drives a session at a URL — with
 * the verb the screen would use — and asserts the status code, because a missing button is not a
 * locked door and the two are edited in different files.
 *
 * The split being proven (AGENTS §2.7, settled 2026-09-11):
 *
 *   data-entry  reads orders, advances fulfilment, adjusts stock
 *   admin only  cancels an order, exports settlements, edits payments, grants roles
 */

/** An order that exists, on the primary storefront, so the routes have something to act on. */
function wave4cOrder(string $status = 'pending'): int
{
    return PaymentFixture::order(status: $status);
}

it('lets an admin open every wave-4C screen', function () {
    $admin = Staff::admin();
    $order = wave4cOrder();

    actingAs($admin)->get('/manage/orders')->assertOk();
    actingAs($admin)->get("/manage/orders/{$order}")->assertOk();
    actingAs($admin)->get('/manage/inventory')->assertOk();
    actingAs($admin)->get('/manage/inventory/ledger')->assertOk();
    actingAs($admin)->get('/manage/inventory/reconciliation')->assertOk();
    actingAs($admin)->get('/manage/users')->assertOk();
    actingAs($admin)->get('/manage/storefronts/1/payments')->assertOk();
    actingAs($admin)->get('/manage/orders/export/settlement')->assertOk();
});

it('lets DATA-ENTRY read orders and advance fulfilment', function () {
    $entry = Staff::dataEntry();
    $order = wave4cOrder();

    actingAs($entry)->get('/manage/orders')->assertOk();
    actingAs($entry)->get("/manage/orders/{$order}")->assertOk();

    // Advancing is theirs: pending → processing is the move a shop floor makes all day.
    actingAs($entry)->put("/manage/orders/{$order}/status", ['status' => 'processing'])
        ->assertRedirect();
    expect(T::str(DB::table('orders')->where('id', $order)->value('status')))->toBe('processing');
});

it('403s DATA-ENTRY on cancel, settlement, payments and role grants — at the verb each screen uses', function () {
    $entry = Staff::dataEntry();
    $order = wave4cOrder();

    // Cancelling moves MONEY and returns STOCK: admin only.
    actingAs($entry)->post("/manage/orders/{$order}/cancel", ['note' => 'nope'])->assertForbidden();

    // A settlement file is payment reconciliation, not shop-floor work.
    actingAs($entry)->get('/manage/orders/export/settlement')->assertForbidden();

    // Payments, every verb the screen has.
    actingAs($entry)->get('/manage/storefronts/1/payments')->assertForbidden();
    actingAs($entry)->post('/manage/storefronts/1/payments/providers', ['provider' => 'paymob', 'is_enabled' => true])
        ->assertForbidden();
    actingAs($entry)->post('/manage/storefronts/1/payments/methods', ['method' => 'card'])->assertForbidden();
    actingAs($entry)->post('/manage/storefronts/1/payments/order', ['ids' => [1]])->assertForbidden();

    // Role grants: an operator who could grant themselves `cancel-orders` would make the whole
    // matrix decorative.
    actingAs($entry)->get('/manage/users')->assertForbidden();
    actingAs($entry)->post('/manage/users/grants', ['email' => 'x@example.com', 'role' => Role::Admin->value])
        ->assertForbidden();
    actingAs($entry)->delete('/manage/users/grants/1')->assertForbidden();

    // …and the order was NOT cancelled by the refused request.
    expect(T::str(DB::table('orders')->where('id', $order)->value('status')))->toBe('pending');
});

it('lets DATA-ENTRY reach the inventory screens, including the adjust endpoint', function () {
    $entry = Staff::dataEntry();

    actingAs($entry)->get('/manage/inventory')->assertOk();
    actingAs($entry)->get('/manage/inventory/ledger')->assertOk();
    actingAs($entry)->get('/manage/inventory/reconciliation')->assertOk();

    // The ability is granted, so an empty payload is a 422 and not a 403 — the distinction that
    // proves the gate opened and the validator closed.
    actingAs($entry)->postJson('/manage/inventory/adjust', [])->assertStatus(422);
});

it('403s an account with NO dashboard grant on every wave-4C route', function () {
    $customer = Staff::customer();
    $order = wave4cOrder();

    foreach ([
        '/manage/orders',
        "/manage/orders/{$order}",
        '/manage/inventory',
        '/manage/inventory/ledger',
        '/manage/inventory/reconciliation',
        '/manage/users',
        '/manage/storefronts/1/payments',
        '/manage/orders/export/settlement',
    ] as $url) {
        // `assertForbidden()` takes no message argument, so the failing URL is carried by the
        // loop variable in the output instead of a message PHPStan rejects.
        actingAs($customer)->get($url)->assertForbidden();
    }
});

it('sends a GUEST to the login screen rather than answering a 4C route', function () {
    get('/manage/orders')->assertRedirect('/manage/login');
    get('/manage/inventory')->assertRedirect('/manage/login');
    get('/manage/users')->assertRedirect('/manage/login');
    get('/manage/storefronts/1/payments')->assertRedirect('/manage/login');
});

it('404s an order that does not exist, rather than confirming it by 403', function () {
    // §3.11.14: an invisible row is a 404. A 403 would confirm the order number exists, which is
    // exactly what an enumeration attempt is asking.
    actingAs(Staff::admin())->get('/manage/orders/99999999')->assertNotFound();
});

it('keeps orders/export/settlement out of the {order} route, which would swallow it', function () {
    // `{order}` is constrained to digits precisely so this path reaches the export and not
    // `show('export')`. If the constraint were dropped this test would see a 404.
    actingAs(Staff::admin())->get('/manage/orders/export/settlement')->assertOk();
});

it('404s a payments screen for a storefront outside the grant, never 403', function () {
    // An admin grant SCOPED to storefront 1 reaches storefront 1's contracts and must not reach
    // storefront 2's — 404 rather than 403, because a 403 confirms the storefront exists and this
    // session was never allowed to learn that (§3.11.14).
    $ids = array_values(array_map(
        fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0),
        DB::table('storefronts')->orderBy('id')->pluck('id')->all(),
    ));

    Assert::assertGreaterThanOrEqual(
        2,
        count($ids),
        'the seeder creates both storefronts; this test needs the second one to have an outside to be outside of',
    );

    [$mine, $other] = [$ids[0], $ids[1]];
    $scopedAdmin = Staff::adminFor($mine);

    actingAs($scopedAdmin)->get("/manage/storefronts/{$mine}/payments")->assertOk();
    actingAs($scopedAdmin)->get("/manage/storefronts/{$other}/payments")->assertNotFound();

    // And the write verbs answer the same way, so the refusal is not a read-only illusion.
    actingAs($scopedAdmin)
        ->post("/manage/storefronts/{$other}/payments/providers", ['provider' => 'paymob', 'is_enabled' => true])
        ->assertNotFound();
});

it('exposes NO route that writes the inventory ledger', function () {
    // The ledger is append-only through InventoryService. This asserts the ABSENCE of a door
    // rather than trusting a paragraph: any future `PUT /manage/inventory/ledger/{id}` or a delete
    // would fail here before it could ship.
    // The ONE write the inventory surface has is the adjustment, which goes through the service
    // and appends a movement. Anything else would be a ledger edit by another name.
    $offenders = array_values(array_diff(Routes::writeUris('manage/inventory'), ['manage/inventory/adjust']));

    expect($offenders)->toBe([], 'the ledger must have no write route, found: '.implode(', ', $offenders));
});
