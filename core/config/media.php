<?php

/*
|--------------------------------------------------------------------------
| Media (CLEAN_CORE_STUDY §5.4)
|--------------------------------------------------------------------------
|
| The image tree is SHARED with the legacy application during the transition
| (§1: "same Uploads_Images directory, symlink / shared mount"), and the
| database stores a FILENAME only — never a path, never a host — so moving to
| object storage later is a config change and not a data migration.
|
| Folder names are the legacy ones on purpose. Both applications write the same
| tree while the transition lasts, `LegacyJson::image()` builds compat URLs from
| `<asset_base>/Uploads_Images/<folder>/<file>`, and a folder rename would break
| every stored row and every cached payload.
|
*/

return [

    // Absolute or relative-to-core path of the shared tree. Relative paths resolve from base_path().
    'root' => env('MEDIA_ROOT', '../backend/public/Uploads_Images'),

    // Where the dashboard's own <img src> points while the tree lives on the legacy host.
    /*
     * The CA bundle cURL verifies a supplier's HTTPS certificate against, for the wave-4D importer
     * that fetches cover images from the client's own site.
     *
     * Needed because a stock PHP on Windows ships with `curl.cainfo` unset, so every HTTPS fetch
     * fails with "unable to get local issuer certificate" — which is what the first real import run
     * hit, silently, 79 products in. Left NULL on a properly configured host, where cURL finds the
     * system store on its own.
     *
     * Verification is never turned OFF. An importer that accepts any certificate is an importer
     * that will one day download somebody else's idea of a product photo.
     */
    'ca_bundle' => env('MEDIA_CA_BUNDLE'),

    'url_base' => env('MEDIA_URL_BASE', env('STOREFRONT_ASSET_BASE', 'https://dash.watchizereg.com').'/Uploads_Images'),

    /*
    | One entry per kind of image the dashboard can upload. `master` is the file both
    | applications treat as canonical (the legacy ImageService presets, kept identical so the
    | two never disagree); `widths` are the extra renditions §5.4 asks for.
    |
    | INVARIANT: every declared width must be STRICTLY BELOW the master's own width, or the entry
    | is dead on arrival. `ImagePipeline::write()` skips `$target >= $width` rather than upscaling
    | (a rendition wider than the master would invent detail), and for a `pad_square` preset the
    | master is ALWAYS exactly the preset width, so the comparison is decidable from this file
    | alone. Two entries were dead for this reason and have been removed: product/product_gallery
    | `1280` against a 1200 master, and banner `2048` against a 1920 master. They cost nothing on
    | disk — they were never written — but they made the ladder read as six widths where five are
    | produced, and they showed up in every upload's `skipped` list as a fault that was really a
    | typo in this file.
    */
    'types' => [
        'product' => [
            'folder' => 'Product',
            'master' => ['width' => 1200, 'height' => 1200, 'quality' => 85, 'pad_square' => true],
            'widths' => [320, 480, 640, 960],
            'thumbnails' => [160, 320],
        ],
        'product_gallery' => [
            'folder' => 'Product_image',
            'master' => ['width' => 1200, 'height' => 1200, 'quality' => 85, 'pad_square' => true],
            'widths' => [320, 480, 640, 960],
            'thumbnails' => [160, 320],
        ],
        'brand' => [
            'folder' => 'Brand',
            'master' => ['width' => 400, 'height' => 400, 'quality' => 85, 'pad_square' => false],
            'widths' => [160, 320],
            'thumbnails' => [],
        ],
        'category' => [
            'folder' => 'Category_type',
            'master' => ['width' => 600, 'height' => 600, 'quality' => 80, 'pad_square' => false],
            'widths' => [320, 480],
            'thumbnails' => [],
        ],
        'banner' => [
            'folder' => 'Banner_home',
            'master' => ['width' => 1920, 'height' => 800, 'quality' => 85, 'pad_square' => false],
            'widths' => [640, 1024, 1600],
            'thumbnails' => [],
        ],
    ],

    /*
    | Rendition encoders. AVIF is skipped at runtime when the host's GD cannot write it
    | (MediaCapabilities reports what is available); the WebP master is always the fallback.
    |
    | ── CLOSED 2026-09-16: the "image quality 78" item is DONE, and the masters stay at 85 ─────
    |
    | `webp_quality` below is the 78 that was asked for, and it has been 78 since the ladder was
    | built. Nothing is owed.
    |
    | The MASTER qualities above are 85 (80 for category) and they are NOT to be dropped to 78.
    | They are the legacy `ImageService` presets, kept identical on purpose: both applications write
    | into the SAME shared `Uploads_Images` tree, and a master written by core at 78 sitting beside
    | one written by the legacy dashboard at 85 is a catalogue that disagrees with itself — for a
    | saving measured in kilobytes on the one file that is never served directly.
    |
    | Developer decision, in their words: keeping them identical to the legacy ImageService presets
    | is deliberate and worth more than the bytes. Do not reopen this by reading the 78 below and
    | assuming the 85s above were an oversight. They were not.
    */
    'renditions' => [
        'avif_quality' => 50,
        'webp_quality' => 78,
    ],

    'upload' => [
        'max_kilobytes' => (int) env('MEDIA_MAX_KB', 8192),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'avif'],
    ],

];
