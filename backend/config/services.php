<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'public_api_key' => env('PUBLIC_API_KEY'),

    'asset_base' => env('ASSET_BASE', env('APP_URL')),

    // SPA base URL — resolved here (not via env() in app code) so it survives
    // `php artisan config:cache`, which stops loading .env at runtime.
    'frontend_url' => env('FRONTEND_URL', env('APP_URL')),

    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        // Full URL read straight from env — must match Google Console exactly.
        // (No APP_URL concatenation, which could produce a doubled path.)
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    'microsoft' => [
        'client_id'     => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect'      => env('MICROSOFT_REDIRECT_URI', env('APP_URL') . '/api/auth/microsoft/callback'),
        'tenant'        => env('MICROSOFT_TENANT_ID', 'common'),
    ],

];
