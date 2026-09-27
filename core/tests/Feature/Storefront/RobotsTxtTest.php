<?php

/*
 * ── Core's robots.txt: images open, everything else closed (2026-09-27) ───────────────────────
 *
 * The SEO audit found `Disallow: /` on api.watchizereg.com — the host every product image lives
 * on — so no product image could be crawled: no Google Images, no product rich results, no free
 * listings. The fix opens /Uploads_Images/ and nothing else. This evaluates the file the way
 * Google does (the longest matching rule wins; Allow wins a tie) so neither half can drift.
 */

/** Google's robots.txt decision for one path, for `User-agent: *`. */
function robotsAllows(string $robots, string $path): bool
{
    $best = ['len' => -1, 'allow' => true];   // no matching rule = allowed
    foreach (preg_split('/\R/', $robots) ?: [] as $line) {
        $line = trim((string) preg_replace('/#.*$/', '', $line));
        if (! preg_match('/^(allow|disallow)\s*:\s*(\S*)$/i', $line, $m) || $m[2] === '') {
            continue;
        }
        $rule = $m[2];
        if (! str_starts_with($path, $rule)) {
            continue;
        }
        $allow = strtolower($m[1]) === 'allow';
        $len = strlen($rule);
        if ($len > $best['len'] || ($len === $best['len'] && $allow)) {
            $best = ['len' => $len, 'allow' => $allow];
        }
    }

    return $best['allow'];
}

it('lets crawlers fetch every public image, and nothing else', function () {
    $robots = (string) file_get_contents(public_path('robots.txt'));

    $open = [
        '/Uploads_Images/Product/1737492385_2025-01-21_679007a12e8b8.webp',
        '/Uploads_Images/Brand/rolex.webp',
        '/Uploads_Images/Category_type/watches.webp',
    ];
    $closed = [
        '/', '/api/all_product', '/api/v2/watchizer/products', '/api/add_order', '/manage',
        '/manage/orders/1', '/build/manifest.json', '/dumps/backup.sql', '/Uploads_Images',
        '/uploads_images/Product/x.webp',   // robots paths are case-sensitive: only the real folder opens
    ];

    $wrong = [];
    foreach ($open as $path) {
        if (! robotsAllows($robots, $path)) {
            $wrong[] = "blocked but must be open: {$path}";
        }
    }
    foreach ($closed as $path) {
        if (robotsAllows($robots, $path)) {
            $wrong[] = "open but must be blocked: {$path}";
        }
    }

    expect($wrong)->toBe([]);
});
