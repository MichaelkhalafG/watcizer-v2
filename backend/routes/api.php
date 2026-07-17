<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\GeneralController;
use App\Http\Controllers\Api\CreateProductController;
use App\Http\Controllers\Api\DetailsProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductVariantApiController;
use App\Http\Controllers\Api\ProductListingController;
use App\Http\Controllers\Api\CatalogMetaController;
use App\Http\Controllers\Api\SocialAuthController;

Route::middleware(['api', 'CheckApi'])->group(function () {

    // ── Cacheable catalog GETs ──────────────────────────────────────────────
    // Add HTTP Cache-Control (public) + an ETag so browsers/proxies can cache and
    // repeat requests come back 304 Not Modified. SetCacheHeaders only acts on
    // GET/HEAD, so it is a no-op on the POST/DELETE routes below, and the private
    // (auth:api / guest.cart) endpoints live in separate groups → never cached.
    // TTLs tuned per data volatility. all_wishlist is deliberately left uncached.

    // General + lookup tables — reference data, rarely change (30 min).
    Route::middleware('cache.headers:public;max_age=1800;etag')->group(function () {
        Route::get('catalog/meta', [CatalogMetaController::class, 'index']);
        Route::get('all_category', [GeneralController::class, 'AllCategory']);
        Route::get('all_blog',     [GeneralController::class, 'AllBlog']);

        Route::get('all_brand',         [CreateProductController::class, 'AllBrand']);
        Route::get('all_grade',         [CreateProductController::class, 'AllGrade']);
        Route::get('all_sub_type',      [CreateProductController::class, 'AllSubType']);
        Route::get('all_category_type', [CreateProductController::class, 'AllCategoryType']);
        Route::get('all_color',         [CreateProductController::class, 'AllColor']);
        Route::get('all_closure_type',  [CreateProductController::class, 'AllClosureType']);
        Route::get('all_display_type',  [CreateProductController::class, 'AllDisplayType']);
        Route::get('all_size_type',     [CreateProductController::class, 'AllSizeType']);
        Route::get('all_shape',         [CreateProductController::class, 'AllShape']);
        Route::get('all_material',      [CreateProductController::class, 'AllMaterial']);
        Route::get('all_feature',       [CreateProductController::class, 'AllFeature']);
        Route::get('all_movement_type', [CreateProductController::class, 'AllMovementType']);
        Route::get('all_gender',        [CreateProductController::class, 'AllGender']);
    });

    // Banners — rotate occasionally (15 min).
    Route::middleware('cache.headers:public;max_age=900;etag')->group(function () {
        Route::get('all_banner_home',   [BannerController::class, 'AllBannerHome']);
        Route::get('all_banner_side',   [BannerController::class, 'AllBannerSide']);
        Route::get('all_banner_bottom', [BannerController::class, 'AllBannerBottom']);
    });

    // Products / offers / ratings — stock & price move, so a shorter cache (10 min).
    Route::middleware('cache.headers:public;max_age=600;etag')->group(function () {
        Route::get('products',                [ProductListingController::class, 'index']);
        Route::get('products/by-name/{name}', [ProductListingController::class, 'showByName']);
        Route::get('products/{id}',           [ProductListingController::class, 'show']);
        Route::get('all_product',             [DetailsProductController::class, 'AllProduct']);
        Route::get('all_product_image',       [DetailsProductController::class, 'AllProductImage']);
        Route::get('all_product_rating',      [DetailsProductController::class, 'AllProductRating']);
        Route::get('all_offer',               [DetailsProductController::class, 'AllOffer']);
        Route::get('all_offer_rating',        [DetailsProductController::class, 'AllOfferRating']);
    });
    // add_product_rating / add_offer_rating moved to the auth:api group below so
    // the rater is resolved from the JWT (was auth() web guard → null for the SPA).
    Route::get('all_wishlist',            [DetailsProductController::class, 'AllWishlist']);
    Route::get('all_wishlist/{user_id}',  [DetailsProductController::class, 'AllWishlist']);
    // add_wishlist / delete_wishlist moved to the auth:api group below — wishlist
    // is a logged-in-only feature; the owner is resolved from the JWT.

    // Orders //
    Route::get('show_shipping_city',   [OrderController::class, 'ShowShippingCity'])->middleware('cache.headers:public;max_age=1800;etag');
    Route::post('add_address',         [OrderController::class, 'AddAddress']);
    // DEPRECATED: remove after P1 — replaced by authenticated GET me/addresses
    // Route::get('show_address',         [OrderController::class, 'ShowAddress']);
    Route::post('add_to_cart',         [OrderController::class, 'AddToCart'])->middleware('guest.cart');
    // DEPRECATED: remove after P1 — replaced by authenticated GET me/cart
    // Route::get('show_cart',            [OrderController::class, 'ShowCart']);
    // Route::get('show_cart/{user_id}',  [OrderController::class, 'ShowCart']);
    Route::delete('delete_cart/{id}',  [OrderController::class, 'DeleteCart'])->middleware('guest.cart');
    // Remove a line by product_id/offer_id (keeps the DB cart in sync when a
    // shopper removes an item on the frontend). POST (not DELETE) so the body is
    // parsed reliably across all PHP/proxy setups.
    Route::post('remove_from_cart',    [OrderController::class, 'RemoveFromCart'])->middleware('guest.cart');
    Route::post('add_order',           [OrderController::class, 'AddOrder'])->middleware('guest.cart');
    // DEPRECATED: remove after P1 — replaced by authenticated GET me/orders
    // Route::get('show_order',           [OrderController::class, 'ShowOrder']);

    // Auth //
    // DEPRECATED (P0-4): exposed all users publicly. Re-add behind an admin guard if the admin panel needs it.
    // Route::get('all_user',         [AuthController::class, 'AllUser']);
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('login',           [AuthController::class, 'login']);
    Route::post('logout',          [AuthController::class, 'logout']);
    // updatePassword / updateProfile moved to the auth:api group below so the
    // caller is resolved from the JWT (was keyed on a client-supplied id → IDOR).

    // ── NEW: Variants & Colors & Sizes ── reference data (30 min HTTP cache + ETag).
    Route::middleware('cache.headers:public;max_age=1800;etag')->group(function () {
        Route::get('products/{product}/variants',         [ProductVariantApiController::class, 'index']);
        Route::get('products/{product}/variants/summary', [ProductVariantApiController::class, 'summary']);
        Route::get('new_colors',                          [ProductVariantApiController::class, 'colors']);
        Route::get('new_sizes',                           [ProductVariantApiController::class, 'sizes']);
    });

});

// Authenticated (JWT) endpoints — scoped to the logged-in caller
Route::middleware(['api', 'CheckApi', 'auth:api'])->group(function () {
    Route::get('me/orders',            [OrderController::class, 'ShowOrder']);
    Route::get('me/addresses',         [OrderController::class, 'ShowAddress']);
    Route::delete('me/addresses/{id}', [OrderController::class, 'DeleteAddress']);

    // Account mutations — resolved from the JWT (IDOR-safe).
    Route::post('updateProfile',       [AuthController::class, 'updateProfile']);
    Route::post('updatePassword',      [AuthController::class, 'updatePassword']);
    Route::delete('me/avatar',         [AuthController::class, 'removeAvatar']);

    // Wishlist — logged-in only; owner resolved from the JWT.
    Route::post('add_wishlist',        [DetailsProductController::class, 'AddWishlist']);
    Route::delete('delete_wishlist/{id}', [DetailsProductController::class, 'DeleteWishlist']);

    // Ratings — must be an authenticated user; rater id comes from the JWT.
    Route::post('add_product_rating',  [DetailsProductController::class, 'AddProductRating']);
    Route::post('add_offer_rating',    [DetailsProductController::class, 'AddOfferRating']);
});

// ── Auth v2 (namespaced under /auth) — adds Socialite, password reset and
// email verification on top of the existing JWT flow. The legacy /login,
// /register, /logout routes above are preserved (the SPA still calls them).
//
// SPA-called endpoints carry the Api-Code header → keep them behind CheckApi.
Route::middleware(['api', 'CheckApi'])->prefix('auth')->group(function () {
    Route::post('register',        [AuthController::class, 'register']);
    Route::post('login',           [AuthController::class, 'login']);          // rate-limited inside the controller
    Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('reset-password',  [AuthController::class, 'resetPassword']);
});

// Browser/email-hit endpoints: reached by an OAuth redirect or an email link,
// neither of which can send the Api-Code header → no CheckApi here.
Route::middleware('api')->prefix('auth')->group(function () {
    Route::get('{provider}/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('{provider}/callback', [SocialAuthController::class, 'callback']);
    Route::get('verify-email/{id}/{hash}', [AuthController::class, 'verifyEmail'])
        ->middleware('signed')
        ->name('api.verification.verify');
});

// Protected (JWT) auth endpoints.
Route::middleware(['api', 'CheckApi', 'auth:api'])->prefix('auth')->group(function () {
    Route::post('logout',              [AuthController::class, 'logout']);
    Route::get('me',                   [AuthController::class, 'me']);
    Route::post('resend-verification', [AuthController::class, 'resendVerification']);
});

// Cart read — guest-capable: guest.cart resolves either a JWT user or a guest token
Route::middleware(['api', 'CheckApi', 'guest.cart'])->group(function () {
    Route::get('me/cart',      [OrderController::class, 'ShowCart']);
    // Checkout-time validation (stock + price) — works for guests and users.
    Route::post('cart/validate', [OrderController::class, 'validateCart']);
});

// Merge a guest cart into the logged-in user's cart (JWT-protected).
Route::middleware(['api', 'CheckApi', 'auth:api'])->group(function () {
    Route::post('cart/merge', [OrderController::class, 'mergeCart']);
});

// Category Hierarchy API (no auth - used by admin dashboard)
Route::prefix('categories')->group(function () {
    Route::get('/main',                [CategoryController::class, 'mainCategories']);
    Route::get('/{parentId}/children', [CategoryController::class, 'children']);
    Route::get('/tree',                [CategoryController::class, 'tree']);
});

Route::get('callback_payment', [OrderController::class, 'CallbackPayment']);