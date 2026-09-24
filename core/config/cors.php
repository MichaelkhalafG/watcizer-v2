<?php

/*
|--------------------------------------------------------------------------
| CORS — mirrors backend/config/cors.php (wave 2 review 🔴-1)
|--------------------------------------------------------------------------
| The core host answers the storefront's /api/* calls (compat + v2) and proxies
| the rest to the legacy host, so it must enforce the SAME curated origin list
| the legacy app enforces. Without this file Laravel falls back to
| allowed_origins => ['*'] and every path the core serves — including the
| proxied authenticated ones — answers any origin. Keep the two files in step.
*/

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter([
        (string) env('FRONTEND_URL', 'https://watchizereg.com'),
        'https://www.watchizereg.com',
        'https://dash.watchizereg.com',
        env('APP_ENV') === 'local' ? 'http://localhost:3000' : null,
        env('APP_ENV') === 'local' ? 'http://localhost:5173' : null,
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    /*
     * `X-Guest-Token` MUST be readable cross-origin (security audit Finding 9, 2026-09-23). The
     * storefront reads it off every response to keep the guest cart (`api.jsx`), and a browser
     * hides any non-safelisted response header from a cross-origin caller unless it is listed
     * here. Same-origin through the old proxy it did not matter; on `api.watchizereg.com` it does
     * — without this every request mints a fresh guest cart and the basket empties itself.
     */
    'exposed_headers' => ['X-Guest-Token'],

    'max_age' => 0,

    'supports_credentials' => false,
];
