<?php

use App\Http\Controllers\Compat\AccountCompatController;
use App\Http\Controllers\Compat\CartCompatController;
use App\Http\Controllers\Compat\CatalogCompatController;
use App\Http\Controllers\Compat\CheckoutCompatController;
use App\Http\Controllers\Compat\GoneController;
use App\Http\Controllers\Compat\ProxyController;
use App\Http\Controllers\V2\CategoryController;
use App\Http\Controllers\V2\MetaController;
use App\Http\Controllers\V2\ProductController;
use App\Http\Controllers\V2\SitemapController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| /api — three surfaces, one truth underneath (CLEAN_CORE_STUDY §1, §3.3)
|--------------------------------------------------------------------------
| 1. /api/v2/{storefront}/…   native v2 read API (clean tables, both storefronts)
| 2. /api/{legacy path}       compat: legacy JSON from clean tables (Watchizer only)
| 3. /api/{anything else}     reverse proxy to the legacy application
*/

// ── 1. v2 ──────────────────────────────────────────────────────────────────
Route::prefix('v2/{storefront}')->middleware(['storefront', 'http.cache'])->where(['storefront' => '[a-z0-9_-]+'])->group(function (): void {
    Route::get('meta', [MetaController::class, 'show']);
    Route::get('categories', [CategoryController::class, 'tree']);
    Route::get('categories/{path}', [CategoryController::class, 'show'])->where('path', '[a-z0-9\-]+(?:/[a-z0-9\-]+)*');
    Route::get('products', [ProductController::class, 'index']);
    Route::get('products/{slug}', [ProductController::class, 'show'])->where('slug', '[^/]+');
    Route::get('sitemap.xml', [SitemapController::class, 'index']);
    Route::get('sitemaps/{locale}/{file}.xml', [SitemapController::class, 'chunk'])->where(['locale' => '[a-z]{2}', 'file' => '[a-z0-9\-]+']);
});

// ── 2. compat (legacy paths, legacy shapes) ───────────────────────────────
Route::middleware('api.code')->group(function (): void {
    // meta + shipping: the legacy cache holds Eloquent models, so these still localise per request (F-18).
    Route::middleware(['cache.headers:public;max_age=1800;etag', 'legacy.locale'])->group(function (): void {
        Route::get('catalog/meta', [CatalogCompatController::class, 'meta']);
        Route::get('show_shipping_city', [CatalogCompatController::class, 'shippingCities']);
    });
    Route::middleware('cache.headers:public;max_age=600;etag')->group(function (): void {
        Route::get('all_product', [CatalogCompatController::class, 'allProduct']);
        Route::get('all_product_image', [CatalogCompatController::class, 'allProductImage']);
        Route::get('all_product_rating', [CatalogCompatController::class, 'allProductRating']);
        Route::get('products/by-name/{name}', [CatalogCompatController::class, 'showByName']);
        // Registered but never called by the storefront (§3.3 last row) — retired before the id route.
        Route::get('products/{product}/variants', GoneController::class);
        Route::get('products/{product}/variants/summary', GoneController::class);
        Route::get('products/{id}', [CatalogCompatController::class, 'show']);
    });

    // ── cart, checkout and account (wave 3) ───────────────────────────────
    // Never HTTP-cached: the legacy app puts every guest.cart / auth:api endpoint in its own
    // group for exactly that reason, and a cached cart would be one shopper's cart served to
    // the next. `compat.guest` mints and echoes the X-Guest-Token; `compat.auth` is the core
    // equivalent of `auth:api`, validating the legacy JWT with the shared secret.
    Route::middleware('compat.guest')->group(function (): void {
        Route::post('add_to_cart', [CartCompatController::class, 'add']);
        Route::post('remove_from_cart', [CartCompatController::class, 'remove']);
        Route::delete('delete_cart/{id}', [CartCompatController::class, 'destroy']);
        Route::get('me/cart', [CartCompatController::class, 'show']);
        Route::post('cart/validate', [CartCompatController::class, 'validateCart']);
        Route::post('add_order', [CheckoutCompatController::class, 'addOrder']);
    });
    Route::middleware('compat.auth')->group(function (): void {
        Route::post('cart/merge', [CartCompatController::class, 'merge']);
        // The account reads carry the same locale negotiation as show_shipping_city: the legacy
        // RouteServiceProvider calls LaravelLocalization::setLocale() on every request, so the
        // nested shipping-city name follows the request locale there too (F-18).
        Route::middleware('legacy.locale')->group(function (): void {
            Route::get('me/orders', [AccountCompatController::class, 'orders']);
            Route::get('me/addresses', [AccountCompatController::class, 'addresses']);
        });
        Route::delete('me/addresses/{id}', [AccountCompatController::class, 'deleteAddress']);
    });
    Route::post('add_address', [AccountCompatController::class, 'addAddress']);

    /** @var list<string> $gone */
    $gone = config()->array('compat.gone');
    foreach ($gone as $path) {
        Route::get($path, GoneController::class);
    }
});
// Paymob calls this one; it carries no Api-Code header, and the legacy app registers it
// OUTSIDE the CheckApi group for that reason. HMAC is the authentication.
Route::get('callback_payment', [CheckoutCompatController::class, 'callbackPayment']);

Route::any('categories/{any}', GoneController::class)->where('any', '.*');

// ── 3. proxy (everything else under /api that is not v2) ──────────────────
Route::any('{path}', ProxyController::class)->where('path', '(?!v2/).*');
