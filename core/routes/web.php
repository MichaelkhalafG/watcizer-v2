<?php

use App\Domain\Access\Role;
use App\Http\Controllers\Compat\SitemapCompatController;
use App\Http\Controllers\Manage\Auth\LoginController;
use App\Http\Controllers\Manage\HomeController;
use App\Http\Controllers\Manage\MediaController;
use App\Http\Controllers\Manage\StorefrontController;
use App\Http\Middleware\EnsureDashboardAccess;
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
