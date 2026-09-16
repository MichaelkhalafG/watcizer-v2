<?php

use App\Domain\Access\Role;
use App\Http\Controllers\Compat\SitemapCompatController;
use App\Http\Controllers\Manage\ActivityController;
use App\Http\Controllers\Manage\Auth\LoginController;
use App\Http\Controllers\Manage\BannerController;
use App\Http\Controllers\Manage\CategoryController;
use App\Http\Controllers\Manage\CustomerController;
use App\Http\Controllers\Manage\HomeController;
use App\Http\Controllers\Manage\InventoryController;
use App\Http\Controllers\Manage\LookupController;
use App\Http\Controllers\Manage\MediaController;
use App\Http\Controllers\Manage\MediaPruneController;
use App\Http\Controllers\Manage\OrderController;
use App\Http\Controllers\Manage\PaymentSettingsController;
use App\Http\Controllers\Manage\PlacementController;
use App\Http\Controllers\Manage\ProductController;
use App\Http\Controllers\Manage\ProductVariantController;
use App\Http\Controllers\Manage\ProfileController;
use App\Http\Controllers\Manage\PromotionController;
use App\Http\Controllers\Manage\ShippingController;
use App\Http\Controllers\Manage\StorefrontController;
use App\Http\Controllers\Manage\UnitController;
use App\Http\Controllers\Manage\UserRoleController;
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

        /*
        | PROFILE -- the signed-in operator's own, and no ability beyond reaching the
        | dashboard: the route takes no id, so there is no other person's profile to
        | authorise. Name, e-mail and password are READ-ONLY here because `users` is a
        | legacy table core may not write (AGENTS 3); the save touches
        | `core_user_preferences` and nothing else.
        */
        Route::get('profile', [ProfileController::class, 'show'])->name('profile');
        Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');

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

        /*
        | BANNERS -- the home slot, per storefront (wave 4D). Under MANAGE_LEGACY_CONTENT, which is
        | the ability that was always meant for the offers/banners/blogs section; offers became the
        | promotions engine, so this is the first screen the ability actually opens. Scoped like
        | placement: a banner decides what one storefront's home page SHOWS.
        */
        Route::middleware([
            'can:'.Role::MANAGE_LEGACY_CONTENT,
            EnsureStorefrontScope::with(Role::MANAGE_LEGACY_CONTENT),
        ])->group(function (): void {
            Route::get('storefronts/{storefront}/banners', [BannerController::class, 'index'])->name('banners.index');
            Route::post('storefronts/{storefront}/banners', [BannerController::class, 'store'])->name('banners.store');
            Route::put('storefronts/{storefront}/banners/{banner}', [BannerController::class, 'update'])
                ->where('banner', '[0-9]+')->name('banners.update');
            Route::delete('storefronts/{storefront}/banners/{banner}', [BannerController::class, 'destroy'])
                ->where('banner', '[0-9]+')->name('banners.destroy');
        });

        /*
        |--------------------------------------------------------------------------
        | Wave 4C — the shop floor: orders, inventory, users, payments
        |--------------------------------------------------------------------------
        |
        | ORDERS are three abilities and not one (AGENTS 2.7). Reading an order and
        | moving it forward are the day job; CANCELLING moves money and returns stock
        | to the ledger, so it is admin-only. The split is on the ROUTES, which is
        | what makes it provable by direct HTTP rather than by a hidden menu --
        | OrderAuthorizationTest posts every one of these as data-entry.
        |
        | {order} is constrained to digits so orders/export/settlement cannot be
        | swallowed by it, and the export is gated on manage-payments: a settlement
        | file is payment reconciliation, not shop-floor work.
        |
        | No {storefront} segment. An order belongs to a storefront through a column
        | added in this wave, but the SCREEN is cross-storefront by design -- the team
        | works one queue, filtered -- and inventing a path segment would imply orders
        | are partitioned when they are not.
        */
        Route::middleware('can:'.Role::VIEW_ORDERS)->group(function (): void {
            Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
            Route::get('orders/{order}', [OrderController::class, 'show'])
                ->where('order', '[0-9]+')->name('orders.show');

            /*
            | CUSTOMERS -- the people who BUY, read-only (wave 4D).
            |
            | Two GETs and nothing else: every table it reads is LEGACY and shared with the live
            | storefront, so there is no write path to authorise. It sits under VIEW_ORDERS rather
            | than an ability of its own because everything on it is already on the order screens
            | -- it groups the same facts by person instead of by order.
            |
            | The key is `u:41` or `g:01001234567`, so the pattern admits a colon and refuses a
            | slash; a customer outside the grant's scope answers 404, never 403.
            */
            Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
            Route::get('customers/{customer}', [CustomerController::class, 'show'])
                ->where('customer', '[A-Za-z0-9:@._+-]+')->name('customers.show');
        });

        Route::put('orders/{order}/status', [OrderController::class, 'advance'])
            ->where('order', '[0-9]+')
            ->middleware('can:'.Role::MANAGE_ORDER_FULFILMENT)->name('orders.advance');

        // Cancel: money and stock. Admin only, and the stock return goes through
        // InventoryService inside OrderFulfilment::cancel() -- never a column write.
        Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])
            ->where('order', '[0-9]+')
            ->middleware('can:'.Role::CANCEL_ORDERS)->name('orders.cancel');

        // Clearing a payment finding is MONEY work, so it sits behind manage-payments rather
        // than the fulfilment ability (review 🔴-1, 2026-09-13).
        Route::post('orders/{order}/findings/{finding}/resolve', [OrderController::class, 'resolveFinding'])
            ->where('order', '[0-9]+')->where('finding', '[0-9]+')
            ->middleware('can:'.Role::MANAGE_PAYMENTS)->name('orders.findings.resolve');

        // The settlement CSV (3.9.6): one row per payment ATTEMPT, seven columns.
        Route::get('orders/export/settlement', [OrderController::class, 'settlement'])
            ->middleware('can:'.Role::MANAGE_PAYMENTS)->name('orders.settlement');

        /*
        | INVENTORY. Data-entry adjusts stock (2.7) and every movement goes through
        | InventoryService -- the screen cannot write a column and neither can anyone
        | else (AGENTS 3). The LEDGER is read-only for every role: there is no route
        | that edits or deletes a movement, and InventoryAuthorizationTest asserts
        | the absence rather than trusting it.
        */
        Route::middleware('can:'.Role::MANAGE_INVENTORY)->group(function (): void {
            Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
            Route::get('inventory/ledger', [InventoryController::class, 'ledger'])->name('inventory.ledger');
            Route::get('inventory/reconciliation', [InventoryController::class, 'reconciliation'])->name('inventory.reconciliation');
            Route::post('inventory/adjust', [InventoryController::class, 'adjust'])->name('inventory.adjust');
        });

        /*
        | USERS AND ROLES -- admin only, promised in 4A. Grants ONLY: this screen never
        | creates, edits or deletes a row in the shared users table (AGENTS 3), it
        | writes core_user_roles. The artisan command stays the bootstrap path, for
        | the case where nobody has a grant yet and therefore nobody can open this.
        */
        Route::middleware('can:'.Role::MANAGE_USERS)->group(function (): void {
            Route::get('users', [UserRoleController::class, 'index'])->name('users.index');
            Route::post('users/grants', [UserRoleController::class, 'store'])->name('users.grants.store');
            Route::delete('users/grants/{grant}', [UserRoleController::class, 'destroy'])
                ->where('grant', '[0-9]+')->name('users.grants.destroy');
        });

        /*
        | MEDIA CLEANUP -- its OWN ability, held by nobody until granted (review, 2026-09-15).
        |
        | `manage-settings` was the wrong gate: every administrator holds it, and this button
        | removes files the LIVE legacy storefront is still serving, with no undo. `MANAGE_MEDIA_PRUNE`
        | is in `Role::RESTRICTED`, so `Gate::before` does not hand it to admins either -- the screen
        | and both routes are absent and refused for everyone until:
        |
        |     php artisan manage:role grant <email> media_pruner
        */
        Route::middleware('can:'.Role::MANAGE_MEDIA_PRUNE)->group(function (): void {
            Route::get('media/prune', [MediaPruneController::class, 'index'])->name('media.prune');
            Route::delete('media/prune', [MediaPruneController::class, 'destroy'])->name('media.prune.destroy');
        });

        /*
        | PAYMENTS -- admin only (3.9.7), scoped to one storefront at a time, never a
        | global cross-storefront list. Credential fields are WRITE-ONLY everywhere
        | below: they render empty, blank means "keep", and no stored secret is ever
        | put in a prop (PaymentSecrecyTest).
        */
        Route::middleware([
            'can:'.Role::MANAGE_PAYMENTS,
            EnsureStorefrontScope::with(Role::MANAGE_PAYMENTS),
        ])->group(function (): void {
            Route::get('storefronts/{storefront}/payments', [PaymentSettingsController::class, 'index'])->name('payments.index');
            Route::post('storefronts/{storefront}/payments/providers', [PaymentSettingsController::class, 'storeProvider'])->name('payments.providers.store');
            Route::put('storefronts/{storefront}/payments/providers/{provider}', [PaymentSettingsController::class, 'updateProvider'])
                ->where('provider', '[0-9]+')->name('payments.providers.update');
            Route::delete('storefronts/{storefront}/payments/providers/{provider}', [PaymentSettingsController::class, 'destroyProvider'])
                ->where('provider', '[0-9]+')->name('payments.providers.destroy');

            Route::post('storefronts/{storefront}/payments/methods', [PaymentSettingsController::class, 'storeMethod'])->name('payments.methods.store');
            Route::put('storefronts/{storefront}/payments/methods/{method}', [PaymentSettingsController::class, 'updateMethod'])
                ->where('method', '[0-9]+')->name('payments.methods.update');
            Route::delete('storefronts/{storefront}/payments/methods/{method}', [PaymentSettingsController::class, 'destroyMethod'])
                ->where('method', '[0-9]+')->name('payments.methods.destroy');
            // The MERGED ordering screen: sort is storefront-wide, so the admin drags one list
            // across every provider, exactly as the customer will see it (3.9.7).
            Route::post('storefronts/{storefront}/payments/order', [PaymentSettingsController::class, 'reorder'])->name('payments.order');
        });

        /*
        |--------------------------------------------------------------------------
        | PROMOTIONS -- admin only (study §3.16.6), and NOT per storefront in the URL
        |--------------------------------------------------------------------------
        |
        | A rule is authored ONCE and the operator ticks which storefronts it applies to, exactly
        | like product placement (developer decision 2026-09-13). So there is no `{storefront}`
        | segment: partitioning the screen by storefront would mean authoring the same promotion
        | twice, which is the shape this model was chosen to avoid.
        |
        | `manage-promotions` is admin-only and absent from data-entry's abilities: a promotion
        | moves money and gives away stock, the same reasoning that keeps `cancel-orders` from them.
        */
        Route::middleware(['can:'.Role::MANAGE_PROMOTIONS])->group(function (): void {
            Route::get('promotions', [PromotionController::class, 'index'])->name('promotions.index');
            Route::get('promotions/create', [PromotionController::class, 'create'])->name('promotions.create');
            // Search and preview come BEFORE `{promotion}` so neither is swallowed by the
            // numeric-id route; both are POST/GET reads that write nothing permanent.
            Route::get('promotions/products', [PromotionController::class, 'search'])->name('promotions.products');
            Route::post('promotions/preview', [PromotionController::class, 'preview'])->name('promotions.preview');
            Route::post('promotions', [PromotionController::class, 'store'])->name('promotions.store');
            Route::get('promotions/{promotion}', [PromotionController::class, 'edit'])
                ->where('promotion', '[0-9]+')->name('promotions.edit');
            Route::put('promotions/{promotion}', [PromotionController::class, 'update'])
                ->where('promotion', '[0-9]+')->name('promotions.update');
            Route::delete('promotions/{promotion}', [PromotionController::class, 'destroy'])
                ->where('promotion', '[0-9]+')->name('promotions.destroy');
        });

        /*
         * THE ACTIVITY LOG (wave 4D) — read-only, and administrator-only.
         *
         * One GET. There is deliberately no edit and no delete, not even for an admin: a log
         * somebody can change answers nothing, and the absence of the route is the guarantee.
         *
         * Admin-only because it shows what every named person did — a management view, not a
         * working one.
         */
        Route::middleware('can:'.Role::MANAGE_USERS)->group(function (): void {
            Route::get('activity', [ActivityController::class, 'index'])->name('activity.index');
        });

        // Brands and the lookup lists. Catalogue-wide, not per storefront: a colour is a
        // colour on every storefront (D3, the shared catalogue).
        Route::middleware('can:'.Role::MANAGE_CATALOG)->group(function (): void {
            Route::get('lookups/{list}', [LookupController::class, 'index'])->name('lookups.index');
            Route::post('lookups/{list}', [LookupController::class, 'store'])->name('lookups.store');
            Route::put('lookups/{list}/{id}', [LookupController::class, 'update'])->name('lookups.update');
            Route::delete('lookups/{list}/{id}', [LookupController::class, 'destroy'])->name('lookups.destroy');

            /*
             * The units cleanup screen (wave 4D, task C3). Its own routes rather than a mode of the
             * lookup screen: a unit is not edited here, it is MERGED into another one and then
             * retired, and that is a different verb with a different refusal.
             */
            /*
             * SHIPPING (wave 4D — the handover blocker).
             *
             * The LIST is inside the catalogue group so data-entry reach it: the delivery price is
             * something they quote on the telephone. Every WRITE is wrapped again in
             * `can:manage-shipping` below, because the price is money — the same split the orders
             * export settled (screen for both roles, act for administrators).
             */
            Route::get('shipping', [ShippingController::class, 'index'])->name('shipping.index');

            Route::middleware('can:'.Role::MANAGE_SHIPPING)->group(function (): void {
                Route::post('shipping', [ShippingController::class, 'store'])->name('shipping.store');
                Route::put('shipping/{city}', [ShippingController::class, 'update'])
                    ->where('city', '[0-9]+')->name('shipping.update');
                Route::delete('shipping/{city}', [ShippingController::class, 'destroy'])
                    ->where('city', '[0-9]+')->name('shipping.destroy');
            });

            Route::get('units', [UnitController::class, 'index'])->name('units.index');
            Route::post('units/merge', [UnitController::class, 'merge'])->name('units.merge');
            Route::post('units/{unit}/retire', [UnitController::class, 'retire'])
                ->where('unit', '[0-9]+')->name('units.retire');
            Route::post('units/{unit}/restore', [UnitController::class, 'restore'])
                ->where('unit', '[0-9]+')->name('units.restore');
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
