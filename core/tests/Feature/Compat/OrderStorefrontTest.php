<?php

use App\Compat\CompatCart;
use App\Models\Storefront\Storefront;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PaymentFixture;
use Tests\Support\Routes;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * `orders.storefront_id` — the writer, and the backfill (developer decision 2026-09-13).
 *
 * Wave 4C added the column and the payment callback started reading it; NOTHING wrote it, so every
 * order was NULL. The callback accepts NULL only for the primary storefront, which made its
 * ownership check (study §3.9.2 check 3) effectively single-storefront: the day Brand Fashion holds
 * its own Paymob contract, a BF order would still have been NULL and would therefore have been
 * accepted against WATCHIZER's credentials — the exact cross-storefront acceptance the two-level
 * payment design exists to prevent.
 *
 * The value was never missing, only dropped: `CompatCheckout` already held it and already passed it
 * to `commitOrder()`, so the LEDGER recorded the storefront while the order row it belonged to did
 * not. These tests hold both halves of the fix — a fresh checkout writes it, and the rows that
 * predate the column read the primary storefront after the one-time backfill.
 *
 * NOT asserted here, deliberately, because it is explicitly deferred: the column stays NULLABLE and
 * the callback keeps its NULL branch until a v2 checkout exists to take the value from its
 * `/api/v2/{storefront}/…` segment.
 */

/**
 * A storefront-1 product with stock, and the price the CHECKOUT will compute for it.
 *
 * The price comes from `storefront_product.effective_price` / `effective_sale_price` — the
 * storefront's own figures, not `catalog_products.selling_price` — and through
 * `CompatCart::catalogPrice()`, the ONE home of the rule "sale only when 0 < sale < selling". The
 * first version of this fixture read the catalogue columns and re-implemented that rule, and the
 * checkout answered 422 with the real total; every storefront-1 product in stock happens to carry
 * an active sale, so there is no unambiguous product to sidestep it with either.
 *
 * @return array{product_id: int, price: float, city_id: int, city_cost: float, token: string}
 */
function storefrontCheckoutFixture(): array
{
    $row = T::one(
        DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $j): void {
                $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1);
            })
            ->whereNull('p.deleted_at')
            ->where('p.stock_express', '>=', 2)
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id')->selectRaw('1');
            })
            ->orderBy('p.id')
            ->select(['p.id', 'sp.effective_price', 'sp.effective_sale_price'])
    );
    $product = Row::cast($row);

    $city = Row::cast(T::one(DB::table('shipping_cities')->orderBy('id')->select(['id', 'shipping_cost'])));

    return [
        'product_id' => Row::int($product, 'id'),
        'price' => CompatCart::catalogPrice(Row::money($product, 'effective_price'), Row::nmoney($product, 'effective_sale_price')),
        'city_id' => Row::int($city, 'id'),
        'city_cost' => round((float) Row::money($city, 'shipping_cost'), 2),
        'token' => (string) Str::uuid(),
    ];
}

/** @return array<string, string> */
function storefrontGuestHeaders(string $token): array
{
    return ['Api-Code' => T::str(config('compat.api_key')), 'X-Guest-Token' => $token];
}

/**
 * One unit in the cart, and the total to pay for it.
 *
 * @param  array{product_id: int, price: float, city_id: int, city_cost: float, token: string}  $f
 */
function storefrontCartTotal(array $f): float
{
    withHeaders(storefrontGuestHeaders($f['token']))->postJson('/api/add_to_cart', [
        'product_id' => $f['product_id'], 'quantity' => 1, 'piece_price' => $f['price'],
        'total_price' => $f['price'], 'type_stock' => 'Express',
    ])->assertOk();

    // `catalogPrice()` already gave the figure the checkout will compute for this line.
    return round($f['price'] + $f['city_cost'], 2);
}

it('stamps the storefront on an order placed through the compat checkout', function () {
    $f = storefrontCheckoutFixture();
    $total = storefrontCartTotal($f);

    $response = withHeaders(storefrontGuestHeaders($f['token']))->postJson('/api/add_order', [
        'shipping_city_id' => $f['city_id'], 'address_line' => 'Storefront Street 1', 'phone' => '01000000000',
        'total_price_for_order' => $total, 'payment_method' => 'cash',
        'guest_name' => 'Storefront Guest', 'guest_email' => 'storefront@example.test',
        'items' => [[
            'product_id' => $f['product_id'], 'quantity' => 1, 'piece_price' => $f['price'],
            'total_price' => $f['price'], 'type_stock' => 'Express',
        ]],
        // The server re-prices the lines and only checks the ORDER total, so the per-line figures
        // above are the client's claim and the total above is the server's own answer.
    ]);
    $response->assertOk();

    $order = Row::cast(T::one(DB::table('orders')->where('order_number', T::str($response->json('order_number')))));
    $orderId = Row::int($order, 'id');

    // 1. the order knows its storefront…
    expect(Row::nint($order, 'storefront_id'))->toBe(1);

    // 2. …and the LEDGER agrees with it about the same event. This is the pair that used to
    //    disagree: the movement carried the storefront and its order carried NULL, so any
    //    per-storefront reconciliation joining the two would have had to pick a side.
    $movement = Row::cast(T::one(
        DB::table('inventory_movements')
            ->where('reference_type', 'orders')->where('reference_id', $orderId)->where('reason', 'order')
    ));

    expect(Row::nint($movement, 'storefront_id'))->toBe(Row::nint($order, 'storefront_id'))
        ->and(Row::nint($movement, 'storefront_id'))->toBe(1);
});

it('keeps the storefront OUT of the compat response, so the harness cannot see it', function () {
    /*
     * The column is core's own bookkeeping. `add_order` answers
     * `{success, message, order_number, …}` and the account endpoint projects an explicit column
     * list — if `storefront_id` leaked into either, the 126-case byte-compat harness would report a
     * difference the legacy host cannot produce, and the switch-night gate would fail on our own
     * addition.
     */
    $f = storefrontCheckoutFixture();
    $total = storefrontCartTotal($f);

    $response = withHeaders(storefrontGuestHeaders($f['token']))->postJson('/api/add_order', [
        'shipping_city_id' => $f['city_id'], 'address_line' => 'Storefront Street 2', 'phone' => '01000000000',
        'total_price_for_order' => $total, 'payment_method' => 'cash',
        'guest_name' => 'Storefront Guest', 'guest_email' => 'storefront2@example.test',
        'items' => [[
            'product_id' => $f['product_id'], 'quantity' => 1, 'piece_price' => $f['price'],
            'total_price' => $f['price'], 'type_stock' => 'Express',
        ]],
        // The server re-prices the lines and only checks the ORDER total, so the per-line figures
        // above are the client's claim and the total above is the server's own answer.
    ])->assertOk();

    $body = T::str($response->getContent());

    expect($body)->not->toContain('storefront_id')
        ->and($body)->not->toContain('storefront');
});

it('backfills every pre-column order to the primary storefront, and is idempotent', function () {
    // Two orders that predate the writer, exactly as the 33 real ones do.
    $stale = [PaymentFixture::order(total: 10.0, storefrontId: null), PaymentFixture::order(total: 20.0, storefrontId: null)];
    foreach ($stale as $id) {
        expect(Row::nint(Row::cast(T::one(DB::table('orders')->where('id', $id))), 'storefront_id'))->toBeNull();
    }

    // A row that already names the primary storefront must be left exactly as it is.
    $fresh = PaymentFixture::order(total: 30.0, storefrontId: 1);

    expect(Artisan::call('orders:backfill-storefront', ['--force' => true]))->toBe(0);

    foreach ([...$stale, $fresh] as $id) {
        expect(Row::nint(Row::cast(T::one(DB::table('orders')->where('id', $id))), 'storefront_id'))->toBe(1);
    }

    // Idempotent: a second run finds nothing and says so.
    expect(Artisan::call('orders:backfill-storefront', ['--force' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Nothing to do');
});

it('REFUSES to backfill once an order names another storefront', function () {
    /*
     * The backfill's whole justification is that no historical order can belong to storefront 2 —
     * Brand Fashion has never had a write path. That argument has an expiry date, so the command
     * re-checks it instead of trusting the paragraph that made it.
     */
    PaymentFixture::order(total: 40.0, storefrontId: null);
    PaymentFixture::order(total: 50.0, storefrontId: Storefront::BRAND_FASHION_ID);

    expect(Artisan::call('orders:backfill-storefront', ['--force' => true]))->toBe(1);
    expect(Artisan::output())->toContain('no longer holds');

    // …and it wrote nothing.
    expect(DB::table('orders')->whereNull('storefront_id')->count())->toBeGreaterThan(0);
});

it('writes nothing without --force, and nothing on a dry run', function () {
    PaymentFixture::order(total: 60.0, storefrontId: null);
    $before = DB::table('orders')->whereNull('storefront_id')->count();

    expect(Artisan::call('orders:backfill-storefront'))->toBe(1);
    expect(DB::table('orders')->whereNull('storefront_id')->count())->toBe($before);

    expect(Artisan::call('orders:backfill-storefront', ['--dry-run' => true]))->toBe(0);
    expect(Artisan::output())->toContain('DRY RUN');
    expect(DB::table('orders')->whereNull('storefront_id')->count())->toBe($before);
});

it('leaves the column NULLABLE and the callback NULL branch alone, because the v2 checkout is not built', function () {
    // Explicitly deferred on 2026-09-13, and asserted so that "we tidied it up" cannot happen by
    // accident before the second writer exists.
    $column = Row::cast(T::row(DB::select("SHOW COLUMNS FROM `orders` LIKE 'storefront_id'")[0] ?? null));
    expect(Row::str($column, 'Null'))->toBe('YES');

    $source = file_get_contents(app_path('Http/Controllers/Payment/PaymentCallbackController.php'));
    expect(is_string($source))->toBeTrue();
    expect(T::str($source))->toContain('carries no storefront_id');

    // And v2 still has no write route — the condition under which the branch may go.
    $writes = Routes::writeUris('api/v2');
    expect($writes)->toBe([], 'a v2 write route now exists: revisit the NULL branch and NOT NULL');
});
