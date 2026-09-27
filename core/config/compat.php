<?php

/*
|--------------------------------------------------------------------------
| Legacy-compat layer (CLEAN_CORE_STUDY §3.3, wave 2)
|--------------------------------------------------------------------------
| The core host answers the legacy Watchizer endpoint set with byte-identical
| legacy JSON built from the clean tables, and proxies the study's proxy rows
| to the legacy application. Nothing here is a secret: the Api-Code value is the
| same public key the storefront ships in its NEXT_PUBLIC_* bundle.
*/

return [
    // The value the storefront sends in the `Api-Code` header (legacy CheckApiMiddleware).
    'api_key' => (string) env('COMPAT_API_KEY', ''),

    // Base of the image URLs the legacy resources emit (legacy `services.asset_base`).
    'asset_base' => (string) env('COMPAT_ASSET_BASE', 'https://dash.watchizereg.com'),

    // Legacy application origin for the proxied paths (auth, offers, blogs, wishlist, cart …).
    'legacy_base' => (string) env('COMPAT_LEGACY_BASE', 'https://dash.watchizereg.com'),
    'proxy_timeout' => 30,

    // The storefront the compat layer serves. Always Watchizer.
    'storefront_id' => 1,

    // Legacy SitemapController constants (hard-coded there, mirrored here).
    'sitemap_domain' => 'https://watchizereg.com',
    'sitemap_image_host' => 'https://dash.watchizereg.com',

    /*
    | Locale of the appended translated attributes (`brand_name`, `product_title`, …) on the
    | cached compat payloads. The legacy host negotiates a locale from Accept-Language on every
    | request but caches `catalog/meta` (1 h), `all_product` (10 min) and `show_shipping_city`
    | (10 min) under locale-blind keys, so it serves whichever locale warmed the cache — EN in
    | practice (the SSR prefetch sends no Accept-Language). That behaviour is the contract
    | (review 🟠-2, decision 2026-09-08): compat pins EN and never localises these payloads.
    */
    'pinned_locale' => 'en',

    // Legacy locale negotiation (mcamara/laravel-localization on the legacy host) — used only
    // where the legacy host is NOT cache-blind: the `/sitemap.xml` locale redirect.
    'locales' => ['en' => 'en_GB', 'ar' => 'ar_AE'],
    'default_locale' => 'en',

    // Legacy paths the storefront never calls; the study (§3.3) retires them with 410.
    'gone' => [
        'products', 'all_category',
        'all_brand', 'all_grade', 'all_sub_type', 'all_category_type', 'all_color', 'all_closure_type',
        'all_display_type', 'all_size_type', 'all_shape', 'all_material', 'all_feature', 'all_movement_type',
        'all_gender', 'new_colors', 'new_sizes',
    ],

    /*
    | The ONLY paths the reverse proxy forwards (study §3.3 "proxy" rows + the wave-3 cart/checkout
    | and account rows that ride the proxy until they move). fnmatch patterns on the path after
    | /api/. Anything else answers 404 here and never reaches the legacy host (review 🟡-8).
    */
    'proxy_paths' => [
        /*
         * auth — what is LEFT of it. Storefront Phase 1 (2026-09-21) moved `login`, `register`,
         * `logout`, `auth/login`, `auth/register`, `auth/logout`, `auth/me`, `updateProfile`,
         * `updatePassword` and `me/avatar` into core, so none of them may reach the legacy host any
         * more — an account created over there would be invisible here, which is the whole reason
         * the move had to happen before the storefront is repointed.
         *
         * Piece 4 (2026-09-21) took the password-reset pair and e-mail verification. **Piece 5
         * (2026-09-22) took the OAuth round trip, and with it the last auth path on this list: the
         * legacy host now answers NO authentication request from this application at all.**
         *
         * That is the property Phase 2 needs. An auth path that could still reach the legacy host
         * would create the account over THERE, and the customer would come back holding a token
         * whose `sub` does not exist in this database — 401 on every call, with nothing in any log
         * to explain it.
         *
         * `auth/*` is deliberately NOT a wildcard: each remaining path is named, so nothing joins
         * the proxy by accident when a new one appears under that prefix.
         */
        // (auth is GONE from this list as of piece 5, 2026-09-22 — see the note above.)
        // legacy content (D5)
        'all_offer', 'all_offer_rating', 'all_blog', 'all_banner_home', 'all_banner_side', 'all_banner_bottom',
        // wishlist + ratings (post-season auth wave)
        'all_wishlist', 'all_wishlist/*', 'add_wishlist', 'delete_wishlist/*', 'add_product_rating', 'add_offer_rating',
        // (wave 3 moved every cart / checkout / account row off this list — nothing left here)
    ],

    /*
    | Legacy JWT verification (wave 3). Core only VERIFIES: issuance, refresh and logout stay on
    | the legacy host for the whole compat period, so this is the shared HS256 secret and nothing
    | else. It is read from the environment and has NO default — an unset secret means every
    | authenticated compat path answers 401, which is the safe direction.
    */
    'jwt_secret' => env('JWT_SECRET'),
    'jwt_algo' => env('JWT_ALGO', 'HS256'),
    'jwt_leeway' => (int) env('JWT_LEEWAY', 0),

    /*
     * Token lifetime in MINUTES, and 43200 is not a round number somebody liked: it is the
     * legacy app's own `jwt.ttl` default (30 days), so a token core issues expires exactly when
     * one the legacy app issued would have. Phase 1, 2026-09-21.
     */
    'jwt_ttl' => (int) env('JWT_TTL', 43200),

    /*
     * Unpaid card orders (2026-09-26, App\Domain\Orders\UnpaidOrders). A card order reserves its
     * stock at checkout; one still unpaid after `expire_after_minutes` is cancelled and its stock
     * returned by `orders:expire-unpaid`. `intention_expiration_seconds` is NOT sent to Paymob
     * until it is set in `.env` from a measurement — Paymob does not document the field's unit
     * (see PaymobProvider). When set, it must be shorter than the window or it is not sent.
     */
    'unpaid' => [
        'expire_after_minutes' => (int) env('UNPAID_ORDER_EXPIRY_MINUTES', 60),
        'intention_expiration_seconds' => is_numeric(env('PAYMOB_INTENTION_EXPIRATION_SECONDS')) ? (int) env('PAYMOB_INTENTION_EXPIRATION_SECONDS') : null,
    ],

    // Where callback_payment sends the shopper back to, hard-coded in the legacy controller.
    'payment_return_url' => env('COMPAT_PAYMENT_RETURN_URL', 'https://watchizereg.com/'),

    // Application-cache TTLs (seconds) — the legacy app used 3600 / 600 for the same payloads.
    'ttl' => [
        'meta' => 3600,
        'all_product' => 600,
        'all_product_image' => 600,
        'names' => 3600,
    ],
];
