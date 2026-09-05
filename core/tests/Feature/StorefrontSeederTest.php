<?php

use App\Models\Storefront\Storefront;
use Database\Seeders\StorefrontSeeder;

it('seeds Watchizer at the deterministic id 1 and is idempotent', function () {
    (new StorefrontSeeder)->run();
    (new StorefrontSeeder)->run();

    $watchizer = Storefront::query()->findOrFail(Storefront::WATCHIZER_ID);

    expect($watchizer->code)->toBe('watchizer')
        ->and($watchizer->default_locale)->toBe('ar')
        ->and($watchizer->locales)->toBe(['ar', 'en'])
        ->and(Storefront::query()->where('code', 'watchizer')->count())->toBe(1);
});

it('aborts when id 1 is already taken by another storefront code', function () {
    (new Storefront)->forceFill(['id' => 1, 'code' => 'intruder', 'name' => 'Intruder', 'locales' => ['en']])->save();

    expect(fn () => (new StorefrontSeeder)->run())->toThrow(RuntimeException::class, 'already taken by code [intruder]');
});

it('aborts when the watchizer code already exists under another id', function () {
    (new Storefront)->forceFill(['id' => 7, 'code' => 'watchizer', 'name' => 'Wrong id', 'locales' => ['en']])->save();

    expect(fn () => (new StorefrontSeeder)->run())->toThrow(RuntimeException::class, 'already exists with id 7');
});
