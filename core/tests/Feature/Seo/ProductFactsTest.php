<?php

use App\Domain\Content\QualityGate;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

/*
 * THE MISSING PRODUCT FACTS AND THE PRODUCT DESCRIPTIONS (Michael, 2026-11-15, SEO item 2c): the quality gate's product
 * profile checks every fact a description states against the record; catalog:load-facts fills only what is empty,
 * corrects only a value that is still what the file expected, writes only descriptions that pass, and undoes all of it.
 */

/** A watch on Brand Fashion with a code, a 42 mm case and a 50 m rating, in stock. */
function pfWatch(): int
{
    $id = CatalogFixture::product('watch', 3000.0);
    DB::table('catalog_products')->where('id', $id)->update(['sku' => 'PF'.random_int(10000, 99999), 'search_class' => null, 'warranty_years' => 2]);
    $mm = T::int(DB::table('catalog_units')->where('code', 'mm')->value('id'));
    $metre = T::int(DB::table('catalog_units')->where('code', 'metre')->value('id'));
    DB::table('catalog_product_watch_specs')->updateOrInsert(['product_id' => $id],
        ['case_size' => 42, 'case_size_unit_id' => $mm, 'water_resistance' => 50, 'water_resistance_unit_id' => $metre, 'movement_type_id' => null, 'band_material_id' => null]);
    CatalogFixture::onStorefront($id, true, 2);
    app(StorefrontCache::class)->flush(2);

    return $id;
}

/** @return array{en: array{description: string}, ar: array{description: string}} */
function pfText(int $id, string $extraEn = '', string $extraAr = ''): array
{
    $code = T::str(DB::table('catalog_products')->where('id', $id)->value('sku'));
    $en = "The {$code} is a men's watch with a 42 mm case. Rated to 50 m, it is fine in the rain and for a light swim in shallow water. "
        ."The stainless steel bracelet can be sized to your wrist by removing links, and the battery-powered movement needs no winding. "
        ."It comes with a 2-year warranty; within it we change the battery free of charge at our stores. We deliver to every governorate "
        ."within 24 hours, with cash on delivery and 14-day returns.".$extraEn;
    $ar = "ساعة {$code} رجالي بعلبة 42 مم. ومقاومتها للماء 50 متر تناسب المطر والسباحة الخفيفة في المياه الضحلة. "
        .'وسوارها الستانلس ستيل يمكن ضبطه على مقاس معصمك بإزالة بعض الحلقات، وماكينتها بالبطارية لا تحتاج إلى تعبئة. وتأتي مع ضمان لمدة سنتين، '
        .'وخلاله نغير البطارية مجانًا في فرعينا. نوصل إلى كل المحافظات خلال 24 ساعة، مع الدفع عند الاستلام والإرجاع خلال 14 يومًا.'.$extraAr;

    return ['en' => ['description' => $en], 'ar' => ['description' => $ar]];
}

it('passes a description whose every fact is the record\'s, and holds each kind of fault', function () {
    $id = pfWatch();
    $t = pfText($id);
    $r = app(QualityGate::class)->checkProduct(2, $id, $t['en'], $t['ar']);
    expect($r['en']['reasons'])->toBe([])->and($r['ar']['reasons'])->toBe([]);

    $held = function (string $en, string $ar, string $needle) use ($id): void {
        $t = pfText($id, $en, $ar);
        $r = app(QualityGate::class)->checkProduct(2, $id, $t['en'], $t['ar']);
        expect(implode(' | ', [...$r['en']['reasons'], ...$r['ar']['reasons']]))->toContain($needle);
    };
    $held(' The 44 mm case suits most wrists.', ' والعلبة 44 مم تناسب أغلب المعاصم.', 'case size the record does not hold');
    $held(' It is rated 100 m for diving.', ' ومقاومتها 100 متر.', 'water rating the record does not hold');
    $held(' Now only EGP 2,500.', ' الآن 2500 جنيه.', 'a price in a product description');
    $held(' A great match for Tommy Hilfiger fans.', '', 'names another brand');
    $held(' Also see model ZZ12345.', ' وانظر ZZ12345.', 'not this product\'s');
    // the shop's own facts still hold
    $held(' Returns are accepted within 30 days.', '', 'return window');
    // English and Arabic state the same facts: an Arabic text without the case size is held in both
    $noCase = ['description' => str_replace(' بعلبة 42 مم', '', $t['ar']['description'])];
    $r = app(QualityGate::class)->checkProduct(2, $id, $t['en'], $noCase);
    expect(implode(' | ', $r['en']['reasons']))->toContain('English and Arabic state different facts');
});

it('fills only what is empty, corrects only what is still expected, writes only passing descriptions, and undoes all of it', function () {
    $id = pfWatch();
    DB::table('catalog_product_gender')->where('product_id', $id)->delete();
    $before = [
        'p' => (array) DB::table('catalog_products')->where('id', $id)->first(['sku', 'updated_at']),
        'w' => (array) DB::table('catalog_product_watch_specs')->where('product_id', $id)->first(),
        'g' => DB::table('catalog_product_gender')->where('product_id', $id)->count(),
        'c' => DB::table('catalog_product_color')->where('product_id', $id)->where('role', 'dial')->count(),
        'd' => DB::table('catalog_product_translations')->where('product_id', $id)->pluck('long_description', 'locale')->all(),
    ];
    $dir = sys_get_temp_dir().'/pf-'.bin2hex(random_bytes(4));
    @mkdir($dir);
    $black = T::int(DB::table('catalog_color_translations')->where('locale', 'en')->where('name', 'Black')->value('color_id'));
    expect($black)->toBeGreaterThan(0);
    file_put_contents($dir.'/facts.tsv', "product_id\tfield\tvalue\tsource\tref\texpect\n"
        ."{$id}\tgender\tmen\ttitle\ttitle\t\n"
        ."{$id}\tmovement\tquartz\twprime\thttps://example.test/p\t\n"
        ."{$id}\tdial_colour\tblack\twprime\thttps://example.test/p\t\n"
        ."{$id}\tcase_size\t44\twprime\thttps://example.test/p\t\n"           // has 42 already: a FILL never overwrites
        ."{$id}\tfix:water_m\t30\twprime+oldbf\tx\t50\n"                       // still 50: corrected
        ."{$id}\tfix:case_size\t40\twprime+oldbf\tx\t39\n");                   // the record says 42, not 39: kept
    $t = pfText($id);
    $code = T::str(DB::table('catalog_products')->where('id', $id)->value('sku'));
    // the description states the record AFTER the facts: 42 mm kept, 30 m corrected
    $en = str_replace('Rated to 50 m, it is fine in the rain and for a light swim in shallow water.', 'Rated to 30 m, it handles rain and splashes.', $t['en']['description']);
    $ar = str_replace('ومقاومتها للماء 50 متر تناسب المطر والسباحة الخفيفة في المياه الضحلة.', 'ومقاومتها للماء 30 متر تحميها من المطر والرذاذ.', $t['ar']['description']);
    $was = [];
    foreach ($before['d'] as $l => $text) {
        $was[T::str($l)] = sha1(is_string($text) ? $text : '');
    }
    file_put_contents($dir.'/desc.jsonl', json_encode(['product_id' => $id, 'en' => $en, 'ar' => $ar, 'replace' => ['en', 'ar'], 'was' => $was], JSON_UNESCAPED_UNICODE)."\n");

    // the command creates its backup table, and a CREATE commits the test's transaction: from here on nothing rolls
    // back by itself, so the test removes what it made, pass or fail
    try {
        Artisan::call('catalog:load-facts', ['file' => $dir.'/facts.tsv', '--descriptions' => $dir.'/desc.jsonl', '--apply' => true]);
        $out = Artisan::output();
        expect($out)->toContain('facts written: 4')->toContain('descriptions: 1 written')->toContain('the record changed since');
        $w = DB::table('catalog_product_watch_specs')->where('product_id', $id)->first();
        expect((float) T::str($w?->case_size))->toBe(42.0)
            ->and((int) T::str($w?->water_resistance))->toBe(30)
            ->and(DB::table('catalog_product_gender')->where('product_id', $id)->count())->toBe(1)
            ->and(DB::table('catalog_product_color')->where('product_id', $id)->where('role', 'dial')->value('color_id'))->toBe($black)
            ->and(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('long_description'))->toBe($en);

        // a second run changes nothing
        Artisan::call('catalog:load-facts', ['file' => $dir.'/facts.tsv', '--descriptions' => $dir.'/desc.jsonl', '--apply' => true]);
        expect(Artisan::output())->toContain('facts written: 0')->toContain('descriptions: 0 written');

        // the undo: every value back, byte for byte, the specs row and the product's date as they were
        Artisan::call('catalog:load-facts', ['file' => '-', '--undo' => true]);
        expect((array) DB::table('catalog_products')->where('id', $id)->first(['sku', 'updated_at']))->toBe($before['p'])
            ->and((array) DB::table('catalog_product_watch_specs')->where('product_id', $id)->first())->toBe($before['w'])
            ->and(DB::table('catalog_product_gender')->where('product_id', $id)->count())->toBe($before['g'])
            ->and(DB::table('catalog_product_color')->where('product_id', $id)->where('role', 'dial')->count())->toBe($before['c'])
            ->and(DB::table('catalog_product_translations')->where('product_id', $id)->pluck('long_description', 'locale')->all())->toBe($before['d']);
    } finally {
        foreach (['catalog_product_color', 'catalog_product_gender', 'catalog_product_images', 'catalog_product_search', 'catalog_product_translations',
            'catalog_product_watch_specs', 'storefront_product'] as $table) {
            DB::table($table)->where('product_id', $id)->delete();
        }
        DB::table('core_activity_log')->where('subject_type', 'catalog_products')->where('subject_id', $id)->delete();
        DB::table('catalog_products')->where('id', $id)->delete();
        DB::statement('DROP TABLE IF EXISTS bk_seo_20261115_facts');
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }
})->skip(
    // the command's backup table is a real deploy's record: a database that holds one is never a place for this test
    // (its --undo and DROP would take the real run back)
    fn (): bool => DB::getSchemaBuilder()->hasTable('bk_seo_20261115_facts') && DB::table('bk_seo_20261115_facts')->exists(),
    'bk_seo_20261115_facts holds a real run on this database - run this test on a copy without it',
);
