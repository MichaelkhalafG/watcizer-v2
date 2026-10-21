<?php

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\T;

/*
 * `orders.storefront_id` — the backfill's promise (Addition A, 2026-10-23).
 *
 * The migration 2026_10_23_000000_orders_storefront_backfill tags the untagged orders with storefront 1 and
 * then makes the column NOT NULL.
 *
 * These tests are written to be TRUE BEFORE AND AFTER it runs, because the suite's database is not the one
 * the migration is deployed to and the gate must mean the same thing on both. What they pin is the reasoning
 * the backfill rests on, and the state it leaves behind:
 *
 *  1. every untagged order predates the storefronts themselves — which is WHY they can only be Watchizer's,
 *     and the assertion that would fail if a NEW writer ever left one untagged;
 *  2. once the column is NOT NULL, nothing is null — the migration's own post-condition, asserted only when
 *     the migration has actually run, so this file is green on a database that predates it.
 */

it('leaves no order untagged that was created after the storefronts existed', function () {
    if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'storefront_id')) {
        expect(true)->toBeTrue('this database has no orders.storefront_id');

        return;
    }

    $firstStorefront = DB::table('storefronts')->min('created_at');
    if ($firstStorefront === null) {
        expect(true)->toBeTrue('no storefront rows to compare against');

        return;
    }

    /*
     * The backfill's whole justification: an order with no storefront must predate there BEING storefronts.
     * One created afterwards would mean a writer that forgot, and guessing its shop would be a guess.
     */
    $late = DB::table('orders')
        ->whereNull('storefront_id')
        ->where('created_at', '>=', $firstStorefront)
        ->orderBy('id')
        ->pluck('id')
        ->map(fn ($i): int => T::int($i))
        ->all();

    expect($late)->toBe([], 'orders created after the storefronts exist but carry none: '.implode(', ', array_map('strval', $late)));
});

it('has no untagged order at all once the column is NOT NULL', function () {
    if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'storefront_id')) {
        expect(true)->toBeTrue('this database has no orders.storefront_id');

        return;
    }

    $nullable = true;
    foreach (Schema::getColumns('orders') as $column) {
        if (is_array($column) && ($column['name'] ?? null) === 'storefront_id') {
            $nullable = (bool) ($column['nullable'] ?? true);
        }
    }

    if ($nullable) {
        // the migration has not been applied to this database; rule 1 above is what guards it meanwhile
        expect(true)->toBeTrue('orders.storefront_id is still nullable here');

        return;
    }

    expect((int) DB::table('orders')->whereNull('storefront_id')->count())->toBe(0);
});

it('points every order at a storefront that exists', function () {
    if (! Schema::hasTable('orders') || ! Schema::hasColumn('orders', 'storefront_id')) {
        expect(true)->toBeTrue('this database has no orders.storefront_id');

        return;
    }

    // a tag that names no storefront is worse than no tag: a per-shop read drops it and nothing says so
    $orphans = DB::table('orders as o')
        ->whereNotNull('o.storefront_id')
        ->whereNotExists(fn (Builder $q) => $q->select(DB::raw('1'))->from('storefronts as s')->whereColumn('s.id', 'o.storefront_id'))
        ->orderBy('o.id')
        ->pluck('o.id')
        ->map(fn ($i): int => T::int($i))
        ->all();

    expect($orphans)->toBe([], 'orders tagged to a storefront that does not exist: '.implode(', ', array_map('strval', $orphans)));
});
