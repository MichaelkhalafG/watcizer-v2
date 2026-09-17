<?php

use App\Domain\Access\Preferences;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Staff;

use function Pest\Laravel\actingAs;

/*
 * The settlement CSV (§3.9.6, developer decision 2026-09-11).
 *
 * Seven columns, one row per payment ATTEMPT, and nothing richer: no grouping, no totals, no
 * per-provider sheet. The earlier grouped-report proposal was a guess about what finance opens in
 * the morning and was withdrawn; whoever reconciles can pivot raw rows themselves.
 *
 * The header is asserted EXACTLY because it is a contract with a spreadsheet someone else builds.
 */

/**
 * The streamed body, as a string.
 *
 * @param  array<string, int|string>  $query
 */
function settlementCsv(array $query = []): string
{
    $response = actingAs(Staff::admin())->get('/manage/orders/export/settlement'.($query === [] ? '' : '?'.http_build_query($query)));
    $response->assertOk();

    return $response->streamedContent();
}

it('writes exactly the agreed columns, in order', function () {
    $csv = settlementCsv();
    $lines = preg_split('/\r?\n/', $csv) ?: [];
    $header = ltrim((string) ($lines[0] ?? ''), "\xEF\xBB\xBF");

    /*
     * Seven columns in wave 4C; EIGHT since wave 4D added `rewards` (study §3.16.5) — which
     * promotion, if any, gave something away on this order. Finance reconciles takings from this
     * file, and a sale that carried a gift is a different sale; without the column the only way to
     * know is to join `order_items` by hand, which nobody does.
     *
     * TEN since M1r added `discount` and `discount_rule` — the MONEY half of the same question.
     * `rewards` names a promotion that gave an ITEM away; these name the one that took money OFF,
     * and by how much. They matter more to this file than `rewards` does: `amount` above is what
     * the PROVIDER took, and when a promotion reduced the order that figure is ALREADY the
     * discounted one — so without these two a reconciled day's takings simply look lower than the
     * catalogue says, with no line explaining the difference.
     *
     * ── The headings are TRANSLATED since 🟡-5 (2026-09-17) ──────────────────────────────────
     *
     * They used to be the raw column keys in every locale — `order_number`, `transaction_id` — on
     * the one file an Egyptian accountant opens every morning. Every other export names its columns
     * in the operator's language; this one shipped its database identifiers.
     *
     * So this is asserted in a KNOWN locale. The COLUMN ORDER is still pinned, because that is what
     * a spreadsheet built on this file depends on: adding one stays a deliberate edit here, and it
     * is appended, never inserted. This assertion failing is the guard working.
     */
    Preferences::setLocale(Staff::admin(), 'en');

    /*
     * PARSED, not compared as a raw line: a heading with a space in it is quoted by the writer, as
     * CSV requires, so a string comparison would be asserting the quoting rules rather than the
     * columns. This asserts the fields a spreadsheet actually sees.
     */
    $header = str_getcsv(ltrim((string) (preg_split('/\r?\n/', settlementCsv())[0] ?? ''), "\xEF\xBB\xBF"));

    expect($header)->toBe([
        'Date', 'Order number', 'Payment provider', 'Payment method', 'Transaction number',
        'Amount', 'Status', 'Gift promotions', 'Discount', 'Discount promotion ID',
    ]);
});

it('opens in Excel as Arabic rather than mojibake, because of the BOM', function () {
    // The BOM is not decoration: without it Excel reads a UTF-8 CSV as Windows-1252 and every
    // Arabic order note in a future column arrives unreadable. This file exists to be opened in
    // Excel, so the BOM is part of the contract.
    expect(settlementCsv())->toStartWith("\xEF\xBB\xBF");
});

it('writes one row per ATTEMPT, so a failure and its retry both appear', function () {
    $provider = PaymentFixture::paymob();
    $method = PaymentFixture::method($provider);
    $orderId = PaymentFixture::order(total: 250.0);
    $number = PaymentFixture::orderNumber($orderId);

    // A failed attempt, then a successful retry on the same order — exactly the pair a
    // reconciliation has to be able to see.
    foreach ([[770001, false, 25000], [770002, true, 25000]] as [$transaction, $success, $amount]) {
        DB::table('payment_statuses')->insert([
            'order_id' => $orderId,
            'provider' => 'paymob',
            'method' => 'card',
            'storefront_payment_method_id' => $method->getAttribute('id'),
            'pay_transaction_id' => $transaction,
            'pay_order_id' => 880001,
            'amount_cents' => $amount,
            'success' => $success ? 'true' : 'false',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $csv = settlementCsv();

    $rows = [];
    foreach (preg_split('/\r?\n/', $csv) ?: [] as $line) {
        if (str_contains($line, $number)) {
            $rows[] = $line;
        }
    }

    expect($rows)->toHaveCount(2, 'both attempts must appear, not one netted row');

    $failed = implode("\n", $rows);
    expect($failed)->toContain('770001')->toContain('770002')
        // The amount is MAJOR units with two decimals — the column a finance sheet adds up, not
        // the minor-unit integer the provider speaks in.
        ->and($failed)->toContain('250.00')
        ->and($failed)->toContain(',failed')
        ->and($failed)->toContain(',success');
});

it('filters by date range and by storefront', function () {
    $provider = PaymentFixture::paymob();
    $method = PaymentFixture::method($provider);
    $orderId = PaymentFixture::order(total: 10.0);
    $number = PaymentFixture::orderNumber($orderId);

    DB::table('payment_statuses')->insert([
        'order_id' => $orderId,
        'provider' => 'paymob',
        'method' => 'card',
        'storefront_payment_method_id' => $method->getAttribute('id'),
        'pay_transaction_id' => 770003,
        'pay_order_id' => 880002,
        'amount_cents' => 1000,
        'success' => 'true',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Today's window contains it…
    expect(settlementCsv(['from' => now()->toDateString(), 'to' => now()->toDateString()]))->toContain($number);

    // …and a window that ended yesterday does not.
    expect(settlementCsv(['from' => now()->subDays(10)->toDateString(), 'to' => now()->subDay()->toDateString()]))
        ->not->toContain($number);

    // The order is on storefront 1, so asking for storefront 2 must not return it: a settlement
    // file that mixed two storefronts' money would be reconciled against the wrong account.
    expect(settlementCsv(['storefront_id' => 2]))->not->toContain($number);
    expect(settlementCsv(['storefront_id' => 1]))->toContain($number);
});

// ── the scope (🟠-1, 2026-09-17) ─────────────────────────────────────────────────────────────

/**
 * The streamed body as a named USER sees it — the scope probe's whole point.
 *
 * @param  array<string, int|string>  $query
 */
function settlementCsvAs(User $user, array $query = []): string
{
    $response = actingAs($user)->get('/manage/orders/export/settlement'.($query === [] ? '' : '?'.http_build_query($query)));
    $response->assertOk();

    return $response->streamedContent();
}

it('never lets a SCOPED grant export another storefront’s takings', function () {
    /*
     * The finding this test exists for: the export carried no storefront scope at all. The route is
     * gated on `manage-payments`, and that ability can be granted SCOPED — so a holder scoped to
     * Brand Fashion was downloading Watchizer's entire payment history: transaction ids, amounts
     * and order numbers for a shop they cannot open one order of.
     *
     * The list screen had `applyScope()` from the day the scope existed. The export is the same
     * question in a different verb and simply never got it.
     */
    $onOne = PaymentFixture::order(100.0, storefrontId: 1);
    $onTwo = PaymentFixture::order(100.0, storefrontId: 2);
    $numberOne = PaymentFixture::orderNumber($onOne);
    $numberTwo = PaymentFixture::orderNumber($onTwo);

    foreach ([$onOne => 991001, $onTwo => 991002] as $orderId => $transaction) {
        DB::table('payment_statuses')->insert([
            'order_id' => $orderId,
            'provider' => 'paymob',
            'method' => 'card',
            'pay_transaction_id' => $transaction,
            'amount_cents' => 1000,
            'success' => 'true',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // An UNSCOPED admin sees both — the behaviour that must not change.
    $all = settlementCsvAs(Staff::admin());
    expect($all)->toContain($numberOne)->toContain($numberTwo);

    // An admin scoped to storefront 2 sees ONLY storefront 2.
    $scoped = settlementCsvAs(Staff::adminFor(2));
    expect($scoped)->toContain($numberTwo)
        ->and($scoped)->not->toContain($numberOne);
});

it('answers 404 when a scoped grant NAMES a storefront outside it', function () {
    /*
     * `applyScope()` alone would already return nothing here, but "no rows" and "not yours" read
     * identically to the caller — and a finance operator handed an empty CSV concludes the day had
     * no takings rather than that they asked for the wrong shop.
     *
     * 404 rather than 403, for the reason §3.11.14 gives everywhere else: a 403 confirms the
     * storefront exists.
     */
    actingAs(Staff::adminFor(2))
        ->get('/manage/orders/export/settlement?storefront_id=1')
        ->assertNotFound();

    // …and its own storefront is still fine.
    actingAs(Staff::adminFor(2))
        ->get('/manage/orders/export/settlement?storefront_id=2')
        ->assertOk();
});
