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

it('sends a name in both languages for every gender, colour and material the form offers', function () {
    /*
     * The rebuilt generator describes the product with three more facts than it used to — the
     * audience, the material and the colour (item 5, "keywords drawn from what the product IS").
     *
     * Same failure mode as the brands above, and the same test for it: the pickers hand the
     * generator an id, and an id the name map does not carry produces a sentence with a hole in
     * it. The difference is that these three arrive through `lookup_names`, so this pins the list
     * NAMES as well — dropping `materials` from that payload would not break a single type.
     */
    $props = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/products/create')->assertOk());

    $names = T::arr($props['lookup_names'] ?? null);
    $lookups = T::arr($props['lookups'] ?? null);

    $missing = [];
    foreach (['genders', 'colors', 'materials'] as $list) {
        // No message argument: `toHaveKey()` reads a second argument as the expected VALUE, not as
        // a description, and the failure it produces is about a type mismatch instead of the list.
        expect(array_keys($names))->toContain($list);

        $pairs = T::arr($names[$list] ?? null);
        foreach (T::arr($lookups[$list] ?? null) as $option) {
            $id = T::str(T::arr($option)['value'] ?? null);
            $pair = $pairs[$id] ?? null;
            if (! is_array($pair) || ! array_key_exists('ar', $pair) || ! array_key_exists('en', $pair)) {
                $missing[] = $list.':'.$id;
            }
        }
    }

    expect($missing)->toBe([], 'a picker offers ids the SEO name map does not carry');
});

it('describes every material field the block config declares, without a second list of keys', function () {
    /*
     * The form reads its materials out of the BLOCK definition — every field whose lookup is
     * `materials` — rather than from a list of spec keys written into the component. This is the
     * test for that: a family that gains a material field must reach the generator with no code
     * change at all.
     *
     * Asserted against the config, because the config is the thing that decides it.
     */
    $declared = [];
    foreach (T::arr(config('catalog.blocks', [])) as $family => $block) {
        foreach (T::arr(T::arr($block)['fields'] ?? null) as $field) {
            if (T::str(T::arr($field)['lookup'] ?? '') === 'materials') {
                $declared[] = $family.'.'.T::str(T::arr($field)['key'] ?? '');
            }
        }
    }

    expect($declared)->not->toBe([], 'no family declares a material field — this test proves nothing');

    $props = Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/products/create')->assertOk());
    $blocks = T::arr($props['blocks'] ?? null);

    $unreachable = [];
    foreach ($declared as $path) {
        [$family, $key] = explode('.', $path, 2);
        $fields = T::arr(T::arr($blocks[$family] ?? null)['fields'] ?? null);

        $found = false;
        foreach ($fields as $field) {
            if (T::str(T::arr($field)['key'] ?? '') === $key
                && T::str(T::arr($field)['lookup'] ?? '') === 'materials') {
                $found = true;
            }
        }

        if (! $found) {
            $unreachable[] = $path;
        }
    }

    expect($unreachable)->toBe([], 'these material fields never reach the browser, so the generator cannot describe them');
});

it('keeps the generator’s family words in step with the seven families that exist', function () {
    /*
     * `FAMILY_WORDS` in `resources/js/lib/seo.ts` is a hard-coded map, and it has to be: the
     * generator needs both locales AT ONCE, and `t()` answers only in the active one. A hard-coded
     * map is a second copy of something, so this is the test that keeps the copies honest.
     *
     * A family added to `Product::FAMILIES` without a word here would generate a description that
     * simply omits what the product is — quietly, on every product of that family.
     *
     * It MOVED here from `Products/Form.tsx` in the rebuild (item 5, 2026-09-19), because the
     * generator now needs the singular as well as the plural: a sentence says "a watch", a keyword
     * says "watches", and both live next to the grammar that consumes them.
     */
    $source = T::str(file_get_contents(resource_path('js/lib/seo.ts')));

    $start = strpos($source, 'export const FAMILY_WORDS');
    expect($start)->not->toBeFalse('FAMILY_WORDS is gone from the SEO generator');

    // A closing brace at the START of a line, and not the bare `};`: the TYPE annotation on
    // this constant contains one — the nested `{ one: string; many: string };` — so a search
    // for the bare brace ends the block inside the declaration and finds no families at all.
    $end = strpos($source, PHP_EOL.'};', (int) $start);
    $block = substr($source, (int) $start, (int) $end - (int) $start);

    $missing = [];
    $singularless = [];
    foreach (Product::FAMILIES as $family) {
        if (! str_contains($block, $family.':')) {
            $missing[] = $family;

            continue;
        }

        /*
         * Both numbers, in both languages — an empty singular is allowed only for `other`, which
         * has no noun and is described by its category instead.
         *
         * Matched across the whole ENTRY rather than one line, and quote-agnostic: the entry is
         * formatted as one line or four depending on how long the words are, and reading a single
         * line passed every family on one formatting and failed every family on the other.
         */
        $entry = substr($block, (int) strpos($block, $family.':'), 400);
        $singular = preg_match('#one:\s*(\S+?)\s*,#u', $entry, $found) === 1
            ? trim($found[1], "\'\"")
            : '';

        if ($family !== 'other' && $singular === '') {
            $singularless[] = $family;
        }
    }

    expect($missing)->toBe([], 'the SEO generator has no word for these families');
    expect($singularless)->toBe([], 'these families have no singular noun, so no sentence can be written about them');
});

it('offers the generator a model number and a title to work from', function () {
    // The two fields the generated sentence is built around. If either stopped being sent, the
    // button would still "work" and would write a sentence with nothing in it.
    $productId = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));

    $props = Props::of(actingAs(Staff::admin())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk());
    $product = T::arr($props['product'] ?? null);

    // `sku` and not `model_number`: the two columns were merged (item 4, 2026-09-19) and the form
    // sends one code. This assertion named the column that no longer travels.
    expect($product)->toHaveKey('sku')->toHaveKey('brand_id')->toHaveKey('search_keywords');

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
