<?php

/*
|--------------------------------------------------------------------------
| Watchizer branding / notifications
|--------------------------------------------------------------------------
|
| Central place for the values the email system (and other app code) needs:
| the admin recipients for new-order notifications, the WhatsApp numbers, and
| the brand assets used in email templates.
|
| Personal contact details are NOT defaulted here — this file is committed to a
| public repository. The values live in the server's .env only:
|
|   ORDER_ADMIN_EMAILS=one@example.com,two@example.com   (comma-separated)
|   WATCHIZER_WHATSAPP_SUPPORT=2015XXXXXXXX              (digits only, intl format)
|   WATCHIZER_WHATSAPP_DEVELOPER=2012XXXXXXXX            (frontend credit line only)
|
| With ORDER_ADMIN_EMAILS unset, new-order admin notifications have no
| recipients; with the WhatsApp keys unset, the support/credit links render
| empty. Set all three on the server before deploying this file.
|
*/

return [

    // Admins that receive a notification for every new order (ORDER_ADMIN_EMAILS, comma-separated).
    'admin_emails' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ORDER_ADMIN_EMAILS', ''))
    ))),

    'whatsapp' => [
        // Customer support line shown in order emails (digits only, intl format).
        'support'   => env('WATCHIZER_WHATSAPP_SUPPORT', ''),
        // Developer credit line — used on the FRONTEND footer/drawer only,
        // NEVER in email templates.
        'developer' => env('WATCHIZER_WHATSAPP_DEVELOPER', ''),
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
