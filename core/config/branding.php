<?php

/*
|--------------------------------------------------------------------------
| Dashboard branding
|--------------------------------------------------------------------------
|
| This dashboard is MULTI-STOREFRONT. Watchizer is the first tenant, not the
| only one, so nothing below may be typed into the shell's markup: the values
| here are the DEFAULT, and App\Support\Branding is the single place that
| resolves them — today from this file, from wave 4B onwards from the
| storefront's own settings when it carries them.
|
| Asset paths are relative to core's own `public/`. The dashboard never
| references the legacy host at runtime (§1): the marks were copied into
| public/assets/brand/ and are served by this application.
|
*/

return [

    'name' => env('BRANDING_NAME', 'Watchizer'),

    // Shown next to the mark in the sidebar; keeps the dashboard honest about which
    // platform it is, not just which storefront.
    'suffix' => env('BRANDING_SUFFIX', 'لوحة التحكم'),

    'logo' => [
        // Dark ink — for light surfaces.
        'default' => 'assets/brand/watchizer-logo.webp',
        // White ink — for the dark sidebar and dark mode. Generated from the same mark.
        'light' => 'assets/brand/watchizer-logo-light.webp',
        // Intrinsic size of both files, so the shell can reserve the box and avoid layout shift.
        'width' => 480,
        'height' => 323,
    ],

    'favicon' => 'assets/brand/favicon-32.png',

    'apple_touch_icon' => 'assets/brand/apple-touch-icon.png',

    /*
    | Developer credit. A config value rather than strings in components, so it appears
    | exactly once and can be changed in one place. Rendered in the dashboard FOOTER only —
    | never on the login screen, never in the header, never on an operational screen.
    */
    'credit' => [
        'text' => env('BRANDING_CREDIT', 'Created by Michael Khalaf'),
        'url' => env('BRANDING_CREDIT_URL'),
    ],

];
