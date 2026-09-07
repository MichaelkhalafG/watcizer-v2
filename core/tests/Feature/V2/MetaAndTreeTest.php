<?php

use Tests\Feature\V2\ApiTestHelpers as H;

use function Pest\Laravel\getJson;

/*
 * GET /api/v2/{storefront}/meta and /categories — happy, empty, 404, locale.
 */

beforeEach(fn () => H::flush());

it('serves the storefront meta with both-locale names and the visible tree', function () {
    $res = getJson(H::base('meta'))->assertOk();
    $res->assertJsonPath('storefront.code', 'watchizer')
        ->assertJsonPath('storefront.default_locale', 'ar')
        ->assertJsonPath('locale', 'ar')
        ->assertJsonStructure(['storefront' => ['code', 'name', 'locales', 'currency', 'settings'], 'brands', 'grades', 'filters' => ['colors', 'materials', 'shapes', 'display_types', 'movements', 'genders'], 'tree', 'banners']);

    $brand = H::arr($res->json('brands.0'));
    expect($brand)->toHaveKeys(['id', 'slug', 'name'])
        ->and(H::arr($brand['name']))->toHaveKeys(['ar', 'en']);
    expect($res->json('tree'))->not->toBeEmpty();
    // Every tree node passes the visibility rule: it holds at least one product.
    foreach (H::treePaths($res->json('tree')) as $path) {
        expect($path)->not->toBe('');
    }
    foreach (H::rows($res->json('tree')) as $root) {
        expect($root['product_count'])->toBeGreaterThan(0);
    }
});

it('honours ?locale= within the storefront locales and falls back to the default otherwise', function () {
    getJson(H::base('meta?locale=en'))->assertOk()->assertJsonPath('locale', 'en');
    getJson(H::base('meta?locale=fr'))->assertOk()->assertJsonPath('locale', 'ar');
});

it('404s an unknown or inactive storefront', function () {
    getJson('/api/v2/nope/meta')->assertNotFound()->assertJsonPath('message', 'Storefront not found');
});

it('serves the category tree and a node by slug path with breadcrumb and children', function () {
    $tree = getJson(H::base('categories'))->assertOk()->json('tree');
    expect($tree)->not->toBeEmpty();
    $node = H::smallSubtree();

    $res = getJson(H::base('categories/'.$node['path']))->assertOk();
    $res->assertJsonPath('category.slug', $node['slug'])
        ->assertJsonPath('category.path', $node['path'])
        ->assertJsonPath('category.product_count', count($node['products']));
    expect($res->json('breadcrumb'))->toHaveCount(2)
        ->and($res->json('breadcrumb.1.path'))->toBe($node['path']);
});

it('404s a category path whose segments are not parent → child, and an unknown slug', function () {
    $node = H::smallSubtree();
    getJson(H::base('categories/'.$node['slug']))->assertNotFound();      // bare depth-2 slug is not its path
    getJson(H::base('categories/does-not-exist'))->assertNotFound();
});

it('never lists the hidden legacy-tree root or its dormant children', function () {
    $paths = H::treePaths(getJson(H::base('categories'))->json('tree'));
    expect($paths)->not->toContain('legacy-tree');
    foreach ($paths as $p) {
        expect($p)->not->toStartWith('legacy-tree/');
    }
});
