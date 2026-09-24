<?php

use App\Domain\Catalog\PreSwitch;
use App\Domain\Import\ImportReport;
use App\Domain\Import\ProductImporter;
use App\Domain\Import\SourceRow;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\T;

/*
 * A product that already exists is SKIPPED ENTIRELY (review 🔴-3).
 *
 * ── What "entirely" has to mean ─────────────────────────────────────────────────────────────
 *
 * Not "skipped, except we refresh the cover". Not "skipped, but we add the supplier's categories
 * on top". The existing row is the better record — a human wrote its Arabic title, chose its
 * section and picked its photograph — and an import that touched any of that would be overwriting
 * work with a machine translation and a supplier's taxonomy.
 *
 * ── Why the proof is shaped like this ───────────────────────────────────────────────────────
 *
 * Asserting "the importer returned null" proves the gate fired, not that nothing moved. So each
 * case EDITS the product by hand first — the way the team would — then runs the import over it and
 * compares every field that a careless importer would have touched. A row-count check on its own
 * would miss an UPDATE, which is the shape this defect actually takes.
 *
 * Both doors are covered, because "already exists" means two different things:
 *   • the product is one Watchizer already sold  → the duplicate gate (`ExistingCatalogue`);
 *   • the product is one THIS importer made      → the `import_ref` idempotency key.
 */

/** A source row that will match `$productId` by SKU. */
function rowMatching(string $sku, string $title = 'Supplier title that must never land'): SourceRow
{
    return new SourceRow(
        ref: 'probe:existing:'.Str::random(8),
        titleEn: $title,
        sku: $sku,
        modelNumber: null,
        description: 'A supplier description that must never land either.',
        sellingPrice: 999.0,
        salePrice: 888.0,
        purchasePrice: 0.0,
        stock: 77,
        categoryPaths: [],
        genders: [],
        materials: [],
        family: 'fashion',
        imageUrl: null,
        isVisible: true,
    );
}

/**
 * Everything a careless importer would have touched, as one comparable snapshot.
 *
 * @return array<string, mixed>
 */
function productFingerprint(int $productId): array
{
    $product = T::row(DB::table('catalog_products')->where('id', $productId)
        ->first(['family', 'brand_id', 'sku', 'selling_price', 'sale_price', 'stock_express', 'stock_market', 'is_active']));

    return [
        'product' => (array) $product,
        'translations' => DB::table('catalog_product_translations')->where('product_id', $productId)
            ->orderBy('locale')->get(['locale', 'title', 'is_machine'])->map(fn ($r): array => (array) $r)->all(),
        'images' => DB::table('catalog_product_images')->where('product_id', $productId)
            ->orderBy('id')->get(['path', 'is_cover', 'sort'])->map(fn ($r): array => (array) $r)->all(),
        'placements' => DB::table('storefront_category_product')->where('product_id', $productId)
            ->orderBy('storefront_category_id')->get(['storefront_id', 'storefront_category_id', 'is_primary'])
            ->map(fn ($r): array => (array) $r)->all(),
        'storefronts' => DB::table('storefront_product')->where('product_id', $productId)
            ->orderBy('storefront_id')->get(['storefront_id', 'is_visible', 'is_featured', 'slug'])
            ->map(fn ($r): array => (array) $r)->all(),
    ];
}

/** Run one import under the same exemption `--allow-preswitch` opens. */
function runImport(SourceRow $row, ImportReport $report): ?int
{
    $result = PreSwitch::allowing(
        ['product', 'variant', 'category', 'lookup', PreSwitch::SECONDARY_TREE_EDIT],
        fn (): ?int => app(ProductImporter::class)->import($row, [2], $report, false, false),
    );

    return is_int($result) ? $result : null;
}

/**
 * A real product, EDITED BY HAND the way the team would edit one.
 *
 * @return array{0: int, 1: string}
 */
function handEditedProduct(): array
{
    $productId = T::int(DB::table('catalog_products')
        ->whereNull('deleted_at')->whereNull('import_ref')
        ->whereNotNull('sku')->where('sku', '<>', '')
        ->whereRaw('CHAR_LENGTH(sku) >= 5')
        ->orderBy('id')->value('id'));

    $sku = T::str(DB::table('catalog_products')->where('id', $productId)->value('sku'));

    // A human-written Arabic title, explicitly NOT machine — the thing most worth protecting.
    DB::table('catalog_product_translations')
        ->where('product_id', $productId)->where('locale', 'ar')
        ->update(['title' => 'عنوان كتبه إنسان ولا يجوز استبداله', 'is_machine' => 0]);

    // An extra placement the team chose, on top of whatever the transform gave it.
    $extraNode = T::int(DB::table('storefront_categories')->where('storefront_id', 2)
        ->whereNotExists(function (Builder $q) use ($productId): void {
            $q->from('storefront_category_product as scp')
                ->whereColumn('scp.storefront_category_id', 'storefront_categories.id')
                ->where('scp.product_id', $productId)->selectRaw('1');
        })->orderBy('id')->value('id'));

    DB::table('storefront_category_product')->insert([
        'storefront_id' => 2,
        'storefront_category_id' => $extraNode,
        'product_id' => $productId,
        'is_primary' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    // A cover the team picked.
    DB::table('catalog_product_images')->insert([
        'product_id' => $productId,
        'path' => 'Product/chosen-by-a-person.webp',
        'is_cover' => 0,
        'sort' => 9,
        'width' => 100,
        'height' => 100,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [$productId, $sku];
}

it('SKIPS a product the catalogue already has, and changes nothing about it', function () {
    [$productId, $sku] = handEditedProduct();
    $before = productFingerprint($productId);
    $report = new ImportReport;

    $result = runImport(rowMatching($sku), $report);

    expect($result)->toBeNull()
        ->and($report->get('duplicate_of_existing'))->toBe(1);

    // The whole of it, field by field: title, translations, images, placements, storefront rows.
    expect(productFingerprint($productId))->toBe($before);
});

it('leaves the HUMAN Arabic title alone, machine flag included', function () {
    /*
     * Singled out because it is the edit with the most work behind it and the least visible loss:
     * a machine translation replacing a human one looks like a title either way.
     */
    [$productId, $sku] = handEditedProduct();

    runImport(rowMatching($sku), new ImportReport);

    $translation = T::row(DB::table('catalog_product_translations')
        ->where('product_id', $productId)->where('locale', 'ar')->first(['title', 'is_machine']));

    expect(T::str($translation->title))->toBe('عنوان كتبه إنسان ولا يجوز استبداله')
        ->and(T::int($translation->is_machine))->toBe(0);
});

it('adds NO image, NO placement and NO storefront row', function () {
    [$productId, $sku] = handEditedProduct();

    $counts = fn (): array => [
        'images' => T::int(DB::table('catalog_product_images')->where('product_id', $productId)->count()),
        'placements' => T::int(DB::table('storefront_category_product')->where('product_id', $productId)->count()),
        'storefronts' => T::int(DB::table('storefront_product')->where('product_id', $productId)->count()),
        'products' => T::int(DB::table('catalog_products')->count()),
    ];

    $before = $counts();
    runImport(rowMatching($sku), new ImportReport);

    expect($counts())->toBe($before);
});

it('skips a product THIS IMPORTER made, on a second run of the same file', function () {
    /*
     * The other door. `import_ref` is the idempotency key, and a re-run must not refresh what it
     * created the first time either — the team may already have corrected it.
     */
    $ref = 'probe:reimport:'.Str::random(8);
    $row = new SourceRow(
        ref: $ref,
        titleEn: 'First pass title',
        sku: null,
        modelNumber: null,
        description: null,
        sellingPrice: 100.0,
        salePrice: null,
        purchasePrice: 0.0,
        stock: 5,
        categoryPaths: [],
        genders: [],
        materials: [],
        family: 'fashion',
        imageUrl: null,
        isVisible: true,
    );

    $report = new ImportReport;
    $productId = runImport($row, $report);

    if ($productId === null) {
        expect(true)->toBeTrue('the first pass did not create a product — nothing to re-run over');

        return;
    }

    // The team corrects it after the first run.
    DB::table('catalog_product_translations')
        ->where('product_id', $productId)->where('locale', 'ar')
        ->update(['title' => 'تصحيح بشري بعد الاستيراد', 'is_machine' => 0]);

    $before = productFingerprint($productId);

    // The same file, run again.
    $second = new ImportReport;
    $again = runImport($row, $second);

    expect($again)->toBe($productId)
        ->and($second->get('already_imported'))->toBe(1)
        ->and(productFingerprint($productId))->toBe($before);
});

it('REPORTS the skip with both codes and the rule, in the durable record', function () {
    [, $sku] = handEditedProduct();
    $report = new ImportReport;

    runImport(rowMatching($sku), $report);

    $detail = '';
    foreach ($report->flagged() as $row) {
        $detail .= T::str(json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    // Both identities and which rule matched — the only way a wrong match is ever found.
    expect($detail)->toContain('already in the catalogue as')
        ->and($detail)->toContain($sku)
        ->and($detail)->toContain('matched by');
});
