<?php

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Orders\OrderFulfilment;
use App\Models\Storefront\Storefront;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * Every wave-4C list filter, proven to actually NARROW the list.
 *
 * ── Why this file exists ────────────────────────────────────────────────────────────────────
 *
 * All of them were dead on delivery and nothing noticed. `TableQuery::resolvedFilters()` reads the
 * `filters[key]` shape the DataTable sends and validates it against the declared whitelist; both
 * 4C list controllers instead read TOP-LEVEL request input (`$request->input('status')`), which is
 * never populated by that shape. So the control rendered, the URL carried the value, the response
 * echoed the filter back in `meta.filters` — and the rows came back unfiltered.
 *
 * It survived the authorisation tests (they only assert 200), the screen captures (they looked
 * plausible) and a human reading the code twice. What caught it was ONE number in the evidence:
 * `?filters[status]=shipped` returned 25 rows from a database holding exactly one shipped order.
 *
 * So these tests assert the thing that was actually broken: a filtered request returns FEWER rows
 * than the unfiltered one, and every row it returns matches the filter. Echoing the filter back is
 * explicitly not enough.
 */

/**
 * Every row of a list response, by URL.
 *
 * @return list<array<mixed>>
 */
function listRows(string $url): array
{
    $props = Props::of(actingAs(Staff::admin())->get($url)->assertOk());
    $table = T::arr($props['table'] ?? []);

    $out = [];
    foreach (T::arr($table['data'] ?? []) as $row) {
        $out[] = T::arr($row);
    }

    return $out;
}

/**
 * The TOTAL a list reports — not the page length.
 *
 * The page is capped at 25, so comparing `count($rows)` between a filtered and an unfiltered
 * request compares 25 with 25 and proves nothing. `meta.total` is the count the filter changes.
 */
function listTotal(string $url): int
{
    $props = Props::of(actingAs(Staff::admin())->get($url)->assertOk());
    $meta = T::arr(T::arr($props['table'] ?? [])['meta'] ?? []);

    return T::int($meta['total'] ?? -1);
}

/**
 * One SCALAR value from every row — narrowed, so `array_unique()` and `toContain()` have a list of
 * comparable values rather than `mixed`.
 *
 * @return list<int|string|null>
 */
function listColumn(string $url, string $key): array
{
    $out = [];
    foreach (listRows($url) as $row) {
        $value = $row[$key] ?? null;
        $out[] = is_int($value) || is_string($value) ? $value : null;
    }

    return $out;
}

it('filters the order queue by STATUS, and the rows obey it', function () {
    // A shipped order to find, against a database whose orders are pending/processing/cancelled.
    $orderId = PaymentFixture::order(total: 10.0, status: 'shipped');

    $all = listColumn('/manage/orders', 'status');
    $shipped = listColumn('/manage/orders?filters[status]=shipped', 'status');

    expect($shipped)->not->toBeEmpty('the shipped order must be findable')
        ->and(listTotal('/manage/orders?filters[status]=shipped'))
        ->toBeLessThan(listTotal('/manage/orders'), 'a filter that returns everything is not filtering')
        ->and(array_unique($shipped))->toBe(['shipped'], 'every row must match the filter')
        ->and($all)->not->toBeEmpty();

    // …and the order it was looking for is the one it found.
    expect(listColumn('/manage/orders?filters[status]=shipped', 'id'))->toContain($orderId);
});

it('filters the order queue by every status the enum holds', function () {
    foreach (OrderFulfilment::STATUSES as $status) {
        $values = listColumn('/manage/orders?filters[status]='.$status, 'status');
        // An empty result is a legitimate answer for a state no order is in; what must never
        // happen is rows that do not match.
        expect(array_unique($values))->toBeIn([[], [$status]], "filters[status]={$status} returned other statuses");
    }
});

it('filters the order queue by STOREFRONT', function () {
    /*
     * The subject is created here rather than assumed: EVERY order on this database has
     * `storefront_id = NULL`, including the ones core's own checkout wrote, because nothing
     * populates that column yet (reported as a 4C gap — the callback's "NULL means the primary
     * storefront" branch is therefore the permanent behaviour, not a sunset). This test is about
     * the FILTER, so it puts one row of each storefront in front of it.
     */
    $mine = PaymentFixture::order(total: 11.0, storefrontId: 1);
    $other = PaymentFixture::order(total: 12.0, storefrontId: Storefront::BRAND_FASHION_ID);

    $ids = listColumn('/manage/orders?filters[storefront_id]=1', 'id');
    expect($ids)->toContain($mine);
    expect($ids)->not->toContain($other);

    foreach (listRows('/manage/orders?filters[storefront_id]=1') as $row) {
        expect($row['storefront_id'] ?? null)->toBe(1);
    }

    // …and the other storefront's filter finds the other order and not this one.
    $otherIds = listColumn('/manage/orders?filters[storefront_id]='.Storefront::BRAND_FASHION_ID, 'id');
    expect($otherIds)->toContain($other);
    expect($otherIds)->not->toContain($mine);
});

it('filters the order queue by DATE RANGE, which needed a null allow-list to work at all', function () {
    /*
     * `from`/`to` are free text, so they are declared `null` ("any scalar"). Declared as `[]` they
     * were an allow-list of NOTHING and `resolvedFilters()` dropped every date the operator picked
     * — a second, independent way the same controls did nothing.
     */
    $newest = T::str(DB::table('orders')->orderByDesc('id')->value('created_at'));
    $day = substr($newest, 0, 10);

    expect(listRows('/manage/orders?filters[from]='.$day.'&filters[to]='.$day))
        ->not->toBeEmpty('the day the newest order was created must contain at least that order');

    // A window that closed before this database existed must come back empty.
    expect(listRows('/manage/orders?filters[from]=2000-01-01&filters[to]=2000-01-02'))->toBe([]);

    // …and the filter is reported back, which is what the reset button and the URL depend on.
    $props = Props::of(actingAs(Staff::admin())->get('/manage/orders?filters[to]=2000-01-02')->assertOk());
    $meta = T::arr(T::arr($props['table'] ?? [])['meta'] ?? []);
    expect(T::arr($meta['filters'] ?? [])['to'] ?? null)->toBe('2000-01-02');
});

it('filters the order queue by PROVIDER, matching an attempt and not only the paid order', function () {
    $provider = PaymentFixture::paymob();
    $method = PaymentFixture::method($provider);
    $orderId = PaymentFixture::order(total: 99.0);

    // A FAILED attempt: the order was never paid, so `orders.paid_via_provider` is null — and an
    // operator filtering by provider is looking for exactly this row.
    DB::table('payment_statuses')->insert([
        'order_id' => $orderId, 'provider' => 'paymob', 'method' => 'card',
        'storefront_payment_method_id' => $method->getAttribute('id'),
        'pay_transaction_id' => 665001, 'pay_order_id' => 665002,
        'amount_cents' => 9900, 'success' => 'false',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $ids = listColumn('/manage/orders?filters[provider]=paymob', 'id');

    expect($ids)->toContain($orderId)
        ->and(listTotal('/manage/orders?filters[provider]=paymob'))
        ->toBeLessThan(listTotal('/manage/orders'));
});

it('filters the stock list to LOW STOCK, and the rows are actually low', function () {
    $rows = listRows('/manage/inventory?filters[view]=low');

    expect(listTotal('/manage/inventory?filters[view]=low'))
        ->toBeLessThan(listTotal('/manage/inventory'), 'the low-stock view is a filter, not the whole list')
        ->toBeGreaterThan(0, 'this catalogue has low-stock products; a zero here means the filter broke');

    foreach ($rows as $row) {
        expect($row['is_low'] ?? null)->toBeTrue();
    }
});

it('filters the stock list by BUCKET', function () {
    foreach (['express', 'market'] as $bucket) {
        foreach (listRows('/manage/inventory?filters[bucket]='.$bucket) as $row) {
            expect($row[$bucket] ?? 0)->toBeGreaterThan(0, "a {$bucket}-filtered row must hold {$bucket} stock");
        }
    }

    foreach (listRows('/manage/inventory?filters[bucket]=out') as $row) {
        expect($row['total'] ?? -1)->toBe(0, 'an out-of-stock row must hold nothing');
    }
});

it('filters the ledger by REASON and by PRODUCT', function () {
    // A movement with a reason nothing else uses on this database, and a known product.
    $row = T::one(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id'));
    $productId = Row::int(Row::cast($row), 'id');

    app(InventoryService::class)->adjust(
        StockTarget::product($productId),
        'express',
        1,
        'restock',
        actor: Actor::system(),
        note: 'filter test',
    );

    $reasons = listColumn('/manage/inventory/ledger?filters[reason]=restock', 'reason');
    expect($reasons)->not->toBeEmpty()
        ->and(array_unique($reasons))->toBe(['restock']);

    $products = listColumn('/manage/inventory/ledger?filters[product_id]='.$productId, 'product_id');
    expect($products)->not->toBeEmpty()
        ->and(array_unique($products))->toBe([$productId]);
});

it('drops a filter value outside the declared whitelist instead of 422-ing a stale bookmark', function () {
    // `resolvedFilters()`'s own rule, asserted here because these screens depend on it: a stale
    // bookmark renders the unfiltered screen rather than an error the team cannot act on.
    expect(listTotal('/manage/orders?filters[status]=teleported'))->toBe(listTotal('/manage/orders'));
});
