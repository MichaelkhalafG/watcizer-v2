<?php

use Database\Seeders\DemoVariantsRemoveSeeder;
use Database\Seeders\DemoVariantsSeeder;
use Illuminate\Support\Facades\DB;

/*
 * DemoVariantsSeeder writes 205 variant rows into real products. Its environment guard is an
 * allow-list of ONE value — `local` — and these tests prove the door is shut from the suite's own
 * environment (`testing`), which is exactly the "not production, still not yours" case a deny-list
 * of `production` would have let through. They also prove the refusal happens BEFORE any write.
 */

it('refuses to seed unless APP_ENV is local, naming the environment it saw', function () {
    expect(app()->environment())->not->toBe('local');
    $variants = DB::table('catalog_product_variants')->count();
    $movements = DB::table('inventory_movements')->count();

    $thrown = null;
    try {
        app(DemoVariantsSeeder::class)->run(app(App\Domain\Inventory\InventoryService::class), app(App\Domain\Catalog\ProductWriter::class));
    } catch (RuntimeException $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain('APP_ENV=local')
        ->and($thrown?->getMessage())->toContain("APP_ENV is '".app()->environment()."'")
        ->and($thrown?->getMessage())->toContain('Nothing was written');
    // …and nothing was.
    expect(DB::table('catalog_product_variants')->count())->toBe($variants)
        ->and(DB::table('inventory_movements')->count())->toBe($movements);
});

it('refuses to remove unless APP_ENV is local, on its own door', function () {
    $thrown = null;
    try {
        app(DemoVariantsRemoveSeeder::class)->run();
    } catch (RuntimeException $e) {
        $thrown = $e;
    }
    expect($thrown)->toBeInstanceOf(RuntimeException::class)
        ->and($thrown?->getMessage())->toContain('APP_ENV=local');
});

it('names its guard as an allow-list of local, not a deny-list of production', function () {
    // A reader of the class sees the rule; a future edit that turns it back into "not production"
    // has to change this line and explain itself.
    $source = (string) file_get_contents(database_path('seeders/DemoVariantsSeeder.php'));
    expect($source)->toContain("if (\$env !== 'local')")
        ->and($source)->not->toContain("environment('production')");
});
