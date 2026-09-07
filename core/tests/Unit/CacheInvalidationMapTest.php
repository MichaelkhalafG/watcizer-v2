<?php

use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontPricing;
use Illuminate\Support\Facades\File;

/*
 * §5.2: no cache key may be written without a listed event that forgets it. Every
 * StorefrontCache::remember() / key() call in app/ names a key family; each family must be in
 * INVALIDATION_MAP with at least one event, and the map must not carry dead families.
 */

it('lists every cache key family the application writes, and nothing else', function () {
    $used = [];
    foreach (File::allFiles(app_path()) as $file) {
        $src = $file->getContents();
        if (preg_match_all("/->(?:remember|key)\\(\\s*[^,]+,\\s*'([a-z_]+)'/", $src, $m) > 0) {
            foreach ($m[1] as $what) {
                $used[$what] = true;
            }
        }
    }
    $used = array_keys($used);
    sort($used);
    $mapped = array_keys(StorefrontCache::INVALIDATION_MAP);
    sort($mapped);

    expect($used)->not->toBeEmpty()->and($used)->toBe($mapped);
    foreach (StorefrontCache::INVALIDATION_MAP as $what => $events) {
        expect($events)->not->toBeEmpty("cache family [$what] has no invalidating event");
    }
});

it('bumps every versioned key on flush and forgets a product key individually', function () {
    $cache = new StorefrontCache;
    $before = $cache->key(1, 'meta');
    $v = $cache->flush(1);
    expect($cache->key(1, 'meta'))->not->toBe($before)->and($cache->key(1, 'meta'))->toEndWith(":v{$v}");

    $n = 0;
    expect($cache->remember(1, 'product', '5', 60, function () use (&$n) {
        $n++;

        return 'dto';
    }))->toBe('dto');
    $cache->remember(1, 'product', '5', 60, function () use (&$n) {
        $n++;

        return 'dto';
    });
    expect($n)->toBe(1);
    $cache->forgetProduct(1, 5);
    $cache->remember(1, 'product', '5', 60, function () use (&$n) {
        $n++;

        return 'dto';
    });
    expect($n)->toBe(2);
});

it('refuses an unlisted key family', function () {
    expect(fn () => (new StorefrontCache)->key(1, 'unlisted'))->toThrow(InvalidArgumentException::class);
});

it('resolves prices through the one pricing helper', function () {
    expect(StorefrontPricing::resolve('3900.00', '3090.00', 'EGP'))->toBe(['amount' => 3900.0, 'sale_amount' => 3090.0, 'currency' => 'EGP', 'discount_pct' => 21, 'has_sale' => true])
        ->and(StorefrontPricing::resolve('100.00', '100.00', 'EGP')['has_sale'])->toBeFalse()
        ->and(StorefrontPricing::resolve('100.00', null, 'EGP')['discount_pct'])->toBe(0)
        ->and(StorefrontPricing::resolve('100.00', '0.00', 'EGP')['sale_amount'])->toBeNull();
});
