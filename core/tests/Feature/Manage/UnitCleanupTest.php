<?php

use App\Domain\Catalog\SpecBlocks;
use App\Domain\Catalog\UnitCleanup;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Units cleanup (wave 4D, task C3).
 *
 * The screen exists because the legacy `size_types` table mixed physical units with garment and
 * shoe sizes, and the transform carries that across faithfully. Every test here is about the two
 * verbs that fix it — MERGE, then RETIRE — and the refusals that keep them in that order.
 */

/** A unit with a code, created for a test to move around. Returns its id. */
function makeUnit(string $code, string $ar, string $en): int
{
    $id = (int) DB::table('catalog_units')->insertGetId([
        'code' => $code,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach ([['ar', $ar], ['en', $en]] as [$locale, $name]) {
        DB::table('catalog_unit_translations')->insert([
            'unit_id' => $id, 'locale' => $locale, 'name' => $name,
        ]);
    }

    return $id;
}

/** A watch product whose case size is measured in $unitId. Returns the product id. */
function specUsing(int $unitId): int
{
    $product = T::int(DB::table('catalog_products')->whereNull('deleted_at')->where('family', 'watch')->value('id'));
    expect($product)->toBeGreaterThan(0, 'this catalogue has no watch to attach a spec to');

    // The specs table has no timestamps — one row per product, written by the product form.
    DB::table('catalog_product_watch_specs')->updateOrInsert(
        ['product_id' => $product],
        ['case_size' => 42, 'case_size_unit_id' => $unitId],
    );

    return $product;
}

// ── 1. what the screen is looking at ─────────────────────────────────────────────────────────

it('finds every unit reference column from the SCHEMA, not from a typed list', function () {
    $columns = UnitCleanup::referenceColumns();

    // Eight today. Derived, so a ninth added by a later migration is covered without anyone
    // remembering to update a constant — which is the whole reason it is not a constant.
    expect($columns)->toContain('case_size_unit_id')
        ->and($columns)->toContain('band_length_unit_id')
        ->and($columns)->toContain('water_resistance_unit_id')
        ->and(count($columns))->toBeGreaterThanOrEqual(8);

    foreach ($columns as $column) {
        expect($column)->toEndWith('_unit_id');
    }
});

it('counts what uses each unit, and flags the ones that are really clothing sizes', function () {
    $units = UnitCleanup::all();
    expect($units)->not->toBe([]);

    $byCode = [];
    foreach ($units as $unit) {
        $byCode[T::str($unit['code'] ?? null)] = $unit;
    }

    // The real units are not flagged…
    expect($byCode['mm']['looks_like_a_size'] ?? null)->toBeFalse()
        ->and($byCode['atm']['looks_like_a_size'] ?? null)->toBeFalse();

    // …and the garment/shoe sizes that came out of `size_types` are.
    foreach (['xs', 'xl', 'xxxl', '42', 'free-size'] as $code) {
        expect($byCode)->toHaveKey($code);
        expect($byCode[$code]['looks_like_a_size'] ?? null)->toBeTrue("[{$code}] should read as a size");
    }

    // `M` is the one that matters: it LOOKS like a size and is used as a unit, which is why the
    // screen offers merge rather than quietly retiring everything that looks like a size.
    expect($byCode['m']['looks_like_a_size'] ?? null)->toBeTrue();
});

// ── 2. merge ─────────────────────────────────────────────────────────────────────────────────

it('moves every reference when two units are merged, and retires the source', function () {
    $from = makeUnit('zz-test-from', 'اختبار', 'Test from');
    $into = makeUnit('zz-test-into', 'اختبار', 'Test into');
    $product = specUsing($from);

    $moved = app(UnitCleanup::class)->merge($from, $into);

    expect($moved)->toBe(1)
        ->and(T::int(DB::table('catalog_product_watch_specs')->where('product_id', $product)->value('case_size_unit_id')))
        ->toBe($into);

    // The source is retired in the SAME operation: a merge that left the empty unit in the picker
    // would have done half the job, and the half it left is the half that caused the mess.
    expect(DB::table('catalog_units')->where('id', $from)->value('retired_at'))->not->toBeNull();
});

it('refuses to merge a unit into itself, or into one that is already retired', function () {
    $a = makeUnit('zz-a', 'أ', 'A');
    $b = makeUnit('zz-b', 'ب', 'B');
    $cleanup = app(UnitCleanup::class);

    expect(fn () => $cleanup->merge($a, $a))->toThrow(ValidationException::class);

    $cleanup->retire($b);
    expect(fn () => $cleanup->merge($a, $b))->toThrow(ValidationException::class);

    // Neither refusal moved anything: `$a` is still live.
    expect(DB::table('catalog_units')->where('id', $a)->value('retired_at'))->toBeNull();
});

// ── 3. retire ────────────────────────────────────────────────────────────────────────────────

it('REFUSES to retire a unit that something still points at', function () {
    $unit = makeUnit('zz-used', 'مستخدمة', 'Used');
    specUsing($unit);

    // The refusal that makes "merge first" a rule rather than advice: retiring a used unit would
    // hide the row while the measurements still point at it.
    expect(fn () => app(UnitCleanup::class)->retire($unit))
        ->toThrow(ValidationException::class);

    expect(DB::table('catalog_units')->where('id', $unit)->value('retired_at'))->toBeNull();
});

it('retires an unused unit, hides it from the picker, and can put it back', function () {
    $unit = makeUnit('zz-unused', 'غير مستخدمة', 'Unused');
    $cleanup = app(UnitCleanup::class);

    $ids = array_column(SpecBlocks::options('units'), 'value');
    expect($ids)->toContain((string) $unit);

    $cleanup->retire($unit);

    // Out of the PICKER — which is the whole point; the row itself is untouched.
    $after = array_column(SpecBlocks::options('units'), 'value');
    expect($after)->not->toContain((string) $unit)
        ->and(DB::table('catalog_units')->where('id', $unit)->exists())->toBeTrue();

    $cleanup->restore($unit);
    expect(array_column(SpecBlocks::options('units'), 'value'))->toContain((string) $unit);
});

it('keeps rendering a retired unit on a product that already uses it', function () {
    // Hiding a row from the picker must never blank a measurement on a live page: the product
    // keeps its reference, and the lookup still resolves it.
    $unit = makeUnit('zz-live', 'حية', 'Live');
    $product = specUsing($unit);

    DB::table('catalog_units')->where('id', $unit)->update(['retired_at' => now()]);

    expect(T::int(DB::table('catalog_product_watch_specs')->where('product_id', $product)->value('case_size_unit_id')))
        ->toBe($unit);

    $row = DB::table('catalog_units')->where('id', $unit)->first(['code']);
    if (! is_object($row)) {
        throw new RuntimeException('the retired unit vanished');
    }
    expect(Row::str(Row::cast($row), 'code'))->toBe('zz-live');
});

// ── 4. the screen ────────────────────────────────────────────────────────────────────────────

it('is catalogue-staff work, and renders the units with their usage', function () {
    actingAs(Staff::dataEntry());
    $props = Props::of(get('/manage/units'));

    $units = $props['units'];
    expect($units)->toBeArray()->and($units)->not->toBe([]);

    // The work is sorted to the TOP: a size that is in use needs a merge and must not be on page
    // two of a table nobody scrolls.
    $first = T::arr(is_array($units) ? ($units[0] ?? null) : null);
    expect($first['looks_like_a_size'] ?? null)->toBeTrue()
        ->and(T::int($first['used'] ?? null))->toBeGreaterThan(0);
});

it('merges and retires through the screen, and says what happened', function () {
    actingAs(Staff::admin());

    $from = makeUnit('zz-screen-from', 'من', 'From');
    $into = makeUnit('zz-screen-into', 'إلى', 'Into');
    specUsing($from);

    post('/manage/units/merge', ['from' => $from, 'into' => $into])->assertSessionHasNoErrors();

    expect(DB::table('catalog_units')->where('id', $from)->value('retired_at'))->not->toBeNull();

    post("/manage/units/{$into}/retire")->assertSessionHasErrors();      // it now holds the spec
    post("/manage/units/{$from}/restore")->assertSessionHasNoErrors();

    expect(DB::table('catalog_units')->where('id', $from)->value('retired_at'))->toBeNull();
});
