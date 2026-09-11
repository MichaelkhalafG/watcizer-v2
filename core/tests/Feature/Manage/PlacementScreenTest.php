<?php

use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The placement screen: one storefront, many products — visibility, ordering, featured, the slug
 * and the primary category.
 *
 * Three invariants, all of which the UI has to EXPLAIN and not merely obey: one primary category
 * per (storefront, product) — a database invariant since M1d — Arabic before visible, and a slug
 * change leaving a 301 behind.
 */

it('sets a primary category by DEMOTING the old one, in one action', function () {
    // M1d put a second unique key on the table (`storefront_id`, `primary_guard`). With a stale
    // primary still flagged, an upsert can match THAT key instead of (category, product) and
    // update a row the caller never named — which is the bug step 19 hit and fixed by demoting
    // first. The dashboard does the same thing for the same reason.
    $watches = CatalogFixture::watchesRoot();
    $fashion = CatalogFixture::fashionRoot();
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId);

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => false,
        'category_ids' => [$watches, $fashion],
        'primary_category_id' => $watches,
    ])->assertSessionHasNoErrors();

    expect(primaryOf($productId))->toBe($watches)
        ->and(primaryCount($productId))->toBe(1);

    // Move the primary to the other category: one primary, still.
    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => false,
        'category_ids' => [$watches, $fashion],
        'primary_category_id' => $fashion,
    ])->assertSessionHasNoErrors();

    expect(primaryOf($productId))->toBe($fashion)
        ->and(primaryCount($productId))->toBe(1);
});

it('picks a primary itself rather than leaving a product with placements and none', function () {
    // The read layer resolves "primary → deepest → lowest id", which is deterministic but is not
    // what anybody CHOSE. A product with categories should always have a primary.
    $watches = CatalogFixture::watchesRoot();
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId);

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => false,
        'category_ids' => [$watches],
        'primary_category_id' => null,
    ])->assertSessionHasNoErrors();

    expect(primaryOf($productId))->toBe($watches);
});

it('ignores a primary that is not one of the chosen categories', function () {
    $watches = CatalogFixture::watchesRoot();
    $fashion = CatalogFixture::fashionRoot();
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId);

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => false,
        'category_ids' => [$watches],
        'primary_category_id' => $fashion,   // ← not in the set
    ])->assertSessionHasNoErrors();

    expect(primaryOf($productId))->toBe($watches)
        ->and(DB::table('storefront_category_product')->where('product_id', $productId)->count())->toBe(1);
});

it('REFUSES a category that belongs to another storefront, without confirming it exists', function () {
    /*
     * This test asserted the opposite until review 🟠-1: the foreign id was SILENTLY DROPPED, on
     * the reasoning that §3.11.14 forbids telling the caller another storefront's row exists.
     *
     * Dropping it was the hole. `place()` filtered the id out as foreign, was left with an empty
     * desired set, and deleted every placement the product already had — a payload naming
     * storefront 2 wiped storefront 1's own data. Refusing at the field is both safe and silent:
     * the validator's answer for a foreign id is identical to its answer for an id that never
     * existed, so nothing is confirmed (the sibling assertion below is what holds that).
     */
    $other = CatalogFixture::secondStorefront();
    $foreign = CatalogFixture::anyNodeOf($other);
    $watches = CatalogFixture::watchesRoot();
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId);
    CatalogFixture::place($productId, $watches);

    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => false,
        'category_ids' => [$watches, $foreign],
        'primary_category_id' => $watches,
    ])->assertSessionHasErrors('category_ids.1');

    $foreignMessage = T::err('category_ids.1');

    // The placement it already had is still there: a refused save writes nothing.
    $placed = DB::table('storefront_category_product')->where('product_id', $productId)->pluck('storefront_category_id')->all();
    expect(array_map(fn (mixed $v): int => T::int($v), $placed))->toBe([$watches]);

    // §3.11.14 held: an id of another storefront and an id of nobody read the same.
    $ghost = T::int(DB::table('storefront_categories')->max('id')) + 9000;
    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false,
        'is_featured' => false,
        'category_ids' => [$watches, $ghost],
        'primary_category_id' => $watches,
    ])->assertSessionHasErrors('category_ids.1');

    expect(T::err('category_ids.1'))->toBe($foreignMessage,
        'a foreign node must be indistinguishable from a non-existent one (§3.11.14)')
        ->and($foreignMessage)->not->toContain((string) $foreign)
        ->and($foreignMessage)->not->toContain((string) $other);
});

it('removes a placement the payload no longer names', function () {
    // The dashboard DOES delete a pivot row here, unlike the transform — which is additive by
    // design because it cannot tell "the team un-placed this" from "legacy dropped the row"
    // (§2.9.6 rule 2). A human clicking a category off is unambiguous.
    $watches = CatalogFixture::watchesRoot();
    $fashion = CatalogFixture::fashionRoot();
    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId);

    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false, 'is_featured' => false,
        'category_ids' => [$watches, $fashion], 'primary_category_id' => $watches,
    ])->assertSessionHasNoErrors();
    expect(DB::table('storefront_category_product')->where('product_id', $productId)->count())->toBe(2);

    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false, 'is_featured' => false,
        'category_ids' => [$fashion], 'primary_category_id' => $fashion,
    ])->assertSessionHasNoErrors();

    expect(DB::table('storefront_category_product')->where('product_id', $productId)->count())->toBe(1)
        ->and(primaryOf($productId))->toBe($fashion);
});

it('writes a 301 when a product slug changes, and keeps the old URL working', function () {
    // Post-switch, and the test says so out loud (review 🟠-3): pre-switch the slug field is
    // LOCKED precisely because the rebuild deletes both the slug and this redirect. The 301
    // machinery is what the screen does once the flag is flipped — `PreSwitchTest` drives the
    // refusal in the flag's default state.
    CatalogFixture::assumeSwitched();

    $productId = CatalogFixture::product();
    CatalogFixture::onStorefront($productId);
    $oldSlug = T::str(DB::table('storefront_product')->where('product_id', $productId)->value('slug'));

    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => false, 'is_featured' => false, 'slug' => 'new-product-slug',
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('storefront_product')->where('product_id', $productId)->value('slug')))->toBe('new-product-slug');

    $redirect = T::one(DB::table('storefront_redirects')
        ->where('storefront_id', 1)->where('from_path', '/product/'.$oldSlug));

    expect(Row::str($redirect, 'to_path'))->toBe('/product/new-product-slug')
        ->and(Row::int($redirect, 'status'))->toBe(301)
        ->and(Row::str($redirect, 'source'))->toBe('slug_change');
});

it('does not move published_at when a product is hidden and shown again', function () {
    // `published_at` drives the listing's default order (`sp_list_position_idx`). Re-showing a
    // product must not jump it to the front of the catalogue.
    $productId = CatalogFixture::product();
    // Placed as well as present: since task 4.2 the writer refuses to SHOW a product that sits in
    // no category on this storefront, which is the state this fixture used to leave it in.
    CatalogFixture::place($productId, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($productId, visible: true);
    $published = T::str(DB::table('storefront_product')->where('product_id', $productId)->value('published_at'));

    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", ['is_visible' => false, 'is_featured' => false])
        ->assertSessionHasNoErrors();
    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", ['is_visible' => true, 'is_featured' => false])
        ->assertSessionHasNoErrors();

    expect(T::str(DB::table('storefront_product')->where('product_id', $productId)->value('published_at')))->toBe($published);
});

it('REPORTS what a bulk show skipped instead of silently dropping it', function () {
    // A bulk action that silently dropped half a selection is how a team finds out in October that
    // twelve products were never published. The Arabic gate is per product, so the bulk path
    // applies them one at a time and names the ones it could not.
    $good = CatalogFixture::product();
    $noArabic = CatalogFixture::productWithoutArabic();
    $watches = CatalogFixture::watchesRoot();
    // Both placed, so the ONLY difference between them is the Arabic title — otherwise this test
    // would pass for the wrong reason once the placement gate exists.
    CatalogFixture::place($good, $watches);
    CatalogFixture::place($noArabic, $watches);
    CatalogFixture::onStorefront($good, visible: false);
    CatalogFixture::onStorefront($noArabic, visible: false);

    actingAs(Staff::admin())->post('/manage/storefronts/1/placement/bulk', [
        'action' => 'show',
        'product_ids' => [$good, $noArabic],
    ])->assertSessionHasErrors('bulk');

    expect(T::int(DB::table('storefront_product')->where('product_id', $good)->value('is_visible')) === 1)->toBeTrue()
        ->and(T::int(DB::table('storefront_product')->where('product_id', $noArabic)->value('is_visible')) === 1)->toBeFalse();

    $message = T::err('bulk');
    expect($message)->toContain('تم تخطّي')
        ->and($message)->toContain((string) $noArabic);
});

it('features and unfeatures in bulk without touching visibility', function () {
    $productId = CatalogFixture::product();
    CatalogFixture::place($productId, CatalogFixture::watchesRoot());
    CatalogFixture::onStorefront($productId, visible: true);

    actingAs(Staff::admin())->post('/manage/storefronts/1/placement/bulk', [
        'action' => 'feature', 'product_ids' => [$productId],
    ])->assertSessionHasNoErrors();

    $row = T::row(DB::table('storefront_product')->where('product_id', $productId)->first(['is_visible', 'is_featured']));
    expect(Row::bool($row, 'is_featured'))->toBeTrue()
        ->and(Row::bool($row, 'is_visible'))->toBeTrue();

    actingAs(Staff::admin())->post('/manage/storefronts/1/placement/bulk', [
        'action' => 'unfeature', 'product_ids' => [$productId],
    ])->assertSessionHasNoErrors();

    expect(T::int(DB::table('storefront_product')->where('product_id', $productId)->value('is_featured')) === 1)->toBeFalse();
});

it('flags the rows that need attention: no Arabic, no primary, unplaced', function () {
    $noArabic = CatalogFixture::productWithoutArabic();
    CatalogFixture::onStorefront($noArabic);

    $unplaced = CatalogFixture::product();
    CatalogFixture::onStorefront($unplaced);

    $rows = Props::rows(placementTable(['filters' => ['flag' => 'no_arabic']]));
    expect(array_map(fn (array $r): int => T::int($r['product_id']), $rows))->toContain($noArabic);

    $rows = Props::rows(placementTable(['filters' => ['flag' => 'unplaced']]));
    expect(array_map(fn (array $r): int => T::int($r['product_id']), $rows))->toContain($unplaced);
    // …and an unplaced product cannot be shown, which is the other half of the same fact.
    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$unplaced}", ['is_visible' => true, 'is_featured' => false])
        ->assertSessionHasErrors();
});

it('sorts products inside one category', function () {
    $node = CatalogFixture::root('Sortable', 'قابل للترتيب');
    $a = CatalogFixture::product();
    $b = CatalogFixture::product();
    CatalogFixture::place($a, $node['id']);
    CatalogFixture::place($b, $node['id'], primary: false);

    actingAs(Staff::dataEntry())->post("/manage/storefronts/1/placement/category/{$node['id']}/sort", [
        'product_ids' => [$b, $a],
    ])->assertSessionHasNoErrors();

    $order = DB::table('storefront_category_product')
        ->where('storefront_category_id', $node['id'])->orderBy('sort_order')->pluck('product_id')->all();

    expect(array_map(fn (mixed $v): int => T::int($v), $order))->toBe([$b, $a]);
});

it('404s a sort inside a category of another storefront', function () {
    $other = CatalogFixture::secondStorefront();
    $foreign = CatalogFixture::anyNodeOf($other);

    actingAs(Staff::admin())->post("/manage/storefronts/1/placement/category/{$foreign}/sort", ['product_ids' => [1]])
        ->assertNotFound();
});

// ── helpers ─────────────────────────────────────────────────────────────────────────────────

function primaryOf(int $productId, int $storefrontId = 1): ?int
{
    $value = DB::table('storefront_category_product')
        ->where('storefront_id', $storefrontId)->where('product_id', $productId)->where('is_primary', true)
        ->value('storefront_category_id');

    return is_numeric($value) ? (int) $value : null;
}

function primaryCount(int $productId, int $storefrontId = 1): int
{
    return DB::table('storefront_category_product')
        ->where('storefront_id', $storefrontId)->where('product_id', $productId)->where('is_primary', true)
        ->count();
}

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function placementTable(array $query = []): array
{
    $props = Props::of(
        actingAs(Staff::dataEntry())->get('/manage/storefronts/1/placement?'.http_build_query($query))->assertOk()
    );
    expect($props['table'])->toBeArray();

    /** @var array<string, mixed> $table */
    $table = $props['table'];

    return $table;
}
