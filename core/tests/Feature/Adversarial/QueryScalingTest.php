<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\AdvPayload;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * PORTED 2026-09-11 from the wave-4B adversarial review (axis 7 — "does any screen's cost grow
 * with its content?"). The reviewer's own axis-7 probes were GREEN; the two category probes beside
 * them were not, and that is where review 🟠-2 came from (the categories screen spent two queries
 * per node deriving families). That guard now lives in `CategoryTreeScaleTest`; these are the
 * others, which cover the product FORM and the three LIST screens.
 *
 * The assertions are SLOPES, not budgets. A budget ("≤ 14 queries") goes stale the first time a
 * legitimate query is added; "a fat row costs no more queries than a lean one" is the property
 * that was actually at risk, and it cannot be satisfied by an N+1 hiding anywhere on the page.
 * (`QueryBudgetTest` holds the absolute numbers for the public API, where they are a contract.)
 */

/** Queries executed on the default connection while `$fn` runs. */
function scalingQueries(Closure $fn): int
{
    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();
    $fn();
    $n = count(DB::connection()->getQueryLog());
    DB::connection()->disableQueryLog();

    return $n;
}

it('renders the product form for a FAT row at the same cost as a lean one', function () {
    CatalogFixture::assumeSwitched();
    $admin = Staff::admin();
    $bags = CatalogFixture::child(CatalogFixture::fashionRoot(), 'Bags', 'حقائب');

    $extra = [];
    for ($i = 0; $i < 6; $i++) {
        $extra[] = CatalogFixture::child(CatalogFixture::fashionRoot(), "Extra{$i}", "فرع{$i}")['id'];
    }

    // Lean: one image, one category.
    actingAs($admin)->postJson('/manage/storefronts/1/products', AdvPayload::product($bags['id'], [
        'images' => [['path' => 'a.webp', 'is_cover' => true]],
    ], 'n1-lean'))->assertRedirect();
    $lean = AdvPayload::newestProductId();

    // Fat: 12 images, 7 categories, and every attribute pivot populated.
    $images = [];
    for ($i = 0; $i < 12; $i++) {
        $images[] = ['path' => "f{$i}.webp", 'is_cover' => $i === 0];
    }
    $ids = fn (string $table, int $limit): array => array_map(
        fn (mixed $v): int => T::int($v),
        DB::table($table)->orderBy('id')->limit($limit)->pluck('id')->all()
    );
    $colors = array_map(fn (int $id): array => ['color_id' => $id, 'role' => 'main'], $ids('catalog_colors', 6));

    actingAs($admin)->postJson('/manage/storefronts/1/products', AdvPayload::product($bags['id'], [
        'images' => $images,
        'feature_ids' => $ids('catalog_features', 5),
        'gender_ids' => $ids('catalog_genders', 3),
        'colors' => $colors,
        'category_ids' => array_merge([$bags['id']], $extra),
        'primary_category_id' => $bags['id'],
    ], 'n1-fat'))->assertRedirect();
    $fat = AdvPayload::newestProductId();

    // The fixtures must really differ, or the comparison is between two identical rows.
    expect(T::int(DB::table('catalog_product_images')->where('product_id', $fat)->count()))->toBe(12)
        ->and(AdvPayload::placements($fat))->toBe(7)
        ->and(T::int(DB::table('catalog_product_images')->where('product_id', $lean)->count()))->toBe(1);

    $qLean = scalingQueries(fn () => actingAs($admin)->get("/manage/storefronts/1/products/{$lean}/edit")->assertOk());
    $qFat = scalingQueries(fn () => actingAs($admin)->get("/manage/storefronts/1/products/{$fat}/edit")->assertOk());

    expect(abs($qFat - $qLean))->toBeLessThanOrEqual(2,
        "the product form's query count scales with the row: {$qLean} -> {$qFat}");
});

it('renders a 50-row page of every list without paying more queries than a 3-row page', function () {
    /*
     * Per-page extras are computed ONCE for the page, never per row (AGENTS §2.24). The way that
     * rule breaks is a helper called inside the row loop — which shows up here and nowhere else.
     *
     * The assertion is "no MORE", not "exactly equal". A bigger page legitimately costs FEWER
     * queries sometimes: some per-page extras are conditional (the missing-Arabic lookup only runs
     * when a row on the page needs it), so the count can move down as the page composition
     * changes. Going DOWN is never the bug this guards against; going up with the row count is.
     */
    $admin = Staff::admin();

    foreach ([
        'products' => '/manage/storefronts/1/products',
        'placement' => '/manage/storefronts/1/placement',
    ] as $name => $url) {
        $smallResponse = null;
        $largeResponse = null;
        $small = scalingQueries(function () use ($admin, $url, &$smallResponse): void {
            $smallResponse = actingAs($admin)->get($url.'?per_page=3');
            $smallResponse->assertOk();
        });
        $large = scalingQueries(function () use ($admin, $url, &$largeResponse): void {
            $largeResponse = actingAs($admin)->get($url.'?per_page=50');
            $largeResponse->assertOk();
        });

        // The page really has to be bigger, or "same cost" is a statement about two 3-row pages.
        $smallRows = $smallResponse === null ? 0 : count(Props::rows(Props::table($smallResponse)));
        $largeRows = $largeResponse === null ? 0 : count(Props::rows(Props::table($largeResponse)));

        expect($largeRows)->toBeGreaterThan($smallRows, "the {$name} list ignored per_page, so this proved nothing")
            ->and($large)->toBeLessThanOrEqual($small,
                "the {$name} list issues more queries for a bigger page ({$small} -> {$large})");
    }

    // The categories screen ships the WHOLE tree rather than a page, so its guard is the slope
    // over node count instead — `CategoryTreeScaleTest` (review 🟠-2).
    $tree = scalingQueries(fn () => actingAs($admin)->get('/manage/storefronts/1/categories?per_page=50')->assertOk());
    expect($tree)->toBeGreaterThan(0);
});
