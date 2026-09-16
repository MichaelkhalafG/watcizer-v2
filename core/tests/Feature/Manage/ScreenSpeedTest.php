<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\Staff;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Every dashboard screen renders in one bounded set of queries — the orders queue included.
 *
 * ── Why this file exists ────────────────────────────────────────────────────────────────────
 *
 * The review measured the orders list at 3.1 seconds in a browser and asked for it under two,
 * "like the rest". A wall-clock assertion is the obvious way to write that down and the wrong one:
 * it would measure this machine's disk on the day it ran, fail on a loaded laptop, and pass on a
 * fast one with an N+1 still in it. What actually scales — what turns a fast screen into a slow one
 * as the shop grows — is the number of round trips and whether that number depends on the number of
 * ROWS. So the budget is queries, the way §5.3 already does it for the storefront API, and the
 * timings are PRINTED rather than asserted, so a regression is visible without being flaky.
 *
 * ── The comparison is the point ─────────────────────────────────────────────────────────────
 *
 * The orders queue is measured beside its peers under identical conditions. "Orders is slow" is
 * only meaningful against "and products is not" — measured together, in one process, on one
 * database, in the same second.
 */

/** @return array{queries: int, ms: float} */
function measureScreen(string $url): array
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $started = hrtime(true);
    $response = get($url);
    $ms = (hrtime(true) - $started) / 1_000_000;

    $log = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    $response->assertOk();

    return ['queries' => count($log), 'ms' => $ms];
}

it('renders every dashboard list in a bounded number of queries, orders included', function () {
    actingAs(Staff::admin());

    $screens = [
        'orders' => '/manage/orders',
        'products' => '/manage/storefronts/1/products',
        'inventory' => '/manage/inventory',
        'customers' => '/manage/customers',
        'promotions' => '/manage/promotions',
        'placement' => '/manage/storefronts/1/placement',
    ];

    // One warm-up request outside the measurement: the FIRST Inertia render of the process compiles
    // the shared props and primes the framework's own caches, and charging that to whichever screen
    // happens to be first in the list is how a screen gets blamed for the boot.
    get('/manage/orders')->assertOk();

    $measured = [];
    foreach ($screens as $name => $url) {
        $measured[$name] = measureScreen($url);
    }

    $report = "\nscreen speed — same process, same database:\n";
    foreach ($measured as $name => $m) {
        $report .= sprintf("  %-12s %3d queries  %6.0f ms\n", $name, $m['queries'], $m['ms']);
    }
    fwrite(STDERR, $report);

    /*
     * The budget. Generous against today's counts on purpose — it is here to catch a per-ROW query
     * appearing, not to freeze the feature set. A screen that pages 50 rows and needs 60 queries has
     * an N+1 in it whatever the clock says.
     */
    foreach ($measured as $name => $m) {
        expect($m['queries'])->toBeLessThanOrEqual(25, "[{$name}] used {$m['queries']} queries for one page");
    }
});

it('does not issue more queries for a bigger page — the orders queue has no per-row query', function () {
    /*
     * The N+1 test proper, and the only one that would have caught the 36s → 0.13s shape recorded in
     * wave 4D: ask for four times the rows and require the query count NOT to move. A screen that
     * batches its extras answers the same number either way; one that does not grows with the page.
     */
    actingAs(Staff::admin());

    get('/manage/orders')->assertOk();                        // warm-up, as above

    $small = measureScreen('/manage/orders?per_page=15');
    $large = measureScreen('/manage/orders?per_page=100');

    fwrite(STDERR, sprintf(
        "\norders per_page — 15: %d queries / %.0f ms   100: %d queries / %.0f ms\n",
        $small['queries'], $small['ms'], $large['queries'], $large['ms'],
    ));

    expect($large['queries'])->toBe($small['queries'],
        'the orders queue issues a query per row — the page extras are no longer batched');
});
