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
    'paymob' => [
        'secret_key' => env('PAYMOB_SECRET_KEY'),
        'public_key' => env('PAYMOB_PUBLIC_KEY'),
        'hmac_secret' => env('PAYMOB_HMAC_SECRET'),
        'payment_methods' => array_values(array_filter(array_map('intval', explode(',', (string) env('PAYMOB_PAYMENT_METHODS', '4988969,4627487,3961568'))))),
    ],
];
