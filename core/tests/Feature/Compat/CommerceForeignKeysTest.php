<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\T;

use function Pest\Laravel\artisan;

/*
 * FK step 2 (CLEAN_CORE_STUDY §2.8.2, M2, risk R2-02) — `core:repoint-commerce-fks`.
 *
 * `order_items.product_id` and `cart_items.product_id` pointed at the LEGACY `products` table, which
 * the write switch froze. A product created in the dashboard since then exists only in
 * `catalog_products`, so the database refused it in a cart line and in an order line — 7,088 of them
 * on the dev copy. Step 1 (production, by hand, 2026-09-27) dropped the two keys; this puts the right
 * ones back: order lines RESTRICT (an ordered product cannot vanish from under the order), cart lines
 * CASCADE (the importer may hard-delete a fresh product sitting in a cart — the cart line goes too).
 *
 * The schema tests read the database the suite runs on: red until the command has run there.
 */

/** @return array{table: string, ref: string, delete_rule: string}|null the FK on this column, if any */
function productFk(string $table): ?array
{
    $row = DB::selectOne(
        "SELECT k.referenced_table_name AS ref, r.delete_rule AS delete_rule
           FROM information_schema.key_column_usage k
           JOIN information_schema.referential_constraints r
             ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name
          WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.column_name = 'product_id' AND k.referenced_table_name IS NOT NULL",
        [$table],
    );

    return $row === null ? null : ['table' => $table, 'ref' => T::str(T::arr((array) $row)['ref']), 'delete_rule' => T::str(T::arr((array) $row)['delete_rule'])];
}

function catalogOnlyProduct(): int
{
    return T::int(DB::table('catalog_products as c')->whereNotExists(fn (Builder $q) => $q->from('products as p')->whereColumn('p.id', 'c.id'))->min('c.id'));
}

it('points order lines at catalog_products with RESTRICT and cart lines with CASCADE', function () {
    expect(productFk('order_items'))->toBe(['table' => 'order_items', 'ref' => 'catalog_products', 'delete_rule' => 'RESTRICT'])
        ->and(productFk('cart_items'))->toBe(['table' => 'cart_items', 'ref' => 'catalog_products', 'delete_rule' => 'CASCADE']);
});

it('takes a product that exists only in the new catalogue into a cart line and an order line', function () {
    $product = catalogOnlyProduct();
    $cart = DB::table('carts')->insertGetId(['guest_token' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

    DB::table('cart_items')->insert(['cart_id' => $cart, 'product_id' => $product, 'quantity' => 1, 'piece_price' => '100.00', 'total_price' => '100.00', 'type_stock' => 'Market']);
    DB::table('order_items')->insert(['order_id' => T::int(DB::table('orders')->min('id')), 'product_id' => $product, 'quantity' => 1, 'piece_price' => '100.00', 'total_price' => '100.00', 'type_stock' => 'Market']);

    expect(DB::table('cart_items')->where('cart_id', $cart)->where('product_id', $product)->exists())->toBeTrue()
        ->and(DB::table('order_items')->where('product_id', $product)->exists())->toBeTrue();
});

it('refuses a product that exists nowhere, in a cart line and in an order line', function () {
    $ghost = T::int(DB::table('catalog_products')->max('id')) + 1_000_000;
    $cart = DB::table('carts')->insertGetId(['guest_token' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => DB::table('cart_items')->insert(['cart_id' => $cart, 'product_id' => $ghost, 'quantity' => 1, 'piece_price' => '1.00', 'total_price' => '1.00', 'type_stock' => 'Market']))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('order_items')->insert(['order_id' => T::int(DB::table('orders')->min('id')), 'product_id' => $ghost, 'quantity' => 1, 'piece_price' => '1.00', 'total_price' => '1.00', 'type_stock' => 'Market']))
        ->toThrow(QueryException::class);
});

it('refuses to repoint while a line points at a product the new catalogue does not have', function () {
    $cart = DB::table('carts')->insertGetId(['guest_token' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now()]);
    DB::statement('SET FOREIGN_KEY_CHECKS=0');
    DB::table('cart_items')->insert(['cart_id' => $cart, 'product_id' => 999_999_999, 'quantity' => 1, 'piece_price' => '1.00', 'total_price' => '1.00', 'type_stock' => 'Market']);
    DB::statement('SET FOREIGN_KEY_CHECKS=1');

    $pending = artisan('core:repoint-commerce-fks', ['--dry-run' => true]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->expectsOutputToContain('cart_items: 1 line(s) point at a product not in catalog_products')->assertFailed()->run();
});

it('changes nothing on a dry run and says what it would do', function () {
    $before = [productFk('order_items'), productFk('cart_items')];

    $pending = artisan('core:repoint-commerce-fks', ['--dry-run' => true]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertSuccessful()->run();

    expect([productFk('order_items'), productFk('cart_items')])->toBe($before);
});
