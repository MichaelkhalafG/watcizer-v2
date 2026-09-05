<?php

use App\Models\Legacy\LegacyProduct;
use App\Models\Storefront\Storefront;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('has the clean core tables beside the untouched legacy tables', function () {
    foreach ([
        'catalog_brands', 'catalog_products', 'catalog_product_translations', 'catalog_product_search',
        'storefronts', 'storefront_product', 'storefront_categories', 'storefront_redirects',
        'inventory_movements', 'integration_outbox', 'core_migrations',
    ] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing clean table {$table}");
    }

    foreach (['products', 'orders', 'users', 'migrations', 'product_variants'] as $legacy) {
        expect(Schema::hasTable($legacy))->toBeTrue("legacy table {$legacy} must still exist");
    }
});

it('writes and reads back one row in a clean table', function () {
    $storefront = Storefront::create([
        'code' => 'wave0-smoke',
        'name' => 'Wave 0 smoke',
        'domain' => 'smoke.test',
        'locales' => ['ar', 'en'],
        'default_locale' => 'ar',
        'currency' => 'EGP',
        'is_active' => true,
        'settings' => ['theme' => 'default'],
    ]);

    $fresh = Storefront::query()->where('code', 'wave0-smoke')->firstOrFail();

    expect($fresh->id)->toBe($storefront->id)
        ->and($fresh->locales)->toBe(['ar', 'en'])
        ->and($fresh->settings)->toBe(['theme' => 'default'])
        ->and($fresh->is_active)->toBeTrue();
});

it('reads legacy tables through the read-only legacy connection', function () {
    $viaLegacy = LegacyProduct::query()->count();

    expect($viaLegacy)->toBeGreaterThan(0)
        ->and($viaLegacy)->toBe(DB::table('products')->count());

    $product = LegacyProduct::query()->firstOrFail();
    $product->active = 0;

    expect(fn () => $product->save())->toThrow(LogicException::class);
});
