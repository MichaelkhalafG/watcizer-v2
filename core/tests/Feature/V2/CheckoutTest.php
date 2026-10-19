<?php

use App\Models\Storefront\Storefront;
use App\Storefront\CacheTags;
use App\Storefront\StorefrontCheckout;
use App\Storefront\StorefrontContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\postJson;

/*
 * The v2 checkout of a storefront (B7 step S2, round 6, 2026-10-18) — App\Storefront\StorefrontCheckout. Proved on
 * the dev catalogue's own rows, rolled back with each test: a guest's cash order of a SIZE on Brand Fashion, end to
 * end (the order row with storefront 2, the line with its variant, the variant's own stock and the ledger, the cart
 * gone); the storefront is ALWAYS written, on both shops, and an order without one cannot be written (Michael,
 * round 6); every refusal comes BEFORE anything is written — a card without a contract, a wrong total, an empty
 * cart, an address that is not the buyer's.
 */

const V2_CHECKOUT_CITY = 1;   // a shipping city with a cost (100.00 on the dev database)

/** @return array{product: int, variant: int, stock: int} a visible storefront-2 variant with Express stock ≥ 2 */
function checkoutVariantFixture(): array
{
    $row = T::row(DB::table('catalog_product_variants as v')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'v.product_id')->where('sp.storefront_id', '=', 2)->where('sp.is_visible', '=', 1))
        ->join('catalog_products as p', 'p.id', '=', 'v.product_id')
        ->where('p.is_active', 1)->whereNull('p.deleted_at')
        ->where('v.is_active', 1)->where('v.stock_express', '>=', 2)
        ->orderBy('v.id')
        ->first(['v.id as variant_id', 'v.product_id', 'v.stock_express']));

    return ['product' => T::int($row->product_id), 'variant' => T::int($row->variant_id), 'stock' => T::int($row->stock_express)];
}

/** A plain product (no active variant) visible on that storefront with Express stock. */
function checkoutPlainFixture(int $storefrontId): int
{
    return T::int(DB::table('catalog_products as p')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId)->where('sp.is_visible', '=', 1))
        ->where('p.is_active', 1)->whereNull('p.deleted_at')->where('p.stock_express', '>=', 2)
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id')->where('v.is_active', 1))
        ->orderBy('p.id')->value('p.id'));
}

/**
 * A guest's cart on `$code` with one line; returns the token and what the checkout must be told the total is.
 *
 * @param  array<string, int|string>  $line  product_id, variant_id (ints) and bucket (a string)
 * @return array{token: string, total: float}
 */
function checkoutCart(string $code, array $line): array
{
    $res = postJson("/api/v2/{$code}/cart/items", $line + ['quantity' => 1])->assertOk();
    $shipping = (float) T::str(DB::table('shipping_cities')->where('id', V2_CHECKOUT_CITY)->value('shipping_cost'));

    return ['token' => T::str($res->headers->get('X-Guest-Token')), 'total' => round(T::float($res->json('data.totals.subtotal')) + $shipping, 2)];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function checkoutBody(float $total, array $extra = []): array
{
    return $extra + ['total' => $total, 'payment_method' => 'cash', 'address_line' => '12 Test Street, Nasr City', 'shipping_city_id' => V2_CHECKOUT_CITY, 'phone' => '01000000000', 'guest_name' => 'Test Buyer'];
}

it('takes a guest\'s cash order of a SIZE on Brand Fashion, end to end', function () {
    $f = checkoutVariantFixture();
    $cart = checkoutCart('brandfashion', ['product_id' => $f['product'], 'variant_id' => $f['variant'], 'bucket' => 'Express']);

    $res = postJson('/api/v2/brandfashion/orders', checkoutBody($cart['total']), ['X-Guest-Token' => $cart['token']])->assertCreated();
    $number = T::str($res->json('data.order_number'));
    $order = T::row(DB::table('orders')->where('order_number', $number)->first());
    expect(T::int($order->storefront_id))->toBe(2)
        ->and(T::str($order->payment_method))->toBe('cash')
        ->and(T::str($order->status))->toBe('processing')
        ->and(round(T::float($order->total_price_for_order), 2))->toBe($cart['total']);

    $line = T::row(DB::table('order_items')->where('order_id', $order->id)->first());
    expect(T::int($line->variant_id))->toBe($f['variant'])->and(T::str($line->type_stock))->toBe('Express');

    // The variant's OWN Express stock went down by one, and the ledger says so, for storefront 2.
    expect(T::int(DB::table('catalog_product_variants')->where('id', $f['variant'])->value('stock_express')))->toBe($f['stock'] - 1);
    $move = T::row(DB::table('inventory_movements')->where('reference_id', $order->id)->where('variant_id', $f['variant'])->first());
    expect(T::int($move->quantity_delta))->toBe(-1)->and(T::int($move->storefront_id))->toBe(2);

    // The cart is gone.
    expect(DB::table('carts')->where('guest_token', $cart['token'])->exists())->toBeFalse();
});

it('always writes the path\'s storefront — on both shops — and an unknown shop writes nothing', function () {
    $written = [];
    foreach (['watchizer' => 1, 'brandfashion' => 2] as $code => $id) {
        $cart = checkoutCart($code, ['product_id' => checkoutPlainFixture($id), 'bucket' => 'Express']);
        $number = T::str(postJson("/api/v2/{$code}/orders", checkoutBody($cart['total']), ['X-Guest-Token' => $cart['token']])->assertCreated()->json('data.order_number'));
        $written[$code] = [$id, T::int(DB::table('orders')->where('order_number', $number)->value('storefront_id'))];
    }
    expect(array_filter($written, fn (array $p): bool => $p[0] !== $p[1]))->toBe([]);

    $before = DB::table('orders')->count();
    postJson('/api/v2/no-such-shop/orders', checkoutBody(100.0), ['X-Guest-Token' => '00000000-0000-4000-8000-000000000000'])->assertNotFound();
    expect(DB::table('orders')->count())->toBe($before);
});

it('cannot write an order without a storefront: the writer refuses before anything is written', function () {
    $none = new Storefront;
    $none->id = 0;
    app()->instance(StorefrontContext::class, new StorefrontContext($none, 'en', new CacheTags));
    $before = DB::table('orders')->count();

    expect(fn () => app(StorefrontCheckout::class)->place(Request::create('/'), null, '00000000-0000-4000-8000-000000000000', null, ['payment_method' => 'cash', 'total' => '0']))
        ->toThrow(LogicException::class);
    expect(DB::table('orders')->count())->toBe($before);
});

it('refuses before writing: a card with no contract, a wrong total, an empty cart, a foreign address', function () {
    $f = checkoutVariantFixture();
    $cart = checkoutCart('brandfashion', ['product_id' => $f['product'], 'variant_id' => $f['variant'], 'bucket' => 'Express']);
    $orders = DB::table('orders')->count();
    $addresses = DB::table('addresses')->count();
    $guest = ['X-Guest-Token' => $cart['token']];
    $foreign = T::int(DB::table('addresses')->whereNotNull('user_id')->value('id'));

    $wrong = [];
    $cases = [
        'payment_method_unavailable' => [checkoutBody($cart['total'], ['payment_method' => 'card']), $guest],
        'total_mismatch' => [checkoutBody($cart['total'] + 5), $guest],
        'cart_empty' => [checkoutBody($cart['total']), ['X-Guest-Token' => '00000000-0000-4000-8000-000000000001']],
        'invalid' => [['total' => $cart['total'], 'payment_method' => 'cash', 'address_id' => $foreign], $guest],
    ];
    foreach ($cases as $code => [$body, $headers]) {
        $res = postJson('/api/v2/brandfashion/orders', $body, $headers);
        if ($res->status() !== 422 || $res->json('error') !== $code) {
            $wrong[] = "{$code}: got {$res->status()} ".json_encode($res->json());
        }
    }
    expect($wrong)->toBe([])
        ->and(DB::table('orders')->count())->toBe($orders)
        ->and(DB::table('addresses')->count())->toBe($addresses)
        ->and(DB::table('carts')->where('guest_token', $cart['token'])->exists())->toBeTrue();
});
