<?php

use App\Compat\CompatCart;
use App\Compat\CompatCheckout;
use App\Domain\Payment\CheckoutMethods;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontHasNoHost;
use App\Storefront\StorefrontUrls;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * ── The no-fallback test (A2, developer 2026-10-12): with every Brand Fashion identity value
 *    EMPTY, nothing of Brand Fashion's resolves to Watchizer's ────────────────────────────────────
 *
 * Four leaks were named. THREE are core's and are proven here:
 *
 *   1. PAYMENTS — a storefront with no live online contract used to fall through to the global
 *      `PAYMOB_*` account (`CompatCheckout::createPaymobIntention()`): Brand Fashion's card order,
 *      Watchizer's money. The builder is DELETED; the order is refused before anything is written,
 *      and nothing reaches any provider.
 *   2. THE HOST — a host that names no storefront used to be answered as Watchizer (compat and the
 *      URL builders both pinned it). In production it is a 404 on both doors. Narrower than first
 *      thought: an unknown host never reaches PHP in production (the CDN and the Apache default arm
 *      refuse it), so the live exposure is a host routed here deliberately whose row does not match
 *      — backlog BF-HOST-STEP-D — and nothing wider.
 *   3. THE PIXEL — `META_CAPI_PIXEL_ID` defaults to Watchizer's in `config/services.php`, so a Brand
 *      Fashion purchase would be sent to Watchizer's pixel. NOT YET PROVEN HERE, on purpose: the
 *      default leaves the code only after the developer has set the key on production and verified
 *      the Conversions API still fires (W5); removing it unverified would switch the client's
 *      conversion tracking off silently. The case below is SKIPPED with that reason, not written as
 *      if it passed.
 *
 * The FOURTH, GA4's "blank means nothing", is the storefront's own guard (`Frontend-next/src/lib/
 * env.js`: anything not `G-…` is no id) and was measured in backlog GA4-PV. It cannot run in core's
 * suite and is not pretended to. A test that implies coverage it does not have is worse than a
 * missing one: the missing one is visible.
 */

const NF_API_KEY = 'no-fallback-test-key';

beforeEach(function () {
    config(['compat.api_key' => NF_API_KEY]);
    DB::table('storefront_payment_providers')->delete();          // every Brand Fashion identity value empty
    Http::preventStrayRequests();
    Http::fake();                                                 // anything sent anywhere is a failure below
});

/** @return array{id: int, price: float} a Watchizer product with stock, for an order body */
function nfProduct(): array
{
    $row = T::row(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'cp.id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1);
        })
        ->whereNull('cp.deleted_at')->where('cp.is_active', 1)->where('cp.stock_express', '>=', 3)
        ->orderBy('cp.id')->first(['cp.id', 'sp.effective_price', 'sp.effective_sale_price']));

    return ['id' => Row::int($row, 'id'), 'price' => CompatCart::catalogPrice(Row::money($row, 'effective_price'), Row::nmoney($row, 'effective_sale_price'))];
}

it('LEAK 1 — refuses a card order on a storefront with no live online contract BEFORE any write, and sends nothing anywhere', function () {
    // Even with somebody having "restored" the old keys: there is no code left that reads them.
    config(['services.paymob.secret_key' => 'restored-secret', 'services.paymob.public_key' => 'restored-public']);

    $product = nfProduct();
    $city = T::row(DB::table('shipping_cities')->orderBy('id')->first(['id', 'shipping_cost']));
    $orders = T::int(DB::table('orders')->count());
    $movements = T::int(DB::table('inventory_movements')->count());

    $response = withHeaders(['Api-Code' => NF_API_KEY, 'X-Guest-Token' => (string) Str::uuid()])
        ->postJson('https://api.watchizereg.com/api/add_order', [
            'shipping_city_id' => Row::int($city, 'id'), 'address_line' => 'No Fallback Street 1', 'phone' => '01000000001',
            'total_price_for_order' => round($product['price'] + (float) Row::money($city, 'shipping_cost'), 2),
            'payment_method' => 'card', 'guest_name' => 'No Fallback', 'guest_email' => 'nofallback@example.test',
            'items' => [['product_id' => $product['id'], 'quantity' => 1, 'piece_price' => $product['price'], 'total_price' => $product['price'], 'type_stock' => 'Express']],
        ]);

    $response->assertStatus(422)->assertJsonPath('success', false);
    expect(T::int(DB::table('orders')->count()))->toBe($orders)                 // nothing written
        ->and(T::int(DB::table('inventory_movements')->count()))->toBe($movements)
        ->and(app(CheckoutMethods::class)->refusal(2, null, 'card', 100.0))->not->toBeNull()   // Brand Fashion, the same answer
        ->and((new ReflectionClass(CompatCheckout::class))->hasMethod('createPaymobIntention'))->toBeFalse()   // the builder is gone, not dormant
        ->and(config('services.paymob.payment_methods'))->toBeNull();
    Http::assertNothingSent();
});

it('LEAK 2 — answers a host that names no storefront with 404 in production, on both doors', function () {
    app()['env'] = 'production';

    // The compat door: the step-D host before its row matches it.
    withHeaders(['Api-Code' => NF_API_KEY])->getJson('https://bf.michaelkhalaf.com/api/catalog/meta')->assertNotFound();
    // …and the one that does match still answers.
    withHeaders(['Api-Code' => NF_API_KEY])->getJson('https://api.watchizereg.com/api/catalog/meta')->assertOk();

    // The URL door: where a payment return, a reset link or a sign-in landing would be built.
    expect(fn () => StorefrontUrls::storefrontOf(Request::create('https://bf.michaelkhalaf.com/api/pay/brandfashion/paymob/callback')))
        ->toThrow(NotFoundHttpException::class);
});

it('LEAK 3 — sends a purchase to NO pixel when the storefront has none', function () {
    // see the file note: proven only after the production key is set and verified (W5)
})->skip('W5: META_CAPI_PIXEL_ID still defaults to Watchizer\'s pixel in config/services.php; the default leaves the code after the developer sets the key on production and verifies the Conversions API still fires — not before');

it('LEAK 2b — a storefront with neither api_host nor domain gets NO host, never Watchizer\'s', function () {
    $fashion = T::int(DB::table('storefronts')->where('code', 'brandfashion')->value('id'));
    DB::table('storefronts')->where('id', $fashion)->update(['domain' => null, 'settings' => null]);
    app(StorefrontCache::class)->forgetStorefront($fashion, 'brandfashion');

    expect(fn () => StorefrontUrls::apiHost($fashion))->toThrow(StorefrontHasNoHost::class)
        ->and(fn () => StorefrontUrls::frontend($fashion))->toThrow(StorefrontHasNoHost::class)
        ->and(fn () => StorefrontUrls::assetBase($fashion))->toThrow(StorefrontHasNoHost::class)
        // Watchizer's own values are untouched by another shop's emptiness.
        ->and(StorefrontUrls::apiHost(1))->toBe('https://api.watchizereg.com');
});
