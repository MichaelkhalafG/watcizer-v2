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
        // auth (OAuth redirect URIs registered on the legacy host; token issuance stays there)
        'login', 'register', 'logout', 'auth/*', 'updateProfile', 'updatePassword', 'me/avatar',
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
