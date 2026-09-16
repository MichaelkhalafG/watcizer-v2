<?php

use App\Domain\Catalog\PreSwitch;
use App\Domain\Import\ArabicTitle;
use App\Domain\Import\BrandResolver;
use App\Domain\Import\CategoryMap;
use App\Domain\Import\CategoryMerger;
use App\Domain\Import\JoyroomSheet;
use App\Domain\Import\WooExport;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\T;
use Tests\Support\WooFixture;

/*
 * The import's READERS and its mapping table (wave 4D).
 *
 * Everything here is about turning somebody else's file into our vocabulary, and every case is a
 * shape that is REALLY in the two files — measured 2026-09-14, not imagined. A reader test written
 * against an invented CSV proves the test's own assumptions.
 */

// ── 1. the mapping table itself ──────────────────────────────────────────────────────────────

it('maps every category leaf to a target that really exists', function () {
    foreach (CategoryMap::LEAVES as $leaf => $mapping) {
        expect($mapping['kind'])->toBeIn([CategoryMap::NODE, CategoryMap::GENDER, CategoryMap::MATERIAL, CategoryMap::NONE]);

        if ($mapping['kind'] === CategoryMap::NODE) {
            // A node target is either in Brand Fashion's real tree today, or declared as one we
            // will create. Anything else is a mapping row pointing at nothing, which would fail
            // 8 000 rows deep into a run instead of here.
            $exists = CategoryMerger::find(2, $mapping['to']) !== null;
            $declared = isset(CategoryMap::NEW_NODES[$mapping['to']]);
            expect($exists || $declared)->toBeTrue("leaf [{$leaf}] points at [{$mapping['to']}]");
        }

        if ($mapping['kind'] === CategoryMap::GENDER) {
            expect(CategoryMap::GENDERS)->toHaveKey($mapping['to']);
        }
    }
});

it('declares a resolvable parent for every node it creates', function () {
    foreach (CategoryMap::NEW_NODES as $path => $definition) {
        expect($definition['ar'])->not->toBe('')
            ->and($definition['en'])->not->toBe('');

        if ($definition['parent'] === null) {
            continue;
        }
        // The parent is either a real node or another declared one, and it must come EARLIER in
        // the list — the merger creates them in order.
        $realParent = CategoryMerger::find(2, $definition['parent']) !== null;
        $declaredParent = isset(CategoryMap::NEW_NODES[$definition['parent']]);
        expect($realParent || $declaredParent)->toBeTrue("[{$path}] has parent [{$definition['parent']}]");

        if ($declaredParent) {
            $order = array_keys(CategoryMap::NEW_NODES);
            $parentAt = (int) array_search($definition['parent'], $order, true);
            $selfAt = (int) array_search($path, $order, true);
            expect($parentAt)->toBeLessThan($selfAt);
        }
    }
});

it('throws away their PATH and keeps the leaf', function () {
    // `Men > Men Watches` is their parent, not ours. Importing their parent would import their
    // disagreement with our tree.
    expect(CategoryMap::leafOf('Watches > Quartz'))->toBe('Quartz')
        ->and(CategoryMap::leafOf('Men'))->toBe('Men')
        ->and(CategoryMap::leafOf(' Women > Women Bags '))->toBe('Women Bags');
});

// ── 2. the WooCommerce reader ────────────────────────────────────────────────────────────────

it('imports only published AND visible rows, and COUNTS the rest by kind', function () {
    $path = WooFixture::file(implode("\n", [
        WooFixture::row(['ID' => 1, 'Type' => 'simple', 'Name' => 'Live watch', 'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches']),
        WooFixture::row(['ID' => 2, 'Type' => 'simple', 'Name' => 'Draft watch', 'Published' => '-1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Categories' => 'Watches']),
        WooFixture::row(['ID' => 3, 'Type' => 'simple', 'Name' => 'Hidden watch', 'Published' => '1', 'Visibility in catalog' => 'hidden', 'Regular price' => '100', 'Categories' => 'Watches']),
        WooFixture::row(['ID' => 4, 'Type' => 'simple', 'Name' => '', 'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100']),
    ]));

    $export = new WooExport($path);
    $rows = iterator_to_array($export->rows());

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->titleEn)->toBe('Live watch');

    $counts = $export->counts();
    // Every rejection is a NUMBER, not a silence.
    expect($counts['skipped_unpublished'])->toBe(1)
        ->and($counts['skipped_hidden'])->toBe(1)
        ->and($counts['skipped_no_name'])->toBe(1);

    unlink($path);
});

it('folds variations into their parent by BOTH conventions the file uses', function () {
    // 111 of the 253 variations say `id:<post id>` and 142 say the parent's SKU. Both, or the
    // shoes arrive as 253 loose products.
    $path = WooFixture::file(implode("\n", [
        WooFixture::row(['ID' => 10, 'Type' => 'variable', 'SKU' => 'THS001', 'Name' => 'Leather shoes', 'Published' => '1', 'Visibility in catalog' => 'visible', 'Categories' => 'Men > Men Shoes']),
        WooFixture::row(['ID' => 11, 'Type' => 'variation', 'Name' => 'Leather shoes - 41', 'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '3000', 'Parent' => 'THS001', 'Attribute 1 name' => 'Shoes Size', 'Attribute 1 value(s)' => '41']),
        WooFixture::row(['ID' => 12, 'Type' => 'variation', 'Name' => 'Leather shoes - 42', 'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '3200', 'Parent' => 'id:10', 'Attribute 1 name' => 'Shoes Size', 'Attribute 1 value(s)' => '42\\']),
    ]));

    $rows = iterator_to_array((new WooExport($path))->rows());

    expect($rows)->toHaveCount(1);
    $product = $rows[0];

    expect($product->variants)->toHaveCount(2)
        // The backslash in "42\" is theirs, four times in the real file.
        ->and(array_column($product->variants, 'label'))->toBe(['41', '42'])
        // A variable parent carries no price of its own (0 of 49 in the file), so the CHEAPEST
        // variant is the product's price — what a listing shows.
        ->and($product->sellingPrice)->toBe(3000.0);

    unlink($path);
});

it('refuses a sale price our own price contract would reject', function () {
    $path = WooFixture::file(implode("\n", [
        WooFixture::row(['ID' => 20, 'Type' => 'simple', 'Name' => 'A', 'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Sale price' => '100', 'Categories' => 'Watches']),
        WooFixture::row(['ID' => 21, 'Type' => 'simple', 'Name' => 'B', 'Published' => '1', 'Visibility in catalog' => 'visible', 'Regular price' => '100', 'Sale price' => '80', 'Categories' => 'Watches']),
    ]));

    $rows = iterator_to_array((new WooExport($path))->rows());

    // `sale >= selling` is dropped, not stored: the storefront prices `sale` only when
    // `0 < sale < selling`, and a violation 422s the checkout (AGENTS §2.4).
    expect($rows[0]->salePrice)->toBeNull()
        ->and($rows[1]->salePrice)->toBe(80.0);

    unlink($path);
});

it('turns their one category field into three different kinds of fact', function () {
    $path = WooFixture::file(WooFixture::row([
        'ID' => 30, 'Type' => 'simple', 'Name' => 'Leather bag', 'Published' => '1', 'Visibility in catalog' => 'visible',
        'Regular price' => '500', 'Categories' => 'Women,Women > Women Bags,Leather,Crossbody Bag,Uncategorized',
    ]));

    $row = iterator_to_array((new WooExport($path))->rows())[0];

    expect($row->genders)->toBe(['Women'])
        ->and($row->materials)->toBe(['Leather'])
        ->and($row->categoryPaths)->toContain('fashion/bags')
        ->and($row->categoryPaths)->toContain('fashion/bags/crossbody-bag')
        /*
         * THREE paths since the developer's correction of 2026-09-14: `Leather` is both a material
         * AND a browsable section, so it contributes a node as well as the material above.
         * `Uncategorized` still contributes nothing — deliberately.
         */
        ->and($row->categoryPaths)->toContain('fashion/materials/leather')
        ->and($row->categoryPaths)->toHaveCount(3)
        /*
         * …and the family still comes from the deepest node that may be PRIMARY, so a leather
         * crossbody bag is a bag. The material node is deeper and is skipped for exactly this
         * reason.
         */
        ->and($row->family)->toBe('bag');

    unlink($path);
});

it('reads a negative stock as zero rather than exploding inside InventoryService', function () {
    // Exactly one row in the real file has −2. It is a bookkeeping scar, not a quantity.
    $path = WooFixture::file(WooFixture::row(['ID' => 40, 'Type' => 'simple', 'Name' => 'Bag', 'Published' => '1',
        'Visibility in catalog' => 'visible', 'Regular price' => '500', 'Stock' => '-2', 'Categories' => 'Women > Women Bags']));

    expect(iterator_to_array((new WooExport($path))->rows())[0]->stock)->toBe(0);

    unlink($path);
});

it('refuses a file that is not the export', function () {
    $path = tempnam(sys_get_temp_dir(), 'woo').'.csv';
    file_put_contents($path, "name,price\nA,1\n");

    expect(fn () => iterator_to_array((new WooExport($path))->rows()))
        ->toThrow(RuntimeException::class, 'missing required column');

    unlink($path);
});

// ── 3. the Joyroom reader ────────────────────────────────────────────────────────────────────

it('groups the price list by MODEL and keeps both of its prices', function () {
    $path = tempnam(sys_get_temp_dir(), 'joy').'.csv';
    file_put_contents($path, implode("\n", [
        'item,model,color,specification,description,rdp,rrp,page,image',
        '"20W Power Bank",JR-PBM01,Black,spec,desc,1100.00,1450.00,1,',
        '"20W Power Bank",JR-PBM01,White,,,1100.00,1450.00,1,',
        '"Privacy Screen Protector",JR-PG01,Privacy,,,50.00,150.00,2,',
    ])."\n");

    $rows = iterator_to_array((new JoyroomSheet($path))->rows());

    expect($rows)->toHaveCount(2);

    $bank = $rows[0];
    expect($bank->sku)->toBe('JR-PBM01')
        // RRP is what a customer pays; RDP is what we paid. This is the ONLY source with a cost.
        ->and($bank->sellingPrice)->toBe(1450.0)
        ->and($bank->purchasePrice)->toBe(1100.0)
        ->and($bank->family)->toBe('electronics')
        ->and($bank->categoryPaths)->toBe(['electronics/power-banks'])
        // Two colours of one model are two VARIANTS, not two products.
        ->and($bank->variants)->toHaveCount(2)
        // No stock column exists in the price list at all.
        ->and($bank->stock)->toBeNull();

    // `Privacy` sits in their COLOR column and is not a colour; it is still the choice a customer
    // makes, so it survives as a label — and a single-choice product gets no variants at all.
    expect($rows[1]->categoryPaths)->toBe(['electronics/screen-protectors'])
        ->and($rows[1]->variants)->toBe([]);

    unlink($path);
});

// ── 4. the Arabic machine translation ────────────────────────────────────────────────────────

it('builds an Arabic title from the formula their titles follow', function () {
    expect(ArabicTitle::from('Tommy Hilfiger Watch For Men 1791594', 'Tommy Hilfiger'))
        ->toBe('ساعة Tommy Hilfiger رجالي 1791594');

    expect(ArabicTitle::from('GUESS Women Cross Bag GUESS BAG0027', 'Guess'))
        ->toContain('حقيبة كروس')
        ->toContain('نسائي')
        ->toContain('BAG0027');

    // The brand is stripped BEFORE matching, or "Police" and "Coach" would be read as words.
    expect(ArabicTitle::from("Police Men's Leather Watch PL15305", 'Police'))
        ->toContain('ساعة')
        ->toContain('جلد');
});

it('keeps the model code exactly as written, because that is what customers search', function () {
    expect(ArabicTitle::from('Emporio Armani Watch For Men AR1968', 'Emporio Armani'))->toContain('AR1968');
    // …and does not mistake a measurement for a code.
    expect(ArabicTitle::from('Generic Watch 44 mm', null))->not->toContain('44');
});

it('returns something usable even for a title it cannot parse', function () {
    // A perfume named after a person matches no noun. The English is kept rather than emitting an
    // Arabic fragment — and the row is marked for review either way.
    $out = ArabicTitle::from('Baxart Rouge 540 by Maison Francis Kurdkjian', null);
    expect($out)->not->toBeNull()->and($out)->toContain('Baxart');
});

// ── 5. the brand, which exists only inside the title ─────────────────────────────────────────

it('matches the LONGEST brand name, not the first word', function () {
    $resolver = new BrandResolver;

    $armani = $resolver->forTitle("Emporio Armani Men's Watch AR60079");
    $exchange = $resolver->forTitle("Armani Exchange Men's Rocco Watch AX2902");

    expect($armani['matched'])->toBeTrue()
        ->and($exchange['matched'])->toBeTrue()
        // Two different houses; a first-word match would have made them one.
        ->and($armani['id'])->not->toBe($exchange['id']);
});

it('never reads "inspired by Gucci" as Gucci', function () {
    $resolver = new BrandResolver;
    // The `Generic` brand is created on first use, and creating a lookup row is a PreSwitch
    // refusal — so the probe runs inside the same exemption the importer uses.
    $row = PreSwitch::allowing(['lookup'], fn (): array => $resolver->forTitle('Generic Women sunglasses Inspired By Gucci sn256'));

    // The single most consequential line in the resolver: a lookalike must not be filed under the
    // house it imitates, in our own catalogue.
    expect($row['matched'])->toBeFalse()
        ->and($row['name'])->toBe(BrandResolver::GENERIC);

    $gucci = T::int(DB::table('catalog_brands')->where('slug', 'gucci')->value('id'));
    expect($row['id'])->not->toBe($gucci);
});

it('ignores casing and punctuation, which their titles do not respect', function () {
    $resolver = new BrandResolver;

    $ids = array_map(
        fn (string $title): int => $resolver->forTitle($title)['id'],
        ['TOMMY HILFIGER Watch', 'Tommy HIlfiger Watch', 'tommy hilfiger watch'],
    );

    expect(array_unique($ids))->toHaveCount(1);
});

it('counts the names it could not match, which is the "brands to add" list', function () {
    $resolver = new BrandResolver;
    PreSwitch::allowing(['lookup'], function () use ($resolver): void {
        $resolver->forTitle('Mini Focus Watch For Men MF0475G.03');
        $resolver->forTitle('Mini Focus Watch For Women MF0476');
        $resolver->forTitle('NAVIFORCE NF9230 Watch');
    });

    $unmatched = $resolver->unmatched();
    expect($unmatched)->toHaveKey('Mini Focus')
        ->and($unmatched['Mini Focus'])->toBe(2)
        // Ranked, because the top of the list is the decision.
        ->and(array_key_first($unmatched))->toBe('Mini Focus');
});
