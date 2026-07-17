<?php

/*
|--------------------------------------------------------------------------
| Watchizer branding / notifications
|--------------------------------------------------------------------------
|
| Central place for the values the email system (and other app code) needs:
| the admin recipients for new-order notifications, the WhatsApp numbers, and
| the brand assets used in email templates. Everything here is overridable via
| .env so nothing sensitive is hard-coded in a way that can't be changed on the
| server, but sensible production defaults are baked in.
|
*/

return [

    // Admins that receive a notification for every new order.
    // Override with ORDER_ADMIN_EMAILS (comma-separated) in .env if needed.
    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('ORDER_ADMIN_EMAILS', implode(',', [
            'mina7makram@gmail.com',
            'Watchizer303@gmail.com',
            'Minaawadrezk@gmail.com',
            'michaelkhalafai@gmail.com',
        ])))
    ))),

    'whatsapp' => [
        // Customer support line shown in order emails (digits only, intl format).
        'support'   => env('WATCHIZER_WHATSAPP_SUPPORT', '201551096234'),
        // Developer credit line — used on the FRONTEND footer/drawer only,
        // NEVER in email templates.
        'developer' => env('WATCHIZER_WHATSAPP_DEVELOPER', '201274550956'),
    ],

    'brand' => [
        'name'      => 'Watchizer',
        'copyright' => '© 2024 Watchizer. All rights reserved.',
        // Absolute URL — emails can't use relative asset paths.
        'logo'      => env('WATCHIZER_EMAIL_LOGO', 'https://dash.watchizereg.com/DashAssets/img/logo.webp'),
    ],

    'urls' => [
        // Public storefront (Next.js) — "Track your order" links.
        'frontend'  => rtrim(env('FRONTEND_URL', 'https://watchizereg.com'), '/'),
        // Admin dashboard — image hosts + "open order" links in admin emails.
        'dashboard' => rtrim(env('APP_URL', 'https://dash.watchizereg.com'), '/'),
    ],
];
