<?php

use App\Storefront\ContentIds;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * Block C (2026-11-12): ONE id per product in a storefront's ad catalogues — the pixel, the Conversions API, the Meta
 * feed — and Google's item id beside it. Watchizer: the product id, unchanged. Brand Fashion: the old site's WooCommerce
 * id where the product is one of the old products (`bfwp:` imported, else `woo:` the sheet copy whose id no imported
 * product holds), `p<id>` otherwise; Google `gla_<old id>` for an old product — the old Merchant Center's item id.
 */

it('gives Watchizer its product ids, and Brand Fashion the old shop\'s ids where it has them', function () {
    $imported = CatalogFixture::product('watch', 100.0);
    $sheetAlone = CatalogFixture::product('watch', 100.0);
    $sheetTwin = CatalogFixture::product('watch', 100.0);
    $twinImported = CatalogFixture::product('watch', 100.0);
    $plain = CatalogFixture::product('watch', 100.0);
    DB::table('catalog_products')->where('id', $imported)->update(['import_ref' => 'bfwp:990001']);
    DB::table('catalog_products')->where('id', $sheetAlone)->update(['import_ref' => 'woo:990002']);
    DB::table('catalog_products')->where('id', $sheetTwin)->update(['import_ref' => 'woo:990003']);       // archived sheet copy…
    DB::table('catalog_products')->where('id', $twinImported)->update(['import_ref' => 'bfwp:990003']);  // …whose old product was imported
    $ids = [$imported, $sheetAlone, $sheetTwin, $twinImported, $plain];

    expect(ContentIds::map(1, $ids))->toBe(array_combine($ids, array_map('strval', $ids)))          // Watchizer: unchanged
        ->and(ContentIds::map(2, $ids))->toBe([
            $imported => '990001', $sheetAlone => '990002', $sheetTwin => 'p'.$sheetTwin, $twinImported => '990003', $plain => 'p'.$plain,
        ])
        ->and(ContentIds::google(2, $ids))->toBe([
            $imported => 'gla_990001', $sheetAlone => 'gla_990002', $sheetTwin => 'p'.$sheetTwin, $twinImported => 'gla_990003', $plain => 'p'.$plain,
        ])
        ->and(ContentIds::google(1, [$imported]))->toBe([$imported => (string) $imported]);
});

it('hands the same id to the shop product page and cards that the feeds and the Conversions API use', function () {
    $id = CatalogFixture::product('watch', 100.0);
    CatalogFixture::onStorefront($id, true, 2);
    DB::table('catalog_products')->where('id', $id)->update(['import_ref' => 'bfwp:990011']);
    $slug = T::str(DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $id)->value('slug'));

    expect(getJson('/api/v2/brandfashion/products/'.$slug)->assertOk()->json('product.content_id'))->toBe('990011');

    // Watchizer's product page: the product id, as before
    $wz = CatalogFixture::product('watch', 100.0);
    CatalogFixture::onStorefront($wz, true, 1);
    $wzSlug = T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $wz)->value('slug'));
    expect(getJson('/api/v2/watchizer/products/'.$wzSlug)->assertOk()->json('product.content_id'))->toBe((string) $wz);
});
