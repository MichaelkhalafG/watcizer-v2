<?php

use App\Compat\CompatCart;
use App\Compat\Diff\HarnessJwt;
use App\Console\Commands\CoreChecksumCommand;
use App\Transform\LegacySource;
use App\Transform\Row;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * AGENTS §3: "Never modify, rename, drop, or write to legacy tables."
 *
 * The `legacy` connection is read-only at the database level, but the clean tables and the legacy
 * tables share ONE schema, so the `default` connection can reach a legacy table too — and wave 3
 * is the first wave whose endpoints WRITE. That makes "core never writes a legacy table" a claim
 * that needs proving rather than asserting.
 *
 * This drives every wave-3 endpoint with the query log armed and inspects what each statement
 * actually targets. The allowed set is the clean tables, plus the SIX shared commerce tables
 * study §2.6 places under core's ownership, plus `offers` — which core writes in exactly one
 * place, `InventoryService::adjustOffer()`, and which is called out in the wave-3 flag list.
 */

const WRITES_API_KEY = 'test-api-code';

it('writes only clean tables and the shared commerce ones, across every wave-3 endpoint', function () {
    config(['compat.api_key' => WRITES_API_KEY, 'compat.jwt_secret' => 'writes-test-secret', 'compat.jwt_algo' => 'HS256']);

    $allowed = array_merge(
        CoreChecksumCommand::CORE_TABLES,
        CoreChecksumCommand::SHARED_COMMERCE_TABLES,
        // Framework-owned tables that are not part of either set.
        ['cache', 'cache_locks', 'sessions', 'core_migrations'],
    );

    /** @var list<array{table: string, sql: string}> $violations */
    $violations = [];
    Event::listen(function (QueryExecuted $event) use ($allowed, &$violations): void {
        if (preg_match('/^\s*(insert\s+into|replace\s+into|update|delete\s+from)\s+`?([a-z0-9_]+)`?/i', $event->sql, $m) !== 1) {
            return;
        }
        $table = strtolower($m[2]);
        if (in_array($table, $allowed, true)) {
            return;
        }
        if (in_array($table, LegacySource::TABLES, true)) {
            $violations[] = ['table' => $table, 'sql' => mb_substr($event->sql, 0, 160)];
        }
    });

    // ── drive every one of the twelve moved paths ────────────────────────────
    $token = (string) Str::uuid();
    $guest = ['Api-Code' => WRITES_API_KEY, 'X-Guest-Token' => $token];
    $userId = T::int(DB::table('users')->orderBy('id')->value('id'));
    $auth = ['Api-Code' => WRITES_API_KEY, 'Authorization' => 'Bearer '.HarnessJwt::mint($userId)];

    $row = DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('cp.deleted_at')->where('cp.stock_express', '>=', 2)->orderBy('cp.id')
        ->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']);
    $row = T::row($row);
    $productId = Row::int($row, 'id');
    $price = CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price'));
    $city = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));
    $cityId = Row::int($city, 'id');
    $shipping = round((float) Row::money($city, 'shipping_cost'), 2);
    $line = ['product_id' => $productId, 'quantity' => 1, 'piece_price' => $price, 'total_price' => $price, 'type_stock' => 'Express'];

    withHeaders($guest)->getJson('/api/me/cart')->assertOk();
    withHeaders($guest)->postJson('/api/add_to_cart', $line)->assertOk();
    withHeaders($guest)->postJson('/api/cart/validate')->assertOk();
    $itemId = T::int(withHeaders($guest)->getJson('/api/me/cart')->json('cart_item.0.id'));
    withHeaders($guest)->deleteJson('/api/delete_cart/'.$itemId)->assertOk();
    withHeaders($guest)->postJson('/api/add_to_cart', $line)->assertOk();
    withHeaders($guest)->postJson('/api/remove_from_cart', ['product_id' => $productId])->assertOk();
    withHeaders($auth)->postJson('/api/cart/merge', ['guest_token' => $token])->assertOk();
    withHeaders(['Api-Code' => WRITES_API_KEY])->postJson('/api/add_address', [
        'shipping_city_id' => $cityId, 'address_line' => 'Writes Street 1', 'phone_number_one' => '01000000000',
    ])->assertOk();
    withHeaders($guest)->postJson('/api/add_order', [
        'shipping_city_id' => $cityId, 'address_line' => 'Writes Street 1', 'phone' => '01000000000',
        'total_price_for_order' => round($price + $shipping, 2), 'payment_method' => 'cash',
        'guest_name' => 'Writes Guest', 'guest_email' => 'writes@example.test',
        'items' => [$line],
    ])->assertOk();
    withHeaders($auth)->getJson('/api/me/orders')->assertOk();
    withHeaders($auth)->getJson('/api/me/addresses')->assertOk();
    withHeaders($auth)->deleteJson('/api/me/addresses/999999')->assertNotFound();
    withHeaders(['Api-Code' => WRITES_API_KEY])->getJson('/api/callback_payment?hmac=x')->assertStatus(403);

    expect($violations)->toBe([]);
});

it('lists the shared commerce tables explicitly, so the exception stays visible', function () {
    // A reviewer should be able to read the exception, not infer it. Six shared commerce tables
    // plus `offers`, whose `stock` column InventoryService::adjustOffer() decrements.
    expect(CoreChecksumCommand::SHARED_COMMERCE_TABLES)->toBe([
        'addresses', 'carts', 'cart_items', 'orders', 'order_items', 'payment_statuses', 'offers',
    ]);

    // …and the frozen set is the rest of the 65: what core must never write, under any path.
    $frozen = CoreChecksumCommand::frozenTables();
    expect(count($frozen))->toBe(count(LegacySource::TABLES) - count(CoreChecksumCommand::SHARED_COMMERCE_TABLES))
        ->and($frozen)->toContain('products')
        ->and($frozen)->toContain('product_translations')
        ->and($frozen)->toContain('users')
        ->and($frozen)->not->toContain('orders');
});
