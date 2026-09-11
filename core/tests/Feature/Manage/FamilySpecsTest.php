<?php

use App\Domain\Catalog\FamilyForCategory;
use App\Domain\Catalog\SpecBlocks;
use App\Models\Catalog\Product;
use App\Transform\FamilyResolver;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * THE FAMILY-RESOLUTION PROOF the deliverable asks for.
 *
 * The rule: a product's family — and therefore which specification block the form shows — is
 * derived from its CATEGORY, by the same class and the same configuration the transform uses. The
 * legacy dashboard compared the category against hard-coded ids and had to be hotfixed
 * (`fix(legacy-dashboard): family check via category_type parent (was hardcoded ids)`); this file
 * is what stops that recurring.
 *
 * Four claims, in the order the deliverable names them:
 *
 *   1. a WATCH category shows watch specs,
 *   2. a FASHION category does not,
 *   3. a NEW category the team invents resolves SENSIBLY — both ways: a new watch-natured root
 *      yields `watch`, and a root whose name the rule cannot read falls to the configured default
 *      instead of guessing,
 *   4. the spec keys ROUND-TRIP: a family's own JSON keys never resolve to a different family,
 *      which is what keeps a dashboard-created product in the family a rehearsal would give it.
 */

it('gives a product in the Watches branch the watch family, and the watch block', function () {
    $families = app(FamilyForCategory::class);
    $watches = CatalogFixture::watchesRoot();

    // The real transform-created node, by its LEGACY KEY — never by a hard-coded clean id, which
    // differs between a rehearsal restore and production (§2.9.6 rule 3).
    expect($families->forNode($watches))->toBe('watch');

    $block = SpecBlocks::for('watch');
    expect($block)->not->toBeNull();
    if ($block === null) {
        return;
    }

    expect($block['table'])->toBe('watch_specs')
        ->and(array_column($block['fields'], 'key'))->toContain('movement_type_id')
        ->and(array_column($block['fields'], 'key'))->toContain('case_size');
});

it('gives a product in the Fashion branch a family with NO watch block', function () {
    $families = app(FamilyForCategory::class);
    $fashion = CatalogFixture::fashionRoot();

    $family = $families->forNode($fashion);

    expect($family)->not->toBe('watch')
        // `fashion` is the configured default and deliberately has no block: inventing attributes
        // for "did not match anything" would make the form lie about the data.
        ->and(SpecBlocks::for($family))->toBeNull();
});

it('resolves a sub type under Fashion by NAME, exactly as the transform does', function () {
    // This is the case that makes the data what it is: 212 of the 464 products are `bag`, and not
    // one of them got there from its ROOT — they got there from the sub type name `Bags` matching
    // `config('transform.family.sub_type_names')`. A dashboard that read only the root would
    // silently re-classify every one of them on the first save.
    $families = app(FamilyForCategory::class);
    $fashion = CatalogFixture::fashionRoot();
    $bags = CatalogFixture::child($fashion, 'Bags', 'حقائب');

    $bagBlock = SpecBlocks::for('bag');
    expect($families->forNode($bags['id']))->toBe('bag')
        ->and($bagBlock)->not->toBeNull();
    if ($bagBlock === null) {
        return;
    }
    expect(array_column($bagBlock['fields'], 'key'))->toContain('strap_length_cm');
});

it('resolves a NEW category the team invents — sensibly, in both directions', function () {
    $families = app(FamilyForCategory::class);

    // (a) A brand-new ROOT whose English name is in the configured watch list → watch.
    //     The team inventing "Watches" for Brand Fashion gets watch specs without a code change.
    $newWatchRoot = CatalogFixture::root('Watches', 'ساعات براند فاشون');
    expect($families->forNode($newWatchRoot['id']))->toBe('watch');

    // (b) A brand-new root the rule has never heard of → the configured DEFAULT, not a guess.
    //     "Shoes" is not a watch, is not in `sub_type_names`, and carries no `extra_attributes`
    //     prefix; the honest answer is the fallback family, and the form then shows no block.
    $shoes = CatalogFixture::root('Shoes', 'أحذية');
    $shoesFamily = $families->forNode($shoes['id']);
    expect($shoesFamily)->toBe(config('transform.family.default'))
        ->and($shoesFamily)->not->toBe('watch');

    // (c) A new CHILD of the new watch root is still a watch: the root is the family-bearing
    //     level, and a deeper tree than legacy's two levels keeps working.
    $complication = CatalogFixture::child($newWatchRoot['id'], 'Moonphase', 'مراحل القمر');
    expect($families->forNode($complication['id']))->toBe('watch');

    // (d) …and a child of the unknown root inherits the same fallback rather than the parent's
    //     name accidentally meaning something.
    $sneakers = CatalogFixture::child($shoes['id'], 'Sneakers', 'سنيكرز');
    expect($families->forNode($sneakers['id']))->toBe($shoesFamily);
});

it('reads the ROOT off the materialised path, not the parent chain or the depth', function () {
    // Three levels deep — deeper than legacy's (category type → sub type) — so a resolver that
    // assumed `depth === 1` or walked one `parent_id` hop would get the wrong answer.
    $watches = CatalogFixture::root('Watches', 'ساعات للاختبار');
    $level2 = CatalogFixture::child($watches['id'], 'Automatic', 'أوتوماتيك');
    $level3 = CatalogFixture::child($level2['id'], 'Skeleton', 'سكيلتون');

    $names = FamilyForCategory::namesFor($level3['id']);

    expect($names['root_id'])->toBe($watches['id'])
        ->and($names['root_en'])->toBe('Watches')
        ->and($names['node_en'])->toBe('Skeleton')
        ->and(app(FamilyForCategory::class)->forNode($level3['id']))->toBe('watch')
        ->and(CatalogFixture::node($level3['id'])->depth)->toBe(3);
});

it('refuses to guess when a node has no materialised path', function () {
    // The tree writer is the only thing allowed to set `path`. A row with a broken one is a
    // corrupted tree, and treating it as a root is how a bag silently becomes a watch — so it
    // throws instead.
    $node = CatalogFixture::root('Broken', 'مكسور');
    DB::table('storefront_categories')->where('id', $node['id'])->update(['path' => '']);

    expect(fn () => FamilyForCategory::namesFor($node['id']))
        ->toThrow(RuntimeException::class, 'no materialised path');
});

it('keeps every spec key inside its own family — the round trip that stops re-classification', function () {
    // The contract `config/catalog.php` states in its own header: a family's JSON keys must never
    // make the resolver return a DIFFERENT family. If `bag_type` resolved to `wallet`, a product
    // the dashboard saved as a bag would come back from a rehearsal as a wallet.
    //
    // Checked through the transform's own resolver, with `extra_attributes` as its only input —
    // which is exactly how the transform reads a legacy product that has no category.
    /** @var array<string, mixed> $config */
    $config = config('transform.family', []);
    $resolver = new FamilyResolver($config);
    $offenders = [];

    foreach (SpecBlocks::all() as $family => $block) {
        if ($block['table'] !== 'specs') {
            continue; // watch specs are columns, not JSON keys
        }
        foreach ($block['fields'] as $field) {
            $resolved = $resolver->resolve('', json_encode([$field['key'] => 1]) ?: null, '');
            // The key either resolves to its own family or to the default (a generic dimension
            // like `width_cm` has no prefix and should not claim a family at all).
            if ($resolved !== $family && $resolved !== $config['default']) {
                $offenders[] = "{$family}.{$field['key']} resolves to {$resolved}";
            }
        }
    }

    expect($offenders)->toBe([], 'a spec key that resolves to another family re-classifies the product');
});

it('declares a block only for families the database accepts', function () {
    // `toContain($needle, $more)` treats extra arguments as MORE NEEDLES, not a message (the trap
    // wave 4A's review hit twice), so the offenders are collected and the empty list is asserted.
    $unknown = array_values(array_diff(array_keys(SpecBlocks::all()), Product::FAMILIES));

    expect($unknown)->toBe([], 'config/catalog.php declares a block for a family the database does not accept');
});

/*
 * ── The same four claims, through the SCREEN ─────────────────────────────────────────────────
 *
 * The tests above prove the rule; these prove the form actually uses it, because a correct
 * resolver behind a form that ignores it is worth nothing.
 */

it('ships the derived family and its REASON to the product form', function () {
    $watches = CatalogFixture::watchesRoot();
    $productId = CatalogFixture::product('watch');
    CatalogFixture::place($productId, $watches);

    $props = Props::of(
        actingAs(Staff::dataEntry())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk()
    );

    expect($props['family'])->toBeArray();
    /** @var array<string, mixed> $family */
    $family = $props['family'];

    expect($family['family'])->toBe('watch')
        ->and($family['node_id'])->toBe($watches)
        // The reason is on the screen, because a block that appears without explanation looks
        // like a bug to the person filling it in.
        ->and($family['reason'])->toBeString()
        ->and(T::str($family['reason']))->toContain('config/transform.php');
});

it('shows a fashion product no spec block, and says why', function () {
    $fashion = CatalogFixture::fashionRoot();
    $productId = CatalogFixture::product('fashion');
    CatalogFixture::place($productId, $fashion);

    $props = Props::of(
        actingAs(Staff::dataEntry())->get("/manage/storefronts/1/products/{$productId}/edit")->assertOk()
    );

    /** @var array<string, mixed> $family */
    $family = $props['family'];
    /** @var array<string, mixed> $blocks */
    $blocks = $props['blocks'];

    expect($family['family'])->not->toBe('watch')
        // Every block is shipped (so changing the category re-renders with no round trip)…
        ->and($blocks)->toHaveKey('watch')
        // …and the family this product has is not among them, so the screen renders the
        // "no specifications for this family" panel.
        ->and($blocks)->not->toHaveKey(T::str($family['family']));
});

it('derives the family from the payload, never from a field the payload could claim', function () {
    CatalogFixture::assumeSwitched();
    // A hostile save cannot say "I am a watch": there is no family field, and the writer computes
    // it from the chosen primary category. This drives the real endpoint with a family key in the
    // body and asserts the stored row ignored it.
    $fashion = CatalogFixture::fashionRoot();
    $productId = CatalogFixture::product('fashion');
    CatalogFixture::place($productId, $fashion);
    CatalogFixture::onStorefront($productId);

    actingAs(Staff::admin())->put("/manage/storefronts/1/products/{$productId}", [
        'family' => 'watch',                       // ← ignored by construction
        'wa_code' => '4b-family-'.bin2hex(random_bytes(4)),
        'brand_id' => DB::table('catalog_brands')->orderBy('id')->value('id'),
        'selling_price' => '750.00',
        'currency' => 'EGP',
        'is_active' => true,
        'title' => ['ar' => 'منتج أزياء', 'en' => 'Fashion product'],
        'category_ids' => [$fashion],
        'primary_category_id' => $fashion,
        'is_visible' => false,
        'is_featured' => false,
    ])->assertRedirect();

    $stored = T::str(DB::table('catalog_products')->where('id', $productId)->value('family'));

    expect($stored)->not->toBe('watch')
        ->and($stored)->toBe(app(FamilyForCategory::class)->forNode($fashion));
});
