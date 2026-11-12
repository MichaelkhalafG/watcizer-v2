<?php

use App\Compat\CompatCategories;
use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Import\BrandFashion\CatalogueCleanup;
use App\Domain\Import\BrandFashion\LegacyImporter;
use App\Domain\Import\BrandFashion\LegacyRedirects;
use App\Domain\Import\CategoryMerger;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Promotions\OfferCopy;
use App\Domain\Promotions\PromotionWriter;
use App\Models\Storefront\StorefrontRedirect;
use App\Support\Coerce;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\Support\CatalogFixture;

/*
 * The Brand Fashion legacy import, steps 1A, 1B, 2 and 3 (2026-11-11). Every test builds the rows,
 * files and rules it asserts on, inside its own transaction.
 */

/** A product through the dashboard's own door; returns its id. */
function bfMakeProduct(string $titleEn, ?string $sku, ?string $importRef = null): int
{
    CatalogFixture::assumeSwitched();
    $brand = Coerce::int(DB::table('catalog_brands')->orderBy('id')->value('id'));
    $id = app(ProductWriter::class)->create([
        'brand_id' => $brand,
        'wa_code' => 'BFT-'.substr(md5($titleEn.microtime()), 0, 10),
        'sku' => $sku,
        'selling_price' => 1000,
        'is_active' => true,
        'title' => ['ar' => 'منتج اختبار '.$titleEn, 'en' => $titleEn],
    ], null);
    if ($importRef !== null) {
        DB::table('catalog_products')->where('id', $id)->update(['import_ref' => $importRef, 'created_by' => null]);
    }

    return $id;
}

function bfPlace(int $productId, int $storefrontId, bool $visible): void
{
    DB::table('storefront_product')->insert([
        'storefront_id' => $storefrontId, 'product_id' => $productId, 'is_visible' => $visible,
        'slug' => 'bft-'.$productId.'-'.$storefrontId, 'effective_price' => 1000, 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('classifies by origin, never touches Watchizer-active or dashboard products, and lists duplicates', function (): void {
    $clean = bfMakeProduct('BFT clean sheet product', null, 'woo:990000001');
    bfPlace($clean, 2, false);
    $opening = bfMakeProduct('BFT opening balance only', 'BFT-OPEN-1', 'woo:990000002');
    app(InventoryService::class)->set(StockTarget::product($opening), InventoryService::BUCKET_EXPRESS, 2, 'import', null, Actor::system());
    $counted = bfMakeProduct('BFT counted by the team', 'BFT-COUNT-1', 'woo:990000003');
    app(InventoryService::class)->set(StockTarget::product($counted), InventoryService::BUCKET_EXPRESS, 3, 'adjustment', null, Actor::system());
    $onWatchizer = bfMakeProduct('BFT live on Watchizer', 'BFT-WZ-1', 'woo:990000004');
    bfPlace($onWatchizer, 1, true);
    $dashboard = bfMakeProduct('BFT dashboard twin', 'BFT-DUP-1');
    $duplicate = bfMakeProduct('BFT sheet twin', 'bft-dup-1 ', 'woo:990000005');

    $cleanup = app(CatalogueCleanup::class);
    $strict = $cleanup->classify(false)['rows'];
    $narrow = $cleanup->classify(true)['rows'];

    expect($strict[$clean]['bucket'])->toBe(CatalogueCleanup::DELETE)
        ->and($strict[$opening]['bucket'])->toBe(CatalogueCleanup::HISTORY)
        ->and($strict[$counted]['bucket'])->toBe(CatalogueCleanup::HISTORY)
        ->and($strict[$onWatchizer]['bucket'])->toBe(CatalogueCleanup::KEEP_WATCHIZER)
        ->and($strict[$duplicate]['bucket'])->toBe(CatalogueCleanup::DUPLICATE)
        ->and($strict[$duplicate]['duplicate_of'])->toContain($dashboard)
        ->and(isset($strict[$dashboard]))->toBeFalse()                    // a dashboard product is not even a candidate
        ->and($narrow[$opening]['bucket'])->toBe(CatalogueCleanup::DELETE)    // its only movement is the import's own
        ->and($narrow[$counted]['bucket'])->toBe(CatalogueCleanup::HISTORY);  // a team count is history either way
});

it('backs up, deletes, logs, and restores exactly', function (): void {
    $clean = bfMakeProduct('BFT clean sheet product', null, 'woo:990000011');
    bfPlace($clean, 2, false);
    $opening = bfMakeProduct('BFT opening balance only', 'BFT-OPEN-2', 'woo:990000012');
    app(InventoryService::class)->set(StockTarget::product($opening), InventoryService::BUCKET_EXPRESS, 2, 'import', null, Actor::system());
    $archive = bfMakeProduct('BFT counted by the team', 'BFT-COUNT-2', 'woo:990000013');

    $snapshot = fn (): array => [
        'products' => DB::table('catalog_products')->whereIn('id', [$clean, $opening, $archive])->orderBy('id')->get(['id', 'sku', 'deleted_at', 'is_active', 'stock_express'])->toArray(),
        'translations' => DB::table('catalog_product_translations')->whereIn('product_id', [$clean, $opening])->count(),
        'placements' => DB::table('storefront_product')->whereIn('product_id', [$clean, $opening])->count(),
        'movements' => DB::table('inventory_movements')->whereIn('product_id', [$clean, $opening])->count(),
    ];
    $before = $snapshot();

    $cleanup = app(CatalogueCleanup::class);
    $file = tempnam(sys_get_temp_dir(), 'bfclean');
    $counts = $cleanup->backup($file, [$clean, $opening], [$archive], true);
    expect($counts['catalog_products'])->toBe(3)->and($counts['inventory_movements'])->toBe(1);

    $done = $cleanup->apply([$clean, $opening], [$archive], true);
    expect($done)->toBe(['deleted' => 2, 'archived' => 1])
        ->and(DB::table('catalog_products')->whereIn('id', [$clean, $opening])->count())->toBe(0)
        ->and(DB::table('inventory_movements')->where('product_id', $opening)->count())->toBe(0)
        ->and(DB::table('catalog_products')->where('id', $archive)->value('deleted_at'))->not->toBeNull()
        ->and(DB::table('core_activity_log')->where('subject_type', 'catalog_products')->whereIn('subject_id', [$clean, $opening])
            ->where('action', ActivityLog::DELETED)->count())->toBe(2);

    $restored = $cleanup->restore($file);
    expect($restored['catalog_products'])->toBe(2)
        ->and($snapshot())->toEqual($before)
        ->and(DB::table('core_activity_log')->whereIn('subject_id', [$clean, $opening])->where('action', ActivityLog::RESTORED)->count())->toBe(2);

    // A second restore would duplicate the ids: refused, nothing changed.
    expect(fn () => $cleanup->restore($file))->toThrow(RuntimeException::class);
    unlink($file);
});

it('imports a legacy product through the dashboard doors, on Brand Fashion only, and never twice', function (): void {
    CatalogFixture::assumeSwitched();
    if (CategoryMerger::find(2, 'watches') === null) {
        Assert::markTestSkipped('this copy has no `watches` node on Brand Fashion');
    }
    $media = sys_get_temp_dir().'/bft-media-'.uniqid();
    $uploads = sys_get_temp_dir().'/bft-uploads-'.uniqid();
    mkdir($uploads.'/2024/01', 0777, true);
    $img = imagecreatetruecolor(900, 900);
    imagepng($img, $uploads.'/2024/01/bft-cover.png');
    config()->set('media.root', $media);

    $brand = Coerce::str(DB::table('catalog_brand_translations')->where('locale', 'en')->orderBy('brand_id')->value('name'));
    $material = Coerce::str(DB::table('catalog_material_translations')->where('locale', 'en')->orderBy('material_id')->value('name'));
    $record = [
        'legacy_id' => 990000101, 'legacy_slug' => 'bft-legacy-watch', 'legacy_url' => 'https://www.brandfashionegy.com/product/bft-legacy-watch/',
        'slug' => 'bft-legacy-watch', 'title_en' => 'BFT Legacy Watch', 'title_ar' => 'ساعة اختبار قديمة',
        'short_en' => 'A short line.', 'long_en' => 'A short line. And more.', 'short_ar' => 'سطر قصير.', 'long_ar' => 'سطر قصير. والمزيد.',
        'sku' => 'BFT-LEG-101', 'type' => 'simple', 'regular' => 1500.0, 'sale' => 1200.0, 'price_issue' => null,
        'stock' => ['managed' => true, 'quantity' => 3, 'status' => 'instock'],
        'category_paths' => ['watches'], 'unmapped_categories' => [], 'genders' => ['Men'], 'materials' => [], 'brand' => $brand,
        'catalog_visible' => true, 'variants' => [], 'images' => [['attachment_id' => 1, 'file' => '2024/01/bft-cover.png', 'alt' => '']],
        'specs' => [['kind' => 'lookup', 'target' => 'band_material_id', 'list' => 'materials', 'label' => 'band material', 'value' => $material, 'value_ar' => '']],
        'specs_unmapped' => [], 'seo' => ['title' => null, 'description' => null, 'focus_keyword' => null],
    ];

    $importer = app(LegacyImporter::class);
    $importer->prepare();
    expect($importer->plan($record)['action'])->toBe('create');
    $result = $importer->create($record, $importer->resolve($record, $uploads), LegacyImporter::STOCK_LEDGER, $uploads);

    $id = $result['id'];
    $product = DB::table('catalog_products')->where('id', $id)->first();
    expect($result['visible'])->toBeTrue()
        ->and($result['slug'])->toBe('bft-legacy-watch')
        ->and(Coerce::str($product?->import_ref))->toBe('bfwp:990000101')
        ->and(Coerce::str($product?->wa_code))->toBe('BFL-990000101')
        ->and(Coerce::str($product?->family))->toBe('watch')
        ->and(DB::table('storefront_product')->where('product_id', $id)->pluck('storefront_id')->all())->toBe([2])
        ->and(DB::table('catalog_product_images')->where('product_id', $id)->whereNotNull('renditions')->count())->toBe(1)
        ->and(DB::table('catalog_product_watch_specs')->where('product_id', $id)->value('band_material_id'))->not->toBeNull()
        ->and(DB::table('inventory_movements')->where('product_id', $id)->where('reason', 'import')->sum('quantity_delta'))->toEqual(3)
        ->and(DB::table('core_activity_log')->where('subject_type', 'catalog_products')->where('subject_id', $id)->where('action', ActivityLog::CREATED)->exists())->toBeTrue();

    // A second run: the same row is "already", its in-source twin is skipped, a SKU match is not created.
    $again = app(LegacyImporter::class);
    $again->prepare();
    $twin = ['legacy_id' => 990000102, 'sku' => ' bft-leg-101'] + $record;
    $existing = bfMakeProduct('BFT existing elsewhere', 'BFT-ELSEWHERE-1');
    $match = ['legacy_id' => 990000103, 'sku' => 'BFT-ELSEWHERE-1'] + $record;
    $again->prepare();

    expect($again->plan($record))->toMatchArray(['action' => 'already', 'existing' => $id])
        ->and($again->plan($twin))->toMatchArray(['action' => 'skip', 'reason' => 'duplicate_in_source', 'existing' => $id])
        ->and($again->plan($match))->toMatchArray(['action' => 'match', 'rule' => 'sku', 'existing' => $existing]);
});

it('copies a coded rule only under a new code, as the target shop\'s own rule', function (): void {
    $writer = app(PromotionWriter::class);
    $brand = Coerce::int(DB::table('catalog_brands')->orderBy('id')->value('id'));
    $source = $writer->save([
        'name' => 'BFT rule', 'code' => 'BFT-CODE-1', 'apply_per' => 'item', 'is_active' => true,
        'starts_at' => now()->subDay()->toDateTimeString(), 'ends_at' => now()->addMonth()->toDateTimeString(),
        'storefronts' => [1], 'conditions' => [],
        'rewards' => [['type' => 'fixed_discount', 'amount' => 100]],
        'scopes' => [['role' => 'target', 'dimension' => 'brand', 'value_id' => $brand]],
    ], null, null);

    $copy = app(OfferCopy::class);
    $refused = $copy->plan(1, 2, []);
    expect(implode(' ', $refused['refusals']))->toContain('BFT-CODE-1');

    $plan = $copy->plan(1, 2, ['BFT-CODE-1' => 'BFT-CODE-2']);
    $plan['rules'] = array_values(array_filter($plan['rules'], fn (array $r): bool => $r['source_id'] === $source));
    $plan['campaigns'] = [];
    $plan['refusals'] = [];
    $created = $copy->apply($plan, 2)['rules'];

    expect($created)->toHaveCount(1)
        ->and(DB::table('promotion_rules')->where('id', $created[0])->value('code'))->toBe('BFT-CODE-2')
        ->and(DB::table('promotion_rule_storefront')->where('promotion_rule_id', $created[0])->pluck('storefront_id')->all())->toBe([2])
        ->and(DB::table('promotion_rule_storefront')->where('promotion_rule_id', $source)->pluck('storefront_id')->all())->toBe([1])
        ->and(DB::table('promotion_rule_scopes')->where('promotion_rule_id', $created[0])->count())->toBe(1)
        ->and(DB::table('core_activity_log')->where('subject_type', 'promotion_rules')->where('subject_id', $created[0])->where('storefront_id', 2)->exists())->toBeTrue();
});

it('refuses to copy a rule that names a category of the source tree', function (): void {
    $writer = app(PromotionWriter::class);
    $node = Coerce::nint(DB::table('storefront_categories')->where('storefront_id', 1)->orderBy('id')->value('id'));
    if ($node === null) {
        Assert::markTestSkipped('no Watchizer category on this copy');
    }
    $writer->save([
        'name' => 'BFT category rule', 'code' => 'BFT-CAT-1', 'apply_per' => 'item', 'is_active' => true,
        'starts_at' => now()->subDay()->toDateTimeString(), 'ends_at' => now()->addMonth()->toDateTimeString(),
        'storefronts' => [1], 'conditions' => [],
        'rewards' => [['type' => 'fixed_discount', 'amount' => 100]],
        'scopes' => [['role' => 'target', 'dimension' => 'category', 'value_id' => $node]],
    ], null, null);

    $plan = app(OfferCopy::class)->plan(1, 2, ['BFT-CAT-1' => 'BFT-CAT-2']);
    expect(implode(' ', $plan['refusals']))->toContain('names a category');
});

it('stores redirect rows by path hash and skips the ones that need none', function (): void {
    $redirects = app(LegacyRedirects::class);
    $done = $redirects->store([
        ['from' => '/product-category/bft-test', 'to' => '/category/bft', 'status' => 'matched', 'kind' => 'category', 'note' => ''],
        ['from' => '/product/bft-same', 'to' => '/product/bft-same', 'status' => 'same', 'kind' => 'product', 'note' => ''],
    ]);

    expect($done)->toBe(['written' => 1, 'skipped_same' => 1])
        ->and(DB::table('storefront_redirects')->where('storefront_id', 2)->where('from_hash', StorefrontRedirect::hashPath('/product-category/bft-test'))
            ->value('source'))->toBe(LegacyRedirects::SOURCE)
        ->and(DB::table('storefront_redirects')->where('from_hash', StorefrontRedirect::hashPath('/product/bft-same'))->exists())->toBeFalse();
});

it('sends a category to the URL the Brand Fashion app serves it at, or to its nearest parent with a page', function (): void {
    $tree = new CompatCategories(2);
    $sub = $tree->subTypes()[0] ?? null;
    if ($sub === null) {
        Assert::markTestSkipped('no visible second-level category on Brand Fashion on this copy');
    }
    $id = Coerce::int($sub['id']);
    $parent = Coerce::nint($sub['parent_id']);
    $href = function (int $node): ?array {
        $found = (fn () => $this->nodeHref($node))->call(app(LegacyRedirects::class));

        return is_array($found) ? $found : null;
    };

    // depth 2: /subtypes/<the English NAME slugged> — never the node's slug column, never /category/
    expect($href($id))->toBe(['to' => '/subtypes/'.LegacyRedirects::appSlug((string) $tree->name($sub, 'en')), 'exact' => true]);

    // out of the menu: no page of its own — its parent's, flagged as not exact
    DB::table('storefront_categories')->where('id', $id)->update(['show_in_menu' => 0]);
    $up = $href($id);
    $parentNode = $parent === null ? null : (new CompatCategories(2))->nodes()[$parent] ?? null;
    if ($parentNode !== null && in_array($parent, array_column((new CompatCategories(2))->categoryTypes(), 'id'), true)) {
        expect($up)->toBe(['to' => '/category/'.LegacyRedirects::appSlug((string) $tree->name($parentNode, 'en')), 'exact' => false]);
    } else {
        expect($up === null || $up['exact'] === false)->toBeTrue();
    }
});

it('counts two old products under one name as two when their photos differ, and as one when the photo is the same', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'bfphoto');
    $rec = fn (int $id, string $photo): array => ['legacy_id' => $id, 'title_en' => 'Bft Generic Wallet For Men', 'sku' => null, 'regular' => 500.0,
        'images' => [['attachment_id' => $id, 'file' => $photo, 'alt' => '']]];
    $records = [$rec(990000401, '2024/01/a.jpg'), $rec(990000402, '2024/01/b.jpg'), $rec(990000403, '2024/01/a.jpg')];
    file_put_contents($file, implode("\n", array_map(fn (array $r): string => (string) json_encode($r), $records))."\n");

    $importer = app(LegacyImporter::class);
    $importer->prepare(null, $file);
    $plans = array_map(fn (array $r): array => $importer->plan($r), $records);

    expect($plans[0]['action'])->toBe('create')
        ->and($plans[1]['action'])->toBe('create')                       // same name, another photo: another product (O-4)
        ->and($plans[2]['action'])->toBe('skip')                         // same name, same photo: the old site's duplicate
        ->and($plans[2]['reason'])->toBe('duplicate_in_source')
        ->and(LegacyImporter::identityKey(null, 'Bft Generic Wallet For Men', '2024/01/a.jpg'))
        ->not->toBe(LegacyImporter::identityKey(null, 'Bft Generic Wallet For Men', '2024/01/b.jpg'))
        ->and(LegacyImporter::identityKey('SKU 1', 'A', '2024/01/a.jpg'))->toBe(LegacyImporter::identityKey('sku 1', 'B', '2024/01/b.jpg'));   // a SKU wins
});

it('acts on the duplicates review sheet: removes the not-duplicates and all but one of each real duplicate, refuses the rest', function (): void {
    $sheet = tempnam(sys_get_temp_dir(), 'bfreview');
    $h = fopen($sheet, 'wb') ?: throw new RuntimeException("cannot write {$sheet}");
    fwrite($h, "\xEF\xBB\xBF");
    fputcsv($h, ['sheet_id', 'wordpress_id', 'sku', 'title', 'matched_by', 'twins', 'twin_count', 'evidence', 'proposal'], escape: '\\');
    $line = fn (int $id, string $twins, string $proposal): array => [$id, 1, '', 'x', 'name', $twins, 1, 'e', $proposal];
    foreach ([
        $line(990000501, '990000502 BF-2 [sheet, 2:hidden]', 'NOT a duplicate (different photos on the old site): remove this sheet copy'),
        $line(990000502, '990000501 BF-1 [sheet, 2:hidden]', 'NOT a duplicate (different photos on the old site): remove this sheet copy'),
        $line(990000511, '990000512 BF-12 [sheet, 2:hidden] | 990000513 BF-13 [sheet, 2:hidden]', 'A real duplicate (same photo on the old site): keep one'),
        $line(990000512, '990000511 BF-11 [sheet, 2:hidden] | 990000513 BF-13 [sheet, 2:hidden]', 'A real duplicate (same photo on the old site): keep one'),
        $line(990000513, '990000511 BF-11 [sheet, 2:hidden] | 990000512 BF-12 [sheet, 2:hidden]', 'NOT a duplicate (different photos on the old site): remove this sheet copy'),
        $line(990000521, '990000522 BF-22 [dashboard, 2:visible]', 'Twin is a dashboard product: keep the twin'),
    ] as $cells) {
        fputcsv($h, $cells, escape: '\\');
    }
    fclose($h);
    $live = fn (int $id): array => ['bucket' => CatalogueCleanup::DUPLICATE, 'import_ref' => 'woo:'.$id, 'title' => 'x'];
    $rows = [990000501 => $live(990000501), 990000511 => $live(990000511), 990000512 => $live(990000512), 990000513 => $live(990000513),
        990000502 => ['bucket' => CatalogueCleanup::HISTORY, 'import_ref' => 'woo:2', 'title' => 'x']];   // gained history since the review

    $r = app(CatalogueCleanup::class)->reviewed($sheet, $rows);

    expect($r['remove'])->toEqualCanonicalizing([990000501, 990000512, 990000513])
        ->and($r['keep'])->toBe([990000511])                                          // the oldest of the real pair (nobody edited either)
        ->and(implode(' ', $r['refused']))->toContain('990000502')                     // not in the duplicate bucket any more: left alone
        ->and(implode(' ', $r['refused']))->toContain('990000521');                    // a proposal O-4 did not decide
});
