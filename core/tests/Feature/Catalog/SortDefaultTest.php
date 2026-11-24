<?php

use App\Console\Commands\CatalogSortDefaultCommand;
use App\Domain\Catalog\PlacementWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\SideDdl;
use Tests\Support\T;

/*
 * The product sort default (Michael, 2026-11-24, D6): every product is 1, the team sets 0 on what it features. The
 * column's default, the writers, and the four-pass data step that turns every existing value into 1.
 */

beforeEach(function () {
    SideDdl::run(CatalogSortDefaultCommand::backupDdl(), freshSnapshot: true);
    DB::table(CatalogSortDefaultCommand::BACKUP)->delete();
});

it('gives a new storefront_product row 1 when nothing names its sort value (the column default)', function () {
    $default = DB::selectOne("SELECT COLUMN_DEFAULT AS d FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'storefront_product' AND COLUMN_NAME = 'sort_order'");
    expect(T::str($default->d ?? null))->toBe('1');
});

it('turns every value into 1 in four passes: a dry run that writes nothing, the apply, a second run with nothing to do, an exact undo', function () {
    // only these five rows are not 1 for this test: two at 0 on Watchizer, one at 7 on Watchizer, one at 0 and one at -3 on Brand Fashion
    DB::table('storefront_product')->update(['sort_order' => 1]);
    $pick = fn (int $sf, int $nth): int => T::int(DB::table('storefront_product')->where('storefront_id', $sf)->orderBy('id')->offset($nth)->value('id'));
    $rows = [$pick(1, 0) => 0, $pick(1, 1) => 0, $pick(1, 2) => 7, $pick(2, 0) => 0, $pick(2, 1) => -3];
    foreach ($rows as $id => $value) {
        DB::table('storefront_product')->where('id', $id)->update(['sort_order' => $value]);
    }
    $all = fn (): array => DB::table('storefront_product')->orderBy('id')->get(['id', 'sort_order', 'updated_at', 'is_featured'])->map(fn ($r): array => (array) $r)->all();
    $before = $all();

    expect(Artisan::call('catalog:sort-default'))->toBe(0);
    expect(Artisan::output())->toContain('shop 1: 2 row(s) at 0')->toContain('shop 1: 1 row(s) at another value')
        ->toContain('shop 2: 1 row(s) at 0')->toContain('DRY RUN: 5 row(s) would become 1');
    expect($all())->toBe($before);

    expect(Artisan::call('catalog:sort-default', ['--apply' => true]))->toBe(0);
    expect(Artisan::output())->toContain('APPLIED: 5 row(s) set to 1 (shop 1: 3, shop 2: 2); 0 left as found');
    $after = $all();
    foreach ($after as $i => $row) {
        // only the sort value of those five rows changed; updated_at and featured untouched
        expect($row['sort_order'])->toBe(1)
            ->and($row['updated_at'])->toBe($before[$i]['updated_at'])
            ->and($row['is_featured'])->toBe($before[$i]['is_featured']);
    }
    expect(DB::table(CatalogSortDefaultCommand::BACKUP)->count())->toBe(5);

    expect(Artisan::call('catalog:sort-default', ['--apply' => true]))->toBe(0);
    expect(Artisan::output())->toContain('NOTHING TO DO: every product is 1 already, 0 rows changed.');
    expect($all())->toBe($after);

    expect(Artisan::call('catalog:sort-default', ['--undo' => true]))->toBe(0);
    expect(Artisan::output())->toContain('UNDONE: 5 row(s) back to their value; the backup is dropped.');
    expect($all())->toBe($before);
});

it('leaves a row the team changed after the apply as the team set it, and keeps the backup', function () {
    DB::table('storefront_product')->update(['sort_order' => 1]);
    $id = T::int(DB::table('storefront_product')->where('storefront_id', 1)->orderBy('id')->value('id'));
    DB::table('storefront_product')->where('id', $id)->update(['sort_order' => 0]);
    expect(Artisan::call('catalog:sort-default', ['--apply' => true]))->toBe(0);
    // the team gives it its own place after the step
    DB::table('storefront_product')->where('id', $id)->update(['sort_order' => 5]);
    expect(Artisan::call('catalog:sort-default', ['--undo' => true]))->toBe(0);
    expect(Artisan::output())->toContain('UNDONE: 0 row(s) back; 1 left as the team set them since');
    expect(DB::table('storefront_product')->where('id', $id)->value('sort_order'))->toBe(5);
});

it('writes 1 for a product placed without a sort value, and a category reorder numbers from 1', function () {
    $sp = DB::table('storefront_product')->where('storefront_id', 1)->orderBy('id')->first(['product_id']);
    $productId = T::int($sp->product_id ?? null);
    app(PlacementWriter::class)->save(
        1, $productId,
        ['is_visible' => true, 'is_featured' => false, 'slug' => T::str(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)->value('slug'))],
    );
    expect(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId)->value('sort_order'))->toBe(1);

    $placed = DB::table('storefront_category_product')->where('storefront_id', 1)->select('storefront_category_id')
        ->groupBy('storefront_category_id')->havingRaw('COUNT(*) >= 2')->orderBy('storefront_category_id')->value('storefront_category_id');
    $category = T::int($placed);
    $ids = array_map(fn ($v): int => T::int($v), DB::table('storefront_category_product')->where('storefront_id', 1)->where('storefront_category_id', $category)->orderBy('product_id')->limit(2)->pluck('product_id')->all());
    app(PlacementWriter::class)->sortInCategory(1, $category, $ids);
    expect(DB::table('storefront_category_product')->where('storefront_category_id', $category)->whereIn('product_id', $ids)->orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2]);
});
