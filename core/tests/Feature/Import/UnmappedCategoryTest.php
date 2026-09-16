<?php

use App\Domain\Catalog\PreSwitch;
use App\Domain\Import\CategoryMap;
use App\Domain\Import\ImportReport;
use App\Domain\Import\ProductImporter;
use App\Domain\Import\SourceRow;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * An unknown category REFUSES the row (decision 2026-09-15, option b).
 *
 * ── The two alternatives, and why both were worse ───────────────────────────────────────────
 *
 * A source leaf that is not in `CategoryMap` has no honest placement. The options were to guess one
 * — which puts a product somewhere nobody chose and is invisible afterwards — or to scope the
 * transform's reconciliation around imported rows, which is teaching the audit to look away. The
 * row is refused instead, and named in the durable report with the leaf that caused it.
 *
 * ── What this costs on today's data: nothing ────────────────────────────────────────────────
 *
 * Measured over the real export: 7 308 rows in scope, **0** with an unmapped leaf. The map already
 * covers every category the file uses. So this is a guard against the NEXT file, which is exactly
 * when a silent mis-placement would have been hardest to notice — and it is why the test below
 * fabricates a row rather than hunting for one.
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

it('REFUSES a row whose category is not in the map, and writes nothing', function () {
    $report = new ImportReport;
    $before = T::int(DB::table('catalog_products')->count());

    $result = importUnderExemption(rowWithUnknownLeaf(), $report);

    expect($result)->toBeNull()
        ->and($report->get('refused_unmapped_category'))->toBe(1)
        // …and nothing was created on the way to refusing.
        ->and(T::int(DB::table('catalog_products')->count()))->toBe($before);
});

it('names the leaf in the DURABLE report, not just in a counter', function () {
    /*
     * A count tells the operator that something was refused; only the leaf tells them what to do
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
        ->and($detail)->toContain('CategoryMap');
});

it('does NOT refuse a leaf the map deliberately answers with none or gender', function () {
    /*
     * The distinction the whole change turns on. `Uncategorized` and the three genders ARE in the
     * map — they are decisions already taken, and they must keep importing. Refusing them would
     * reject thousands of rows over a rule meant for unknown ones.
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

it('leaves a row with a KNOWN category alone — the refusal is not a blanket', function () {
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

    expect($report->get('refused_unmapped_category'))->toBe(0);
});
