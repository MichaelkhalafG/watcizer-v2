<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\PromotionFixture;
use Tests\Support\T;

use function Pest\Laravel\getJson;

/*
 * GET /api/v2/{storefront}/offers — the OFFERS a storefront runs: the dashboard's promotions (round 5,
 * 2026-10-18). Not a discount ("Sale" is ProductListing's `on_sale`). The list is the engine's own candidate
 * test, so each case is asserted on BOTH storefronts: a query that ignored the storefront would pass a
 * one-directional test whenever the fixture happened to sit on the storefront being asked.
 */

function offersSfId(string $code): int
{
    return T::int(DB::table('storefronts')->where('code', $code)->value('id'));
}

/** @return list<int> the ids the endpoint lists for that storefront */
function offersListedIds(string $code): array
{
    $res = getJson("/api/v2/{$code}/offers")->assertOk();
    expect($res->headers->get('Cache-Control'))->toContain('private');
    $ids = [];
    foreach (T::rows($res->json('data')) as $row) {
        $ids[] = T::int($row['id']);
    }

    return $ids;
}

it('lists a running offer on its own storefront only, both directions', function () {
    $wz = offersSfId('watchizer');
    $bf = offersSfId('brandfashion');
    $forBf = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(500)], [PromotionFixture::freeShipping()], [$bf], name: 'offer for Brand Fashion');
    $forWz = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(500)], [PromotionFixture::freeShipping()], [$wz], name: 'offer for Watchizer');
    $forBoth = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(900)], [PromotionFixture::freeShipping()], [$wz, $bf], name: 'offer for both');

    $bfIds = offersListedIds('brandfashion');
    $wzIds = offersListedIds('watchizer');
    expect(in_array($forBf, $bfIds, true))->toBeTrue()
        ->and(in_array($forBf, $wzIds, true))->toBeFalse()
        ->and(in_array($forWz, $wzIds, true))->toBeTrue()
        ->and(in_array($forWz, $bfIds, true))->toBeFalse()
        ->and(in_array($forBoth, $bfIds, true))->toBeTrue()
        ->and(in_array($forBoth, $wzIds, true))->toBeTrue();
});

it('leaves out every rule the engine would not consider: inactive, expired, not started, no condition', function () {
    $bf = offersSfId('brandfashion');
    $cond = [PromotionFixture::subtotalAtLeast(500)];
    $reward = [PromotionFixture::freeShipping()];
    $out = [
        'inactive' => PromotionFixture::rule($cond, $reward, [$bf], active: false),
        'expired' => PromotionFixture::rule($cond, $reward, [$bf], startsAt: now()->subMonth()->format('Y-m-d H:i:s'), endsAt: now()->subDay()->format('Y-m-d H:i:s')),
        'not started' => PromotionFixture::rule($cond, $reward, [$bf], startsAt: now()->addDay()->format('Y-m-d H:i:s'), endsAt: now()->addMonth()->format('Y-m-d H:i:s')),
        'no condition' => PromotionFixture::rule([], $reward, [$bf]),
    ];
    $running = PromotionFixture::rule($cond, $reward, [$bf]);

    $ids = offersListedIds('brandfashion');
    $leaked = [];
    foreach ($out as $why => $id) {
        if (in_array($id, $ids, true)) {
            $leaked[] = $why;
        }
    }
    expect($leaked)->toBe([])->and(in_array($running, $ids, true))->toBeTrue();
});

it('describes a running offer by its name, window, conditions and rewards', function () {
    $bf = offersSfId('brandfashion');
    $id = PromotionFixture::rule([PromotionFixture::subtotalAtLeast(750)], [PromotionFixture::freeShipping()], [$bf], name: 'شحن مجاني فوق ٧٥٠');

    $rows = T::rows(getJson('/api/v2/brandfashion/offers')->assertOk()->json('data'));
    $row = null;
    foreach ($rows as $r) {
        if (T::int($r['id']) === $id) {
            $row = $r;
        }
    }
    expect($row)->not->toBeNull();
    $row = T::arr($row);
    expect($row['name'])->toBe('شحن مجاني فوق ٧٥٠')
        ->and($row)->toHaveKeys(['starts_at', 'ends_at', 'conditions', 'rewards'])
        ->and(T::rows($row['conditions'])[0]['type'])->toBe(T::str(PromotionFixture::subtotalAtLeast(750)['type']))
        ->and(T::rows($row['rewards'])[0]['type'])->toBe(T::str(PromotionFixture::freeShipping()['type']));
});
