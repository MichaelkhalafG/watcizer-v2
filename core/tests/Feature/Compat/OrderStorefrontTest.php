<?php

use App\Compat\CompatCart;
use App\Models\Storefront\Storefront;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
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

/*
 * ── The backfill, after it ran (rewritten 2026-11-05) ─────────────────────────────────────────────
 *
 * Three tests stood here: the command backfills pre-column orders, refuses once an order names another
 * storefront, and writes nothing without --force. Each BUILT an order with no storefront — and since the
 * migration `2026_10_23_000000_orders_storefront_backfill` (production, 2026-10-23) the column is NOT NULL,
 * so that order cannot be written: the three failed on their own fixture, not on the command. The local
 * copies had never run that migration (they were migrated file by file), which is why the gate of
 * 2026-10-30 still passed; a plain `migrate` on 2026-11-04 applied it and 36 tests met the constraint.
 *
 * What is still true and worth holding: the constraint itself, and that the one-time command is a safe
 * no-op on a database where it has nothing left to do.
 */
it('holds every order to a storefront: the column refuses NULL, and the one-time backfill has nothing to do', function () {
    $column = Row::cast(T::row(DB::select("SHOW COLUMNS FROM `orders` LIKE 'storefront_id'")[0] ?? null));
    expect(Row::str($column, 'Null'))->toBe('NO');

    // The database is the guard: a writer that forgets the storefront fails loudly instead of leaving an
    // order no per-storefront read can find. (A savepoint, so the refusal does not poison the test's transaction.)
    $refused = false;
    try {
        DB::transaction(fn () => PaymentFixture::order(total: 10.0, storefrontId: null));
    } catch (QueryException) {
        $refused = true;
    }
    expect($refused)->toBeTrue('an order without a storefront must be refused by the column');
    expect(DB::table('orders')->whereNull('storefront_id')->count())->toBe(0);

    // The command, on the state it left behind: nothing to do, with or without the flags.
    expect(Artisan::call('orders:backfill-storefront', ['--force' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Nothing to do');
    expect(Artisan::call('orders:backfill-storefront', ['--dry-run' => true]))->toBe(0);
});

it('has no NULL branch left in the payment callback, and knows every v2 write route by name', function () {
    // History: deferred on 2026-09-13; on 2026-10-18 the column was to stay NULLABLE with a callback branch for an
    // order without a storefront; on 2026-10-23 ORDERS-STOREFRONT-NOT-NULL was done after all (the migration backfilled
    // the nine pre-column orders and made the column NOT NULL — DEPLOY_20261023.md §3.1). This test still said
    // NULLABLE because the local copies had not run that migration; it says what production says now.
    // With no NULL order possible, the callback's "accepted as the primary storefront" branch was dead and is gone
    // (2026-11-05): ownership is one comparison, and nothing can fall through it.
    $source = file_get_contents(app_path('Http/Controllers/Payment/PaymentCallbackController.php'));
    expect(is_string($source))->toBeTrue();
    expect(T::str($source))->not->toContain('carries no storefront_id')
        ->and(T::str($source))->toContain('$belongs = $orderStorefront === $storefrontId;');

    // Every v2 write route, by name. Still a tripwire: a NEW one must first prove it writes the path's storefront
    // (as CartStorefrontTest does for carts and CheckoutTest for orders), and only then be added here.
    $writes = Routes::writeUris('api/v2');
    expect($writes)->toBe([
        'api/v2/{storefront}/cart/items',
        'api/v2/{storefront}/cart/items/{item}',
        'api/v2/{storefront}/orders',
    ], 'a NEW v2 write route: prove it writes the storefront of its path, then name it here');
});
