<?php

use App\Domain\Catalog\SpecBlocks;
use App\Models\Catalog\Product;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The SEO generator's inputs (item 8, developer 2026-09-18).
 *
 * ── What it is for ──────────────────────────────────────────────────────────────────────────
 *
 * The developer's words: *"It exists to prevent human error — the team will not write 7,700 meta
 * descriptions."* One click fills the SEO title, the description and the keywords, in both
 * languages, from the product's own data, and every field stays editable.
 *
 * ── What is testable here, and what is not ──────────────────────────────────────────────────
 *
 * The composition lives in `resources/js/lib/seo.ts` and this tree has no JavaScript test runner,
 * so the sentences it writes are not asserted here. What IS asserted is the thing that would break
 * it silently: the generator writes an Arabic sentence and an English one, so it needs every brand
 * and every category name in BOTH languages, and the pickers hand it an id. A picker offering an id
 * the name map does not carry produces a sentence with a hole in it, and nobody would notice until
 * a customer read it.
 */

it('sends a name in both languages for every brand the picker offers', function () {
    $props = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/products/create')->assertOk());

    $names = T::arr($props['brand_names'] ?? null);
    $options = T::arr($props['brands'] ?? null);

    expect($options)->not->toBe([], 'the brand picker is empty — this test proves nothing');

    $missing = [];
    foreach ($options as $option) {
        $id = T::str(T::arr($option)['value'] ?? null);
        $pair = $names[$id] ?? null;
        if (! is_array($pair) || ! array_key_exists('ar', $pair) || ! array_key_exists('en', $pair)) {
            $missing[] = $id;
        }
    }

    expect($missing)->toBe([], 'the brand picker offers ids the name map does not carry');
});

it('sends a name in both languages for every category a section offers', function () {
    $props = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/products/create')->assertOk());

    $names = T::arr($props['category_names'] ?? null);
    $sections = T::arr($props['sections'] ?? null);

    expect($sections)->not->toBe([], 'no storefront sections');

    $missing = [];
    foreach ($sections as $section) {
        foreach (T::arr(T::arr($section)['categories'] ?? null) as $option) {
            $id = T::str(T::arr($option)['value'] ?? null);
            $pair = $names[$id] ?? null;
            if (! is_array($pair) || ! array_key_exists('ar', $pair) || ! array_key_exists('en', $pair)) {
                $missing[] = $id;
            }
        }
    }

    // Across EVERY storefront's section, because the form renders one per storefront and the
    // generator may be asked about whichever primary category is chosen in any of them.
    expect($missing)->toBe([], 'a section offers category ids the name map does not carry');
});

it('keeps the form’s family words in step with the seven families that exist', function () {
    /*
     * `FAMILY_NAMES` in `Products/Form.tsx` is a hard-coded map, and it has to be: the generator
     * needs both locales AT ONCE, and `t()` answers only in the active one. A hard-coded map is a
     * second copy of something, so this is the test that keeps the copies honest.
     *
     * A family added to `Product::FAMILIES` without a word here would generate a description that
     * simply omits the category — quietly, on every product of that family.
     */
    $source = T::str(file_get_contents(resource_path('js/pages/Manage/Products/Form.tsx')));

    $start = strpos($source, 'const FAMILY_NAMES');
    expect($start)->not->toBeFalse('FAMILY_NAMES is gone from the product form');

    $end = strpos($source, '};', (int) $start);
    $block = substr($source, (int) $start, (int) $end - (int) $start);

    $missing = [];
    foreach (Product::FAMILIES as $family) {
        if (! str_contains($block, $family.':')) {
            $missing[] = $family;
        }
    }

    expect($missing)->toBe([], 'the product form has no word for these families');
});

it('offers the generator a model number and a title to work from', function () {
    // The two fields the generated sentence is built around. If either stopped being sent, the
    // button would still "work" and would write a sentence with nothing in it.
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));

    $props = Props::of(actingAs(Staff::admin())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk());
    $product = T::arr($props['product'] ?? null);

    expect($product)->toHaveKey('model_number')->toHaveKey('brand_id')->toHaveKey('search_keywords');

    $translations = T::arr($product['translations'] ?? null);
    foreach (['title', 'meta_title', 'meta_description'] as $field) {
        expect($translations)->toHaveKey($field);
        expect(T::arr($translations[$field]))->toHaveKey('ar')->toHaveKey('en');
    }
});

it('names the same seven families on the server, so the two lists cannot drift apart', function () {
    // The server's own list, which the picker renders. `SpecBlocks` is unrelated to families and is
    // imported only to keep this file's use-list honest about what it touches.
    expect(SpecBlocks::TYPES)->toBeArray();

    $props = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/products/create')->assertOk());
    $offered = [];
    foreach (T::arr($props['families'] ?? null) as $option) {
        $offered[] = T::str(T::arr($option)['value'] ?? null);
    }

    sort($offered);
    $expected = Product::FAMILIES;
    sort($expected);

    expect($offered)->toBe($expected);
});
