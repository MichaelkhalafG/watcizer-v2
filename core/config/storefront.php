<?php

/*
|--------------------------------------------------------------------------
| v2 read API (CLEAN_CORE_STUDY §3.5 native shapes, §5 performance, §6.4 sitemaps)
|--------------------------------------------------------------------------
*/

return [
    // Base of the media URLs (Uploads_Images lives on the legacy host during the transition, §1).
    'asset_base' => (string) env('STOREFRONT_ASSET_BASE', 'https://dash.watchizereg.com'),

    'listing' => [
        'per_page' => 24,
        'max_per_page' => 96,
        'count_cap' => 10001,      // COUNT(*) is bounded (§5.1): the header never counts a 20k category
        'related' => 8,
    ],

    'sitemap' => [
        'chunk' => 5000,
    ],

    // Application-cache TTLs in seconds (§5.2 table).
    'ttl' => [
        'storefront' => 600,
        'meta' => 3600,
        'tree' => 3600,
        'lookups' => 3600,
        'count' => 600,
        'product' => 3600,
        'sitemap' => 21600,
    ],

    'http_cache' => [
        'control' => 'max-age=60, public, s-maxage=600, stale-while-revalidate=3600',   // Symfony emits directives in this (sorted) order
    ],
];
