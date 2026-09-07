<?php

use App\Http\Controllers\Compat\CatalogCompatController;
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
Route::middleware(['api.code', 'legacy.locale'])->group(function (): void {
    Route::middleware('cache.headers:public;max_age=1800;etag')->group(function (): void {
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

    /** @var list<string> $gone */
    $gone = config()->array('compat.gone');
    foreach ($gone as $path) {
        Route::get($path, GoneController::class);
    }
});
Route::any('categories/{any}', GoneController::class)->where('any', '.*');

// ── 3. proxy (everything else under /api that is not v2) ──────────────────
Route::any('{path}', ProxyController::class)->where('path', '(?!v2/).*');
