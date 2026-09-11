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
    'url_base' => env('MEDIA_URL_BASE', env('STOREFRONT_ASSET_BASE', 'https://dash.watchizereg.com').'/Uploads_Images'),

    /*
    | One entry per kind of image the dashboard can upload. `master` is the file both
    | applications treat as canonical (the legacy ImageService presets, kept identical so the
    | two never disagree); `widths` are the extra renditions §5.4 asks for.
    */
    'types' => [
        'product' => [
            'folder' => 'Product',
            'master' => ['width' => 1200, 'height' => 1200, 'quality' => 85, 'pad_square' => true],
            'widths' => [320, 480, 640, 960, 1280],
            'thumbnails' => [160, 320],
        ],
        'product_gallery' => [
            'folder' => 'Product_image',
            'master' => ['width' => 1200, 'height' => 1200, 'quality' => 85, 'pad_square' => true],
            'widths' => [320, 480, 640, 960, 1280],
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
            'widths' => [640, 1024, 1600, 2048],
            'thumbnails' => [],
        ],
    ],

    // Rendition encoders. AVIF is skipped at runtime when the host's GD cannot write it
    // (MediaCapabilities reports what is available); the WebP master is always the fallback.
    'renditions' => [
        'avif_quality' => 50,
        'webp_quality' => 78,
    ],

    'upload' => [
        'max_kilobytes' => (int) env('MEDIA_MAX_KB', 8192),
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'avif'],
    ],

];
