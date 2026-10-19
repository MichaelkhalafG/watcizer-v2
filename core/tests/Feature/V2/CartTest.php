<?php

use App\Compat\CartIdentity;
use App\Compat\CompatCart;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * The v2 cart of a storefront (B7 step S1, round 5, 2026-10-18) — App\Storefront\StorefrontCart, beside the compat
 * cart and never through it. Proved here on the dev catalogue's own rows (rolled back with the test): a guest adds a
 * SIZE, at the product page's own price; every refusal by its code, and a refused first add leaves no cart behind;
 * the cart is invisible to the other storefront — through v2 AND through compat's storefront-1 lookup; a line is set,
 * not added to; update and delete reach only the shopper's own line.
 */

const V2_CART_BF = '/api/v2/brandfashion';

/**
 * A product visible on storefront 2 that sells through variants, and an active variant of it with Express stock.
 *
 * @return array{product: int, variant: int, stock: int, slug: string}
 */
function cartVariantFixture(): array
{
    $row = T::row(DB::table('catalog_product_variants as v')
        ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'v.product_id')->where('sp.storefront_id', '=', 2)->where('sp.is_visible', '=', 1))
        ->join('catalog_products as p', 'p.id', '=', 'v.product_id')
        ->where('p.is_active', 1)->whereNull('p.deleted_at')
        ->where('v.is_active', 1)->where('v.stock_express', '>=', 2)
        ->orderBy('v.id')
        ->first(['v.id as variant_id', 'v.product_id', 'v.stock_express', 'sp.slug']));

    return ['product' => T::int($row->product_id), 'variant' => T::int($row->variant_id), 'stock' => T::int($row->stock_express), 'slug' => T::str($row->slug)];
}

/** A product visible on storefront 2 with no active variant and Express stock. */
function cartPlainFixture(): int
{
    return T::int(DB::table('storefront_product as sp')
        ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 2)->where('sp.is_visible', 1)
        ->where('p.is_active', 1)->whereNull('p.deleted_at')->where('p.stock_express', '>=', 2)
        ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id')->where('v.is_active', 1))
        ->orderBy('p.id')->value('p.id'));
}

/** @return array<string, string> */
function cartGuestHeader(string $token): array
{
    return ['X-Guest-Token' => $token];
}

it('lets a guest add a size, at the product page\'s own price, and gives the guest a token', function () {
    $f = cartVariantFixture();
    $res = postJson(V2_CART_BF.'/cart/items', ['product_id' => $f['product'], 'variant_id' => $f['variant'], 'bucket' => 'Express', 'quantity' => 1])->assertOk();
    $token = T::str($res->headers->get('X-Guest-Token'));
    expect($token)->toMatch('/^[0-9a-f-]{36}$/')
        ->and($res->headers->get('Cache-Control'))->toContain('no-store');

    $line = T::rows($res->json('data.items'))[0];
    expect(T::int(T::arr($line['variant'])['id']))->toBe($f['variant'])
        ->and($line['bucket'])->toBe('Express')
        ->and($line['problem'])->toBeNull();

    // The same price the product page shows for that variant.
    $detail = getJson(V2_CART_BF.'/products/'.$f['slug'])->assertOk();
    $price = null;
    foreach (T::rows($detail->json('product.variants')) as $v) {
        if (T::int($v['id']) === $f['variant']) {
            $p = T::arr($v['price']);
            $price = $p['has_sale'] === true ? T::float($p['sale_amount']) : T::float($p['amount']);
        }
    }
    expect($price)->not->toBeNull()->and(T::float($line['unit_price']))->toBe($price);

    // Read back with the token.
    $again = getJson(V2_CART_BF.'/cart', cartGuestHeader($token))->assertOk();
    expect(T::rows($again->json('data.items')))->toHaveCount(1)
        ->and(T::int(DB::table('carts')->where('guest_token', $token)->value('storefront_id')))->toBe(2);
});

it('refuses each line it cannot sell, by its code, and leaves no cart behind for a refused first add', function () {
    $f = cartVariantFixture();
    $plain = cartPlainFixture();
    $otherVariant = T::int(DB::table('catalog_product_variants')->where('product_id', '!=', $f['product'])->where('is_active', 1)->value('id'));
    $hidden = T::int(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
        ->where('sp.storefront_id', 2)->where('sp.is_visible', 0)->where('p.is_active', 1)->whereNull('p.deleted_at')->value('p.id'));
    $carts = DB::table('carts')->count();

    $cases = [
        'variant_required' => ['product_id' => $f['product'], 'quantity' => 1],
        'variant_not_allowed' => ['product_id' => $plain, 'variant_id' => $f['variant'], 'quantity' => 1],
        'variant_mismatch' => ['product_id' => $f['product'], 'variant_id' => $otherVariant, 'quantity' => 1],
        'unavailable' => ['product_id' => $hidden, 'quantity' => 1],
    ];
    // The stock refusal is reachable only under the quantity cap (99); the dev fixture holds far fewer.
    if ($f['stock'] < 99) {
        $cases['insufficient_stock'] = ['product_id' => $f['product'], 'variant_id' => $f['variant'], 'bucket' => 'Express', 'quantity' => $f['stock'] + 1];
    }
    $wrong = [];
    foreach ($cases as $code => $body) {
        $res = postJson(V2_CART_BF.'/cart/items', $body);
        if ($res->status() !== 422 || $res->json('error') !== $code) {
            $wrong[] = "{$code}: got {$res->status()} ".json_encode($res->json());
        }
    }
    expect($wrong)->toBe([])->and(DB::table('carts')->count())->toBe($carts);
});

it('keeps the cart on its own storefront: invisible to the other one through v2 and through compat', function () {
    $f = cartVariantFixture();
    $token = T::str(postJson(V2_CART_BF.'/cart/items', ['product_id' => $f['product'], 'variant_id' => $f['variant'], 'quantity' => 1])->assertOk()->headers->get('X-Guest-Token'));

    $wz = getJson('/api/v2/watchizer/cart', cartGuestHeader($token))->assertOk();
    expect($wz->json('data.id'))->toBeNull()->and(T::rows($wz->json('data.items')))->toBe([])
        ->and((new CompatCart(1))->find(CartIdentity::guest($token)))->toBeNull()
        ->and((new CompatCart(2))->find(CartIdentity::guest($token)))->not->toBeNull();
});

it('sets a line rather than adding to it, and lets only its own shopper change or remove it', function () {
    $f = cartVariantFixture();
    $first = postJson(V2_CART_BF.'/cart/items', ['product_id' => $f['product'], 'variant_id' => $f['variant'], 'bucket' => 'Express', 'quantity' => 1])->assertOk();
    $token = T::str($first->headers->get('X-Guest-Token'));
    $again = postJson(V2_CART_BF.'/cart/items', ['product_id' => $f['product'], 'variant_id' => $f['variant'], 'bucket' => 'Express', 'quantity' => 2], cartGuestHeader($token))->assertOk();
    $lines = T::rows($again->json('data.items'));
    expect($lines)->toHaveCount(1)->and(T::int($lines[0]['quantity']))->toBe(2);
    $item = T::int($lines[0]['id']);

    $stranger = '00000000-0000-4000-8000-000000000000';
    patchJson(V2_CART_BF."/cart/items/{$item}", ['quantity' => 1], cartGuestHeader($stranger))->assertNotFound();
    deleteJson(V2_CART_BF."/cart/items/{$item}", [], cartGuestHeader($stranger))->assertNotFound();

    $patched = patchJson(V2_CART_BF."/cart/items/{$item}", ['quantity' => 1], cartGuestHeader($token))->assertOk();
    expect(T::int(T::rows($patched->json('data.items'))[0]['quantity']))->toBe(1);
    $deleted = deleteJson(V2_CART_BF."/cart/items/{$item}", [], cartGuestHeader($token))->assertOk();
    expect(T::rows($deleted->json('data.items')))->toBe([]);
});
