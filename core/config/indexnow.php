<?php

/*
|--------------------------------------------------------------------------
| IndexNow (SEO Phase 1, 2026-11-14)
|--------------------------------------------------------------------------
|
| Every product, article and page-text save queues the addresses it touched (indexnow_queue); `indexnow:flush` sends
| them in batches. OFF until go-live: with `enabled` false the queue fills and nothing is sent — the combined deploy
| turns it on (INDEXNOW_ENABLED=true) once the new pages are live, so no engine is told about a page that is not.
|
| Each storefront's key is derived from the app key (App\Domain\Seo\IndexNow::key) and served by the storefront at
| /indexnow-key.txt — IndexNow's `keyLocation`, so no key file has to be placed by hand.
*/
return [
    'enabled' => (bool) env('INDEXNOW_ENABLED', false),
    'endpoint' => env('INDEXNOW_ENDPOINT', 'https://api.indexnow.org/indexnow'),
    // at most this many addresses per request (the protocol allows 10,000)
    'batch' => (int) env('INDEXNOW_BATCH', 1000),
    'timeout' => 20,
];
