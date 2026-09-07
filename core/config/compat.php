<?php

/*
|--------------------------------------------------------------------------
| Legacy-compat layer (CLEAN_CORE_STUDY §3.3, wave 2)
|--------------------------------------------------------------------------
| The core host answers the legacy Watchizer endpoint set with byte-identical
| legacy JSON built from the clean tables, and proxies every other /api/* path
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

    // Legacy locale negotiation (mcamara/laravel-localization config on the legacy host):
    // supported locales with their regional codes, default = legacy app.locale.
    'locales' => ['en' => 'en_GB', 'ar' => 'ar_AE'],
    'default_locale' => 'en',

    // Legacy paths the storefront never calls; the study (§3.3) retires them with 410.
    'gone' => [
        'products', 'all_category',
        'all_brand', 'all_grade', 'all_sub_type', 'all_category_type', 'all_color', 'all_closure_type',
        'all_display_type', 'all_size_type', 'all_shape', 'all_material', 'all_feature', 'all_movement_type',
        'all_gender', 'new_colors', 'new_sizes',
    ],

    // Application-cache TTLs (seconds) — the legacy app used 3600 / 600 for the same payloads.
    'ttl' => [
        'meta' => 3600,
        'all_product' => 600,
        'all_product_image' => 600,
        'names' => 3600,
    ],
];
