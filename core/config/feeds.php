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
| The GOOGLE Merchant (Shopping) feed (2026-11-11, D-9) has its own section below, the same keys, its
| own token per storefront (FEED_GOOGLE_<CODE>_TOKEN) and its own URL:
|
|   GET /feeds/google/{storefront code}/{token}.tsv
|
| Its rows are the Meta feed's lines renamed (`GoogleFeed`), so the two catalogues cannot disagree.
| Brand Fashion's domain is the REAL one (www, D-3); its token stays empty until the domain switch,
| so the feed is off until the links it would send shoppers to are Brand Fashion's.
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
        // Brand Fashion's REAL domain (www, D-3), written 2026-11-12 overnight for the switch: the token stays EMPTY
        // until the switch, so the feed is off (no file, the URL 404) until the links it carries are Brand Fashion's.
        // Its ids are the old site's (ContentIds, Block C), so the old Meta catalogue's items continue.
        'brandfashion' => [
            'token' => (string) env('FEED_META_BRANDFASHION_TOKEN', ''),
            'domain' => 'https://www.brandfashionegy.com',
            'image_host' => 'https://api.brandfashionegy.com',
            'locale' => 'en',
            'exclude' => [],
        ],
    ],

    'google' => [
        'watchizer' => [
            'token' => (string) env('FEED_GOOGLE_WATCHIZER_TOKEN', ''),
            'domain' => 'https://watchizereg.com',
            'image_host' => 'https://api.watchizereg.com',
            'locale' => 'en',
            'exclude' => [],
        ],
        // Brand Fashion's REAL domain (www, D-3): the token stays EMPTY until the domain switch, so the
        // feed cannot send Google's shoppers to a host that is not yet Brand Fashion's (the Meta rule above).
        'brandfashion' => [
            'token' => (string) env('FEED_GOOGLE_BRANDFASHION_TOKEN', ''),
            'domain' => 'https://www.brandfashionegy.com',
            'image_host' => 'https://api.brandfashionegy.com',
            'locale' => 'en',
            'exclude' => [],
        ],
    ],
];
