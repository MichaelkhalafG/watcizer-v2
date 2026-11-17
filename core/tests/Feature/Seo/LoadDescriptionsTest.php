<?php

use App\Console\Commands\CatalogLoadDescriptionsCommand;
use App\Storefront\StorefrontCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\SideDdl;
use Tests\Support\T;

/*
 * PRODUCT DESCRIPTIONS BY STABLE KEY (release 2026-11-18, B3). The file never names a product id: a line is resolved
 * by its WhatsApp code on the database it runs on, behind a title guard (the brand and the model in the title), and a
 * text the team changed since the file was made stays theirs.
 */

/**
 * A watch on Brand Fashion with a known code, brand and model in its title.
 *
 * @return array{0: int, 1: string, 2: string, 3: string} id, wa_code, brand slug, model
 */
function ldWatch(): array
{
    $id = CatalogFixture::product('watch', 3000.0);
    $model = 'LD'.random_int(10000, 99999);
    $wa = 'LD-TEST-'.bin2hex(random_bytes(4));
    DB::table('catalog_products')->where('id', $id)->update(['sku' => $model, 'wa_code' => $wa, 'import_ref' => 'ld:'.$wa, 'search_class' => null, 'warranty_years' => null]);
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->update(['title' => "Test Brand Women's Watch {$model}", 'short_description' => '', 'long_description' => 'Thin.']);
    DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->update(['short_description' => '', 'long_description' => '']);
    DB::table('catalog_product_watch_specs')->updateOrInsert(['product_id' => $id], ['case_size' => null, 'water_resistance' => null]);
    CatalogFixture::onStorefront($id, true, 2);
    app(StorefrontCache::class)->flush(2);
    $brand = T::str(DB::table('catalog_brands as b')->join('catalog_products as p', 'p.brand_id', '=', 'b.id')->where('p.id', $id)->value('b.slug'));

    return [$id, $wa, $brand, $model];
}

/**
 * A line for that watch, every fact the record's own.
 *
 * @param  array<string, mixed>  $over
 */
function ldLine(string $wa, string $brand, string $model, array $over = []): string
{
    return json_encode(array_merge([
        'key' => ['wa_code' => $wa, 'import_ref' => 'ld:'.$wa], 'brand' => $brand, 'model' => $model,
        'en' => "This women's watch, the {$model}, has a clean dial and a bracelet that sits comfortably on the wrist all day. "
            .'Its quartz movement keeps accurate time and needs no winding; we change the battery at our stores when it runs low. '
            .'Many of our pieces can also be seen in our stores in Arkadia Mall and Heliopolis before you buy. '
            .'We deliver to every governorate within 24 hours, with cash on delivery and 14-day returns.',
        'ar' => "ساعة {$model} الحريمي بميناء نظيف وسوار يجلس براحة على المعصم طوال اليوم. وماكينتها الكوارتز دقيقة ولا تحتاج إلى تعبئة، "
            .'ونغير البطارية في فرعينا حين تضعف. ويمكنك رؤية كثير من قطعنا في فرعينا بأركاديا مول ومصر الجديدة قبل الشراء. '
            .'نوصل إلى كل المحافظات خلال 24 ساعة، مع الدفع عند الاستلام لتفحصها قبل الدفع، والإرجاع خلال 14 يومًا.',
        'was' => ['en' => null, 'ar' => null],
    ], $over), JSON_UNESCAPED_UNICODE)."\n";
}

function ldFile(string $lines): string
{
    $path = sys_get_temp_dir().'/ld-'.bin2hex(random_bytes(4)).'.jsonl';
    file_put_contents($path, $lines);

    return $path;
}

it('refuses a file that names a product id', function () {
    $path = ldFile(json_encode(['product_id' => 1, 'en' => 'x', 'ar' => 'y'])."\n");
    expect(Artisan::call('catalog:load-descriptions', ['file' => $path]))->toBe(1)
        ->and(Artisan::output())->toContain('keyed by WhatsApp code only');
    @unlink($path);
});

it('writes by WhatsApp code behind the title guard, keeps a text the team changed, and undoes all of it', function () {
    SideDdl::run(CatalogLoadDescriptionsCommand::backupDdl(), freshSnapshot: true);
    [$id, $wa, $brand, $model] = ldWatch();
    [$id2, $wa2, $brand2, $model2] = ldWatch();
    [$id3, $wa3, $brand3, $model3] = ldWatch();
    // the second product's title no longer names the model the file was made for: refused
    DB::table('catalog_product_translations')->where('product_id', $id2)->where('locale', 'en')->update(['title' => 'Another watch']);
    // the third product's English text was changed by the team after the file was made: English kept, Arabic written
    DB::table('catalog_product_translations')->where('product_id', $id3)->where('locale', 'en')->update(['long_description' => str_repeat('The team wrote this. ', 20)]);
    $path = ldFile(ldLine($wa, $brand, $model).ldLine($wa2, $brand2, $model2).ldLine($wa3, $brand3, $model3, ['was' => ['en' => sha1('Thin.'), 'ar' => null]])
        .ldLine('LD-NO-SUCH', $brand, $model));

    // a dry run writes nothing
    Artisan::call('catalog:load-descriptions', ['file' => $path]);
    expect(Artisan::output())->toContain('DRY RUN')
        ->and(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('long_description'))->toBe('Thin.');

    Artisan::call('catalog:load-descriptions', ['file' => $path, '--apply' => true]);
    $out = Artisan::output();
    expect($out)->toContain('no longer names '.$model2)->toContain('0 active products carry this code')
        ->and(T::str(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('long_description')))->toContain("the {$model}, has a clean dial")
        ->and(T::str(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->value('long_description')))->toContain('ساعة '.$model)
        ->and(DB::table('catalog_product_translations')->where('product_id', $id2)->where('locale', 'en')->value('long_description'))->toBe('Thin.')
        ->and(T::str(DB::table('catalog_product_translations')->where('product_id', $id3)->where('locale', 'en')->value('long_description')))->toStartWith('The team wrote this.')
        ->and(T::str(DB::table('catalog_product_translations')->where('product_id', $id3)->where('locale', 'ar')->value('long_description')))->toContain('ساعة '.$model3)
        ->and(DB::table(CatalogLoadDescriptionsCommand::BACKUP)->where('product_id', $id)->where('field', 'description.en')->value('old_value'))->toBe('Thin.');

    Artisan::call('catalog:load-descriptions', ['file' => $path, '--undo' => true]);
    expect(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('long_description'))->toBe('Thin.')
        ->and(DB::table('catalog_product_translations')->where('product_id', $id3)->where('locale', 'ar')->value('long_description'))->toBe('');
    @unlink($path);
});

it('holds a pair whose facts are not the record\'s, in both languages', function () {
    SideDdl::run(CatalogLoadDescriptionsCommand::backupDdl(), freshSnapshot: true);
    [$id, $wa, $brand, $model] = ldWatch();
    $line = ldLine($wa, $brand, $model);
    $bad = str_replace('has a clean dial', 'has a 42 mm case and a clean dial', $line);
    $path = ldFile($bad);
    Artisan::call('catalog:load-descriptions', ['file' => $path, '--apply' => true]);
    expect(Artisan::output())->toContain('HELD '.$wa)->toContain('case size the record does not hold')
        ->and(DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->value('long_description'))->toBe('Thin.');
    @unlink($path);
});
