<?php

/*
|--------------------------------------------------------------------------
| Customer accounts (storefront Phase 1, 2026-09-21)
|--------------------------------------------------------------------------
|
| The handful of values the customer-facing auth surface needs. A file of its
| own rather than more keys in `config/compat.php`, because these are not about
| the compat layer: they survive it. When the storefront stops calling legacy
| paths entirely, `compat.php` shrinks and this does not.
|
*/

return [

    /*
    | Where a customer is sent by a link in an e-mail.
    |
    | The reset e-mail points at the STOREFRONT, not at this API: `ResetPassword.jsx` reads
    | `?token=` and `?email=` off the address bar and posts them back to `auth/reset-password`.
    | A link that pointed here would show a shopper a JSON endpoint.
    |
    | Shares `FRONTEND_URL` with `config/cors.php` on purpose — one host, named once. Its default
    | is the live storefront, so an unset environment mails a link that works rather than one
    | pointing at localhost.
    */
    'storefront_url' => (string) env('FRONTEND_URL', 'https://watchizereg.com'),

    /*
    | How many verification e-mails one account may ask for, and how often.
    |
    | The legacy limiter was one per minute per user id, and it is reproduced: the cost of a
    | second one is a real e-mail to a real inbox, and an unthrottled resend button is a way to
    | use this shop to post mail to somebody else.
    */
    'resend_verification' => [
        'attempts' => (int) env('CUSTOMER_RESEND_ATTEMPTS', 1),
        'decay_seconds' => (int) env('CUSTOMER_RESEND_DECAY', 60),
    ],

];
