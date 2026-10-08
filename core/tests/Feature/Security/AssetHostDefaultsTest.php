<?php

/*
 * ── The image host must not depend on remembering a variable (2026-10-08) ──────────────────────
 *
 * Three settings build every image URL core emits: `compat.asset_base` (the product page's gallery,
 * `LegacyJson::imageUrl`), `storefront.asset_base` (v2 and the storefront's own URLs) and
 * `media.url_base` (the dashboard's media library). Production sets all three in its .env. Their
 * DEFAULTS used to be `https://dash.watchizereg.com` — the legacy host, which answers 410 since
 * 2026-09-29 — so a host whose .env lost one of them would have pointed images at a dead host with
 * nothing failing in review. The defaults are now the API host, and this test reads the config
 * FILES with the variables absent, because the running config only proves what the local .env says.
 */

/**
 * Evaluate a config file with the given variables absent, then put the real environment back.
 *
 * @param  list<string>  $absent
 * @return array<array-key, mixed>
 */
function assetConfigUnder(string $file, array $absent): array
{
    $saved = [];
    foreach ($absent as $key) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
    }

    try {
        $config = require config_path($file);
        if (! is_array($config)) {
            throw new RuntimeException("config/{$file} did not return an array.");
        }

        return $config;
    } finally {
        foreach ($saved as $key => [$e, $srv, $g]) {
            if ($e !== null) {
                $_ENV[$key] = $e;
            }
            if ($srv !== null) {
                $_SERVER[$key] = $srv;
            }
            if ($g !== false) {
                putenv($key.'='.$g);
            }
        }
    }
}

const ASSET_HOST_VARIABLES = ['COMPAT_ASSET_BASE', 'STOREFRONT_ASSET_BASE', 'MEDIA_URL_BASE'];

it('defaults every image host to the API host when the .env does not set it', function () {
    expect(assetConfigUnder('compat.php', ASSET_HOST_VARIABLES)['asset_base'])->toBe('https://api.watchizereg.com')
        ->and(assetConfigUnder('storefront.php', ASSET_HOST_VARIABLES)['asset_base'])->toBe('https://api.watchizereg.com')
        ->and(assetConfigUnder('media.php', ASSET_HOST_VARIABLES)['url_base'])->toBe('https://api.watchizereg.com/Uploads_Images');
});

it('never falls back to the legacy host, which answers 410', function () {
    foreach (['compat.php' => 'asset_base', 'storefront.php' => 'asset_base', 'media.php' => 'url_base'] as $file => $key) {
        $value = assetConfigUnder($file, ASSET_HOST_VARIABLES)[$key];
        expect(is_string($value) && str_contains($value, 'dash.watchizereg.com'))->toBeFalse("config/{$file} {$key} = ".var_export($value, true));
    }
});

it('still honours an explicit value, so a local or staging host is unaffected', function () {
    $previous = getenv('STOREFRONT_ASSET_BASE');
    putenv('STOREFRONT_ASSET_BASE=http://127.0.0.1:8000');
    $_ENV['STOREFRONT_ASSET_BASE'] = $_SERVER['STOREFRONT_ASSET_BASE'] = 'http://127.0.0.1:8000';
    try {
        $config = require config_path('storefront.php');
        expect($config['asset_base'])->toBe('http://127.0.0.1:8000');
    } finally {
        unset($_ENV['STOREFRONT_ASSET_BASE'], $_SERVER['STOREFRONT_ASSET_BASE']);
        putenv('STOREFRONT_ASSET_BASE');
        if ($previous !== false) {
            putenv('STOREFRONT_ASSET_BASE='.$previous);
            $_ENV['STOREFRONT_ASSET_BASE'] = $_SERVER['STOREFRONT_ASSET_BASE'] = $previous;
        }
    }
});
