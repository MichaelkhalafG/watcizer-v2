<?php

use App\Support\StorefrontServerKey;

use function Pest\Laravel\withHeaders;

/*
 * The storefront SERVER's own rate limit (2026-09-30, option b).
 *
 * Every storefront page is rendered on one server that calls core once per page view, so under the
 * per-IP 60 a minute the whole site ran out at ~60 page views a minute and product pages 404'd. With
 * the server-only secret in `X-Storefront-Server-Key` the server gets its own, much higher bucket;
 * every other caller — browsers, bots, anyone who guesses at the header — keeps the per-IP 60.
 */

const SERVER_KEY = 'test-storefront-server-key-0123456789abcdef';

/** How many of `$n` requests from `$ip` got through before the first 429 (or $n if none did). */
function throughBefore429(int $n, string $ip, ?string $key): int
{
    for ($i = 1; $i <= $n; $i++) {
        // Always set the header (empty = a browser): withHeaders() persists between requests in a test.
        $headers = [StorefrontServerKey::HEADER => $key ?? ''];
        $status = withHeaders($headers)->withServerVariables(['REMOTE_ADDR' => $ip])
            ->getJson('/api/v2/watchizer/payment-methods')->status();
        if ($status === 429) {
            return $i - 1;
        }
    }

    return $n;
}

beforeEach(function () {
    config(['compat.server_key' => SERVER_KEY, 'compat.server_rate_per_minute' => 150]);
});

it('keeps a browser to 60 a minute', function () {
    expect(throughBefore429(65, '10.77.0.1', null))->toBe(60);
});

it('gives the storefront server its own, higher limit with the right key', function () {
    expect(throughBefore429(100, '10.77.0.2', SERVER_KEY))->toBe(100);
});

it('does not let the server\'s bucket and a browser\'s share one address\'s budget', function () {
    // The server used 100 of its 150; a browser at the SAME address still has its full 60.
    throughBefore429(100, '10.77.0.3', SERVER_KEY);
    expect(throughBefore429(65, '10.77.0.3', null))->toBe(60);
});

it('treats a wrong key, or a key configured too short to be a secret, as a plain browser', function () {
    expect(throughBefore429(65, '10.77.0.4', 'not-the-key-not-the-key-not-the-key'))->toBe(60);

    config(['compat.server_key' => 'short']);
    expect(throughBefore429(65, '10.77.0.5', 'short'))->toBe(60);

    config(['compat.server_key' => '']);
    expect(throughBefore429(65, '10.77.0.6', ''))->toBe(60);
});

it('keeps the key out of every browser bundle: one server-only file reads it, never as NEXT_PUBLIC_', function () {
    $root = dirname(base_path()).DIRECTORY_SEPARATOR.'Frontend-next';
    $readers = [];
    foreach (['src', 'app'] as $dir) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.DIRECTORY_SEPARATOR.$dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file instanceof SplFileInfo || ! preg_match('/\.(jsx?|mjs|ts|tsx)$/', $file->getFilename())) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            expect(str_contains($src, 'NEXT_PUBLIC_STOREFRONT_SERVER_KEY'))->toBeFalse("{$file->getFilename()} would ship the key to browsers");
            if (str_contains($src, 'process.env.STOREFRONT_SERVER_KEY')) {
                $readers[] = $file->getFilename();
                expect(str_starts_with(ltrim($src), "'use client'"))->toBeFalse('a client component reads the server key');
            }
        }
    }
    expect($readers)->toBe(['serverFetch.js']);
});
