<?php

/*
| SEO MEASUREMENT AND SAFETY (SEO Phase 5, 2026-11-14) — App\Domain\Seo\SeoReport, App\Domain\Content\BlogSafety.
|
| The two credentials are EMPTY until Michael adds them after go-live (the deploy steps list them); everything that
| needs them simply reports "not connected" until then.
|   GSC_SERVICE_ACCOUNT_JSON  the Search Console service account's JSON key (the whole JSON on one line, or a path to the
|                             file); the account must be added as a user of each shop's Search Console property
|   BING_WEBMASTER_API_KEY    the Bing Webmaster Tools API key (Settings → API access)
*/
return [
    'gsc_service_account' => (string) env('GSC_SERVICE_ACCOUNT_JSON', ''),
    'bing_api_key' => (string) env('BING_WEBMASTER_API_KEY', ''),

    // where the weekly report is written (one Markdown file per shop and week)
    'report_dir' => storage_path('app/seo-reports'),

    'safety' => [
        // a shop's publishing is PAUSED when more than this share of an imported batch is held by the quality gate
        'max_held_share' => (float) env('SEO_MAX_HELD_SHARE', 0.3),
        // …or (Search Console connected) when the blog's impressions or indexed blog pages fall by more than this, week on week
        'max_weekly_drop' => (float) env('SEO_MAX_WEEKLY_DROP', 0.4),
        // the queue alert: fewer days of scheduled articles than this (Michael, 2026-11-14: weekly batches — 2 days, not 30;
        // an EMPTY queue is not an error: publishing simply waits for the next batch)
        'min_queue_days' => 2,
    ],
];
