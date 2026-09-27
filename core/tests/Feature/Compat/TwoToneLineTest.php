<?php

use App\Compat\CompatCart;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * ── A two-tone finish reaches the order line whole (2026-09-27) ───────────────────────────────
 *
 * The product page now sends a line's colour as the product's FINISH: its hexes in order, joined
 * by '/'. `add_to_cart` refused anything longer than one hex (max:7), and the order line must keep
 * every colour so the e-mail and the dashboard can name them.
 */

const TT_API_KEY = 'two-tone-line-test-key';

beforeEach(function () {
    config(['compat.api_key' => TT_API_KEY]);
});

/** @return array{id: int, price: float} */
function ttProduct(): array
{
    $row = T::row(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1);
        })
        ->whereNull('cp.deleted_at')->where('cp.stock_express', '>=', 3)
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'cp.id'))
        ->orderBy('cp.id')->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']));

    return ['id' => Row::int($row, 'id'), 'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price'))];
}

it('accepts a two-tone finish in the cart and keeps it on the order line', function () {
    $product = ttProduct();
    $guest = (string) Str::uuid();
    $finish = '#C0C0C0/#1F3A5F';
    $line = ['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'],
        'total_price' => $product['price'], 'type_stock' => 'Express', 'color_band' => $finish, 'color_dial' => '#111111'];

    withHeaders(['Api-Code' => TT_API_KEY, 'X-Guest-Token' => $guest])->postJson('/api/add_to_cart', $line)->assertOk();
    expect(DB::table('cart_items')->where('product_id', $product['id'])->where('color_band', $finish)->exists())->toBeTrue();

    $city = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));
    $response = withHeaders(['Api-Code' => TT_API_KEY, 'X-Guest-Token' => $guest])->postJson('/api/add_order', [
        'address_line' => 'Two Tone Street 1', 'shipping_city_id' => Row::int($city, 'id'), 'phone' => '01000000000',
        'guest_name' => 'Two Tone', 'guest_phone' => '01000000000',
        'total_price_for_order' => round($product['price'] + (float) Row::money($city, 'shipping_cost'), 2),
        'payment_method' => 'cash',
        'items' => [$line],
    ])->assertOk();

    $orderId = T::int(DB::table('orders')->where('order_number', T::str($response->json('order_number')))->value('id'));
    expect(DB::table('order_items')->where('order_id', $orderId)->value('color_band'))->toBe($finish)
        ->and(DB::table('order_items')->where('order_id', $orderId)->value('color_dial'))->toBe('#111111');
});
