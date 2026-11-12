<?php

use App\Http\Controllers\V2\RedirectController;
use App\Models\Storefront\StorefrontRedirect;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shops;

use function Pest\Laravel\getJson;

/*
 * The reader of `storefront_redirects` (2026-11-11, D-17): an old URL → its new one, per storefront.
 * The rows are written here, inside the test's transaction.
 */

function redirectRow(int $storefront, string $from, string $to): void
{
    DB::table('storefront_redirects')->insert([
        'storefront_id' => $storefront, 'from_hash' => StorefrontRedirect::hashPath($from), 'from_path' => $from,
        'to_path' => $to, 'status' => 301, 'source' => 'bf_legacy', 'hits' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('normalises a path the way the rows were stored', function () {
    expect(RedirectController::normalise('/product-category/men/men-watches/'))->toBe('/product-category/men/men-watches')
        ->and(RedirectController::normalise('/product/%D8%B3%D8%A7%D8%B9%D8%A9/?lang=ar'))->toBe('/product/ساعة')
        ->and(RedirectController::normalise('/'))->toBe('/')
        ->and(RedirectController::normalise('no-slash'))->toBeNull()
        ->and(RedirectController::normalise(''))->toBeNull();
});

it('answers the new URL for an old one — trailing slash, case and encoding aside — and counts the hit', function () {
    redirectRow(2, '/product-category/men/men-watches', '/category/watches');
    $api = Shops::api('brandfashion');

    getJson($api.'/api/v2/brandfashion/redirect?path='.rawurlencode('/Product-Category/men/men-watches/'))
        ->assertOk()
        ->assertJsonPath('data.to', '/category/watches')
        ->assertJsonPath('data.status', 301)
        ->assertHeader('Cache-Control', 'max-age=0, private');

    expect(DB::table('storefront_redirects')->where('from_path', '/product-category/men/men-watches')->value('hits'))->toBe(1);
});

it('answers 404 for a path with no row, and never another storefront\'s row', function () {
    redirectRow(1, '/only-on-watchizer', '/listing');
    $api = Shops::api('brandfashion');

    getJson($api.'/api/v2/brandfashion/redirect?path=/only-on-watchizer')->assertNotFound()->assertJsonPath('data', null);
    getJson($api.'/api/v2/brandfashion/redirect?path=/nothing-here/')->assertNotFound();
    getJson($api.'/api/v2/brandfashion/redirect?path=not-a-path')->assertNotFound();
});
