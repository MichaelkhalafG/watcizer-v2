<?php

use App\Domain\Customers\CustomerMail;
use App\Http\Controllers\Compat\AccountCompatController;
use App\Http\Controllers\Compat\CartCompatController;
use App\Http\Controllers\Compat\CatalogCompatController;
use App\Http\Controllers\Compat\CheckoutCompatController;
use App\Http\Controllers\Compat\GoneController;
use App\Http\Controllers\Compat\ProxyController;
use App\Http\Controllers\Customer\CustomerAuthController;
use App\Http\Controllers\Customer\CustomerPasswordController;
use App\Http\Controllers\Customer\CustomerProfileController;
use App\Http\Controllers\Customer\CustomerSocialController;
use App\Http\Controllers\Customer\CustomerVerificationController;
use App\Http\Controllers\Payment\PaymentCallbackController;
use App\Http\Controllers\V2\CategoryController;
use App\Http\Controllers\V2\MetaController;
use App\Http\Controllers\V2\PaymentMethodController;
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

// The checkout's payment methods: v2, but NOT under http.cache — its ten minutes plus an hour of
// stale would keep a switched-off method on the checkout for that long (PaymentMethodController).
Route::prefix('v2/{storefront}')->middleware('storefront')->where(['storefront' => '[a-z0-9_-]+'])->group(function (): void {
    Route::get('payment-methods', [PaymentMethodController::class, 'index']);
});

// ── 2. compat (legacy paths, legacy shapes) ───────────────────────────────
Route::middleware('api.code')->group(function (): void {
    /*
     * PRIVATE, not public (2026-09-29). These were `public` (legacy parity), and Hostinger's CDN in
     * front of api.watchizereg.com stores public responses — ONE copy per URL: it drops Laravel's
     * `Vary: Origin`. Whichever request filled the copy decided the CORS header for everyone for the
     * max-age: the storefront's own server-side fetch sends no Origin, gets no
     * Access-Control-Allow-Origin, and every browser then failed CORS ("No
     * 'Access-Control-Allow-Origin' header") until the copy expired. Measured live: a request from
     * www.watchizereg.com got `Access-Control-Allow-Origin: https://watchizereg.com` back from a
     * cache HIT. `private` keeps the browser's own cache and ETag and forbids shared caches. A
     * deliberate divergence from the legacy headers (compat:diff compares Cache-Control).
     */
    // meta + shipping: the legacy cache holds Eloquent models, so these still localise per request (F-18).
    Route::middleware(['cache.headers:private;max_age=1800;etag', 'legacy.locale'])->group(function (): void {
        Route::get('catalog/meta', [CatalogCompatController::class, 'meta']);
        Route::get('show_shipping_city', [CatalogCompatController::class, 'shippingCities']);
    });
    Route::middleware('cache.headers:private;max_age=600;etag')->group(function (): void {
        Route::get('all_product', [CatalogCompatController::class, 'allProduct']);
        // Storefront-only (C-1 stage 2): the header menu's catalogue facts, derived from all_product.
        Route::get('catalog/nav', [CatalogCompatController::class, 'nav']);
        // Storefront-only (C-1 stage 3): the listing with its facet counts, and product cards by id.
        Route::get('catalog/listing', [CatalogCompatController::class, 'listing']);
        Route::get('catalog/cards', [CatalogCompatController::class, 'cards']);
        // C-1 stage 4: the product page's related products and the cart's suggestions, scored here.
        Route::get('catalog/related', [CatalogCompatController::class, 'related']);
        // C-1 stage 4: one product for the product page, by URL slug or id.
        Route::get('catalog/product', [CatalogCompatController::class, 'product']);
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
        /*
         * ── Locale NEGOTIATED on the two writes here whose errors reach the shopper (2026-09-23) ──────────────────
         *
         * The legacy host sets the locale from Accept-Language on EVERY request (default `en`, hard-
         * coded in its config/app.php) and ships `lang/ar/validation.php`: an Arabic phone gets
         * Arabic field errors under the checkout inputs, anything else English (`Checkout.jsx`
         * renders `errors[field][0]`). Outside this group core answered in `APP_LOCALE`, which is
         * `ar` on the server since 2026-09-20 (the dashboard's Arabic front door) — so after the
         * flip EVERY shopper, English browser included, would have got Arabic field errors. That
         * came AFTER the last compat:diff run, which is why no harness case had caught it: the
         * header-less `address:invalid`, `cart:remove:*` and `checkout:no-address` cases would all
         * have failed the next re-diff.
         *
         * Only the routes whose RESPONSE carries the validator's text are here. `add_to_cart`
         * validates too, but legacy catches `\Exception` around it and answers a 500 with a ref, so
         * no field text ever reaches the client and the locale changes nothing it can see (compat
         * reproduces that, see CartCases `cart:add:invalid`). `delete_cart`, `me/cart` and
         * `cart/validate` produce nothing locale-dependent in core. Negotiating on any of those
         * would add risk and change nothing.
         * Nothing PERSISTED depends on it either: the method list LEFT JOINs its labels with a
         * fallback, the mail path reads no locale, and the order's city name is pinned to `en`.
         *
         * What is NOT byte-identical: core's Arabic phrasing for a few rules (`exists`, `integer`,
         * some attribute names) differs from legacy's file — same language, same status, same field
         * keys. Sanctioned as D-25 in DeviationRules rather than copying legacy's strings into a
         * file the dashboard shares.
         */
        Route::post('add_to_cart', [CartCompatController::class, 'add']);
        Route::middleware('legacy.locale')->group(function (): void {
            Route::post('remove_from_cart', [CartCompatController::class, 'remove']);
            // Throttled per endpoint (security audit, Finding 2): each accepted call reserves stock.
            Route::post('add_order', [CheckoutCompatController::class, 'addOrder'])->middleware('throttle:add-order');
        });
        Route::delete('delete_cart/{id}', [CartCompatController::class, 'destroy']);
        Route::get('me/cart', [CartCompatController::class, 'show']);
        Route::post('cart/validate', [CartCompatController::class, 'validateCart']);
    });
    /*
     * ── customer accounts (storefront Phase 1, piece 3, 2026-09-21) ──────────────────────────
     *
     * These were PROXIED to the legacy host for the whole compat period, and they are the reason
     * Phase 2 could not simply be an environment flip: the storefront cannot be pointed at this
     * database while its accounts are created on another one. A customer who registered over there
     * would hold a token whose `sub` does not exist here, and every authenticated call would answer
     * 401 with nothing to explain it.
     *
     * Both spellings are registered because both exist on the legacy host and the storefront uses
     * each: `Login.jsx` posts `/login`, `ForgotPassword.jsx` posts `/auth/forgot-password`, and
     * `AuthCallback.jsx` reads `/auth/me`. Same controller, same behaviour — the prefix is an
     * accident of how the legacy routes grew, not a distinction.
     */
    Route::post('login', [CustomerAuthController::class, 'login']);
    // Per-endpoint throttle (review 🟠-4 / 🟡-11): registration creates an account AND sends mail
    // from the shop's own domain, so the global 60/min is not a ceiling anyone would have chosen.
    Route::post('register', [CustomerAuthController::class, 'register'])
        ->middleware('throttle:customer-register');
    Route::post('logout', [CustomerAuthController::class, 'logout']);
    Route::post('auth/login', [CustomerAuthController::class, 'login']);
    // Social sign-in START (piece 5). Keeps the API key: `SocialButtons.jsx` fetches this and
    // then navigates itself, so the flow is stateless and there is no server session anywhere.
    Route::get('auth/{provider}/redirect', [CustomerSocialController::class, 'redirect'])
        ->where('provider', '[a-z]{2,20}');
    Route::post('auth/register', [CustomerAuthController::class, 'register'])
        ->middleware('throttle:customer-register');

    // Password reset (piece 4). Unauthenticated by nature: the customer is here because they
    // cannot sign in. The token is the credential, and the broker verifies it against
    // `core_password_resets` — never the legacy table, which the legacy app still uses.
    // `forgot` is throttled by the broker itself (one link per address per minute). `reset` had
    // nothing but the global 60/min in front of a one-hour token — see AppServiceProvider.
    Route::post('auth/forgot-password', [CustomerPasswordController::class, 'forgot']);
    Route::post('auth/reset-password', [CustomerPasswordController::class, 'reset'])
        ->middleware('throttle:customer-reset');

    Route::middleware('compat.auth')->group(function (): void {
        Route::get('auth/me', [CustomerAuthController::class, 'me']);
        Route::post('auth/logout', [CustomerAuthController::class, 'logout']);
        Route::post('auth/resend-verification', [CustomerVerificationController::class, 'resend']);
        Route::post('updateProfile', [CustomerProfileController::class, 'update']);
        Route::post('updatePassword', [CustomerProfileController::class, 'password']);
        Route::delete('me/avatar', [CustomerProfileController::class, 'removeAvatar']);
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
    // Negotiated for the same reason as the three cart/checkout writes above: it validates, and
    // the legacy host answers its field errors in the shopper's Accept-Language.
    Route::post('add_address', [AccountCompatController::class, 'addAddress'])->middleware('legacy.locale');

    /** @var list<string> $gone */
    $gone = config()->array('compat.gone');
    foreach ($gone as $path) {
        Route::get($path, GoneController::class);
    }
});
/*
 * ── social sign-in CALLBACK (storefront Phase 1, piece 5) ────────────────────────────
 *
 * OUTSIDE the `api.code` group, and it has to be: the browser arrives here from Google, and no
 * third-party redirect carries an `Api-Code` header this application invented. The legacy routes
 * file puts its own callback outside `CheckApi` in the same words.
 *
 * What stands in the header's place is the provider's own signed exchange — Socialite trades the
 * `code` with Google over TLS using the client secret, and a caller who cannot complete that
 * exchange receives nothing. The response is always a REDIRECT to the storefront, never JSON: this
 * is a page a person is looking at.
 */
Route::get('auth/{provider}/callback', [CustomerSocialController::class, 'callback'])
    ->where('provider', '[a-z]{2,20}')
    ->name('customer.social.callback');

/*
 * ── e-mail verification (storefront Phase 1, piece 4) ─────────────────────────────────
 *
 * OUTSIDE the `api.code` group, and it has to be: this URL is clicked by a person in their mail
 * client, and no mail client sends an `Api-Code` header this application invented. The legacy
 * routes file puts its own verification route outside `CheckApi` for the same stated reason.
 *
 * What stands in the header's place is `signed`, which is a stronger check than a shared public
 * key: the signature is computed from this application's `APP_KEY` and cannot be forged by anybody
 * who merely knows the customer's e-mail address — which is all the `{hash}` segment proves.
 */
Route::get('auth/verify-email/{id}/{hash}', [CustomerVerificationController::class, 'verify'])
    ->middleware('signed')
    ->where('id', '[0-9]+')
    ->where('hash', '[0-9a-f]{40}')
    ->name(CustomerMail::VERIFY_ROUTE);

/*
 * ── payment callbacks (wave 4C, study §3.9.2) ────────────────────────────────────────────────
 *
 * The only unauthenticated routes on this host, and they cannot be otherwise: a payment provider
 * cannot send an `Api-Code` header this application invented. The SIGNATURE is the authentication,
 * verified against the resolved contract's own secret — so two storefronts holding two Paymob
 * accounts cannot validate each other's callbacks.
 *
 * The scoped route is the one every NEW storefront, provider and method is registered with. The
 * alias below is Watchizer's existing Paymob URL, kept permanently, because that URL lives in the
 * merchant portal against an integration id and changing it has no atomic cutover: a transaction in
 * flight at that moment would call back to the old address, leaving money taken and an order with
 * nobody to confirm it. Both run the SAME four checks in the SAME controller.
 */
/*
 * GET **and POST** (review G2b). Paymob delivers a callback twice and by two different methods:
 *
 *   - `redirection_url` — the SHOPPER'S BROWSER returning, a GET with the twenty signed fields
 *     flattened into the query string;
 *   - `notification_url` — the PROCESSED callback, a server-to-server POST whose JSON body nests
 *     the same fields under `obj.*`, with the `hmac` on the query string.
 *
 * Registering GET only meant the processed callback got a 405 and the order depended entirely on
 * the shopper coming back — and a shopper who closes the tab after paying is exactly the case the
 * processed callback exists for. Both methods run the SAME four checks in the same controller;
 * `CompatCheckout::paymobField()` already reads both payload shapes, which is why this is a route
 * line and not a second handler.
 */
Route::match(['get', 'post'], 'pay/{storefront}/{provider}/callback', [PaymentCallbackController::class, 'handle'])
    ->where('storefront', '[a-z0-9-]{2,32}')
    ->where('provider', '[a-z0-9_]{2,32}')
    ->name('pay.callback');

/*
 * Watchizer's EXISTING Paymob URL. It resolves (storefront 1, paymob) and runs the same four
 * checks — but only once that contract holds credentials. Until the runbook's `.env`-to-table
 * step is performed it falls through to the wave-3 handler, so today's live callback behaves
 * exactly as it does today. See PaymentCallbackController::alias().
 */
Route::match(['get', 'post'], 'callback_payment', [PaymentCallbackController::class, 'alias'])->name('pay.callback.alias');

Route::any('categories/{any}', GoneController::class)->where('any', '.*');

// ── 3. proxy (everything else under /api that is not v2) ──────────────────
Route::any('{path}', ProxyController::class)->where('path', '(?!v2/).*');
