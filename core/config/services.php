<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Paymob
    |--------------------------------------------------------------------------
    | Mirrors backend/config/services.php. The KEYS ARE DEVELOPER-HANDLED and are never written
    | into this repo (AGENTS §3): every one is an env() read with no default, and with them unset
    | the checkout's card branch takes the same "credentials not configured" path the legacy code
    | takes — a 422, never a silent success. `payment_methods` is the legacy integration-id list,
    | moved out of the controller so it is configuration rather than three magic numbers.
    */
    /*
    |--------------------------------------------------------------------------
    | Social sign-in (storefront Phase 1, piece 5, 2026-09-22)
    |--------------------------------------------------------------------------
    | Mirrors backend/config/services.php. **The secrets are developer-handled and are never
    | written into this repo** (AGENTS §3): every one is an `env()` read with no default, and with
    | them unset `CustomerSocial` refuses the provider by name rather than starting a flow that
    | cannot finish.
    |
    | `redirect` is read WHOLE from the environment rather than built from `APP_URL`, exactly as the
    | legacy config does and for the reason its comment gives: the value must match what is
    | registered in the provider's console byte for byte, and concatenating a base with a path is
    | how a doubled slash silently invalidates every callback.
    |
    | These URIs point at THIS host and must be registered alongside — not instead of — the legacy
    | ones until the storefront is repointed, or a customer mid-flow at the cutover lands on a URI
    | the provider does not know.
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),
    ],

    'paymob' => [
        'secret_key' => env('PAYMOB_SECRET_KEY'),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        'payment_methods' => array_values(array_filter(array_map('intval', explode(',', (string) env('PAYMOB_PAYMENT_METHODS', '4988969,4627487,3961568'))))),
    ],
];
