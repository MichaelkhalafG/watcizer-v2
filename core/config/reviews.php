<?php

/*
| VERIFIED REVIEWS (SEO Phase 5 item 1, 2026-11-14) — App\Domain\Reviews\Reviews.
|
| After an order is delivered, its customer is asked for a review by e-mail (and the dashboard offers the team a
| WhatsApp link with the same review page). The link opens /review/<token> on the shop: one form per product of that
| order — stars, words, up to three photos. Every review is VERIFIED (it comes from a delivered order) and waits for the
| team's approval on the dashboard; only approved reviews are shown and feed the product's structured data.
|
| ON WITH THE DEPLOY (Michael, 2026-11-14): the go-live step sets REVIEW_REQUESTS_ENABLED=true and REVIEW_REQUESTS_FROM
| (the go-live moment, UTC). Only orders PLACED on or after that moment are ever asked — no request, e-mail or WhatsApp
| link for an order from before go-live. Without REVIEW_REQUESTS_FROM, no request is created at all.
*/
return [
    'requests_enabled' => (bool) env('REVIEW_REQUESTS_ENABLED', false),
    'requests_from' => (string) env('REVIEW_REQUESTS_FROM', ''),
    // ask this many days after the order was marked delivered, and never for an order delivered longer ago than `within_days`
    'after_days' => (int) env('REVIEW_REQUEST_AFTER_DAYS', 3),
    'within_days' => 30,
    // a review link works this long after it was sent
    'expires_days' => 60,
    // per order, per run: the most e-mails one run sends (the mailbox has a daily limit)
    'max_per_run' => 50,
    'max_photos' => 3,
    'photo_max_kb' => 8192,
    'body_min' => 10,
    'body_max' => 2000,

    /*
    | REVIEWS FROM EVERYONE (Michael, 2026-11-17): a review written on a product page, without logging in, is LIVE at once
    | and protected in the background — Cloudflare Turnstile (invisible), limits per e-mail, per device and per address,
    | and a review holding a link or a phone number waits for the team. Never held for its stars.
    |
    | TURNSTILE_SITE_KEY / TURNSTILE_SECRET_KEY: one invisible widget for both shops' domains (Michael creates it and puts
    | both in core's .env). The site key is public: core hands it to the product page. Until both are set the check is
    | skipped (and logged once a day): the limits and the hold rules still apply.
    */
    'turnstile' => [
        'site_key' => (string) env('TURNSTILE_SITE_KEY', ''),
        'secret_key' => (string) env('TURNSTILE_SECRET_KEY', ''),
        'verify_url' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        'timeout' => 5,
    ],
    // reviews per e-mail per day, per device per hour, per address per hour
    'guest_limits' => ['email_per_day' => 3, 'device_per_hour' => 5, 'ip_per_hour' => 10],
];
