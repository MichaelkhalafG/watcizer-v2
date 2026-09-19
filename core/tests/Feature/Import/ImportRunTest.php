<?php

use App\Domain\Catalog\PreSwitch;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Import\CategoryMerger;
use App\Domain\Import\ImportReport;
use App\Domain\Import\JoyroomSheet;
use App\Domain\Import\ProductImporter;
use App\Domain\Import\WooExport;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Support\DeadlockRetry;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\Gates;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;
use Tests\Support\WooFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * The import END TO END (wave 4D): the exemption, the doors, the markers, the badge.
 *
 * Images are OFF in every test here. A test that reaches brandfashionegy.com is a test that fails
 * when somebody else's web server is slow, and what these tests are about is what the importer
 * WRITES — the fetch itself is proved by the real run, which is the only honest proof of a network
 * call anyway.
 */

/**
 * Import a fixture file and hand back the report.
 *
 * @param  list<int>  $storefronts
 */
function importRows(string $rows, array $storefronts = [2], bool $exempt = true): ImportReport
{
    $path = WooFixture::file($rows);
    $report = new ImportReport;

    $importer = app(ProductImporter::class);
    $work = function () use ($path, $importer, $storefronts, $report): void {
        $importer->ensureMaterials($report);
        foreach ((new WooExport($path))->rows() as $row) {
            /*
             * Retried exactly as the command retries, and for the same reason: since `--shard`
             * exists, several importers really do write this catalogue at once — including while
             * the suite runs — and a deadlock is contention, not a result. Without this the tests
             * failed with "nothing was imported" whenever a real import was in flight.
             */
            DeadlockRetry::run(fn () => $importer->import($row, $storefronts, $report, false));
        }
    };

    $exempt
        ? PreSwitch::allowing(['product', 'variant', 'category', 'lookup', PreSwitch::SECONDARY_TREE_EDIT], $work)
        : $work();

    unlink($path);

    return $report;
}

/** The imported product, read the strict way the application reads every row. */
function importedProduct(string $ref): stdClass
{
    $row = DB::table('catalog_products')->where('import_ref', $ref)->first();
    if (! is_object($row)) {
        throw new RuntimeException("nothing was imported for [{$ref}]");
    }

    return Row::cast($row);
}

/** One column of a row that may be null, without a chain of null checks in every test. */
function rowValue(mixed $row, string $column): mixed
{
    expect(is_object($row))->toBeTrue("expected a row carrying [{$column}]");

    return is_object($row) ? (Row::cast($row)->{$column} ?? null) : null;
}

// ── 1. the PreSwitch exemption ───────────────────────────────────────────────────────────────

it('REFUSES to create a product before the write-switch without the exemption', function () {
    /*
     * This test is ABOUT the refusal, so it asks for the mode that refuses (item 5, 2026-09-18).
     * The shipped default is `warn` — the gates carry their sentence as a caveat and the controls
     * work. `enforce` is still supported and still has to be proved. {@see Tests\Support\Gates}.
     */
    Gates::enforcePreSwitch();

    expect(PreSwitch::completed())->toBeFalse();

    // The default is a refusal, and it has to stay one: the whole importer is a door the developer
    // opened deliberately, for one command, in one run.
    expect(fn () => importRows(WooFixture::row([
        'ID' => 9900900, 'Type' => 'simple', 'Name' => 'Refused watch', 'Published' => '1',
        'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches',
    ]), [2], exempt: false))->toThrow(RuntimeException::class);

    expect(DB::table('catalog_products')->where('import_ref', 'woo:9900900')->exists())->toBeFalse();
});

it('CLOSES the exemption again — even when the work throws', function () {
    /*
     * This test is ABOUT the refusal, so it asks for the mode that refuses (item 5, 2026-09-18).
     * The shipped default is `warn` — the gates carry their sentence as a caveat and the controls
     * work. `enforce` is still supported and still has to be proved. {@see Tests\Support\Gates}.
     */
    Gates::enforcePreSwitch();

    expect(PreSwitch::allows('product'))->toBeFalse();

    PreSwitch::allowing(['product'], function (): void {
        expect(PreSwitch::allows('product'))->toBeTrue()
            ->and(PreSwitch::exempted())->toContain('product');
    });

    expect(PreSwitch::allows('product'))->toBeFalse();

    // The failure path is the one that matters: a run that dies half way must not leave the gate
    // open for whatever runs next in the same process.
    try {
        PreSwitch::allowing(['product'], function (): void {
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(PreSwitch::allows('product'))->toBeFalse()
        ->and(PreSwitch::exempted())->toBe([]);
});

it('names its exemptions one by one, never a blanket', function () {
    /*
     * This test is ABOUT the refusal, so it asks for the mode that refuses (item 5, 2026-09-18).
     * The shipped default is `warn` — the gates carry their sentence as a caveat and the controls
     * work. `enforce` is still supported and still has to be proved. {@see Tests\Support\Gates}.
     */
    Gates::enforcePreSwitch();

    PreSwitch::allowing(['product'], function (): void {
        // Asking for products does not also open categories or lookups.
        expect(PreSwitch::allows('product'))->toBeTrue()
            ->and(PreSwitch::allows('category'))->toBeFalse()
            ->and(PreSwitch::allows('lookup'))->toBeFalse()
            ->and(PreSwitch::mayEditTree(2))->toBeFalse();
    });

    // And an exemption for a creation that does not exist is a typo, not a silent no-op.
    expect(fn () => PreSwitch::allowing(['prodcut'], fn () => null))
        ->toThrow(RuntimeException::class, 'no entry for the creation');
});

// ── 2. what actually lands ───────────────────────────────────────────────────────────────────

it('imports a product through the writers, with its Arabic machine-translated and MARKED', function () {
    $report = importRows(WooFixture::row([
        'ID' => 9900901, 'Type' => 'simple', 'SKU' => 'TH-901', 'Name' => 'Tommy Hilfiger Watch For Men 1791594',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '3850', 'Sale price' => '3090',
        'Stock' => '4', 'Categories' => 'Men,Men > Men Watches,Watches > Quartz,Watches',
    ]));

    $product = importedProduct('woo:9900901');

    expect(Row::nstr($product, 'sku'))->toBe('TH-901')
        // `wa_code` is NOT NULL and UNIQUE and the file has no equivalent, so the import mints one
        // that is stable and obviously not a legacy code.
        ->and(Row::str($product, 'wa_code'))->toBe('BF-9900901')
        ->and((float) Row::str($product, 'selling_price'))->toBe(3850.0)
        ->and((float) Row::nstr($product, 'sale_price'))->toBe(3090.0)
        ->and(Row::str($product, 'family'))->toBe('watch');

    // The brand came out of the TITLE, because their Brands column is empty in every row.
    $brand = T::int(DB::table('catalog_brands')->where('slug', 'tommy-hilfiger')->value('id'));
    expect(T::int(Row::int($product, 'brand_id')))->toBe($brand);

    $arabic = DB::table('catalog_product_translations')
        ->where('product_id', Row::int($product, 'id'))->where('locale', 'ar')->first(['title', 'is_machine']);

    expect($arabic)->not->toBeNull()
        ->and(rowValue($arabic, 'title'))->toContain('ساعة')
        ->and(rowValue($arabic, 'title'))->toContain('رجالي')
        // THE badge. A machine-written title must never look like a human-written one.
        ->and(T::int(rowValue($arabic, 'is_machine')))->toBe(1);

    // Stock arrived as a MOVEMENT, never as a column.
    expect(T::int(Row::int($product, 'stock_express')))->toBe(4);
    expect(DB::table('inventory_movements')
        ->where('product_id', Row::int($product, 'id'))->where('reason', 'import')->exists())->toBeTrue();

    expect($report->get('imported'))->toBe(1);
});

it('creates the mapped category nodes ONCE and places the product in the deepest one', function () {
    $before = T::int(DB::table('storefront_categories')->where('storefront_id', 2)->count());

    importRows(implode("\n", [
        WooFixture::row(['ID' => 9900910, 'Type' => 'simple', 'Name' => 'Bag one', 'Published' => '1', 'Visibility in catalog' => 'visible',
            'Regular price' => '500', 'Categories' => 'Women,Women > Women Bags,Crossbody Bag']),
        WooFixture::row(['ID' => 9900911, 'Type' => 'simple', 'Name' => 'Bag two', 'Published' => '1', 'Visibility in catalog' => 'visible',
            'Regular price' => '600', 'Categories' => 'Women,Women > Women Bags,Crossbody Bag']),
    ]));

    /*
     * `fashion/bags` already exists, `crossbody-bag` is mapped, and the node exists afterwards
     * either way. What is asserted is the property that matters and holds on ANY catalogue: the
     * two products together add AT MOST one node, and never one each. (The delta is 1 on a fresh
     * catalogue and 0 when a previous import already created it — both are correct, and asserting
     * the 1 would have made this test depend on what ran before it.)
     */
    $after = T::int(DB::table('storefront_categories')->where('storefront_id', 2)->count());
    expect($after - $before)->toBeLessThanOrEqual(1);

    $node = CategoryMerger::find(2, 'fashion/bags/crossbody-bag');
    expect($node)->not->toBeNull();

    $product = importedProduct('woo:9900910');
    $primary = DB::table('storefront_category_product')
        ->where('storefront_id', 2)->where('product_id', Row::int($product, 'id'))->where('is_primary', 1)
        ->value('storefront_category_id');

    // The DEEPEST node is the primary: a breadcrumb saying "Fashion → Bags → Crossbody" beats one
    // saying "Fashion".
    expect(T::int($primary))->toBe($node);
});

it('never lets an incomplete product face a customer', function () {
    // No image and no category: two of the three gates `PlacementWriter` gu ards.
    importRows(WooFixture::row(['ID' => 9900920, 'Type' => 'simple', 'Name' => 'Nowhere watch', 'Published' => '1',
        'Visibility in catalog' => 'visible', 'Regular price' => '900', 'Categories' => 'Uncategorized']));

    $product = importedProduct('woo:9900920');
    $placement = DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', Row::int($product, 'id'))->first();

    expect($placement)->not->toBeNull()
        // Imported, present, and HIDDEN — the import cannot publish something half-made.
        ->and(T::int(rowValue($placement, 'is_visible')))->toBe(0);
});

it('imports the FIRST duplicate SKU and reports the rest instead of dropping them', function () {
    $report = importRows(implode("\n", [
        WooFixture::row(['ID' => 9900930, 'Type' => 'simple', 'SKU' => 'DZ7314', 'Name' => 'Diesel watch one', 'Published' => '1',
            'Visibility in catalog' => 'visible', 'Regular price' => '7500', 'Categories' => 'Watches']),
        WooFixture::row(['ID' => 9900931, 'Type' => 'simple', 'SKU' => 'DZ7314', 'Name' => 'Diesel watch two', 'Published' => '1',
            'Visibility in catalog' => 'visible', 'Regular price' => '7500', 'Categories' => 'Watches']),
    ]));

    // 73 SKUs in the real file cover 148 rows, and `catalog_products.sku` is UNIQUE. Both rows are
    // imported; the second simply has no SKU, and says so.
    expect(importedProduct('woo:9900930')->sku)->toBe('DZ7314')
        ->and(importedProduct('woo:9900931')->sku)->toBeNull();

    $kinds = array_column($report->flagged(), 'kind');
    expect($kinds)->toContain('duplicate_sku');
});

it('gives a nameless brand the Generic brand and MARKS it, never refusing the row', function () {
    $report = importRows(WooFixture::row(['ID' => 9900940, 'Type' => 'simple', 'Name' => 'Mini Focus Watch For Men MF0475',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '800', 'Categories' => 'Watches']));

    $product = importedProduct('woo:9900940');
    $generic = T::int(DB::table('catalog_brands')->where('slug', 'generic')->value('id'));

    expect(T::int(Row::int($product, 'brand_id')))->toBe($generic);

    $detail = '';
    foreach ($report->flagged() as $row) {
        if ($row['ref'] === 'woo:9900940' && $row['kind'] === 'missing_data') {
            $detail = $row['detail'];
        }
    }
    expect($detail)->toContain(ImportReport::MISSING_BRAND);
});

it('is idempotent: the same file twice imports nothing the second time', function () {
    $row = WooFixture::row(['ID' => 9900950, 'Type' => 'simple', 'SKU' => 'IDEM-1', 'Name' => 'Idempotent watch',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches']);

    $first = importRows($row);
    $countAfterFirst = T::int(DB::table('catalog_products')->count());

    $second = importRows($row);

    expect($first->get('imported'))->toBe(1)
        ->and($second->get('imported'))->toBe(0)
        ->and($second->get('already_imported'))->toBe(1)
        ->and(T::int(DB::table('catalog_products')->count()))->toBe($countAfterFirst);
});

it('folds shoe sizes into variants and creates the sizes it does not have', function () {
    $report = importRows(implode("\n", [
        WooFixture::row(['ID' => 9900960, 'Type' => 'variable', 'SKU' => 'THS960', 'Name' => 'Tommy Hilfiger Leather Shoes',
            'Published' => '1', 'Visibility in catalog' => 'visible', 'Categories' => 'Men,Men > Men Shoes']),
        WooFixture::row(['ID' => 9900961, 'Type' => 'variation', 'Name' => 'Shoes - 41', 'Published' => '1', 'Visibility in catalog' => 'visible',
            'Regular price' => '3000', 'Parent' => 'THS960', 'Attribute 1 name' => 'Shoes Size', 'Attribute 1 value(s)' => '41']),
        WooFixture::row(['ID' => 9900962, 'Type' => 'variation', 'Name' => 'Shoes - 47', 'Published' => '1', 'Visibility in catalog' => 'visible',
            'Regular price' => '3000', 'Parent' => 'THS960', 'Attribute 1 name' => 'Shoes Size', 'Attribute 1 value(s)' => '47']),
    ]));

    $product = importedProduct('woo:9900960');
    $variants = DB::table('catalog_product_variants')->where('product_id', Row::int($product, 'id'))->orderBy('id')->get(['label', 'size_id']);

    expect($variants)->toHaveCount(2)
        ->and(rowValue($variants[0], 'label'))->toBe('41');

    // Our lookup has 40–45; the file goes to 47. The missing size is created rather than dropped.
    expect($report->get('sizes_created'))->toBeGreaterThan(0);
    expect(T::int(rowValue($variants[1], 'size_id')))->toBeGreaterThan(0);
});

// ── 3. the dashboard, which is where the markers have to be usable ───────────────────────────

it('shows the missing-data marker and the machine badge, and BOTH filters change the rows', function () {
    importRows(implode("\n", [
        // Whole enough to carry no marker but the image (images are off in tests).
        WooFixture::row(['ID' => 9900970, 'Type' => 'simple', 'SKU' => 'OK-970', 'Name' => 'Tommy Hilfiger Watch For Men 970',
            'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches']),
        // Missing almost everything.
        WooFixture::row(['ID' => 9900971, 'Type' => 'simple', 'Name' => 'Mini Focus Watch 971', 'Published' => '1',
            'Visibility in catalog' => 'visible', 'Regular price' => '0', 'Categories' => 'Uncategorized']),
    ]));

    actingAs(Staff::admin());

    $allTable = Props::table(get('/manage/storefronts/2/products?per_page=100'));
    $missingTable = Props::table(get('/manage/storefronts/2/products?per_page=100&filters[flag]=missing_data'));
    $machineTable = Props::table(get('/manage/storefronts/2/products?per_page=100&filters[flag]=machine_ar'));

    /*
     * §4 law: each filter must CHANGE the set. Compared on the TOTAL, not on the page — a
     * catalogue with more rows than one page fills every page to 100 and the page size stops
     * measuring anything at all. (It did: this assertion first ran against a page of 100 out of
     * 1 600 imported products and compared 100 with 100.)
     */
    $total = static fn (array $table): int => T::int(T::arr($table['meta'] ?? null)['total'] ?? null);

    expect($total($missingTable))->toBeLessThan($total($allTable))
        ->and($total($machineTable))->toBeLessThan($total($allTable))
        ->and($total($machineTable))->toBeGreaterThan(0);

    $all = Props::rows($allTable);
    $missing = Props::rows($missingTable);

    // The badge and the filter must agree about what "not finished" means.
    foreach ($missing as $row) {
        expect($row['missing'])->not->toBe([]);
    }

    // Searched for by code rather than scanned out of page one: with a real import in the
    // catalogue the subject is not on the first page, and a test that only passes on a small
    // database is a test that will fail the week the shop grows.
    $byCode = [];
    foreach ([$all, Props::rows(Props::table(get('/manage/storefronts/2/products?q=BF-9900971')))] as $page) {
        foreach ($page as $row) {
            $byCode[T::str($row['wa_code'] ?? null)] = $row;
        }
    }

    expect($byCode['BF-9900971']['missing'])->toContain('sku')
        ->and($byCode['BF-9900971']['missing'])->toContain('category')
        ->and($byCode['BF-9900971']['missing'])->toContain('brand')
        ->and($byCode['BF-9900971']['missing'])->toContain('price')
        ->and($byCode['BF-9900971']['machine_ar'])->toBeTrue();
});

it('clears the machine badge the moment a human edits that translation', function () {
    importRows(WooFixture::row(['ID' => 9900980, 'Type' => 'simple', 'SKU' => 'ED-980', 'Name' => 'Tommy Hilfiger Watch For Men 980',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches']));

    $product = importedProduct('woo:9900980');
    expect(T::int(DB::table('catalog_product_translations')
        ->where('product_id', Row::int($product, 'id'))->where('locale', 'ar')->value('is_machine')))->toBe(1);

    // The developer's requirement, and the reason the flag lives on the translation row: it
    // disappears because the EDIT cleared it, not because a screen remembered to send something.
    app(ProductWriter::class)->update(Row::int($product, 'id'), [
        'brand_id' => Row::int($product, 'brand_id'),
        'wa_code' => Row::str($product, 'wa_code'),
        'sku' => Row::nstr($product, 'sku'),
        'selling_price' => Row::str($product, 'selling_price'),
        'currency' => 'EGP',
        'is_active' => true,
        'family' => Row::str($product, 'family'),
        'title' => ['ar' => 'ساعة تومي هيلفيغر رجالي — كتبها إنسان', 'en' => 'Tommy Hilfiger Watch For Men 980'],
    ], null);

    expect(T::int(DB::table('catalog_product_translations')
        ->where('product_id', Row::int($product, 'id'))->where('locale', 'ar')->value('is_machine')))->toBe(0);
});

// ── 4. the two things the REAL runs taught (2026-09-14) ──────────────────────────────────────

it('believes a source that names its own brand, instead of reading the title', function () {
    // The Joyroom price list is one supplier's catalogue and its titles are descriptions, so the
    // first real run filed all 86 products under `Generic` with brands like "20W Magnetic" and
    // "Screen Protector". A source that KNOWS its brand is believed.
    $path = tempnam(sys_get_temp_dir(), 'joy').'.csv';
    file_put_contents($path, implode("\n", [
        'item,model,color,specification,description,rdp,rrp,page,image',
        '"20W Magnetic Wireless Power Bank",JR-TEST01,Black,,,100.00,200.00,1,',
    ])."\n");

    $report = new ImportReport;
    $importer = app(ProductImporter::class);
    PreSwitch::allowing(['product', 'variant', 'category', 'lookup', PreSwitch::SECONDARY_TREE_EDIT],
        function () use ($path, $importer, $report): void {
            foreach ((new JoyroomSheet($path))->rows() as $row) {
                $importer->import($row, [1, 2], $report, false);
            }
        });
    unlink($path);

    $product = importedProduct('joyroom:JR-TEST01');
    $brandName = DB::table('catalog_brand_translations')
        ->where('brand_id', Row::int($product, 'brand_id'))->where('locale', 'en')->value('name');

    expect($brandName)->toBe('Joyroom');

    // …and nothing is marked for review, because the answer was not a guess.
    foreach ($report->flagged() as $row) {
        if ($row['ref'] === 'joyroom:JR-TEST01' && $row['kind'] === 'missing_data') {
            expect($row['detail'])->not->toContain(ImportReport::MISSING_BRAND);
        }
    }
});

it('leaves NOTHING behind when a row fails half way, so a re-run can finish it', function () {
    // A row that fails after `import_ref` is written would otherwise stay in the catalogue as a
    // half product AND be skipped as "already imported" for ever. Measured on `joyroom:JR-HG2`,
    // which lost a lock race with a parallel worker: two translations, one image, no placement.
    importRows(WooFixture::row([
        'ID' => 9900990, 'Type' => 'simple', 'SKU' => 'DISCARD-990', 'Name' => 'Tommy Hilfiger Watch For Men 990',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches',
    ]));

    $product = importedProduct('woo:9900990');
    $productId = Row::int($product, 'id');

    expect(app(ProductImporter::class)->discard('woo:9900990'))->toBeTrue()
        ->and(DB::table('catalog_products')->where('id', $productId)->exists())->toBeFalse()
        // Everything that pointed at it went with it: no orphan translation, no orphan placement.
        ->and(DB::table('catalog_product_translations')->where('product_id', $productId)->exists())->toBeFalse()
        ->and(DB::table('storefront_product')->where('product_id', $productId)->exists())->toBeFalse();

    // And the re-run imports it properly rather than skipping it.
    $again = importRows(WooFixture::row([
        'ID' => 9900990, 'Type' => 'simple', 'SKU' => 'DISCARD-990', 'Name' => 'Tommy Hilfiger Watch For Men 990',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches',
    ]));
    expect($again->get('imported'))->toBe(1);
});

it('discards its OWN opening balance, but never a movement somebody else made', function () {
    importRows(WooFixture::row([
        'ID' => 9900991, 'Type' => 'simple', 'SKU' => 'HISTORY-991', 'Name' => 'Tommy Hilfiger Watch For Men 991',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100',
        'Stock' => '5', 'Categories' => 'Watches',
    ]));

    $productId = Row::int(importedProduct('woo:9900991'), 'id');

    /*
     * The opening stock is a ledger movement, and the first version of `discard()` refused on any
     * movement at all — which meant a corrected mapping could never be re-applied: 2 221 of 2 977
     * imported rows were unremovable for no reason but their own import. "An importer does not undo
     * history" means somebody ELSE'S history.
     */
    expect(app(ProductImporter::class)->discard('woo:9900991'))->toBeTrue()
        ->and(DB::table('catalog_products')->where('import_ref', 'woo:9900991')->exists())->toBeFalse()
        // …and the opening balance went with the product, so the ledger names no ghost.
        ->and(DB::table('inventory_movements')->where('product_id', $productId)->exists())->toBeFalse();
});

it('REFUSES to discard a product that anything else has touched', function () {
    importRows(WooFixture::row([
        'ID' => 9900992, 'Type' => 'simple', 'SKU' => 'HISTORY-992', 'Name' => 'Tommy Hilfiger Watch For Men 992',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100',
        'Stock' => '5', 'Categories' => 'Watches',
    ]));

    $productId = Row::int(importedProduct('woo:9900992'), 'id');

    // One movement that is NOT the import's — a hand correction through the one door — and the
    // product becomes untouchable. That is the line between "clean up my mess" and "delete the
    // shop".
    app(InventoryService::class)->adjust(
        StockTarget::product($productId),
        InventoryService::BUCKET_EXPRESS,
        -1,
        'adjustment',
        null,
        Actor::system(),
    );

    expect(app(ProductImporter::class)->discard('woo:9900992'))->toBeFalse()
        ->and(DB::table('catalog_products')->where('import_ref', 'woo:9900992')->exists())->toBeTrue();
});

// ── 5. the developer's map corrections (2026-09-14) ──────────────────────────────────────────

it('places a leather bag in BOTH sections and keeps BAGS as the primary', function () {
    /*
     * The correction in one test. A leather handbag must be findable under "Leather" AND be a bag
     * in every way that matters: breadcrumb, family, spec block. `fashion/materials/leather` is
     * depth 3 and `fashion/bags` is depth 2, so the old "deepest wins" rule would have handed the
     * primary to the material.
     */
    importRows(WooFixture::row([
        'ID' => 9900100, 'Type' => 'simple', 'SKU' => 'LEATHER-100', 'Name' => 'Michael Kors Leather Handbag MK100',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '4500',
        'Categories' => 'Women,Women > Women Bags,Leather,Handbag',
    ]));

    $product = importedProduct('woo:9900100');
    $productId = Row::int($product, 'id');

    $bagNode = CategoryMerger::find(2, 'fashion/bags/handbag');
    $leatherNode = CategoryMerger::find(2, 'fashion/materials/leather');
    expect($bagNode)->not->toBeNull()->and($leatherNode)->not->toBeNull();

    $placements = DB::table('storefront_category_product')
        ->where('storefront_id', 2)->where('product_id', $productId)
        ->pluck('is_primary', 'storefront_category_id')->all();

    // In both sections…
    $bagId = T::int($bagNode);
    $leatherId = T::int($leatherNode);

    expect($placements)->toHaveKey($bagId)
        ->and($placements)->toHaveKey($leatherId)
        // …and the BAG is the primary, never the material.
        ->and(T::int($placements[$bagId] ?? null))->toBe(1)
        ->and(T::int($placements[$leatherId] ?? null))->toBe(0);

    // The family follows the primary, so it is a bag — and the material is recorded on the product.
    expect(Row::str($product, 'family'))->toBe('bag');

    $specs = json_decode(Row::nstr($product, 'specs') ?? '{}', true);
    $leather = T::int(DB::table('catalog_material_translations')->where('locale', 'en')->where('name', 'Leather')->value('material_id'));
    expect(is_array($specs) ? ($specs['material_id'] ?? null) : null)->toBe($leather);
});

it('keeps exactly ONE primary however many sections a product is in', function () {
    // The invariant the developer asked to have confirmed: an ADDITIONAL placement is additional.
    // M1d's unique key on (storefront_id, primary_guard) enforces it in the database; this asserts
    // the importer never tries to write a second one.
    importRows(WooFixture::row([
        'ID' => 9900101, 'Type' => 'simple', 'SKU' => 'LEATHER-101', 'Name' => 'Tommy Hilfiger Leather Wallet TH101',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '900',
        'Categories' => 'Men,Men > Men Wallets,Leather,Men Accessories',
    ]));

    $productId = Row::int(importedProduct('woo:9900101'), 'id');

    $primaries = T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 2)->where('product_id', $productId)->where('is_primary', 1)->count());
    $all = T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 2)->where('product_id', $productId)->count());

    expect($primaries)->toBe(1)
        ->and($all)->toBeGreaterThan(1);
});

it('lets a material node be the primary ONLY when the row names nothing else, and reports it', function () {
    // 53 Satin rows in the real file carry no type leaf at all. A placed product cannot be
    // primary-less, so the material takes it — and every one is named in the report.
    $report = importRows(WooFixture::row([
        'ID' => 9900102, 'Type' => 'simple', 'SKU' => 'SATIN-102', 'Name' => 'Generic Satin Scarf S102',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '300',
        'Categories' => 'Women,Satin',
    ]));

    $productId = Row::int(importedProduct('woo:9900102'), 'id');
    $satinNode = CategoryMerger::find(2, 'fashion/materials/satin');

    $primary = T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 2)->where('product_id', $productId)->where('is_primary', 1)
        ->value('storefront_category_id'));

    expect($primary)->toBe($satinNode)
        ->and(array_column($report->flagged(), 'kind'))->toContain('material_only_primary');
});

it('sends the accessories leaves to their own node, not to the Fashion root', function () {
    // The developer's correction: a root full of loose products with no sub-section is worse than
    // one repeated word. 133 of the 204 in-scope accessories have no other type leaf.
    importRows(WooFixture::row([
        'ID' => 9900103, 'Type' => 'simple', 'SKU' => 'ACC-103', 'Name' => 'Generic Men Accessory A103',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '250',
        'Categories' => 'Men,Men > Men Accessories',
    ]));

    $productId = Row::int(importedProduct('woo:9900103'), 'id');
    $accessories = CategoryMerger::find(2, 'fashion/accessories');
    $fashionRoot = CategoryMerger::find(2, 'fashion');

    expect($accessories)->not->toBeNull();

    $primary = T::int(DB::table('storefront_category_product')
        ->where('storefront_id', 2)->where('product_id', $productId)->where('is_primary', 1)
        ->value('storefront_category_id'));

    expect($primary)->toBe($accessories)->and($primary)->not->toBe($fashionRoot);
});

it('marks a product whose image did not process, and the filter finds only those', function () {
    /*
     * ── M1s, 2026-09-17 ─────────────────────────────────────────────────────────────────────
     *
     * GD can return `true` and write a ZERO-BYTE rendition. The pipeline now removes the file and
     * does not record it, so nothing broken is served — but the person who can fix it, by uploading
     * a better source, still has to be told. `renditions_failed` on the image row is how, and this
     * pins the whole path from that column to the chip on the list.
     *
     * Written onto the row directly rather than by forcing an encoder to fail: a host whose GD is
     * broken is not something a test can arrange, and the behaviour under test starts at the column.
     * `MediaTest` covers the other half — that the pipeline never records an empty rendition.
     */
    importRows(implode("\n", [
        WooFixture::row(['ID' => 9900990, 'Type' => 'simple', 'SKU' => 'IMG-990', 'Name' => 'Tommy Hilfiger Watch For Men 990',
            'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches']),
        WooFixture::row(['ID' => 9900991, 'Type' => 'simple', 'SKU' => 'IMG-991', 'Name' => 'Tommy Hilfiger Watch For Men 991',
            'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches']),
    ]));

    $subject = T::int(DB::table('catalog_products')->where('import_ref', 'woo:9900990')->value('id'));
    $other = T::int(DB::table('catalog_products')->where('import_ref', 'woo:9900991')->value('id'));

    // Images are off during an import test, so both products need a row to hang the marker on.
    foreach ([$subject, $other] as $productId) {
        DB::table('catalog_product_images')->insert([
            'product_id' => $productId, 'path' => 'Product/probe-'.$productId.'.webp',
            'is_cover' => 1, 'sort' => 0, 'width' => 1200, 'height' => 1200,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('catalog_product_images')->where('product_id', $subject)
        ->update(['renditions_failed' => 'FAILED 480w webp: the encoder reported success but wrote 0 bytes']);

    actingAs(Staff::admin());

    // 1. The MARKER is on the row, and reads as its own token rather than as "no image" — the
    //    product has one; it is the processing that failed, and the two need different actions.
    $rows = Props::rows(Props::table(get('/manage/storefronts/2/products?q=IMG-990')));
    $seen = [];
    foreach ($rows as $row) {
        $seen = array_merge($seen, T::arr($row['missing'] ?? null));
    }
    expect($seen)->toContain('image_problem')
        ->and($seen)->not->toContain('image');

    // 2. The FILTER selects it…
    $flagged = Props::rows(Props::table(get('/manage/storefronts/2/products?per_page=100&filters[flag]=image_problem')));
    $ids = array_map(static fn (array $r): int => T::int($r['id'] ?? null), $flagged);
    expect($ids)->toContain($subject)
        // …and ONLY it. A filter that also returns the healthy product beside it is worse than none.
        ->and($ids)->not->toContain($other);

    // 3. …and "show me everything unfinished" includes it, because the composite filter and the
    //    badge must select the same set (the §4 law the six other markers already obey).
    $missing = Props::rows(Props::table(get('/manage/storefronts/2/products?per_page=100&filters[flag]=missing_data')));
    expect(array_map(static fn (array $r): int => T::int($r['id'] ?? null), $missing))->toContain($subject);

    // 4. A human re-uploading clears it — the same lifecycle `is_machine` has. The marker exists to
    //    ask somebody to act and must stop asking once they have.
    $row = Row::cast(T::one(DB::table('catalog_products')->where('id', $subject)));
    app(ProductWriter::class)->update($subject, [
        'brand_id' => Row::int($row, 'brand_id'),
        'wa_code' => Row::str($row, 'wa_code'),
        'sku' => Row::nstr($row, 'sku'),
        'selling_price' => Row::str($row, 'selling_price'),
        'currency' => 'EGP',
        'is_active' => true,
        'family' => Row::str($row, 'family'),
        'title' => ['ar' => 'ساعة تومي هيلفيغر رجالي', 'en' => 'Tommy Hilfiger Watch For Men 990'],
        'images' => [[
            'id' => null, 'path' => 'Product/replacement-'.$subject.'.webp',
            'is_cover' => true, 'sort' => 0, 'width' => 1200, 'height' => 1200,
            'alt_en' => null, 'alt_ar' => null, 'renditions' => null,
        ]],
    ], null);
    expect(DB::table('catalog_product_images')->where('product_id', $subject)->whereNotNull('renditions_failed')->count())
        ->toBe(0);
});

it('KEEPS the machine badge when a save does not touch the translation (A-BUG-1)', function () {
    /*
     * The other half of the badge's lifecycle, and the one that was broken.
     *
     * `flag=machine_ar` is the review queue for 7,087 imported Arabic titles. Because the writer
     * cleared `is_machine` on every pass through the door, the queue drained through work that has
     * nothing to do with Arabic — the review measured a price-only edit clearing it, and a bulk
     * deactivate of 25 products (form never opened) taking the queue from 7,087 to 7,061.
     *
     * The damage was invisible: the activity log records `is_active`, so nothing explained where
     * the rows went.
     */
    importRows(WooFixture::row(['ID' => 9900995, 'Type' => 'simple', 'SKU' => 'KEEP-995', 'Name' => 'Tommy Hilfiger Watch For Men 995',
        'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches']));

    $product = importedProduct('woo:9900995');
    $id = Row::int($product, 'id');

    $badge = static fn (): int => T::int(DB::table('catalog_product_translations')
        ->where('product_id', $id)->where('locale', 'ar')->value('is_machine'));

    expect($badge())->toBe(1, 'the importer must have set the badge for this to prove anything');

    $arabic = T::str(DB::table('catalog_product_translations')
        ->where('product_id', $id)->where('locale', 'ar')->value('title'));

    // A PRICE-ONLY edit, sending the SAME translations back — exactly what the product form does on
    // an ordinary save, and what the review measured clearing the flag on product 635.
    $payload = static fn (string $price, string $ar) => [
        'brand_id' => Row::int($product, 'brand_id'),
        'wa_code' => Row::str($product, 'wa_code'),
        'sku' => Row::nstr($product, 'sku'),
        'selling_price' => $price,
        'currency' => 'EGP',
        'is_active' => true,
        'family' => Row::str($product, 'family'),
        'title' => ['ar' => $ar, 'en' => Row::str($product, 'wa_code').' EN'],
    ];

    app(ProductWriter::class)->update($id, $payload('149.00', $arabic), null);

    expect($badge())->toBe(1, 'a price-only save must not empty the Arabic review queue')
        ->and(T::str(DB::table('catalog_product_translations')
            ->where('product_id', $id)->where('locale', 'ar')->value('title')))->toBe($arabic);

    // …and the price really did change, so the save was real and not a no-op that proved nothing.
    expect(T::str(DB::table('catalog_products')->where('id', $id)->value('selling_price')))->toBe('149.00');

    // NOW a human actually rewrites the Arabic: the badge must go.
    app(ProductWriter::class)->update($id, $payload('149.00', 'ساعة كتبها إنسان'), null);

    expect($badge())->toBe(0, 'editing the Arabic must clear the badge — that is what it is for');
});

it('does not clear the badge through a bulk action that never opens the form (A-BUG-1)', function () {
    /*
     * The review's second measurement, and the more damaging one: 25 products deactivated in one
     * click cleared 25 badges. A bulk action routes through the same full-replace save, so it sent
     * the stored translations back and the old writer treated that as a human edit.
     */
    $refs = [];
    foreach ([9900996, 9900997, 9900998] as $wooId) {
        $refs[] = 'woo:'.$wooId;
    }

    importRows(implode("\n", array_map(
        static fn (int $wooId): string => WooFixture::row(['ID' => $wooId, 'Type' => 'simple', 'SKU' => 'BULK-'.$wooId,
            'Name' => 'Tommy Hilfiger Watch For Men '.$wooId, 'Published' => '1',
            'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches']),
        [9900996, 9900997, 9900998],
    )));

    $ids = DB::table('catalog_products')->whereIn('import_ref', $refs)->pluck('id')->all();
    expect($ids)->toHaveCount(3);

    $badged = static fn (): int => T::int(DB::table('catalog_product_translations')
        ->whereIn('product_id', $ids)->where('locale', 'ar')->where('is_machine', 1)->count());

    expect($badged())->toBe(3);

    actingAs(Staff::admin())
        ->post('/manage/storefronts/2/products/bulk', ['action' => 'deactivate', 'ids' => $ids])
        ->assertRedirect();

    expect($badged())->toBe(3, 'a bulk deactivate must not drain the Arabic review queue')
        // …and the bulk action really did its job, so this is not passing on a no-op.
        ->and(T::int(DB::table('catalog_products')->whereIn('id', $ids)->where('is_active', 0)->count()))->toBe(3);
});
