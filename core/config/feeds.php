<?php

/*
|--------------------------------------------------------------------------
| Product feeds (2026-10-10)
|--------------------------------------------------------------------------
|
| A Meta catalogue feed per STOREFRONT, generated on the schedule to a file (`feeds:meta`, hourly
| at :40) and served from that file — never built on a request (`MetaFeedController`):
|
|   GET /feeds/meta/{storefront code}/{token}.csv
|
| Keyed by storefront code from day one. A storefront with no entry here, or an entry whose token is
| empty, has NO feed: the command generates nothing for it and the URL answers 404. There is never
| a fallback to another storefront's feed — Brand Fashion must not inherit Watchizer's (the same
| lesson as the Meta pixel, W5).
|
| Per storefront:
|   token       40 letters/digits — the URL's only credential (Meta cannot send our Api-Code header).
|               Generate with `php -r "echo bin2hex(random_bytes(20)), PHP_EOL;"`. Set in .env.
|   domain      where the product links point. Written HERE, not read from `storefronts.domain`:
|               Brand Fashion's row holds its real domain until switch-over (decision E3), and a feed
|               must never send shoppers to a host that is not live.
|   image_host  the host that serves Uploads_Images for this shop.
|   locale      the language of titles, descriptions and names ('en' on day one; an Arabic
|               override feed is a backlog row, not built).
|   exclude     product ids left out of the feed. EMPTY ON PURPOSE (owner, 2026-10-09: every product
|               goes in). It exists so a product Meta flags can be pulled with a config edit, then
|               `config:cache` and one `feeds:meta` run — no code change, no deploy.
|
| The Google Merchant feed is NOT here: it is gated on O10 (authenticity), see the backlog.
*/

return [
    // Where the generated files live: <path>/<storefront code>/meta.csv (outside the web root).
    'path' => storage_path('app/feeds'),

    'meta' => [
        'watchizer' => [
            'token' => (string) env('FEED_META_WATCHIZER_TOKEN', ''),
            'domain' => 'https://watchizereg.com',
            'image_host' => 'https://api.watchizereg.com',
            'locale' => 'en',
            'exclude' => [],
        ],
        // 'brandfashion' — deliberately absent until its own domain is live (phase D/F).
    ],
];
