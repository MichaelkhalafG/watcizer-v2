<?php

use App\Domain\Content\OriginalityClaims;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\T;

/*
 * No originality claim on the page of a brand whose products are all kept from search engines or all a matched Rolex
 * (Michael, 2026-11-14). The section, the "are they original?" question and the meta description's "Original" go;
 * the exchange condition ("in its original, unopened state") stays.
 */

const ORIGINALITY_TEXT = "## Rolex at Watchizer\n\nWatchizer carries Rolex.\n\n## Original, with its papers\n\nEvery Rolex on this page is original.\n\n- Exchange within 14 days.\n\n## Questions and answers\n\n### Are the Rolex watches at Watchizer original?\nYes. Each one comes with its serial number.\n\n### Can I exchange a watch?\nYes, within 14 days, with the watch in its original, unopened state.";

it('removes the three claims and nothing else', function () {
    $out = (string) OriginalityClaims::stripDescription(ORIGINALITY_TEXT);
    expect($out)->not->toContain('Original, with its papers')
        ->and($out)->not->toContain('Are the Rolex watches at Watchizer original?')
        ->and($out)->not->toContain('Every Rolex on this page is original')
        ->and($out)->toContain('## Rolex at Watchizer')
        ->and($out)->toContain('### Can I exchange a watch?')
        ->and($out)->toContain('in its original, unopened state');

    $ar = (string) OriginalityClaims::stripDescription("## رولكس\n\nنص.\n\n## أصلية ومعها أوراقها\n\nكل ساعة أصلية.\n\n## أسئلة وأجوبة\n\n### هل ساعات Rolex في Watchizer أصلية؟\nنعم.\n\n### هل يمكنني الاستبدال؟\nنعم.");
    expect($ar)->not->toContain('أصلية ومعها أوراقها')->and($ar)->not->toContain('أصلية؟')->and($ar)->toContain('هل يمكنني الاستبدال؟');

    expect(OriginalityClaims::stripMeta('Original Rolex watches in Egypt at Watchizer: Datejust.'))->toBe('Rolex watches in Egypt at Watchizer: Datejust.')
        ->and(OriginalityClaims::stripMeta('ساعات رولكس أصلية في مصر من Watchizer.'))->toBe('ساعات رولكس في مصر من Watchizer.')
        ->and(OriginalityClaims::stripMeta('Tissot watches in Egypt.'))->toBe('Tissot watches in Egypt.');
});

it('changes only the brands kept from search, backs each row up, and is a no-op the second time', function () {
    // every visible Rolex on the copy is a matched one: Rolex is a restricted brand on both shops
    DB::table('catalog_products as p')->join('catalog_brands as b', 'b.id', '=', 'p.brand_id')->where('b.slug', 'rolex')
        ->update(['p.search_class' => 'restricted']);
    DB::table('core_seo_texts')->where(['storefront_id' => 1, 'subject_type' => 'brand', 'subject_key' => 'rolex', 'locale' => 'en'])
        ->update(['description' => ORIGINALITY_TEXT, 'meta_description' => 'Original Rolex watches in Egypt at Watchizer.']);
    $tissot = T::row(DB::table('core_seo_texts')->where(['storefront_id' => 1, 'subject_type' => 'brand', 'subject_key' => 'tissot', 'locale' => 'en'])->first() ?? (object) ['description' => null]);

    Artisan::call('seo:strip-originality', ['--apply' => true, '--storefront' => [1]]);

    $rolex = T::row(DB::table('core_seo_texts')->where(['storefront_id' => 1, 'subject_type' => 'brand', 'subject_key' => 'rolex', 'locale' => 'en'])->first());
    expect(T::str($rolex->description))->not->toContain('Original, with its papers')
        ->and(T::str($rolex->meta_description))->toBe('Rolex watches in Egypt at Watchizer.')
        // backed up, once per row (a row backed up by an earlier run keeps its first copy)
        ->and(DB::table('bk_seo_originality_20261114')->where('id', T::int($rolex->id))->exists())->toBeTrue();
    $tissotAfter = DB::table('core_seo_texts')->where(['storefront_id' => 1, 'subject_type' => 'brand', 'subject_key' => 'tissot', 'locale' => 'en'])->value('description');
    expect($tissotAfter)->toBe($tissot->description ?? null);              // a brand not kept from search: untouched

    Artisan::call('seo:strip-originality', ['--storefront' => [1]]);
    expect(Artisan::output())->toContain('would change 0 text(s)');
    // the backup table is made by the deploy step (or the first --apply): DDL would commit this test's transaction
})->skip(fn () => ! Schema::hasTable('bk_seo_originality_20261114'), 'the backup table does not exist on this database yet (the deploy step creates it)');
