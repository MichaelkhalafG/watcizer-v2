<?php

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Domain\Inventory\InventoryService;
use App\Models\Storefront\StorefrontPaymentProvider;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\PaymentFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * Rendered evidence for wave 4C, in the shape wave 4B established (`docs/wave4b/screens/`): the
 * real Inertia response for each screen — the server-rendered shell whose `data-page` attribute
 * carries every prop the React page will receive — plus that prop payload pretty-printed beside it.
 *
 * Why this and not a browser screenshot: opening the dashboard in a browser requires logging in,
 * and a password is not something an agent handles. This captures the exact bytes the browser would
 * receive, which is the part that can be checked against a claim. What it does NOT show is layout,
 * so "renders correctly on a tablet" stays on the loud-flag list until a human opens it.
 *
 * SKIPPED unless `CAPTURE_SCREENS=1`, because it writes files outside the repository and the
 * battery should not do that on every run:
 *
 *     CAPTURE_SCREENS=1 php artisan test tests/Feature/Manage/CaptureWave4CScreensTest.php
 */

const SCREENS_DIR = __DIR__.'/../../../../new branding/docs/wave4c/screens';

/**
 * Write one capture: the response body, and its Inertia props as readable JSON.
 *
 * @param  TestResponse<Response>  $response
 */
function captureScreen(string $name, TestResponse $response): void
{
    $response->assertOk();
    $html = $response->getContent();
    $html = is_string($html) ? $html : '';

    file_put_contents(SCREENS_DIR.'/'.$name.'.html', $html);

    $page = $response->viewData('page');
    if (is_array($page)) {
        $json = json_encode($page['props'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents(SCREENS_DIR.'/'.$name.'.props.json', is_string($json) ? $json : '{}');
    }
}

beforeEach(function (): void {
    if (getenv('CAPTURE_SCREENS') !== '1') {
        // `Assert::markTestSkipped()` rather than `$this->markTestSkipped()`: inside a Pest
        // closure `$this` is a `TestCall`, which has no such method (recorded in rehearsal #3).
        Assert::markTestSkipped('set CAPTURE_SCREENS=1 to regenerate the wave-4C screen captures');
    }
    if (! is_dir(SCREENS_DIR)) {
        mkdir(SCREENS_DIR, 0o777, true);
    }
});

it('captures the order queue, filtered and unfiltered', function () {
    $admin = Staff::admin();

    captureScreen('01-orders-list', actingAs($admin)->get('/manage/orders'));
    captureScreen('02-orders-list-pending', actingAs($admin)->get('/manage/orders?filters[status]=pending'));
    captureScreen('03-orders-list-storefront-1', actingAs($admin)->get('/manage/orders?filters[storefront_id]=1'));
});

it('captures one order before and after a cancellation, so the released stock is visible', function () {
    $admin = Staff::admin();

    // An order with reserved stock, so the movements panel has both halves to show.
    $row = T::one(DB::table('catalog_products')->whereNull('deleted_at')->where('stock_express', '>=', 2)->orderBy('id'));
    $productId = Row::int($row, 'id');

    $addressId = DB::table('addresses')->orderBy('id')->value('id');
    $orderId = (int) DB::table('orders')->insertGetId([
        'user_id' => null,
        'address_id' => (int) (is_numeric($addressId) ? $addressId : 0),
        'storefront_id' => 1,
        'total_price_for_order' => '2500.00',
        'payment_method' => 'card',
        'order_number' => 'EV'.random_int(100000, 999999),
        'status' => 'processing',
        'guest_name' => 'evidence capture',
        'guest_phone' => '01000000000',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
        'quantity' => 2, 'piece_price' => '1250.00', 'total_price' => '2500.00',
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId);

    // A payment attempt, so the attempts table is not empty in the evidence.
    $provider = PaymentFixture::paymob();
    $method = PaymentFixture::method($provider);
    DB::table('payment_statuses')->insert([
        'order_id' => $orderId, 'provider' => 'paymob', 'method' => 'card',
        'storefront_payment_method_id' => $method->getAttribute('id'),
        'pay_transaction_id' => 991001, 'pay_order_id' => 991002,
        'amount_cents' => 250000, 'success' => 'true',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    captureScreen('04-order-detail-reserved', actingAs($admin)->get("/manage/orders/{$orderId}"));

    // …then cancel it, and capture the release in the ledger panel.
    actingAs($admin)->post("/manage/orders/{$orderId}/cancel", ['note' => 'إلغاء بغرض التوثيق'])->assertRedirect();
    captureScreen('05-order-detail-cancelled-stock-returned', actingAs($admin)->get("/manage/orders/{$orderId}"));

    // …and the same order seen by DATA-ENTRY: `abilities.cancel` is false, so the screen offers no
    // cancel control — while the server refuses it anyway (`Wave4CAuthorizationTest`).
    captureScreen('06-order-detail-as-data-entry', actingAs(Staff::dataEntry())->get("/manage/orders/{$orderId}"));
});

it('captures the four-step flow, so the new states are visible in the evidence', function () {
    // The real data holds no shipped or delivered order, so the states the 2026-09-12 decision
    // added would be absent from the evidence unless one order is walked through them.
    $admin = Staff::admin();

    $orderId = PaymentFixture::order(total: 750.0, status: 'processing');
    $row = T::one(DB::table('catalog_products')->whereNull('deleted_at')->where('stock_express', '>=', 1)->orderBy('id'));
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => Row::int(Row::cast($row), 'id'), 'offer_id' => null,
        'quantity' => 1, 'piece_price' => '750.00', 'total_price' => '750.00',
        'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now(),
    ]);

    // `shipped`: the parcel is in the van — still cancellable, and the next button says "تم التوصيل".
    actingAs($admin)->put("/manage/orders/{$orderId}/status", ['status' => 'shipped'])->assertRedirect();
    captureScreen('19-order-shipped', actingAs($admin)->get("/manage/orders/{$orderId}"));

    // The filtered LIST is captured here, while the order is actually shipped — captured after the
    // walk finished it showed an empty list, which is true but proves nothing about the filter.
    captureScreen('22-orders-list-shipped-filter', actingAs($admin)->get('/manage/orders?filters[status]=shipped'));

    // `delivered`: the customer has it — cancel is refused from here, and the only move left closes it.
    actingAs($admin)->put("/manage/orders/{$orderId}/status", ['status' => 'delivered'])->assertRedirect();
    captureScreen('20-order-delivered-cancel-refused', actingAs($admin)->get("/manage/orders/{$orderId}"));

    // `completed` = CLOSED: no moves left at all.
    actingAs($admin)->put("/manage/orders/{$orderId}/status", ['status' => 'completed'])->assertRedirect();
    captureScreen('21-order-completed-closed', actingAs($admin)->get("/manage/orders/{$orderId}"));
});

it('captures the inventory screens', function () {
    $admin = Staff::admin();

    captureScreen('07-inventory-list', actingAs($admin)->get('/manage/inventory'));
    captureScreen('08-inventory-low-stock', actingAs($admin)->get('/manage/inventory?filters[view]=low'));
    captureScreen('09-inventory-out-of-stock', actingAs($admin)->get('/manage/inventory?filters[bucket]=out'));
    captureScreen('10-inventory-ledger', actingAs($admin)->get('/manage/inventory/ledger'));
    captureScreen('11-inventory-ledger-orders-only', actingAs($admin)->get('/manage/inventory/ledger?filters[reference_type]=orders'));
    captureScreen('12-inventory-reconciliation', actingAs($admin)->get('/manage/inventory/reconciliation'));
});

it('captures users and roles, with a search result', function () {
    $admin = Staff::admin();
    app(Roles::class)->assign(Staff::customer(), Role::DataEntry, 1, $admin);

    captureScreen('13-users-and-roles', actingAs($admin)->get('/manage/users'));
    captureScreen('14-users-search', actingAs($admin)->get('/manage/users?q=@'));
});

it('captures the payment screens: empty, configured, and a duplicated method key', function () {
    $admin = Staff::admin();

    // Empty first — the state production is in today, and the one the runbook starts from.
    DB::table('storefront_payment_providers')->delete();
    captureScreen('15-payments-empty', actingAs($admin)->get('/manage/storefronts/1/payments'));

    // Then a real arrangement: Paymob with credentials and three methods…
    $paymob = PaymentFixture::paymob();
    PaymentFixture::method($paymob, 'card', '4001', sort: 0);
    PaymentFixture::method($paymob, 'valu', '4002', sort: 1, labelAr: 'فاليو', labelEn: 'valU');
    PaymentFixture::method($paymob, 'wallet', '4003', sort: 2, labelAr: 'محفظة', labelEn: 'Wallet');
    captureScreen('16-payments-configured', actingAs($admin)->get('/manage/storefronts/1/payments'));

    // …plus an offline contract for cash, and a SECOND provider offering `card` too, so the
    // collapse rule is visible: one customer entry, lowest sort wins, the loser says who covers it.
    $offline = StorefrontPaymentProvider::query()->create([
        'storefront_id' => 1, 'provider' => 'offline', 'is_enabled' => true,
        'credentials' => null, 'settings' => null,
    ]);
    PaymentFixture::method($offline, 'cod', null, sort: 9, labelAr: 'الدفع عند الاستلام', labelEn: 'Cash on delivery');

    $second = StorefrontPaymentProvider::query()->create([
        'storefront_id' => 1, 'provider' => 'fawry', 'is_enabled' => true,
        'credentials' => ['secret_key' => 'evidence-only-not-real'], 'settings' => null,
    ]);
    PaymentFixture::method($second, 'card', 'F-1', sort: 5, labelAr: 'بطاقة بنكية', labelEn: 'Bank card');

    captureScreen('17-payments-duplicate-method-collapse', actingAs($admin)->get('/manage/storefronts/1/payments'));

    // And the settlement export, as bytes.
    $csv = actingAs($admin)->get('/manage/orders/export/settlement');
    $csv->assertOk();
    file_put_contents(SCREENS_DIR.'/18-settlement-export.csv', $csv->streamedContent());
});

it('records what the captures prove, beside them', function () {
    $notes = <<<'MD'
        # Wave 4C — rendered evidence (2026-09-12)

        Each `*.html` is the REAL Inertia response for that screen: the server-rendered shell whose
        `data-page` attribute carries every prop the React page receives. Each `*.props.json` is that
        prop payload, pretty-printed, so a claim about a screen can be checked against the bytes the
        browser would get. Regenerate with:

            CAPTURE_SCREENS=1 php artisan test tests/Feature/Manage/CaptureWave4CScreensTest.php

        | File | What it shows |
        |---|---|
        | `01-orders-list` | the queue, all storefronts, newest first |
        | `02-orders-list-pending` | the same list filtered to `pending` — every control is a server-side filter |
        | `03-orders-list-storefront-1` | filtered to one storefront (a column, not a path segment) |
        | `04-order-detail-reserved` | lines with variant, address, a successful payment attempt, and the `order` reservation in the ledger panel |
        | `05-order-detail-cancelled-stock-returned` | the SAME order after cancelling: status `cancelled`, and the `order_cancel` release beside the reservation with the operator's note |
        | `06-order-detail-as-data-entry` | the same order for data-entry: `abilities.cancel` is false, so no cancel control is rendered (the server refuses it too) |
        | `07-inventory-list` | stock per product, both buckets, variants expandable |
        | `08-inventory-low-stock` | the low-stock view — a FILTER on the same list, so the count and the list cannot disagree |
        | `09-inventory-out-of-stock` | both buckets at zero |
        | `10-inventory-ledger` | the movement ledger, read-only |
        | `11-inventory-ledger-orders-only` | filtered to `orders` — note the reference type is PLURAL, which is what the service writes |
        | `12-inventory-reconciliation` | `inventory:verify`'s own output, verbatim |
        | `13-users-and-roles` | grants, with the legacy `users.type` flag shown for contrast and no "add user" control anywhere |
        | `14-users-search` | the capped search (20) that finding an account for a grant uses |
        | `15-payments-empty` | the state production is in today, and where the runbook starts |
        | `16-payments-configured` | Paymob with three methods; **`credentials_set` is true and no credential VALUE appears anywhere in the file** |
        | `17-payments-duplicate-method-collapse` | two providers both offering `card`: the merged list marks one `serves` and the other "covered by", the customer preview shows ONE card entry, and the label-mismatch warning fires |
        | `18-settlement-export.csv` | the seven-column export, as bytes |
        | `19-order-shipped` | the state added on 2026-09-12: `may_cancel` still true (the parcel is in the van), next move "تم التوصيل" |
        | `20-order-delivered-cancel-refused` | `may_cancel` **false** — the customer has the goods, so what follows is a return |
        | `21-order-completed-closed` | `completed` = CLOSED: `advance` empty, label "مغلق" |
        | `22-orders-list-shipped-filter` | the queue filtered to `shipped`, proving the new state is a first-class filter |

        ## The one check worth running against these files

        `grep` the CAPTURES for a credential — the `.html`, `.props.json` and `.csv` files, not this
        README, which necessarily names the strings it tells you to search for:

            grep -lE 'evidence-only-not-real|test-secret-key|test-hmac-secret' *.html *.props.json *.csv

        `16` and `17` are rendered from contracts that DO hold credentials, so a hit would be a real
        leak. Run on the captures as generated: no match. What those files DO carry about a secret is
        `credentials_set: true`, the KEY NAMES (`secret_key`, `public_key`, `hmac_secret`) and
        `credentials_complete: true` — which is everything a key rotation needs to be checkable and
        nothing more. `PaymentSecrecyTest` asserts the same property inside the suite; this is the
        copy a human can verify without running anything.
        MD;

    file_put_contents(SCREENS_DIR.'/README.md', preg_replace('/^        /m', '', $notes)."\n");

    expect(file_exists(SCREENS_DIR.'/README.md'))->toBeTrue();
});
