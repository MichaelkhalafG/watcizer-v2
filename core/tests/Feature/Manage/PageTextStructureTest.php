<?php

use App\Domain\Content\SeoTextStructure;
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
 * ── A page text edited as the parts its layout draws (the review of 2026-11-05, item 43 B) ───────
 *
 * The stored text stays ONE Markdown field per language. The edit page shows it as fields because
 * `SeoTextStructure` reads the text into its parts and compiles the parts back into the SAME text — proven here on
 * the shape the seeder writes — and saving a page untouched from the fields writes the same words.
 */

const PTS_TEXT = <<<'MD'
The opening paragraph of the page, **bold** kept.

A second paragraph of the intro.

## Rolex at Watchizer

Watchizer carries Rolex across its families: Datejust, Submariner. If the model you want is not listed yet, ask for it and we add it.

- **Models listed:** 95.
- **Movement:** Automatic (63), Quartz (28).
- **Price range:** EGP 4,500 to EGP 9,800.

## Original, with its papers

Every Rolex on this page is original and comes with its papers.

- The first point.
- The second point, with a [link](/faq).
- The third point.

## Questions and answers

### Are the watches original?
Yes.

### How fast is delivery?
Within a day.
MD;

beforeEach(function () {
    actingAs(Staff::admin());
});

/** @return array{key: string, name: string} a brand page storefront 1 has */
function ptsBrand(): array
{
    foreach (T::rows(Props::of(get('/manage/storefronts/1/page-texts')->assertOk())['subjects']) as $subject) {
        if ($subject['type'] === 'brand') {
            return ['key' => T::str($subject['key']), 'name' => T::str($subject['name'])];
        }
    }
    throw new RuntimeException('storefront 1 has no brand page');
}

function ptsStored(string $key, string $locale): string
{
    return T::str(DB::table('core_seo_texts')->where('storefront_id', 1)->where('subject_type', 'brand')->where('subject_key', $key)->where('locale', $locale)->value('description'));
}

it('reads a text into its parts and compiles the parts back into the same text, character for character', function () {
    $parts = SeoTextStructure::parse(PTS_TEXT);
    expect($parts)->not->toBeNull();
    assert($parts !== null);
    expect($parts['intro'])->toBe("The opening paragraph of the page, **bold** kept.\n\nA second paragraph of the intro.")
        ->and($parts['at']['heading'] ?? null)->toBe('Rolex at Watchizer')
        ->and(array_column($parts['at']['facts'] ?? [], 'label'))->toBe(['Models listed', 'Movement', 'Price range'])
        ->and($parts['sections'])->toHaveCount(1)
        ->and($parts['sections'][0]['heading'])->toBe('Original, with its papers')
        ->and($parts['sections'][0]['bullets'])->toBe(['The first point.', 'The second point, with a [link](/faq).', 'The third point.'])
        ->and($parts['faq']['heading'] ?? null)->toBe('Questions and answers')
        ->and(SeoTextStructure::compile($parts))->toBe(SeoTextStructure::normal(PTS_TEXT));

    // an empty text is an empty set of parts; a text that is NOT in the shape is left to the Markdown editor
    expect(SeoTextStructure::parse(''))->toBe(['intro' => '', 'at' => null, 'sections' => [], 'faq' => null])
        ->and(SeoTextStructure::parse("## A section\n\n- a point\n\nText after the points."))->toBeNull()
        ->and(SeoTextStructure::parse("## Questions\n\n### One?\nYes.\n\n## A section after the questions\n\nText."))->toBeNull();
});

it('shows a page as fields, saves it untouched as the same words, and lets the admin hide a fact and reorder the points', function () {
    $brand = ptsBrand();
    $key = $brand['key'];
    put('/manage/storefronts/1/page-texts', ['type' => 'brand', 'key' => $key, 'en' => ['description' => PTS_TEXT, 'meta_description' => 'm'], 'ar' => ['description' => PTS_TEXT, 'meta_description' => 'm']])
        ->assertSessionHasNoErrors();
    $before = ptsStored($key, 'en');

    $fields = T::arr(getJson("/manage/storefronts/1/page-texts/structure?type=brand&key={$key}")->assertOk()->json());
    $en = T::arr($fields['en']);
    expect($en['structured'])->toBeTrue()->and(T::arr($en['at'])['heading'])->toBe('Rolex at Watchizer');
    $facts = T::rows(T::arr($en['at'])['facts']);
    // every fact the TEXT carries is shown; the labels are the text's (plus any the catalogue adds, hidden or shown)
    $shown = array_values(array_map(fn (array $f): string => T::str($f['label']), array_filter($facts, fn (array $f): bool => $f['shown'] === true)));
    expect($shown)->toContain('Models listed')->toContain('Movement')->toContain('Price range');

    $payload = fn (array $part, array $labels, array $bullets): array => [
        'intro' => $part['intro'],
        'at' => ['heading' => T::arr($part['at'])['heading'], 'paragraph' => T::arr($part['at'])['paragraph'], 'shown' => $labels],
        'sections' => [['heading' => T::rows($part['sections'])[0]['heading'], 'paragraph' => T::rows($part['sections'])[0]['paragraph'], 'bullets' => $bullets]],
        'faq' => $part['faq'],
    ];
    $bullets = array_values(array_map(fn (mixed $b): string => T::str($b), T::arr(T::rows($en['sections'])[0]['bullets'])));
    $textLabels = ['Models listed', 'Movement', 'Price range'];

    // saved untouched: the intro, the paragraphs, the points and the questions are the same words
    put('/manage/storefronts/1/page-texts', ['type' => 'brand', 'key' => $key, 'en' => ['meta_description' => 'm', 'structure' => $payload($en, $textLabels, $bullets)], 'ar' => ['meta_description' => 'm', 'description' => PTS_TEXT]])
        ->assertSessionHasNoErrors();
    $after = SeoTextStructure::parse(ptsStored($key, 'en'));
    $was = SeoTextStructure::parse($before);
    assert($after !== null && $was !== null);
    expect($after['intro'])->toBe($was['intro'])
        ->and($after['at']['paragraph'] ?? null)->toBe($was['at']['paragraph'] ?? null)
        ->and(array_column($after['at']['facts'] ?? [], 'label'))->toBe($textLabels)
        ->and($after['sections'])->toBe($was['sections'])
        ->and($after['faq'])->toBe($was['faq']);

    // a fact hidden, the points reordered and one removed: only that changes
    put('/manage/storefronts/1/page-texts', ['type' => 'brand', 'key' => $key, 'en' => ['meta_description' => 'm', 'structure' => $payload($en, ['Models listed', 'Price range'], [$bullets[2], $bullets[0]])], 'ar' => ['meta_description' => 'm', 'description' => PTS_TEXT]])
        ->assertSessionHasNoErrors();
    $changed = SeoTextStructure::parse(ptsStored($key, 'en'));
    assert($changed !== null);
    expect(array_column($changed['at']['facts'] ?? [], 'label'))->toBe(['Models listed', 'Price range'])
        ->and($changed['sections'][0]['bullets'])->toBe(['The third point.', 'The first point.'])
        ->and($changed['intro'])->toBe($was['intro'])
        ->and($changed['faq'])->toBe($was['faq']);
    // a fact LINE can never be typed: a label the page does not have is not written
    put('/manage/storefronts/1/page-texts', ['type' => 'brand', 'key' => $key, 'en' => ['meta_description' => 'm', 'structure' => $payload($en, ['Models listed', 'An invented fact'], $bullets)], 'ar' => ['meta_description' => 'm', 'description' => PTS_TEXT]])
        ->assertSessionHasNoErrors();
    expect(ptsStored($key, 'en'))->not->toContain('An invented fact');
    // the other language, sent as Markdown, was written as Markdown
    expect(SeoTextStructure::normal(ptsStored($key, 'ar')))->toBe(SeoTextStructure::normal(PTS_TEXT));
});

it('answers for a page the storefront does not have with 404, and the check command reads every text and writes nothing', function () {
    getJson('/manage/storefronts/1/page-texts/structure?type=brand&key=no-such-brand-here')->assertNotFound();

    $before = DB::table('core_seo_texts')->orderBy('id')->get(['id', 'description', 'updated_at'])->map(fn (object $r): array => (array) $r)->all();
    expect(Artisan::call('seo:structure-check'))->toBe(0);
    expect(DB::table('core_seo_texts')->orderBy('id')->get(['id', 'description', 'updated_at'])->map(fn (object $r): array => (array) $r)->all())->toBe($before);
});
