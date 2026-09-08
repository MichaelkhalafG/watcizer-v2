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

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
