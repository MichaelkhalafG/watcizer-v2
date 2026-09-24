<?php

use App\Domain\Catalog\PreSwitch;
use App\Domain\Import\CategoryMap;
use App\Domain\Import\ImportReport;
use App\Domain\Import\ProductImporter;
use App\Domain\Import\SourceRow;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * An unknown category FLAGS the row — it does not refuse it, and it never guesses a placement
 * (decision 2026-09-17, replacing the refusal of 2026-09-15).
 *
 * ── What the refusal got right, and what it got wrong ───────────────────────────────────────
 *
 * A source leaf that is not in `CategoryMap` has no honest placement. Two dishonest answers were on
 * the table when this was first decided: guess a placement — which puts a product somewhere nobody
 * chose and is invisible afterwards — or scope the transform's reconciliation around imported rows,
 * which is teaching the audit to look away. Both are still refused, and that part has not changed.
 *
 * What changed is the conclusion. Refusing the PLACEMENT is right; refusing the PRODUCT is not. The
 * title, price, stock, images and brand on such a row are all perfectly good, and the old rule threw
 * every one of them away because a single word in one column was unfamiliar. So the product now
 * lands UNPLACED and marked `needs_category`, with the offending leaf named in the durable report,
 * and somebody assigns it from the dashboard in seconds instead of re-running a file of thousands.
 *
 * ── What this costs on today's data: nothing ────────────────────────────────────────────────
 *
 * Measured over the real export on 2026-09-17: 8 614 rows, 60 distinct category leaves, **all 60
 * mapped** — and no leaf in the map that the file does not use. So this path is a guard against the
 * NEXT file, which is exactly when a silent mis-placement would be hardest to notice, and it is why
 * the cases below fabricate a row rather than hunting for one.
 */

/**
 * Run one import inside the same scoped exemption the import command uses.
 *
 * `PreSwitch::assertMayCreate('product')` is the FIRST statement of `import()` — deliberately, so a
 * shut gate stops a run on its first row instead of writing thousands of identical refusals. These
 * cases are about what happens AFTER it, so they open it exactly as `--allow-preswitch` does and
 * let `allowing()` restore it in its `finally`.
 */
function importUnderExemption(SourceRow $row, ImportReport $report): ?int
{
    $result = PreSwitch::allowing(
        ['product', 'variant', 'category', 'lookup', PreSwitch::SECONDARY_TREE_EDIT],
        fn (): ?int => app(ProductImporter::class)->import($row, [2], $report, false, false),
    );

    return is_int($result) ? $result : null;
}

/** A row with one leaf the map does not know. */
function rowWithUnknownLeaf(string $leaf = 'Totally Invented Section'): SourceRow
{
    return new SourceRow(
        ref: 'probe:unmapped:'.md5($leaf),
        titleEn: 'A product in a section we have never heard of',
        sku: null,
        modelNumber: null,
        description: null,
        sellingPrice: 100.0,
        salePrice: null,
        purchasePrice: 0.0,
        stock: 1,
        categoryPaths: [],
        genders: [],
        materials: [],
        family: 'fashion',
        imageUrl: null,
        isVisible: true,
        unmappedLeaves: [$leaf],
    );
}

it('IMPORTS a row whose category is not in the map, rather than throwing the product away', function () {
    $report = new ImportReport;
    $before = T::int(DB::table('catalog_products')->count());

    $productId = importUnderExemption(rowWithUnknownLeaf(), $report);

    expect($productId)->toBeInt()
        ->and($report->get('needs_category'))->toBe(1)
        ->and(T::int(DB::table('catalog_products')->count()))->toBe($before + 1);
});

it('places it NOWHERE — the guarantee the whole rule exists for', function () {
    /*
     * THE case. A guessed placement is invisible after the fact: the product sits in a real section,
     * looks deliberate, and nothing anywhere records that a machine chose it. So the assertion is not
     * "it was placed correctly" but "it was not placed at all".
     */
    $report = new ImportReport;
    $productId = importUnderExemption(rowWithUnknownLeaf('An Aisle That Does Not Exist'), $report);

    expect($productId)->toBeInt();

    $placements = T::int(DB::table('storefront_category_product')->where('product_id', $productId)->count());
    expect($placements)->toBe(0, 'an unmapped leaf must never produce a placement');

    /*
     * …and it is not on sale while it is unplaced. `PlacementWriter::save()` refuses to make a
     * product visible without a placement, and that refusal is what keeps a half-known product away
     * from a customer until a person has finished it.
     */
    $visible = DB::table('storefront_product')->where('product_id', $productId)->pluck('is_visible');
    foreach ($visible as $flag) {
        expect((bool) $flag)->toBeFalse('an unplaced product must not be visible');
    }
});

it('names the leaf in the DURABLE report, not just in a counter', function () {
    /*
     * A count tells the operator that something needs attention; only the leaf tells them what to do
     * about it. The row lands in `report.csv`, which survives the rebuild.
     */
    $report = new ImportReport;
    importUnderExemption(rowWithUnknownLeaf('Nonexistent Aisle'), $report);

    $flagged = $report->flagged();
    expect($flagged)->not->toBe([]);

    $detail = '';
    foreach ($flagged as $row) {
        $detail .= T::str(json_encode($row));
    }

    expect($detail)->toContain('Nonexistent Aisle')
        // Both routes out are named, because which one is right depends on whether the word is a
        // section we want or a one-off in their data.
        ->and($detail)->toContain('CategoryMap')
        ->and($detail)->toContain('dashboard');
});

it('marks it needs_category, which is NOT the same marker as an empty category', function () {
    /*
     * `category` means the source said nothing usable; `needs_category` means the source said a real
     * word the map has not been asked about. The actions differ — decide what it is, versus map a
     * word we already have — so collapsing them would hide the second inside the first.
     */
    expect(ImportReport::NEEDS_CATEGORY)->not->toBe(ImportReport::MISSING_CATEGORY);

    $report = new ImportReport;
    importUnderExemption(rowWithUnknownLeaf('Yet Another Unknown'), $report);

    $detail = '';
    foreach ($report->flagged() as $row) {
        $detail .= T::str(json_encode($row));
    }

    expect($detail)->toContain(ImportReport::NEEDS_CATEGORY);
});

it('does NOT flag a leaf the map deliberately answers with none or gender', function () {
    /*
     * The distinction the whole change turns on. `Uncategorized` and the three genders ARE in the
     * map — they are decisions already taken, and they must keep importing unflagged by this rule.
     */
    foreach (['Uncategorized', 'Men', 'Women', 'Unisex'] as $leaf) {
        $mapped = CategoryMap::leaf($leaf);
        expect($mapped)->not->toBeNull("[{$leaf}] should be in the map");
        expect(in_array(T::str(T::arr($mapped)['kind'] ?? null), [CategoryMap::NONE, CategoryMap::GENDER], true))
            ->toBeTrue("[{$leaf}] should be a deliberate none/gender answer");
    }

    // …and the reader therefore reports no unmapped leaf for them.
    expect(CategoryMap::leaf('Totally Invented Section'))->toBeNull();
});

it('leaves a row with a KNOWN category alone — the flag is not a blanket', function () {
    $report = new ImportReport;

    $known = new SourceRow(
        ref: 'probe:mapped:'.bin2hex(random_bytes(4)),
        titleEn: 'A product in a section we do know',
        sku: null,
        modelNumber: null,
        description: null,
        sellingPrice: 100.0,
        salePrice: null,
        purchasePrice: 0.0,
        stock: 1,
        categoryPaths: [],
        genders: [],
        materials: [],
        family: 'fashion',
        imageUrl: null,
        isVisible: true,
        unmappedLeaves: [],
    );

    importUnderExemption($known, $report);

    expect($report->get('needs_category'))->toBe(0);
});
