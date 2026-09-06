<?php

use App\Transform\LegacySource;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * Unit tests carry no DatabaseTransactions, so the legacy session has no open transaction
 * and the database-level guard can be switched on and exercised for real. The write it
 * attempts targets `WHERE id = -1`: even if the guard failed, nothing would change.
 */

afterEach(function () {
    DB::purge('legacy');   // drop the read-only session so later tests get a fresh connection
});

it('makes the legacy session read-only at the database level', function () {
    $source = new LegacySource(DB::connection('legacy'));
    $source->enforceReadOnly();

    expect($source->isReadOnlyEnforced())->toBeTrue();

    $write = fn () => DB::connection('legacy')->table('products')->where('id', -1)->update(['active' => 0]);

    expect($write)->toThrow(QueryException::class, 'READ ONLY transaction');
});

it('refuses tables outside the legacy whitelist', function () {
    $source = new LegacySource(DB::connection('legacy'));

    expect(fn () => $source->table('catalog_products'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $source->table('storefront_product'))->toThrow(InvalidArgumentException::class)
        ->and($source->table('products')->count())->toBeGreaterThan(0)
        ->and(count(LegacySource::TABLES))->toBe(65);
});
