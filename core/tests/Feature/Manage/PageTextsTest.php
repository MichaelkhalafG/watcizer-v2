<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\SeoFacts;
use App\Domain\Content\SeoTexts;
use App\Http\Controllers\Manage\SeoTextController;
use App\Storefront\StorefrontCache;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\put;

/*
 * PAGE TEXTS (M1y, 2026-10-30, SEO item 5): the introduction and meta description of a brand or category page, per
 * storefront and language. The dashboard lists the storefront's own pages and saves both languages (logged); the v2
 * listing carries the text of the page it IS as `meta.context`, both languages, and nothing for a filtered view; the
 * seeder fills only real pages, keeps what the dashboard wrote and writes nothing without --apply.
 */

beforeEach(function () {
    DB::table(SeoTexts::TABLE)->delete();
    app(StorefrontCache::class)->flush(1);
});

/** @return array{key: string, name: string, depth: int} the first top-level category page of Watchizer */
function ptCategory(): array
{
    foreach (SeoTextController::categories(1) as $c) {
        if ($c['depth'] === 1) {
            return $c;
        }
    }
    throw new RuntimeException('no category page on storefront 1');
}

it('lists the storefront\'s brand and category pages and saves both languages, logged', function () {
    actingAs(Staff::admin());
    $category = ptCategory();

    $props = Props::of(get('/manage/storefronts/1/page-texts')->assertOk());
    $subjects = T::rows($props['subjects']);
    expect(array_values(array_unique(array_map(fn (array $s): string => T::str($s['type']), $subjects))))->toBe(['brand', 'category'])
        ->and(array_column($subjects, 'key'))->toContain($category['key'])
        ->and(Props::navKeys(get('/manage/storefronts/1/page-texts')))->toContain('page_texts');

    put('/manage/storefronts/1/page-texts', [
        'type' => 'category', 'key' => $category['key'],
        'en' => ['description' => "First paragraph.\n\nSecond.", 'meta_description' => 'An English meta.'],
        'ar' => ['description' => '', 'meta_description' => 'وصف عربي.'],
    ])->assertSessionHasNoErrors();

    $en = SeoTexts::for(1, 'category', $category['key'], 'en');
    $ar = SeoTexts::for(1, 'category', $category['key'], 'ar');
    expect($en['description'] ?? null)->toBe("First paragraph.\n\nSecond.")
        ->and($en['meta_description'] ?? null)->toBe('An English meta.')
        ->and(T::arr($ar))->toHaveKey('description')->and(T::arr($ar)['description'])->toBeNull()   // an empty field is stored as nothing
        ->and($ar['meta_description'] ?? null)->toBe('وصف عربي.')
        ->and(DB::table('core_activity_log')->where('subject_type', SeoTexts::TABLE)->where('action', ActivityLog::UPDATED)->count())->toBe(2);

    // a page the storefront does not have is refused, and nothing is written
    put('/manage/storefronts/1/page-texts', ['type' => 'brand', 'key' => 'no-such-brand', 'en' => ['description' => 'x']])
        ->assertSessionHasErrors('key');
    expect(DB::table(SeoTexts::TABLE)->where('subject_key', 'no-such-brand')->exists())->toBeFalse();
});

it('carries the page\'s own text on the v2 listing as meta.context, both languages, and none for a filtered view', function () {
    $category = ptCategory();
    $brand = SeoTextController::brands(1)[0];
    SeoTexts::save(1, 'category', $category['key'], ['en' => ['description' => 'Cat EN', 'meta_description' => 'Cat meta EN'], 'ar' => ['description' => 'تصنيف']], null);
    SeoTexts::save(1, 'brand', $brand['key'], ['en' => ['meta_description' => 'Brand meta']], null);
    $brandId = T::int(DB::table('catalog_brands')->where('slug', $brand['key'])->value('id'));

    $cat = getJson('/api/v2/watchizer/products?per_page=1&category='.$category['key'])->assertOk();
    expect($cat->json('meta.context.type'))->toBe('category')
        ->and($cat->json('meta.context.key'))->toBe($category['key'])
        ->and($cat->json('meta.context.en.description'))->toBe('Cat EN')
        ->and($cat->json('meta.context.en.meta_description'))->toBe('Cat meta EN')
        ->and($cat->json('meta.context.ar.description'))->toBe('تصنيف');

    // by slug and by id (the storefront sends the id), the same brand text
    foreach ([$brand['key'], (string) $brandId] as $token) {
        $res = getJson('/api/v2/watchizer/products?per_page=1&brand='.$token)->assertOk();
        expect($res->json('meta.context.key'))->toBe($brand['key'])
            ->and($res->json('meta.context.en.meta_description'))->toBe('Brand meta')
            ->and($res->json('meta.context.ar'))->toBeNull();
    }

    // a category AND a brand is a filtered view, not a page; so is the whole catalogue
    expect(getJson('/api/v2/watchizer/products?per_page=1&category='.$category['key'].'&brand='.$brand['key'])->assertOk()->json('meta.context'))->toBeNull()
        ->and(getJson('/api/v2/watchizer/products?per_page=1')->assertOk()->json('meta.context'))->toBeNull();

    // the other storefront's text is not this one's
    expect(SeoTexts::bilingual(2, 'category', $category['key']))->toBeNull();
});

it('seeds the prepared texts only onto real pages, keeps a written one, and writes nothing on a dry run', function () {
    $rolex = collect(SeoTextController::brands(1))->firstWhere('key', 'rolex');
    expect($rolex)->not->toBeNull();   // the dev data has Rolex on Watchizer; the seeder file names it
    SeoTexts::save(1, 'brand', 'rolex', ['en' => ['description' => 'Typed in the dashboard']], null);

    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer']);
    expect(DB::table(SeoTexts::TABLE)->count())->toBe(1);

    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--apply' => true]);
    $output = Artisan::output();
    expect(SeoTexts::for(1, 'brand', 'rolex', 'en')['description'] ?? null)->toBe('Typed in the dashboard')
        ->and($output)->toContain('keep   brand rolex');

    // every row written is a page the storefront has, with the shop's name filled in
    $pages = array_merge(
        array_map(fn (array $b) => 'brand:'.$b['key'], SeoTextController::brands(1)),
        array_map(fn (array $c) => 'category:'.$c['key'], SeoTextController::categories(1)),
    );
    foreach (DB::table(SeoTexts::TABLE)->where('storefront_id', 1)->get() as $raw) {
        $row = T::row($raw);
        expect($pages)->toContain(T::str($row->subject_type).':'.T::str($row->subject_key))
            ->and(T::str($row->meta_description ?? ''))->not->toContain('{shop}');
    }
    $watches = SeoTexts::for(1, 'category', 'watches', 'en');
    if ($watches !== null) {
        expect(T::str($watches['meta_description']))->toContain('Watchizer');
    }

    // a second run changes nothing
    $before = DB::table(SeoTexts::TABLE)->count();
    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--apply' => true]);
    expect(DB::table(SeoTexts::TABLE)->count())->toBe($before);
});

// ── the rewrite of 2026-11-05 (the twelve updates, Part 6) ──

/** The names a payment method goes by; none may appear in a prepared text (the developer's rule, 2026-11-05). */
const PT_PAYMENT_WORDS = [
    'visa', 'mastercard', 'meeza', 'cash on delivery', 'vodafone', 'orange money', 'valu', 'apple pay', 'bank transfer',
    'instapay', 'fawry', 'installment', 'instalment', 'wallet payment',
    // whole names: "ماستر" alone is in "جي إم تي ماستر" (the GMT-Master) and "ميزة" alone is the word "feature"
    'فيزا', 'ماستركارد', 'ماستر كارد', 'بطاقة ميزة', 'عند الاستلام', 'فودافون', 'أورانج موني', 'ڤاليو', 'فاليو', 'تحويل بنكي', 'إنستاباي', 'تقسيط',
];

it('writes every prepared text in the target shape, and names no payment method in any of them', function () {
    $failures = [];
    $texts = 0;
    foreach (['brands', 'categories'] as $file) {
        $decoded = T::arr(json_decode(T::str(file_get_contents(database_path('seeders/content/seo/'.$file.'.json'))), true));
        foreach ($decoded as $key => $byLocale) {
            if ($key === '_about') {
                continue;
            }
            foreach (['en', 'ar'] as $locale) {
                $text = T::str(T::arr(T::arr($byLocale)[$locale] ?? null)['description'] ?? '');
                $texts++;
                $where = "{$file} {$key} {$locale}";
                if (preg_match_all('/^## /mu', $text) < 2) {
                    $failures[] = "{$where}: fewer than two sections";
                }
                if (substr_count($text, '{facts}') !== 1) {
                    $failures[] = "{$where}: the facts token is not there exactly once";
                }
                if (preg_match_all('/^- /mu', $text) < 3) {
                    $failures[] = "{$where}: fewer than three bullets";
                }
                if (preg_match_all('/^### .*[?؟]$/mu', $text) < 4) {
                    $failures[] = "{$where}: fewer than four questions";
                }
                if (! str_contains($text, '](/faq)')) {
                    $failures[] = "{$where}: no link to the FAQ where payment is mentioned";
                }
                if (preg_match('/<[a-z!\/]/iu', $text) === 1) {
                    $failures[] = "{$where}: carries HTML";
                }
                foreach (PT_PAYMENT_WORDS as $word) {
                    if (mb_stripos($text, $word) !== false) {
                        $failures[] = "{$where}: names a payment method ({$word})";
                    }
                }
            }
        }
    }

    expect($texts)->toBe(88)          // 19 brands + 25 categories, in two languages: the 174 page texts of the two shops
        ->and($failures)->toBe([]);
});

/*
 * ── The numbers in the texts are FIXED, and of two kinds (the developer's decision, 2026-11-05) ─────────────
 *
 *  (a) the brand's own catalogue — official sources only, each figure with its source and the date it was read
 *      (brand_sources.json); nothing estimated, nothing without a source;
 *  (b) OUR catalogue — every model a page lists on that storefront, in stock or not; a category is the sum of its
 *      brands (facts.json, written by `seo:count-facts`).
 *
 * `seo:seed-texts` prints what the two FILES say, never what the database says at that moment.
 *
 * WHAT REACHES THE PAGE (the developer's review, 2026-11-05): the brand's range presented as available at the shop
 * (the `page` block: family names, plain facts) and our own numbers — with NO date, NO outbound link, NO source note
 * and NO comparison between the brand's catalogue and ours; each language uses its own names.
 */

/**
 * Point the two content files at a scratch folder for one test.
 *
 * @return array{0: string, 1: string} [facts.json, brand_sources.json]
 */
function ptNumberFiles(): array
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pt-numbers-'.bin2hex(random_bytes(4));
    mkdir($dir);
    $files = [$dir.DIRECTORY_SEPARATOR.'facts.json', $dir.DIRECTORY_SEPARATOR.'brand_sources.json'];
    config(['storefront.seo_facts_file' => $files[0], 'storefront.seo_brand_sources_file' => $files[1]]);

    return $files;
}

it('counts OUR catalogue as every model a page lists, in stock or not, and a category as the sum of its brands', function () {
    $checked = 0;
    foreach (SeoTextController::brands(1) as $page) {
        $brand = DB::table('catalog_brands')->where('slug', $page['key'])->value('id');
        if ($brand === null) {
            continue;
        }
        $all = fn (): Builder => DB::table('catalog_products as p')
            ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))
            ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.brand_id', $brand);
        $facts = SeoFacts::count('watchizer', 'brand', $page['key']);

        // every real catalogue product of the brand here — NOT the smaller number that is in stock today
        expect($facts['models'])->toBe($all()->count())
            ->and(T::int($facts['models']))->toBeGreaterThanOrEqual($all()->where('p.in_stock', 1)->count());
        if (++$checked === 3) {
            break;
        }
    }
    expect($checked)->toBeGreaterThan(0);

    $category = SeoFacts::count('watchizer', 'category', ptCategory()['key']);
    $sum = 0;
    foreach (T::arr($category['brands'] ?? []) as $brand) {
        $sum += T::int(T::arr($brand)['count']);
    }
    expect(T::int($category['models']))->toBe($sum)->toBeGreaterThan(0);
});

it('seo:count-facts compares on a dry run and writes only with --write; the seeder then prints THOSE numbers, of both kinds', function () {
    [$ours, $brands] = ptNumberFiles();

    Artisan::call('seo:count-facts');
    expect(is_file($ours))->toBeFalse()
        ->and(Artisan::output())->toContain('Dry run');

    Artisan::call('seo:count-facts', ['--write' => true]);
    $doc = T::arr(json_decode(T::str(file_get_contents($ours)), true));
    expect($doc['counted_on'])->toBe(now()->toDateString())
        ->and(T::str($doc['counted_from']))->toContain('database')
        ->and(array_keys(T::arr($doc['method'])))->toBe(['models', 'category_total', 'types_brands', 'movements_colors_materials', 'price', 'refresh']);
    Artisan::call('seo:count-facts');
    expect(Artisan::output())->toContain('0 differ from the recorded numbers');

    // a brand page of Watchizer that has models
    $pages = T::arr(T::arr(T::arr($doc['storefronts'])['watchizer'])['brand']);
    $key = '';
    foreach ($pages as $candidate => $facts) {
        if (T::int(T::arr($facts)['models']) > 0) {
            $key = (string) $candidate;
            break;
        }
    }
    expect($key)->not->toBe('');

    // (a) the brand's range: the RECORD (a sourced figure with its count, its source and its date) and the PAGE
    //     block (the family names and one plain fact) — only the page block may reach a page
    file_put_contents($brands, json_encode([$key => [
        'name' => 'Brand X', 'checked_on' => '2026-11-05',
        'figures' => [
            ['label' => ['en' => 'Collections', 'ar' => 'المجموعات'], 'value' => ['en' => '7 — Alpha, Beta', 'ar' => '7 — Alpha، Beta'], 'source' => 'https://brand.example/collections', 'how' => 'counted from the list'],
        ],
        'page' => [
            'families' => ['Alpha', 'Beta'],
            'facts' => [['label' => ['en' => 'Movements in the range', 'ar' => 'أنواع الحركة في المجموعة'], 'value' => ['en' => 'automatic and quartz', 'ar' => 'أوتوماتيك وكوارتز']]],
        ],
    ]], JSON_UNESCAPED_UNICODE));
    $names = ['en' => '', 'ar' => ''];
    foreach (SeoTextController::brands(1) as $page) {
        if ($page['key'] === $key) {
            $names = $page['names'];
        }
    }
    // (b) FIXED: the recorded number is changed by hand — the text must print the FILE's number, not a fresh count
    $pages[$key] = ['models' => 4321] + T::arr($pages[$key]);
    $doc['storefronts'] = ['watchizer' => ['brand' => $pages, 'category' => []]];
    $doc['counted_on'] = '2026-01-02';
    file_put_contents($ours, json_encode($doc, JSON_UNESCAPED_UNICODE));

    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--overwrite' => true, '--apply' => true]);
    $output = Artisan::output();
    $en = T::str(SeoTexts::for(1, 'brand', $key, 'en')['description'] ?? '');
    $ar = T::str(SeoTexts::for(1, 'brand', $key, 'ar')['description'] ?? '');

    // the brand's range, as available at the shop — and our own number from the FILE
    $section = fn (string $text): string => T::str(preg_split('/\n## /u', explode("## {$names['en']} at Watchizer", $text, 2)[1] ?? '')[0] ?? '');
    $sectionEn = $section($en);
    expect($en)->toContain("## {$names['en']} at Watchizer")
        ->and($sectionEn)->toContain("Watchizer carries {$names['en']} across its families: Alpha, Beta.")
        ->toContain('If the model you want is not listed yet, ask for it and we add it.')
        ->toContain('- **Models listed:** 4321.')
        ->toContain('- **Movements in the range:** automatic and quartz.')
        ->and(str_contains($en, '{facts}'))->toBeFalse();

    // NOT on the page: a date, an outbound link, a source, the brand's own count, a "theirs vs ours" comparison
    foreach (['2026', 'http', 'brand.example', '7 —', 'catalogue', 'counted', 'as read', 'in numbers'] as $never) {
        expect(str_contains($sectionEn, $never))->toBeFalse("the section must not carry \"{$never}\"");
    }
    // each language uses its own names: no Arabic letter in the English section, and the Arabic one names the brand in Arabic
    expect(preg_match('/\p{Arabic}/u', $sectionEn))->toBe(0)
        ->and($ar)->toContain("## {$names['ar']} في Watchizer")->toContain('**الموديلات المعروضة:** 4321.')
        ->and(str_contains($ar, 'http'))->toBeFalse();

    // a page the files have no numbers for is written WITHOUT the section, and said out loud
    $category = ptCategory()['key'];
    expect($output)->toContain("no numbers for category {$category} on watchizer")
        ->and(str_contains(T::str(SeoTexts::for(1, 'category', $category, 'en')['description'] ?? ''), ' at Watchizer'))->toBeFalse();

    @unlink($ours);
    @unlink($brands);
    @rmdir(dirname($ours));
});

it('keeps the two content files honest: every printed brand figure has its official source and date, and our numbers say how they were counted', function () {
    $failures = [];

    $brands = SeoFacts::read(database_path(SeoFacts::BRAND_FILE));
    $texts = array_diff(array_keys(SeoFacts::read(database_path('seeders/content/seo/brands.json'))), ['_about']);
    foreach ($texts as $key) {
        if (! isset($brands[$key])) {
            $failures[] = "{$key}: a brand with a page text has no entry in brand_sources.json";
        }
    }
    $withFigures = 0;
    foreach ($brands as $key => $raw) {
        if ($key === '_about') {
            continue;
        }
        $entry = T::arr($raw);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', T::str($entry['checked_on'] ?? '')) !== 1) {
            $failures[] = "{$key}: no checked_on date";
        }
        $figures = T::arr($entry['figures'] ?? []);
        $withFigures += $figures === [] ? 0 : 1;
        foreach ($figures as $i => $rawFigure) {
            $figure = T::arr($rawFigure);
            if (! str_starts_with(T::str($figure['source'] ?? ''), 'https://')) {
                $failures[] = "{$key} figure {$i}: no https source";
            }
            if (trim(T::str($figure['how'] ?? '')) === '') {
                $failures[] = "{$key} figure {$i}: does not say how it was read or counted";
            }
            foreach (['label', 'value'] as $field) {
                foreach (['en', 'ar'] as $locale) {
                    if (trim(T::str(T::arr($figure[$field] ?? [])[$locale] ?? '')) === '') {
                        $failures[] = "{$key} figure {$i}: {$field}.{$locale} is empty";
                    }
                }
            }
        }
        // what is NOT recorded is written down with its reason
        if ($figures === [] && T::arr($entry['left_out'] ?? []) === []) {
            $failures[] = "{$key}: no figure and no reason";
        }
        // THE PAGE block: only what the record backs — a family name must appear in a sourced figure of this entry,
        // and a brand with no figure presents no range at all; nothing in it carries a link or a year
        $page = T::arr($entry['page'] ?? []);
        $record = '';
        foreach ($figures as $rawFigure) {
            $record .= ' '.T::str(T::arr(T::arr($rawFigure)['value'] ?? [])['en'] ?? '');
        }
        foreach (T::arr($page['families'] ?? []) as $family) {
            if (! str_contains($record, T::str($family))) {
                $failures[] = "{$key}: the family \"".T::str($family).'" is not in any sourced figure of this brand';
            }
        }
        if ($figures === [] && (T::arr($page['families'] ?? []) !== [] || T::arr($page['facts'] ?? []) !== [])) {
            $failures[] = "{$key}: a page block with no sourced figure behind it";
        }
        $printed = (string) json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (str_contains($printed, 'http') || preg_match('/\b20\d\d\b/', $printed) === 1) {
            $failures[] = "{$key}: the page block carries a link or a year";
        }
        foreach (T::arr($page['facts'] ?? []) as $i => $rawFact) {
            foreach (['label', 'value'] as $field) {
                foreach (['en', 'ar'] as $locale) {
                    if (trim(T::str(T::arr(T::arr($rawFact)[$field] ?? [])[$locale] ?? '')) === '') {
                        $failures[] = "{$key} page fact {$i}: {$field}.{$locale} is empty";
                    }
                }
            }
        }
    }

    $ours = SeoFacts::read(database_path(SeoFacts::OURS_FILE));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', T::str($ours['counted_on'] ?? '')) !== 1 || trim(T::str($ours['counted_from'] ?? '')) === '') {
        $failures[] = 'facts.json: no counted_on / counted_from';
    }
    foreach (['models', 'category_total', 'price', 'refresh'] as $method) {
        if (trim(T::str(T::arr($ours['method'] ?? [])[$method] ?? '')) === '') {
            $failures[] = "facts.json: method.{$method} is missing";
        }
    }
    $shops = T::arr($ours['storefronts'] ?? []);
    foreach (['watchizer', 'brandfashion'] as $code) {
        $pages = T::arr($shops[$code] ?? []);
        if (T::arr($pages['brand'] ?? []) === [] || T::arr($pages['category'] ?? []) === []) {
            $failures[] = "facts.json: nothing counted for {$code}";
        }
        // a category's total is the sum of its brands' counts
        foreach (T::arr($pages['category'] ?? []) as $key => $rawFacts) {
            $facts = T::arr($rawFacts);
            $sum = 0;
            foreach (T::arr($facts['brands'] ?? []) as $brand) {
                $sum += T::int(T::arr($brand)['count']);
            }
            if (T::int($facts['models']) !== $sum) {
                $failures[] = "facts.json: {$code} category {$key} is not the sum of its brands";
            }
        }
    }

    expect($failures)->toBe([])
        ->and($withFigures)->toBeGreaterThan(0);
});

it('never lets --overwrite replace a text the dashboard wrote, unless --overwrite-edited is named', function () {
    $admin = Staff::admin();
    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--apply' => true]);
    $seeded = T::str(SeoTexts::for(1, 'category', 'watches', 'en')['description'] ?? '');
    expect($seeded)->not->toBe('');

    // somebody edits ONE page in the dashboard, after the seed
    actingAs($admin)->put('/manage/storefronts/1/page-texts', [
        'type' => 'category', 'key' => 'watches',
        'en' => ['description' => "## Our own words\n\nTyped by the team.", 'meta_description' => 'Ours.'],
        'ar' => ['description' => 'كلماتنا.', 'meta_description' => 'لنا.'],
    ])->assertSessionHasNoErrors();
    $others = DB::table(SeoTexts::TABLE)->where('storefront_id', 1)->where('subject_key', '!=', 'watches')->count();

    // the dry run names it, and writes nothing
    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--overwrite' => true]);
    expect(Artisan::output())->toContain('edited category watches')->toContain('left alone');

    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--overwrite' => true, '--apply' => true]);
    $output = Artisan::output();
    expect(SeoTexts::for(1, 'category', 'watches', 'en')['description'] ?? null)->toBe("## Our own words\n\nTyped by the team.")
        ->and($output)->toContain('edited category watches')
        ->and($output)->toContain('edited in the dashboard and left alone 1')
        // every OTHER page was rewritten — as nobody, so the next run may rewrite it again
        ->and(DB::table(SeoTexts::TABLE)->where('storefront_id', 1)->where('subject_key', '!=', 'watches')->whereNull('updated_by')->count())->toBe($others);

    // the explicit flag replaces it too; alone it is refused
    expect(Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--overwrite-edited' => true, '--apply' => true]))->toBe(1);
    Artisan::call('seo:seed-texts', ['--storefront' => 'watchizer', '--overwrite' => true, '--overwrite-edited' => true, '--apply' => true]);
    expect(T::str(SeoTexts::for(1, 'category', 'watches', 'en')['description'] ?? ''))->toContain('## Questions and answers')
        ->and(DB::table(SeoTexts::TABLE)->where('storefront_id', 1)->whereNotNull('updated_by')->count())->toBe(0);
});

it('accepts a long Markdown text from the screen, and hands the editor its image host', function () {
    $category = ptCategory();
    $long = "## Section\n\n".str_repeat("- A bullet with a real specific.\n", 400);      // ~13,000 characters: past the old 5,000 limit
    actingAs(Staff::admin())->put('/manage/storefronts/1/page-texts', [
        'type' => 'category', 'key' => $category['key'],
        'en' => ['description' => $long, 'meta_description' => 'm'],
        'ar' => ['description' => 'نص', 'meta_description' => 'م'],
    ])->assertSessionHasNoErrors();

    expect(T::str(SeoTexts::for(1, 'category', $category['key'], 'en')['description'] ?? ''))->toBe(trim($long))
        ->and(T::str(Props::of(get('/manage/storefronts/1/page-texts')->assertOk())['assetBase'] ?? ''))->toStartWith('http');
});
