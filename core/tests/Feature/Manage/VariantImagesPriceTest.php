<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\VariantWriter;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * Part 1 (2026-11-04): the variant's price is entered in FULL and stored as a difference (1.3),
 * the product's existing images belong to rows (1.2), and the product's own colour is a row —
 * made at conversion, or offered in one click on a product converted before this (1.4).
 *
 * Every case constructs its product, its colours and its images; nothing is read off the laptop.
 */

/**
 * Two distinct colour ids — the first plays the product's "own" colour below.
 *
 * @return list<int>
 */
function twoColours(): array
{
    $ids = array_values(DB::table('catalog_colors')->orderBy('id')->limit(2)->pluck('id')->map(fn (mixed $id): int => T::int($id))->all());
    expect(count($ids))->toBe(2, 'the catalogue needs two colours for this test');

    return $ids;
}

function colourName(int $colourId): string
{
    return T::str(DB::table('catalog_color_translations')->where('color_id', $colourId)->orderByRaw("FIELD(locale, 'ar', 'en')")->value('name'));
}

/** The product's own colour, the way the catalogue records a fashion product's single colour: under `band`. */
function giveOwnColour(int $productId, int $colourId): void
{
    DB::table('catalog_product_color')->insert(['product_id' => $productId, 'color_id' => $colourId, 'role' => 'band', 'position' => 0]);
}

/** A second (or third) image on the fixture product; the fixture itself gives it the cover. */
function addImage(int $productId, string $name): int
{
    return (int) DB::table('catalog_product_images')->insertGetId([
        'product_id' => $productId, 'path' => "Product/{$name}.webp", 'is_cover' => 0,
        'sort' => T::int(DB::table('catalog_product_images')->where('product_id', $productId)->max('sort') ?? 0) + 1,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function imageOwner(int $imageId): ?int
{
    $v = DB::table('catalog_product_images')->where('id', $imageId)->value('variant_id');

    return is_numeric($v) ? (int) $v : null;
}

it('takes the row price in FULL and stores the difference from the selling price, never showing the difference', function () {
    CatalogFixture::assumeSwitched();
    [$red] = twoColours();
    $productId = CatalogFixture::product(selling: 1200.0);

    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'أحمر', 'color_id' => $red, 'is_active' => true, 'price' => 1290,
    ])->assertSessionHasNoErrors();

    $variant = T::one(DB::table('catalog_product_variants')->where('product_id', $productId));
    // stored exactly as the column always was: a difference
    expect(Row::money($variant, 'price_delta'))->toBe('90.00');

    // read back as a full price, which is the only thing the form shows
    $rows = app(VariantWriter::class)->rows($productId);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['price'])->toBe('1290.00')
        ->and(app(VariantWriter::class)->summary($productId)['base_price'])->toBe('1200.00');

    // below the product's price is a negative difference, and legal
    $variantId = Row::int($variant, 'id');
    actingAs(Staff::admin())->put("/manage/products/{$productId}/variants/{$variantId}", [
        'label' => 'أحمر', 'color_id' => $red, 'is_active' => true, 'price' => 1100, 'sort' => 0,
    ])->assertSessionHasNoErrors();
    expect(T::str(DB::table('catalog_product_variants')->where('id', $variantId)->value('price_delta')))->toBe('-100.00');

    // the accepted consequence: the base moves, the row moves with it by the same difference
    DB::table('catalog_products')->where('id', $productId)->update(['selling_price' => '1500.00']);
    expect(app(VariantWriter::class)->rows($productId)[0]['price'])->toBe('1400.00');

    // and `price_delta` is still accepted on its own (the importer's path), so an older caller keeps working
    actingAs(Staff::admin())->put("/manage/products/{$productId}/variants/{$variantId}", [
        'label' => 'أحمر', 'color_id' => $red, 'is_active' => true, 'price_delta' => 25, 'sort' => 0,
    ])->assertSessionHasNoErrors();
    expect(T::str(DB::table('catalog_product_variants')->where('id', $variantId)->value('price_delta')))->toBe('25.00');
});

it('marks which of the product\'s images belong to a row — one image, one row — and refuses another product\'s image', function () {
    CatalogFixture::assumeSwitched();
    [$red, $blue] = twoColours();
    $productId = CatalogFixture::product();
    $cover = T::int(DB::table('catalog_product_images')->where('product_id', $productId)->value('id'));
    $second = addImage($productId, 'p1-second');
    $third = addImage($productId, 'p1-third');
    $foreign = T::int(DB::table('catalog_product_images')->where('product_id', CatalogFixture::product())->value('id'));
    $mark = T::int(DB::table(ActivityLog::TABLE)->max('id') ?? 0);

    // the red row takes two of the three
    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'أحمر', 'color_id' => $red, 'is_active' => true, 'image_ids' => [$cover, $second],
    ])->assertSessionHasNoErrors();
    $redId = T::int(DB::table('catalog_product_variants')->where('product_id', $productId)->where('color_id', $red)->value('id'));
    expect(imageOwner($cover))->toBe($redId)->and(imageOwner($second))->toBe($redId)->and(imageOwner($third))->toBeNull();

    // the blue row claims the second: it MOVES, because an image belongs to one row only
    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'أزرق', 'color_id' => $blue, 'is_active' => true, 'image_ids' => [$second],
    ])->assertSessionHasNoErrors();
    $blueId = T::int(DB::table('catalog_product_variants')->where('product_id', $productId)->where('color_id', $blue)->value('id'));
    expect(imageOwner($cover))->toBe($redId)->and(imageOwner($second))->toBe($blueId);

    // the panel reads the sets back, in gallery order
    $rows = app(VariantWriter::class)->rows($productId);
    $byId = [];
    foreach ($rows as $row) {
        $byId[T::int($row['id'])] = $row['image_ids'];
    }
    expect($byId[$redId])->toBe([$cover])->and($byId[$blueId])->toBe([$second]);

    // saving the red row WITHOUT the cover hands it back to the product's general gallery
    actingAs(Staff::admin())->put("/manage/products/{$productId}/variants/{$redId}", [
        'label' => 'أحمر', 'color_id' => $red, 'is_active' => true, 'sort' => 0, 'image_ids' => [],
    ])->assertSessionHasNoErrors();
    expect(imageOwner($cover))->toBeNull();

    // another product's image is "not there", not "not yours"
    actingAs(Staff::admin())->put("/manage/products/{$productId}/variants/{$redId}", [
        'label' => 'أحمر', 'color_id' => $red, 'is_active' => true, 'sort' => 0, 'image_ids' => [$foreign],
    ])->assertSessionHasErrors('image_ids.0');
    expect(imageOwner($foreign))->toBeNull();

    // the activity log says who gave which row which pictures
    $entries = T::many(DB::table(ActivityLog::TABLE)->where('id', '>', $mark)->where('subject_type', 'catalog_product_variants')->orderBy('id'));
    $withImages = array_filter($entries, fn (stdClass $e): bool => str_contains(T::str($e->changes ?? ''), 'image_ids'));
    expect($withImages)->not->toBeEmpty('the row\'s images are part of what the log records');

    // deleting the blue row (no history: never stocked) hands its image back — the schema's ON DELETE SET NULL
    actingAs(Staff::admin())->delete("/manage/products/{$productId}/variants/{$blueId}")->assertSessionHasNoErrors();
    expect(DB::table('catalog_product_variants')->where('id', $blueId)->exists())->toBeFalse()
        ->and(imageOwner($second))->toBeNull();
});

it('CONVERTS by moving the product\'s units down and making its own colour a row, when the first row is another colour', function () {
    CatalogFixture::assumeSwitched();
    [$brown, $red] = twoColours();
    $productId = CatalogFixture::product(selling: 1200.0);
    giveOwnColour($productId, $brown);
    $inventory = app(InventoryService::class);
    $inventory->set(StockTarget::product($productId), 'express', 12, 'manual');
    $inventory->set(StockTarget::product($productId), 'market', 3, 'manual');
    $mark = T::int(DB::table(ActivityLog::TABLE)->max('id') ?? 0);

    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'أحمر', 'color_id' => $red, 'is_active' => true, 'price' => 1290, 'stock_express' => 5,
    ])->assertSessionHasNoErrors();

    $rows = T::many(DB::table('catalog_product_variants')->where('product_id', $productId)->orderBy('sort')->orderBy('id'));
    expect($rows)->toHaveCount(2);
    $base = $rows[0];
    $redRow = $rows[1];
    // the base row: the product's own colour, its own name, its own price, holding the units the product held
    expect(Row::nint($base, 'color_id'))->toBe($brown)
        ->and(Row::str($base, 'label'))->toBe(colourName($brown))
        ->and(Row::money($base, 'price_delta'))->toBe('0.00')
        ->and(Row::int($base, 'stock_express'))->toBe(12)
        ->and(Row::int($base, 'stock_market'))->toBe(3)
        ->and(Row::int($base, 'sort'))->toBe(0)
        // the requested row, exactly as typed
        ->and(Row::nint($redRow, 'color_id'))->toBe($red)
        ->and(Row::int($redRow, 'stock_express'))->toBe(5)
        ->and(Row::money($redRow, 'price_delta'))->toBe('90.00');

    // the aggregate is the SUM of its rows — which it was not before Part 1, when the twelve stayed on top
    $product = T::one(DB::table('catalog_products')->where('id', $productId));
    expect(Row::int($product, 'stock_express'))->toBe(17)
        ->and(Row::int($product, 'stock_market'))->toBe(3)
        ->and(Row::bool($product, 'in_stock'))->toBeTrue();

    // the move is HISTORY: a product-level zeroing that names the original count, written while the
    // product still had no rows — and nothing product-level after that
    $zeroing = T::many(DB::table('inventory_movements')->where('product_id', $productId)->whereNull('variant_id')->where('quantity_after', 0)->orderBy('id'));
    expect($zeroing)->toHaveCount(2)
        ->and(T::str($zeroing[0]->note ?? ''))->toContain('قبل التحويل: 12');
    $lastProductLevel = T::int(DB::table('inventory_movements')->where('product_id', $productId)->whereNull('variant_id')->max('id'));
    $firstVariantLevel = T::int(DB::table('inventory_movements')->where('product_id', $productId)->whereNotNull('variant_id')->min('id'));
    expect($lastProductLevel)->toBeLessThan($firstVariantLevel);
    // the ledger agrees with both rows
    expect($inventory->ledgerQuantity(StockTarget::variant($productId, Row::int($base, 'id')), 'express'))->toBe(12)
        ->and($inventory->ledgerQuantity(StockTarget::variant($productId, Row::int($redRow, 'id')), 'express'))->toBe(5);

    // both rows are in the activity log, the base one first, under the same actor
    $created = T::many(DB::table(ActivityLog::TABLE)->where('id', '>', $mark)->where('subject_type', 'catalog_product_variants')->where('action', ActivityLog::CREATED)->orderBy('id'));
    expect($created)->toHaveCount(2)
        ->and(T::int($created[0]->subject_id))->toBe(Row::int($base, 'id'))
        ->and(T::int($created[1]->subject_id))->toBe(Row::int($redRow, 'id'));

    // and the panel knows the own colour is covered
    expect(app(VariantWriter::class)->summary($productId)['own_color'])->toBe(['id' => $brown, 'name' => colourName($brown), 'has_row' => true]);
});

it('lets the first row INHERIT the product\'s units when that row is the product\'s own colour', function () {
    CatalogFixture::assumeSwitched();
    [$brown] = twoColours();
    $productId = CatalogFixture::product();
    giveOwnColour($productId, $brown);
    app(InventoryService::class)->set(StockTarget::product($productId), 'express', 7, 'manual');

    // no quantity typed: the row is the brown, so the seven browns are its
    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'بني', 'color_id' => $brown, 'is_active' => true,
    ])->assertSessionHasNoErrors();

    $rows = T::many(DB::table('catalog_product_variants')->where('product_id', $productId));
    expect($rows)->toHaveCount(1, 'no second row: the typed row IS the base colour');
    $row = $rows[0];
    $product = T::one(DB::table('catalog_products')->where('id', $productId));
    expect(Row::int($row, 'stock_express'))->toBe(7)
        ->and(Row::int($product, 'stock_express'))->toBe(7)
        ->and(Row::int($product, 'stock_market'))->toBe(0);
});

it('converts a product WITHOUT an own colour by moving its units into the ledger and taking the rows as typed', function () {
    CatalogFixture::assumeSwitched();
    $productId = CatalogFixture::product();
    app(InventoryService::class)->set(StockTarget::product($productId), 'express', 9, 'manual');
    $sizeId = T::int(DB::table('catalog_sizes')->orderBy('id')->value('id'));

    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => 'S', 'size_id' => $sizeId, 'is_active' => true, 'stock_express' => 4,
    ])->assertSessionHasNoErrors();

    $product = T::one(DB::table('catalog_products')->where('id', $productId));
    expect(DB::table('catalog_product_variants')->where('product_id', $productId)->count())->toBe(1)
        ->and(Row::int($product, 'stock_express'))->toBe(4, 'the aggregate is the sum of the rows, not the old count plus the rows')
        ->and(DB::table('inventory_movements')->where('product_id', $productId)->whereNull('variant_id')->where('quantity_after', 0)->exists())->toBeTrue('the nine are recorded, not lost');
});

it('offers the own colour as a one-click row on a product converted before Part 1, and the click makes it', function () {
    CatalogFixture::assumeSwitched();
    [$brown, $red] = twoColours();
    $productId = CatalogFixture::product(selling: 800.0);
    giveOwnColour($productId, $brown);
    // the brown wallet as it was found: converted straight to a red row, with no brown row
    DB::table('catalog_product_variants')->insert([
        'product_id' => $productId, 'label' => 'RED', 'color_id' => $red, 'price_delta' => '0.00',
        'is_active' => 1, 'sort' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $summary = app(VariantWriter::class)->summary($productId);
    expect($summary['own_color'])->toBe(['id' => $brown, 'name' => colourName($brown), 'has_row' => false]);
    // and the product form carries it to the panel
    $props = Props::of(actingAs(Staff::admin())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk());
    $variants = T::arr($props['variants']);
    expect(T::arr($variants['summary'])['own_color'])->toBe($summary['own_color'])
        ->and(T::arr($variants['summary'])['base_price'])->toBe('800.00');

    // what the panel's button posts
    actingAs(Staff::admin())->post("/manage/products/{$productId}/variants", [
        'label' => colourName($brown), 'color_id' => $brown, 'price' => '800.00', 'is_active' => true, 'image_ids' => [],
    ])->assertSessionHasNoErrors();
    expect(T::arr(app(VariantWriter::class)->summary($productId)['own_color'])['has_row'])->toBeTrue()
        ->and(DB::table('catalog_product_variants')->where('product_id', $productId)->count())->toBe(2);
});
