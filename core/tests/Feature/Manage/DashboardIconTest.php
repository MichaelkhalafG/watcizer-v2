<?php

use function Pest\Laravel\get;

/*
 * The dashboard's tab icon (2026-09-27). `public/favicon.ico` was a 0-byte file and the page head
 * named no icon, so every dashboard tab showed the browser's blank page. Both halves are checked:
 * the head points at the files, and the files are real images.
 */

it('names a tab icon and a home-screen icon in the dashboard head', function () {
    get('/manage/login')->assertOk()
        ->assertSee('<link rel="icon" href="/favicon.ico" sizes="any">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false);
});

it('ships a real ICO and a 180x180 PNG behind those links', function () {
    $ico = (string) file_get_contents(public_path('favicon.ico'));
    expect(strlen($ico))->toBeGreaterThan(0)
        ->and(substr($ico, 0, 4))->toBe("\x00\x00\x01\x00");

    $png = getimagesize(public_path('apple-touch-icon.png'));
    expect($png)->not->toBeFalse()
        ->and($png !== false ? [$png[0], $png[1], $png['mime']] : null)->toBe([180, 180, 'image/png']);
});
