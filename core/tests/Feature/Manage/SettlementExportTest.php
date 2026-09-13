<?php

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

it('writes exactly the seven agreed columns, in order', function () {
    $csv = settlementCsv();
    $lines = preg_split('/\r?\n/', $csv) ?: [];
    $header = ltrim((string) ($lines[0] ?? ''), "\xEF\xBB\xBF");

    expect($header)->toBe('date,order_number,provider,method,transaction_id,amount,status');
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
