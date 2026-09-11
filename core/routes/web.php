<?php

use App\Domain\Access\Role;
use App\Http\Controllers\Compat\SitemapCompatController;
use App\Http\Controllers\Manage\Auth\LoginController;
use App\Http\Controllers\Manage\CategoryController;
use App\Http\Controllers\Manage\HomeController;
use App\Http\Controllers\Manage\LookupController;
use App\Http\Controllers\Manage\MediaController;
use App\Http\Controllers\Manage\PlacementController;
use App\Http\Controllers\Manage\ProductController;
use App\Http\Controllers\Manage\ProductVariantController;
use App\Http\Controllers\Manage\StorefrontController;
use App\Http\Middleware\EnsureDashboardAccess;
use App\Http\Middleware\EnsureStorefrontScope;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The dashboard (CLEAN_CORE_STUDY §1: Inertia dashboard at /manage on the core host)
|--------------------------------------------------------------------------
|
| Authorisation is layered, and both layers are on the SERVER:
|
|   1. `auth` + EnsureDashboardAccess — signed in AND holding a core role. A customer with a valid
|      storefront account gets 403 here, because `users.type` grants nothing (AGENTS §2.18).
|   2. `can:<ability>` per route group — what this role may actually do.
|
| The sidebar hides what a role cannot reach, but hiding is never the control: every route below
| is proven to refuse by `tests/Feature/Manage/RouteAuthorizationTest.php`, which drives a
| data-entry session straight at the admin URLs.
|
| Wave 4B/4C/4D screens are deliberately NOT stubbed as routes. They appear in the sidebar as
| disabled items carrying their wave, so nothing links to a half-built page.
|
*/

Route::prefix('manage')->name('manage.')->group(function (): void {
    // Sign-in is open (guests must reach it) but rate-limited in the controller.
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:20,1')->name('login.store');
    Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

    Route::middleware(['auth', EnsureDashboardAccess::class])->group(function (): void {
        Route::get('/', HomeController::class)->middleware('can:'.Role::VIEW_DASHBOARD)->name('home');

        // Uploads: data-entry needs them for 4B's product forms, so the ability is theirs too.
        Route::post('media', [MediaController::class, 'store'])->middleware('can:'.Role::MANAGE_MEDIA)->name('media.store');

        // Storefronts: admin only (§2.7 — creating/disabling a storefront is not a data-entry job).
        Route::middleware('can:'.Role::MANAGE_STOREFRONTS)->group(function (): void {
            Route::get('storefronts', [StorefrontController::class, 'index'])->name('storefronts.index');
            Route::get('storefronts/{storefront}/edit', [StorefrontController::class, 'edit'])->name('storefronts.edit');
            Route::put('storefronts/{storefront}', [StorefrontController::class, 'update'])->name('storefronts.update');
        });

        /*
        |--------------------------------------------------------------------------
        | Wave 4B — the catalogue the team lives in
        |--------------------------------------------------------------------------
        |
        | Three groups, three abilities, and one extra middleware wherever the URL
        | names a storefront.
        |
        | `EnsureStorefrontScope` is the rule wave 4A's review left for 4B
        | (study §3.11.14): `can:manage-catalog` answers the UNSCOPED question, so a
        | grant limited to storefront 1 passes it even when the URL says storefront 2.
        | The scope middleware asks again with the storefront in hand and answers
        | **404**, never 403 — a 403 confirms the row exists and turns the URL into an
        | id oracle. `RouteAuthorizationTest` asserts that every `{storefront}` route
        | below carries it.
        |
        | Products are SHARED across storefronts (D3), so the product screens take the
        | storefront as a path segment that selects WHICH placement columns are shown
        | rather than which products exist — and they still carry the scope check,
        | because they can write that storefront's placement row.
        |
        */
        Route::middleware([
            'can:'.Role::MANAGE_CATALOG,
            EnsureStorefrontScope::with(Role::MANAGE_CATALOG),
        ])->group(function (): void {
            Route::get('storefronts/{storefront}/products', [ProductController::class, 'index'])->name('products.index');
            Route::get('storefronts/{storefront}/products/create', [ProductController::class, 'create'])->name('products.create');
            Route::post('storefronts/{storefront}/products', [ProductController::class, 'store'])->name('products.store');
            Route::get('storefronts/{storefront}/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
            Route::put('storefronts/{storefront}/products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::post('storefronts/{storefront}/products/bulk', [ProductController::class, 'bulk'])->name('products.bulk');

            // The category tree, per storefront.
            Route::get('storefronts/{storefront}/categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::post('storefronts/{storefront}/categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::put('storefronts/{storefront}/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::put('storefronts/{storefront}/categories/{category}/move', [CategoryController::class, 'move'])->name('categories.move');
            Route::post('storefronts/{storefront}/categories/reorder', [CategoryController::class, 'reorder'])->name('categories.reorder');
            Route::delete('storefronts/{storefront}/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
        });

        /*
        | Variants. The row's ATTRIBUTES are a catalog edit; the QUANTITY is a stock
        | movement, and `can:manage-inventory` is the ability that says so. Data-entry
        | holds both (§2.7); naming them separately is what lets a future role hold one.
        |
        | No `{storefront}` segment: a variant belongs to a product, and a product is
        | shared. There is nothing storefront-scoped to gate here, and inventing a
        | segment for symmetry would be a lie about the data.
        */
        Route::middleware(['can:'.Role::MANAGE_CATALOG, 'can:'.Role::MANAGE_INVENTORY])->group(function (): void {
            Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])->name('variants.store');
            Route::put('products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])->name('variants.update');
            Route::delete('products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])->name('variants.destroy');
            Route::post('products/{product}/variants/reorder', [ProductVariantController::class, 'reorder'])->name('variants.reorder');
        });

        // Placement is its own ability (§2.7): who may decide what a storefront SHOWS.
        Route::middleware([
            'can:'.Role::MANAGE_PLACEMENT,
            EnsureStorefrontScope::with(Role::MANAGE_PLACEMENT),
        ])->group(function (): void {
            Route::get('storefronts/{storefront}/placement', [PlacementController::class, 'index'])->name('placement.index');
            Route::put('storefronts/{storefront}/placement/{product}', [PlacementController::class, 'update'])->name('placement.update');
            Route::post('storefronts/{storefront}/placement/bulk', [PlacementController::class, 'bulk'])->name('placement.bulk');
            Route::post('storefronts/{storefront}/placement/category/{category}/sort', [PlacementController::class, 'sort'])->name('placement.sort');
        });

        // Brands and the lookup lists. Catalogue-wide, not per storefront: a colour is a
        // colour on every storefront (D3, the shared catalogue).
        Route::middleware('can:'.Role::MANAGE_CATALOG)->group(function (): void {
            Route::get('lookups/{list}', [LookupController::class, 'index'])->name('lookups.index');
            Route::post('lookups/{list}', [LookupController::class, 'store'])->name('lookups.store');
            Route::put('lookups/{list}/{id}', [LookupController::class, 'update'])->name('lookups.update');
            Route::delete('lookups/{list}/{id}', [LookupController::class, 'destroy'])->name('lookups.destroy');
        });
    });
});

// The wave-0 walking skeleton lived at `/`. It now redirects to the dashboard, so the host has one
// front door and nothing renders an unauthenticated Inertia page by accident.
Route::get('/', fn () => redirect()->route('manage.home'))->name('dashboard');

// Legacy Watchizer sitemap contract (CLEAN_CORE_STUDY §3.3, §6.4): the Next.js rewrite fetches
// `/en/sitemap.xml`; the bare path 302s to the negotiated locale exactly like the legacy host.
// Not Inertia responses: the dashboard middleware would add `Vary: X-Inertia`, which the legacy host never sends.
Route::get('/sitemap.xml', [SitemapCompatController::class, 'redirect'])->withoutMiddleware(HandleInertiaRequests::class);
Route::get('/{locale}/sitemap.xml', [SitemapCompatController::class, 'show'])->where('locale', '[a-z]{2}')->withoutMiddleware(HandleInertiaRequests::class);
