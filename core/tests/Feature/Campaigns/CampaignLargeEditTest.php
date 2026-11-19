<?php

use App\Domain\Campaigns\CampaignPrices;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\put;
use function Pest\Laravel\withoutMiddleware;

/*
 * ── A large offer, edited (2026-11-19, the team's hot-deals report) ──────────────────────────────────────
 *
 * The team edited a hot-deals offer of several hundred products — removed some, added others — and Save opened a
 * dialog listing hundreds of names, taller than the screen, so the save could never be confirmed. The cause: every
 * save asked again about every campaign price under the purchase price, even the ones confirmed when they were saved.
 * These tests hold the fix: a large offer saves; an edit asks only about NEW or CHANGED prices and writes only the
 * rows it changed; a refusal says how many and which (as a list), and a refused save changes nothing.
 */
const CLE_SELLING = 2000.0;
const CLE_COST = 1000.0;
const CLE_BELOW_COST = 900.0;

beforeEach(function () {
    withoutMiddleware(ThrottleRequests::class);
    DB::table('storefront_campaigns')->delete();
    CampaignPrices::forget(1);
    app(StorefrontCache::class)->flush(1);
});

/** @return list<int> visible products on storefront 1, selling 2,000, cost 1,000, each with its own names */
function cleProducts(int $count, string $tag): array
{
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $id = CatalogFixture::product('watch', CLE_SELLING);
        CatalogFixture::onStorefront($id, true, 1);
        DB::table('catalog_products')->where('id', $id)->update(['purchase_price' => number_format(CLE_COST, 2, '.', '')]);
        DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $id)->update(['effective_price' => number_format(CLE_SELLING, 2, '.', '')]);
        DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->update(['title' => "Offer {$tag} {$i}"]);
        DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'ar')->update(['title' => "عرض {$tag} {$i}"]);
        $ids[] = $id;
    }

    return $ids;
}

/**
 * @param  list<int>  $ids
 * @param  array<int, array{mode: string, value: float}>  $prices
 * @return array<string, mixed>
 */
function clePayload(array $ids, array $prices, bool $confirm = false, string $name = 'Big hot deals'): array
{
    return [
        'name' => $name, 'presentation' => 'hot_deals', 'starts_at' => now()->subHour()->format('Y-m-d\TH:i'),
        'ends_at' => now()->addDays(3)->format('Y-m-d\TH:i'), 'is_active' => true, 'product_ids' => $ids, 'prices' => $prices,
        'confirm_prices' => $confirm,
    ];
}

/** @return array<int, array{id: int, position: int, updated_at: string}> product id → its row */
function cleRows(int $campaignId): array
{
    $out = [];
    foreach (DB::table('storefront_campaign_products')->where('campaign_id', $campaignId)->get(['id', 'product_id', 'position', 'updated_at']) as $raw) {
        $out[Row::int($raw, 'product_id')] = ['id' => Row::int($raw, 'id'), 'position' => Row::int($raw, 'position'), 'updated_at' => Row::str($raw, 'updated_at')];
    }

    return $out;
}

it('saves a large offer, and an edit that removes and adds products writes only those rows and asks nothing again', function () {
    $ids = cleProducts(260, 'L');
    $extra = cleProducts(2, 'N');
    $priced = array_slice($ids, 0, 220);
    $prices = [];
    foreach ($priced as $id) {
        $prices[$id] = ['mode' => 'price', 'value' => CLE_BELOW_COST];      // under the purchase price: asked once
    }
    actingAs(Staff::admin());

    // the first save of 220 prices under cost asks, with the count and the names as a list — never every id in a line
    post('/manage/storefronts/1/campaigns', clePayload($ids, $prices))->assertSessionHasErrors('confirm_prices');
    expect(T::err('confirm_prices'))->toContain('(220)');
    expect(str_contains(T::err('confirm_prices'), (string) $priced[0]))->toBeFalse();
    expect(T::arr(json_decode(T::err('confirm_prices_names'), true)))->toHaveCount(220);
    expect(DB::table('storefront_campaigns')->count())->toBe(0);
    post('/manage/storefronts/1/campaigns', clePayload($ids, $prices, true))->assertSessionHasNoErrors();
    $campaign = T::int(DB::table('storefront_campaigns')->value('id'));
    expect(DB::table('storefront_campaign_products')->where('campaign_id', $campaign)->count())->toBe(260);

    // every stored row stamped in the past, to see which ones the edit touches
    DB::table('storefront_campaign_products')->where('campaign_id', $campaign)->update(['updated_at' => '2026-01-01 00:00:00']);
    $before = cleRows($campaign);

    // the edit: three removed (two priced, one not), two added at the end, the kept prices as they were, NOT confirmed
    $removed = [$ids[5], $ids[100], $ids[250]];
    $kept = array_values(array_diff($ids, $removed));
    $keptPrices = array_diff_key($prices, array_flip($removed));
    put("/manage/storefronts/1/campaigns/{$campaign}", clePayload([...$kept, ...$extra], $keptPrices))->assertSessionHasNoErrors();

    $after = cleRows($campaign);
    expect(count($after))->toBe(259)
        ->and(array_keys(array_intersect_key($after, array_flip($removed))))->toBe([])
        ->and(isset($after[$extra[0]], $after[$extra[1]]))->toBeTrue();
    foreach ($kept as $id) {
        // a kept row is the same row, at the same position, untouched
        expect($after[$id])->toBe($before[$id]);
    }
    // the new ones come after the last kept one, so the order is the order asked
    $order = DB::table('storefront_campaign_products')->where('campaign_id', $campaign)->orderBy('position')->orderBy('id')->pluck('product_id')->map(fn (mixed $v): int => T::int($v))->all();
    expect($order)->toBe([...$kept, ...$extra]);
});

it('asks only about the new or changed prices, names only those, and changes nothing until confirmed', function () {
    $ids = cleProducts(30, 'C');
    $prices = [];
    foreach (array_slice($ids, 0, 25) as $id) {
        $prices[$id] = ['mode' => 'price', 'value' => CLE_BELOW_COST];
    }
    actingAs(Staff::admin());
    post('/manage/storefronts/1/campaigns', clePayload($ids, $prices, true))->assertSessionHasNoErrors();
    $campaign = T::int(DB::table('storefront_campaigns')->value('id'));
    $stamp = DB::table('storefront_campaigns')->where('id', $campaign)->value('updated_at');
    $before = cleRows($campaign);

    // one new below-cost price and one changed one: asked about those two only
    $changed = $prices;
    $changed[$ids[26]] = ['mode' => 'price', 'value' => 800.0];
    $changed[$ids[3]] = ['mode' => 'price', 'value' => 850.0];
    put("/manage/storefronts/1/campaigns/{$campaign}", clePayload($ids, $changed))->assertSessionHasErrors('confirm_prices');
    expect(T::err('confirm_prices'))->toContain('(2)')
        ->and(json_decode(T::err('confirm_prices_names'), true))->toBe(['عرض C 3', 'عرض C 26']);

    // the refused save changed nothing: the rows, their prices and the campaign itself
    expect(cleRows($campaign))->toBe($before)
        ->and(DB::table('storefront_campaigns')->where('id', $campaign)->value('updated_at'))->toBe($stamp)
        ->and(T::float(DB::table('storefront_campaign_products')->where('campaign_id', $campaign)->where('product_id', $ids[3])->value('price_value')))->toBe(CLE_BELOW_COST);

    put("/manage/storefronts/1/campaigns/{$campaign}", clePayload($ids, $changed, true))->assertSessionHasNoErrors();
    expect(T::float(DB::table('storefront_campaign_products')->where('campaign_id', $campaign)->where('product_id', $ids[3])->value('price_value')))->toBe(850.0);
});

it('refuses products the shop does not show with how many and which, in the dashboard language, and changes nothing', function () {
    $ids = cleProducts(8, 'P');
    actingAs(Staff::admin());
    post('/manage/storefronts/1/campaigns', clePayload($ids, []))->assertSessionHasNoErrors();
    $campaign = T::int(DB::table('storefront_campaigns')->value('id'));
    $before = cleRows($campaign);

    // two products this storefront does not carry (one not placed on it, one deleted)
    $loose = CatalogFixture::product('watch', CLE_SELLING);
    DB::table('catalog_product_translations')->where('product_id', $loose)->where('locale', 'en')->update(['title' => 'Not on this shop']);
    DB::table('catalog_product_translations')->where('product_id', $loose)->where('locale', 'ar')->update(['title' => 'ليس في هذا المتجر']);
    DB::table('catalog_products')->where('id', $ids[7])->update(['deleted_at' => now()]);
    put("/manage/storefronts/1/campaigns/{$campaign}", clePayload([...$ids, $loose], []))->assertSessionHasErrors('product_ids');
    expect(T::err('product_ids'))->toContain('(2)')
        ->and(json_decode(T::err('product_ids_names'), true))->toBe(['عرض P 7', 'ليس في هذا المتجر'])
        ->and(cleRows($campaign))->toBe($before);

    // the same refusal in English for an operator using the dashboard in English (their saved preference)
    $admin = T::int(Staff::admin()->getKey());
    $had = DB::table('core_user_preferences')->where('user_id', $admin)->value('locale');
    DB::table('core_user_preferences')->updateOrInsert(['user_id' => $admin], ['locale' => 'en']);
    try {
        put("/manage/storefronts/1/campaigns/{$campaign}", clePayload([...$ids, $loose], []))->assertSessionHasErrors('product_ids');
        expect(json_decode(T::err('product_ids_names'), true))->toBe(['Offer P 7', 'Not on this shop'])
            ->and(T::err('product_ids'))->toContain('Products not shown on this storefront or deleted (2)');
    } finally {
        $had === null ? DB::table('core_user_preferences')->where('user_id', $admin)->delete()
            : DB::table('core_user_preferences')->where('user_id', $admin)->update(['locale' => $had]);
    }
});

it('refuses an overlapping active hot-deals offer with one short sentence naming the other offer, and saves nothing', function () {
    $ids = cleProducts(4, 'O');
    actingAs(Staff::admin());
    post('/manage/storefronts/1/campaigns', clePayload([$ids[0], $ids[1]], [], name: 'Live hot deals'))->assertSessionHasNoErrors();
    post('/manage/storefronts/1/campaigns', clePayload([$ids[2], $ids[3]], [], name: 'Second hot deals'))->assertSessionHasErrors('starts_at');
    expect(T::err('starts_at'))->toContain('Live hot deals')
        ->and(mb_strlen(T::err('starts_at')))->toBeLessThan(300)
        ->and(DB::table('storefront_campaigns')->count())->toBe(1)
        ->and(DB::table('storefront_campaign_products')->count())->toBe(2);
});
