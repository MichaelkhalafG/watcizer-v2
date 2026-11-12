<?php

use App\Feeds\MetaFeed;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

/*
 * Brand Fashion's Meta catalogue feed (2026-11-12, for the switch): OFF until its token is set; with it, every row
 * carries the id the old site's pixel sent (ContentIds — `bfwp:N` → `N`) and links on the real www domain.
 */

it('keeps Brand Fashion\'s Meta feed off until its token is set', function () {
    config()->set('feeds.meta.brandfashion.token', '');

    expect(MetaFeed::config('brandfashion'))->toBeNull()
        ->and(config('feeds.meta.brandfashion.domain'))->toBe('https://www.brandfashionegy.com')
        ->and(config('feeds.meta.brandfashion.image_host'))->toBe('https://api.brandfashionegy.com')
        ->and(MetaFeed::configured())->not->toContain('brandfashion');
});

it('gives an old Brand Fashion product the old site\'s id and a www link once the token is set', function () {
    config()->set('feeds.meta.brandfashion.token', str_repeat('b1', 20));
    $id = CatalogFixture::product('watch', 100.0);
    CatalogFixture::onStorefront($id, true, 2);
    DB::table('catalog_products')->where('id', $id)->update(['import_ref' => 'bfwp:990021']);

    $path = app(MetaFeed::class)->generate('brandfashion')['path'];
    $rows = array_map('str_getcsv', file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    $head = T::arr(array_shift($rows));
    $idCol = (int) array_search('id', $head, true);
    $linkCol = (int) array_search('link', $head, true);
    $mine = array_values(array_filter($rows, fn (array $r): bool => ($r[$idCol] ?? null) === '990021'));

    expect($mine)->toHaveCount(1)
        ->and($head[$idCol] ?? null)->toBe('id')
        ->and(T::str($mine[0][$linkCol] ?? null))->toStartWith('https://www.brandfashionegy.com/');
    @unlink($path);
    @rmdir(dirname($path));                                     // only when empty: never another file
});
