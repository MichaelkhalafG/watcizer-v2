<?php

use App\Storefront\ImageUrl;
use Illuminate\Support\Facades\DB;
use Tests\Feature\V2\ApiTestHelpers as H;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * The renditions reach the payloads (2026-10-22).
 *
 * `catalog_product_images.renditions` is written by `ImagePipeline` as `width => {format => file}` with
 * BARE filenames; `ImageUrl::object()` read it as `format => {width => file}` and placed the name in no
 * folder, so 8,607 rows with a full ladder answered `srcset: []` and would have 404'd had they not. These
 * tests hold the reader to the writer's shape, and to the rule that nothing is derived from the naming
 * convention: only what a row records is offered.
 */

beforeEach(fn () => H::flush());

it('reads the shape the pipeline writes, places a bare rendition beside its master, and sorts by width', function () {
    config(['storefront.request_asset_base' => null, 'storefront.asset_base' => 'https://assets.test']);
    $written = [
        '320' => ['avif' => 'm-320.avif', 'webp' => 'm-320.webp'],
        '160' => ['webp' => 'm-160.webp', 'avif' => 'm-160.avif'],
    ];

    $srcset = ImageUrl::srcset('Product/m.webp', $written);

    expect(array_map(fn (array $r): string => $r['width'].' '.$r['format'], $srcset))->toBe(['160 avif', '160 webp', '320 avif', '320 webp'])
        ->and($srcset[0]['src'])->toBe('https://assets.test/Uploads_Images/Product/m-160.avif', 'a bare filename lives in the MASTER\'s folder')
        ->and(ImageUrl::object('Product/m.webp', 800, 800, null, $written)['srcset'])->toBe($srcset);
});

it('reads the documented shape too, keeps a recorded folder, and drops what is not a rendition', function () {
    config(['storefront.request_asset_base' => null, 'storefront.asset_base' => 'https://assets.test']);
    $documented = [
        'avif' => ['320' => 'Product_image/g-320.avif', 'junk' => 'g-x.avif', '0' => 'g-0.avif'],
        'webp' => ['640' => 'g-640.webp', '320' => ''],
        'png' => ['320' => 'g-320.png'],
        'note' => 'not an array',
    ];

    $srcset = ImageUrl::srcset('Product_image/g.webp', $documented);

    expect(array_map(fn (array $r): string => $r['width'].' '.$r['format'].' '.$r['src'], $srcset))->toBe([
        '320 avif https://assets.test/Uploads_Images/Product_image/g-320.avif',
        '640 webp https://assets.test/Uploads_Images/Product_image/g-640.webp',
    ])->and(ImageUrl::srcset('Product/m.webp', null))->toBe([])
        ->and(ImageUrl::srcset('Product/m.webp', []))->toBe([]);
});

/**
 * A visible product on the test storefront whose cover row records renditions, with its slug.
 *
 * @return array{id: int, path: string, renditions: string, slug: string}|null
 */
function srcsetProduct(): ?array
{
    $row = DB::table('catalog_product_images as i')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'i.product_id')
        ->join('catalog_products as p', 'p.id', '=', 'i.product_id')
        ->where('sp.storefront_id', 1)->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
        ->where('i.is_cover', 1)->whereNotNull('i.renditions')->where('i.renditions', '!=', '[]')
        ->orderBy('i.product_id')
        ->first(['i.product_id', 'i.path', 'i.renditions', 'sp.slug']);

    return $row === null ? null : ['id' => T::int($row->product_id), 'path' => T::str($row->path), 'renditions' => T::str($row->renditions), 'slug' => T::str($row->slug)];
}

it('serves the recorded renditions on the v2 product detail and card, every URL in the master\'s folder', function () {
    $product = srcsetProduct();
    expect($product)->not->toBeNull('no visible product with recorded renditions on storefront 1');
    if ($product === null) {
        return;
    }

    $page = getJson(H::base('products/'.$product['slug']))->assertOk()->json('product');
    $cover = H::arr(H::rows(H::arr($page)['images'])[0]);
    $srcset = H::rows($cover['srcset']);
    $recorded = T::arr(json_decode($product['renditions'], true));
    $expected = 0;
    foreach ($recorded as $formats) {
        $expected += count(T::arr($formats));
    }
    $folder = substr($product['path'], 0, (int) strrpos($product['path'], '/'));

    expect($srcset)->toHaveCount($expected, 'every recorded file, and nothing that is not recorded');
    foreach ($srcset as $entry) {
        $e = H::arr($entry);
        $width = $e['width'];
        $format = H::str($e['format']);
        expect($width)->toBeInt()->toBeGreaterThan(0)
            ->and($format)->toBeIn(['avif', 'webp'])
            ->and(H::str($e['src']))->toContain('/Uploads_Images/'.$folder.'/')
            ->and(H::str($e['src']))->toEndWith('-'.T::int($width).'.'.$format);
    }

    // The card carries the same cover object as the detail.
    $card = H::arr(getJson(H::base('products?per_page=96&sort=newest'))->assertOk()->json('data.0'));
    expect(H::arr($card['image']))->toHaveKeys(['src', 'srcset']);
});

it('serves the cover\'s renditions on the compat card row and on its gallery rows, on the compat image host', function () {
    $product = srcsetProduct();
    expect($product)->not->toBeNull('no visible product with recorded renditions on storefront 1');
    if ($product === null) {
        return;
    }
    config(['compat.api_key' => 'srcset-test-key']);
    $cards = getJson('/api/catalog/cards?ids='.$product['id'], ['Api-Code' => 'srcset-test-key'])->assertOk()->json();
    $row = H::arr(H::rows(H::arr($cards)['products'])[0]);
    $srcset = H::rows($row['image_srcset']);
    $base = rtrim(config()->string('compat.asset_base'), '/');

    expect($row)->toHaveKey('image')
        ->and($srcset)->not->toBe([])
        ->and(H::str(H::arr($srcset[0])['src']))->toStartWith($base.'/Uploads_Images/Product/');
    foreach (H::rows(H::arr($cards)['images']) as $image) {
        expect(H::arr($image))->toHaveKey('srcset');
    }
});
