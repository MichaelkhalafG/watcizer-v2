<?php

use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontHasNoIdentity;
use App\Storefront\StorefrontIdentity;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shops;
use Tests\Support\StorefrontIdentityFixture;
use Tests\Support\T;

/*
 * ── WHO a storefront is comes from its row (W2, developer 2026-10-13) ────────────────────────────
 *
 * The host says which storefront; the storefront says who it is. Name, wordmark, WhatsApp numbers
 * and copyright are read from `storefronts.name` and `storefronts.settings.identity`, the wordmark
 * is served from the storefront's OWN asset host, and a storefront that lacks any of it — or whose
 * wordmark file is not on disk — is a NAMED failure, never Watchizer's values and never a blank.
 */

beforeEach(function () {
    config(['storefront.asset_base' => 'https://api.watchizereg.test']);   // Watchizer's env value, as in StorefrontUrlsTest
    expect(Shops::domain('brandfashion'))->not->toBe('watchizereg.com');   // whichever host the row holds today
});

function shop(string $code): int
{
    return T::int(DB::table('storefronts')->where('code', $code)->value('id'));
}

it('reads each shop\'s identity from its own row, and puts the wordmark on its own asset host', function () {
    $w = StorefrontIdentity::for(shop('watchizer'));
    $f = StorefrontIdentity::for(shop('brandfashion'));

    expect($w->brandName)->toBe('Watchizer')
        ->and($w->wordmarkUrl())->toBe('https://api.watchizereg.test/assets/brand/watchizer-mail-wordmark@2x.png')
        ->and($w->whatsappSupport)->toBe('201551096234')
        ->and($w->whatsappDeveloper)->toBe('201274550956')
        ->and($w->copyright)->toBe(StorefrontIdentityFixture::VALUES['watchizer']['copyright'])
        ->and($w->whatsappSupportUrl())->toStartWith('https://wa.me/201551096234?text=');

    expect($f->brandName)->toBe('Brand Fashion')
        ->and($f->wordmarkUrl())->toBe(Shops::api('brandfashion').'/assets/brand/brandfashion-mail-wordmark@2x.png')
        // Two numbers, two roles (BF handover D22): the customer-service one differs, the developer's does not.
        ->and($f->whatsappSupport)->toBe('201283888490')
        ->and($f->whatsappDeveloper)->toBe($w->whatsappDeveloper)
        ->and($f->copyright)->toContain('Brand Fashion');

    // The files the identities name exist — a 400x122 PNG each, as the spec sizes the mail wordmark.
    foreach ([$w, $f] as $identity) {
        $info = getimagesize(public_path($identity->wordmarkPath));
        expect($info)->toBeArray()->and([$info[0] ?? 0, $info[1] ?? 0, $info['mime'] ?? ''])->toBe([400, 122, 'image/png']);
    }
});

it('hands every template the same four keys, from the row', function () {
    $data = StorefrontIdentity::for(shop('brandfashion'))->viewData();

    expect(array_keys($data))->toBe(['brandName', 'wordmarkUrl', 'whatsappUrl', 'copyright'])
        ->and($data['brandName'])->toBe('Brand Fashion')
        ->and($data['whatsappUrl'])->toContain('wa.me/201283888490');
});

it('is a NAMED failure for a storefront with no identity, no wordmark file, or no name — never another shop\'s', function () {
    $fashion = shop('brandfashion');

    StorefrontIdentityFixture::clear($fashion);
    expect(fn () => StorefrontIdentity::for($fashion))->toThrow(StorefrontHasNoIdentity::class, 'mail_wordmark is not set');

    DB::table('storefronts')->where('id', $fashion)->update(['settings' => json_encode(['identity' => StorefrontIdentityFixture::VALUES['brandfashion'] + [], 'promotions' => ['money_rewards' => false]])]);
    DB::table('storefronts')->where('id', $fashion)->update(['settings' => json_encode(['identity' => ['mail_wordmark' => 'assets/brand/does-not-exist.png'] + StorefrontIdentityFixture::VALUES['brandfashion']])]);
    app(StorefrontCache::class)->forgetStorefront($fashion, 'brandfashion');
    expect(fn () => StorefrontIdentity::for($fashion))->toThrow(StorefrontHasNoIdentity::class, 'missing under public/');

    DB::table('storefronts')->where('id', $fashion)->update(['settings' => json_encode(['identity' => ['whatsapp_support' => 'call me'] + StorefrontIdentityFixture::VALUES['brandfashion']])]);
    app(StorefrontCache::class)->forgetStorefront($fashion, 'brandfashion');
    expect(fn () => StorefrontIdentity::for($fashion))->toThrow(StorefrontHasNoIdentity::class, 'whatsapp_support');

    DB::table('storefronts')->where('id', $fashion)->update(['name' => '']);
    app(StorefrontCache::class)->forgetStorefront($fashion, 'brandfashion');
    expect(fn () => StorefrontIdentity::for($fashion))->toThrow(StorefrontHasNoIdentity::class, 'no name');

    // Watchizer is untouched by Brand Fashion's emptiness.
    expect(StorefrontIdentity::for(shop('watchizer'))->brandName)->toBe('Watchizer');
});
