<?php

use App\Domain\Access\Role;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * Slug editing and the tree's SHAPE are admin-only (item 6, developer 2026-09-18).
 *
 * ── The line, and why it is drawn where it is ───────────────────────────────────────────────
 *
 * Data-entry keeps everything they do all day. What moved is the handful of operations whose blast
 * radius is the whole storefront rather than one row:
 *
 *   • a product's SLUG — its public address. Customers have bookmarked it, Google has indexed it,
 *     and the 301 written alongside is the only thing between a change and a 404.
 *   • a category's SLUG — the same argument, one level up.
 *   • creating, moving, reordering, deleting a node, and switching one OFF — each moves every
 *     product underneath, the breadcrumb, the menu and the derived family.
 *
 * RENAMING stays with data-entry. It changes a word on a page.
 *
 * ── What these tests are for ────────────────────────────────────────────────────────────────
 *
 * Two halves, and the second is the one that earns the file. The first is the refusal. The second
 * is that the operator can still do the thing the refusal points them at — a rule that also breaks
 * the neighbouring work is a rule the team routes around, and this endpoint writes five fields at
 * once, so it was one careless `can:` away from taking visibility and ordering with it.
 */

it('refuses a DATA-ENTRY slug change, on the slug field, and writes nothing', function () {
    CatalogFixture::assumeSwitched();

    $row = T::one(
        DB::table('storefront_product')->where('storefront_id', 1)->whereNotNull('slug')->orderBy('product_id')
    );
    $productId = Row::int($row, 'product_id');
    $was = Row::str($row, 'slug');
    $watches = CatalogFixture::watchesRoot();

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => true,
        'is_featured' => false,
        'sort_order' => 0,
        'category_ids' => [$watches],
        'primary_category_id' => $watches,
        'slug' => $was.'-by-data-entry',
    ])->assertSessionHasErrors('slug');

    expect(T::str(DB::table('storefront_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->value('slug')))->toBe($was);
});

it('still lets DATA-ENTRY do the rest of the placement screen in the same request', function () {
    CatalogFixture::assumeSwitched();

    /*
     * The half that matters. `placement.update` writes the slug and four other things at once, so
     * the obvious implementation — `can:edit-product-slug` on the route — would have taken
     * visibility, order, featured and the category set away from the people whose job they are.
     * The check is on the FIELD, and this is what proves it.
     */
    $productId = T::int(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'cp.id')
        ->where('sp.storefront_id', 1)->whereNull('cp.deleted_at')->orderBy('cp.id')->value('cp.id'));
    $watches = CatalogFixture::watchesRoot();
    $slug = T::str(DB::table('storefront_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->value('slug'));

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => true,
        'is_featured' => true,
        'sort_order' => 42,
        'category_ids' => [$watches],
        'primary_category_id' => $watches,
        // The stored slug, echoed back exactly as the screen sends it. Unchanged is not a change.
        'slug' => $slug,
    ])->assertSessionHasNoErrors();

    $after = T::one(DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $productId));
    expect(Row::bool($after, 'is_featured'))->toBeTrue()
        ->and(Row::int($after, 'sort_order'))->toBe(42);
});

it('lets an ADMIN change the same slug, which is the point of the grant', function () {
    CatalogFixture::assumeSwitched();

    $row = T::one(
        DB::table('storefront_product')->where('storefront_id', 1)->whereNotNull('slug')->orderBy('product_id')
    );
    $productId = Row::int($row, 'product_id');
    $was = Row::str($row, 'slug');
    $watches = CatalogFixture::watchesRoot();

    actingAs(Staff::admin())->put("/manage/storefronts/1/placement/{$productId}", [
        'is_visible' => true,
        'is_featured' => false,
        'sort_order' => 0,
        'category_ids' => [$watches],
        'primary_category_id' => $watches,
        'slug' => $was.'-by-admin',
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('storefront_product')
        ->where('storefront_id', 1)->where('product_id', $productId)->value('slug')))->toBe($was.'-by-admin');
});

it('403s DATA-ENTRY on every route that changes the tree SHAPE', function () {
    CatalogFixture::assumeSwitched();

    $watches = CatalogFixture::watchesRoot();
    $entry = Staff::dataEntry();

    // At the verb each screen actually uses — a GET against a POST route answers 405, which would
    // have passed a "not 200" assertion while proving nothing.
    actingAs($entry)->post('/manage/storefronts/1/categories', [
        'name' => ['ar' => 'تصنيف جديد', 'en' => 'New node'],
    ])->assertForbidden();

    actingAs($entry)->put("/manage/storefronts/1/categories/{$watches}/move", [
        'parent_id' => null,
    ])->assertForbidden();

    actingAs($entry)->post('/manage/storefronts/1/categories/reorder', [
        'ids' => [$watches],
    ])->assertForbidden();

    actingAs($entry)->delete("/manage/storefronts/1/categories/{$watches}")->assertForbidden();
});

it('still lets DATA-ENTRY RENAME a category, which is their daily work', function () {
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/categories/{$watches}", [
        'name' => ['ar' => 'ساعات (تسمية جديدة)', 'en' => 'Watches'],
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('storefront_category_translations')
        ->where('storefront_category_id', $watches)->where('locale', 'ar')->value('name')))
        ->toBe('ساعات (تسمية جديدة)');
});

it('refuses a DATA-ENTRY category SLUG change and a DEACTIVATION on the rename route', function () {
    CatalogFixture::assumeSwitched();
    $watches = CatalogFixture::watchesRoot();

    /*
     * The rename route writes four fields, and two of them are not renames. Left alone, an
     * operator who may only rename could have changed the category's public URL or taken every
     * product under it off the storefront — through the one route that was deliberately left open
     * to them.
     */
    $before = T::one(DB::table('storefront_categories')->where('id', $watches));

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/categories/{$watches}", [
        'name' => ['ar' => 'ساعات', 'en' => 'Watches'],
        'slug' => Row::str($before, 'slug').'-moved',
    ])->assertSessionHasErrors('tree');

    actingAs(Staff::dataEntry())->put("/manage/storefronts/1/categories/{$watches}", [
        'name' => ['ar' => 'ساعات', 'en' => 'Watches'],
        'is_active' => false,
    ])->assertSessionHasErrors('tree');

    $after = T::one(DB::table('storefront_categories')->where('id', $watches));
    expect(Row::str($after, 'slug'))->toBe(Row::str($before, 'slug'))
        ->and(Row::bool($after, 'is_active'))->toBe(Row::bool($before, 'is_active'));
});

it('tells the SCREEN which rule is stopping it, so nothing looks broken', function () {
    CatalogFixture::assumeSwitched();

    // Data-entry: the controls are locked and the reason names a PERSON, not a date.
    $placement = Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/1/placement')->assertOk());
    $slugRole = T::arr($placement['slug_role'] ?? null);
    expect($slugRole['allowed'] ?? null)->toBeFalse()
        ->and(T::str($slugRole['message'] ?? null))->not->toBe('');

    $categories = Props::of(actingAs(Staff::dataEntry())->get('/manage/storefronts/1/categories')->assertOk());
    $treeRole = T::arr($categories['tree_role'] ?? null);
    expect($treeRole['allowed'] ?? null)->toBeFalse()
        ->and(T::str($treeRole['message'] ?? null))->not->toBe('');

    // Admin: allowed, and NO sentence — a message beside a control that works is noise, and an
    // operator who reads one every day stops reading all of them.
    $adminPlacement = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/placement'))['slug_role'] ?? null);
    expect($adminPlacement['allowed'] ?? null)->toBeTrue()
        ->and($adminPlacement['message'] ?? null)->toBeNull();

    $adminTree = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/storefronts/1/categories'))['tree_role'] ?? null);
    expect($adminTree['allowed'] ?? null)->toBeTrue()
        ->and($adminTree['message'] ?? null)->toBeNull();
});

it('defines both abilities, so they can be granted by name rather than only inherited', function () {
    // In ABILITIES (so `Gate::before` gives them to an administrator) and absent from data-entry's
    // list — which is the whole of "admin-only" in this codebase, stated rather than assumed.
    expect(Role::ABILITIES)->toContain(Role::EDIT_PRODUCT_SLUG)
        ->and(Role::ABILITIES)->toContain(Role::EDIT_CATEGORY_TREE)
        ->and(Role::DataEntry->abilities())->not->toContain(Role::EDIT_PRODUCT_SLUG)
        ->and(Role::DataEntry->abilities())->not->toContain(Role::EDIT_CATEGORY_TREE)
        ->and(Role::Admin->can(Role::EDIT_PRODUCT_SLUG))->toBeTrue()
        ->and(Role::Admin->can(Role::EDIT_CATEGORY_TREE))->toBeTrue();
});

it('keeps the four gated routes behind the ability, in the route table itself', function () {
    // Hiding a button is never the control. This asserts the middleware is actually on the route,
    // so a future refactor that moves the routes out of the group fails here rather than in prod.
    // Collected into an array rather than asserted per route with a message: Pest's `toContain()`
    // is VARIADIC, so a "message" second argument becomes a second NEEDLE and the test starts
    // demanding that the middleware list contains its own failure sentence.
    $unguarded = [];
    foreach (['categories.store', 'categories.move', 'categories.reorder', 'categories.destroy'] as $name) {
        $route = Route::getRoutes()->getByName('manage.'.$name);
        if ($route === null) {
            $unguarded[] = $name.': the route does not exist';

            continue;
        }
        if (! in_array('can:'.Role::EDIT_CATEGORY_TREE, $route->gatherMiddleware(), true)) {
            $unguarded[] = $name.': not behind '.Role::EDIT_CATEGORY_TREE;
        }
    }

    expect($unguarded)->toBe([]);
});
