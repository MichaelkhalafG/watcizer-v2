<?php

use App\Domain\Seo\OldImages;
use App\Http\Controllers\V2\RedirectController;
use App\Models\Storefront\StorefrontRedirect;
use Illuminate\Support\Facades\Artisan;
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

it('sends an old WordPress image COPY where its original goes, and leaves an unknown one a 404 (2026-11-24)', function () {
    $master = 'https://api.brandfashionegy.com/Uploads_Images/Product/9.webp';
    redirectRow(2, '/wp-content/uploads/2024/02/coach-bag.jpg', $master);
    $api = Shops::api('brandfashion');
    foreach ([
        '/wp-content/uploads/2024/02/coach-bag-300x300.jpg',
        '/wp-content/uploads/2024/02/coach-bag-scaled.jpg',
        '/wp-content/uploads/2024/02/coach-bag-1024x1024-scaled.jpg',
        '/wp-content/uploads/2024/02/coach-bag-e1712345678.jpg',
        '/wp-content/uploads/2024/02/coach-bag.jpg.webp',
        '/wp-content/uploads/2024/02/coach-bag-600x600.webp',
    ] as $copy) {
        getJson($api.'/api/v2/brandfashion/redirect?path='.rawurlencode($copy))->assertOk()->assertJsonPath('data.to', $master);
    }
    expect(DB::table('storefront_redirects')->where('from_path', '/wp-content/uploads/2024/02/coach-bag.jpg')->value('hits'))->toBe(6);
    // an image the import never copied, and a copy on the other shop: 404
    getJson($api.'/api/v2/brandfashion/redirect?path=/wp-content/uploads/2024/02/banner-300x300.jpg')->assertNotFound();
    getJson(Shops::api('watchizer').'/api/v2/watchizer/redirect?path=/wp-content/uploads/2024/02/coach-bag-300x300.jpg')->assertNotFound();
});

it('names the originals of a copy, nearest first, and nothing for a path that is not an old upload', function () {
    expect(OldImages::originals('/wp-content/uploads/a/b-300x200.png'))->toBe(['/wp-content/uploads/a/b.png', '/wp-content/uploads/a/b.jpg', '/wp-content/uploads/a/b.jpeg', '/wp-content/uploads/a/b.webp'])
        ->and(OldImages::originals('/wp-content/uploads/a/b.jpg'))->toBe([])
        ->and(OldImages::originals('/product/x-300x300.jpg'))->toBe([]);
});

it('lists the old image 404s in two groups and writes nothing (seo:old-images)', function () {
    redirectRow(2, '/wp-content/uploads/2024/03/wallet.jpg', 'https://api.brandfashionegy.com/Uploads_Images/Product/7.webp');
    foreach (['/wp-content/uploads/2024/03/wallet-150x150.jpg' => 9, '/wp-content/uploads/2024/03/logo-old.png' => 4] as $path => $hits) {
        DB::table('seo_not_found')->insert(['storefront_id' => 2, 'path' => $path, 'path_hash' => StorefrontRedirect::hashPath($path), 'hits' => $hits]);
    }
    $before = DB::table('storefront_redirects')->sum('hits');
    expect(Artisan::call('seo:old-images', ['--storefront' => 2]))->toBe(0);
    $out = Artisan::output();
    expect($out)->toContain('wallet-150x150.jpg -> https://api.brandfashionegy.com/Uploads_Images/Product/7.webp')
        ->and($out)->toMatch('/STAY 404[^\n]*\n\s+4\s+\/wp-content\/uploads\/2024\/03\/logo-old\.png/');
    expect(DB::table('storefront_redirects')->sum('hits'))->toBe($before);
});
