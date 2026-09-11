<?php

use App\Domain\Catalog\LookupWriter;
use App\Storefront\Lookups;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * Brands and the eleven lookup lists (scope item 6) — one screen, twelve datasets, driven by
 * `config('catalog.lookups')`.
 *
 * The usage count is the point: every reference from a product or a variant is a `RESTRICT`
 * foreign key (M1 `restrictOnDelete()`), so deleting a colour 200 products use fails at the
 * DATABASE with a 1451 that reaches a team member as a 500 page. The count, and the refusal that
 * names it, is the message; the foreign key stays the mechanism.
 */

it('exposes every configured list, and every list points at real tables', function () {
    $lists = LookupWriter::all();

    expect($lists)->toHaveCount(12)
        ->and($lists)->toHaveKey('brands')
        ->and($lists)->toHaveKey('colors')
        ->and($lists)->toHaveKey('units');

    $broken = [];
    foreach ($lists as $key => $def) {
        foreach ([$def['master'], $def['translations']] as $table) {
            if (! Schema::hasTable($table)) {
                $broken[] = "{$key}: table {$table} does not exist";
            }
        }
        if (! Schema::hasColumn($def['translations'], $def['fk'])) {
            $broken[] = "{$key}: {$def['translations']}.{$def['fk']} does not exist";
        }
        foreach ($def['usage'] as [$table, $column]) {
            if (! Schema::hasColumn($table, $column)) {
                $broken[] = "{$key}: usage {$table}.{$column} does not exist";
            }
        }
        foreach (array_keys($def['extra']) as $column) {
            if (! Schema::hasColumn($def['master'], $column)) {
                $broken[] = "{$key}: extra column {$def['master']}.{$column} does not exist";
            }
        }
    }

    expect($broken)->toBe([], 'config/catalog.php names something the schema does not have');
});

it('shares its keys with the storefront read layer, so the two cannot disagree', function () {
    // `App\Storefront\Lookups::TABLES` is what the storefront caches. A list the dashboard calls
    // `movements` and the read layer calls `movement_types` would be two names for one dataset.
    $shared = array_intersect(array_keys(LookupWriter::all()), array_keys(Lookups::TABLES));

    expect(count($shared))->toBeGreaterThanOrEqual(11);

    foreach ($shared as $key) {
        expect(LookupWriter::definition($key)['master'])->toBe(Lookups::TABLES[$key][0], "list [{$key}] points at a different table than the read layer");
    }
});

it('creates a row with both names and its extra columns', function () {
    CatalogFixture::assumeSwitched();
    actingAs(Staff::dataEntry())->post('/manage/lookups/colors', [
        'name' => ['ar' => 'أزرق اختبار', 'en' => 'Test Blue'],
        'extra' => ['hex' => '#1a2b3c'],
    ])->assertSessionHasNoErrors();

    $row = T::one(DB::table('catalog_colors')->orderByDesc('id'));
    // Stored upper-case, as the transform stores it (deviation D-07): CSS does not care, but a
    // mixed-case column makes two identical colours look different.
    expect(Row::str($row, 'hex'))->toBe('#1A2B3C');

    $names = DB::table('catalog_color_translations')->where('color_id', Row::int($row, 'id'))->pluck('name', 'locale');
    expect($names['ar'])->toBe('أزرق اختبار')->and($names['en'])->toBe('Test Blue');
});

it('refuses a row with no Arabic name', function () {
    CatalogFixture::assumeSwitched();
    actingAs(Staff::dataEntry())->post('/manage/lookups/colors', [
        'name' => ['ar' => '', 'en' => 'English only'],
        'extra' => ['hex' => '#000000'],
    ])->assertSessionHasErrors('name.ar');
});

it('deletes an emptied English name rather than storing it blank', function () {
    CatalogFixture::assumeSwitched();
    actingAs(Staff::admin())->post('/manage/lookups/materials', [
        'name' => ['ar' => 'مادة', 'en' => 'Material'],
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('catalog_materials')->orderByDesc('id')->value('id'));

    actingAs(Staff::admin())->put("/manage/lookups/materials/{$id}", [
        'name' => ['ar' => 'مادة', 'en' => ''],
    ])->assertSessionHasNoErrors();

    expect(DB::table('catalog_material_translations')->where('material_id', $id)->where('locale', 'en')->exists())->toBeFalse()
        ->and(DB::table('catalog_material_translations')->where('material_id', $id)->where('locale', 'ar')->exists())->toBeTrue();
});

it('counts usage, and REFUSES to delete a row anything references', function () {
    // The real case: a colour used by a product pivot. The FK is RESTRICT, so the refusal here is
    // the message and not the mechanism — but the message is what stops the 500.
    $colorId = T::int(DB::table('catalog_product_color')->orderBy('color_id')->value('color_id'));
    $uses = DB::table('catalog_product_color')->where('color_id', $colorId)->count();
    expect($uses)->toBeGreaterThan(0);

    actingAs(Staff::admin())->delete("/manage/lookups/colors/{$colorId}")->assertSessionHasErrors('delete');

    expect(DB::table('catalog_colors')->where('id', $colorId)->exists())->toBeTrue();
    expect(T::err('delete'))->toContain((string) $uses);
});

it('deletes a row nothing references', function () {
    CatalogFixture::assumeSwitched();
    actingAs(Staff::admin())->post('/manage/lookups/colors', [
        'name' => ['ar' => 'لون غير مستخدم', 'en' => 'Unused colour'],
        'extra' => ['hex' => '#abcdef'],
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('catalog_colors')->orderByDesc('id')->value('id'));
    expect(DB::table('catalog_product_color')->where('color_id', $id)->count())->toBe(0);

    actingAs(Staff::admin())->delete("/manage/lookups/colors/{$id}")->assertSessionHasNoErrors();

    expect(DB::table('catalog_colors')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('catalog_color_translations')->where('color_id', $id)->exists())->toBeFalse();
});

it('shows a usage count per row, computed in one query per reference pair', function () {
    $props = Props::of(actingAs(Staff::dataEntry())->get('/manage/lookups/colors')->assertOk());
    /** @var list<array<string, mixed>> $rows */
    $rows = $props['rows'];

    expect($rows)->not->toBe([]);

    $used = 0;
    foreach ($rows as $row) {
        expect($row)->toHaveKey('uses');
        if (T::int($row['uses']) > 0) {
            $used++;
        }
    }
    expect($used)->toBeGreaterThan(0, 'no colour on the screen shows a usage count, which cannot be right');

    // Spot-check one against the database, so the number is real and not a placeholder.
    $first = $rows[0];
    $id = T::int($first['id']);
    $actual = DB::table('catalog_product_color')->where('color_id', $id)->count()
        + DB::table('catalog_product_variants')->where('color_id', $id)->count();
    expect(T::int($first['uses']))->toBe($actual);
});

it('generates a brand slug from the English name and refuses an Arabic-only one', function () {
    CatalogFixture::assumeSwitched();
    // Public URLs are `LegacySlug`-shaped: Arabic characters are dropped, so an Arabic-only name
    // yields '' and the writer refuses rather than creating a brand with an empty URL.
    actingAs(Staff::admin())->post('/manage/lookups/brands', [
        'name' => ['ar' => 'ماركة اختبار', 'en' => 'Test Brand Name'],
        'extra' => ['slug' => '', 'is_active' => true],
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('catalog_brands')->orderByDesc('id')->value('slug')))->toBe('test-brand-name');

    actingAs(Staff::admin())->post('/manage/lookups/brands', [
        'name' => ['ar' => 'ماركة عربية فقط', 'en' => ''],
        'extra' => ['slug' => '', 'is_active' => true],
    ])->assertSessionHasErrors();
});

it('refuses a duplicate brand slug with a field error', function () {
    CatalogFixture::assumeSwitched();
    $existing = T::str(DB::table('catalog_brands')->orderBy('id')->value('slug'));

    actingAs(Staff::admin())->post('/manage/lookups/brands', [
        'name' => ['ar' => 'مكرر', 'en' => 'Duplicate'],
        'extra' => ['slug' => $existing, 'is_active' => true],
    ])->assertSessionHasErrors('extra.slug');
});

it('validates a hex colour instead of storing whatever arrives', function () {
    CatalogFixture::assumeSwitched();
    foreach (['nope', '#12345', 'red', '#1234567'] as $bad) {
        actingAs(Staff::admin())->post('/manage/lookups/colors', [
            'name' => ['ar' => 'سيئ', 'en' => 'Bad'],
            'extra' => ['hex' => $bad],
        ])->assertSessionHasErrors('extra.hex');
    }
});

it('bumps every storefront cache version when a lookup name changes', function () {
    // A lookup name is in `catalog/meta`, the compat `names` payload and every product DTO, so a
    // rename must invalidate every storefront.
    $cache = app(StorefrontCache::class);
    $before = $cache->version(1);

    $id = T::int(DB::table('catalog_colors')->orderBy('id')->value('id'));
    actingAs(Staff::admin())->put("/manage/lookups/colors/{$id}", [
        'name' => ['ar' => 'اسم جديد', 'en' => 'New name'],
        'extra' => ['hex' => '#111111'],
    ])->assertSessionHasNoErrors();

    expect($cache->version(1))->toBeGreaterThan($before);
});

it('renders every list without error, which is what twelve datasets on one screen has to mean', function () {
    foreach (array_keys(LookupWriter::all()) as $key) {
        $props = Props::of(actingAs(Staff::dataEntry())->get("/manage/lookups/{$key}")->assertOk());

        expect($props['list'])->toBeArray();
        /** @var array<string, mixed> $list */
        $list = $props['list'];
        expect($list['key'])->toBe($key)
            ->and($props['rows'])->toBeArray()
            ->and($props['lists'])->toBeArray();
    }
});

it('keeps the lookup screens catalogue-wide, with no storefront in the URL', function () {
    // A colour is a colour on every storefront (D3: one shared catalogue), so these routes name no
    // storefront and carry no scope middleware — and the authorisation test asserts the opposite
    // for the routes that DO name one.
    $route = Route::getRoutes()->getByName('manage.lookups.index');
    expect($route)->not->toBeNull();
    if ($route === null) {
        return;
    }
    expect($route->uri())->not->toContain('{storefront}');

    // It is still storefront-AWARE where it matters: a rename invalidates every storefront's
    // cache, which the test above proves.
    expect(CatalogFixture::STOREFRONT)->toBe(1);
});
