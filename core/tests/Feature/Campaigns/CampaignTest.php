<?php

use App\Domain\Campaigns\Campaigns;
use App\Domain\Campaigns\CampaignWriter;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\PaymentFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;
use Tests\Support\VariantFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/*
 * ── Timed campaigns: Hot Deals and Flash Sale (the twelve updates, Part 2, 2026-11-05) ────────────
 *
 * ONE mechanism, two presentations. What these tests hold is the brief's "real data, no theatre":
 *
 *  • a campaign is live between its start and its REAL end, and at the end it is gone from every
 *    reader at once — the endpoint, the listing filter — with no grace and no restart;
 *  • the progress bar is computed from a starting stock recorded ONCE when the product joins the live
 *    campaign, and from the stock LEDGER after that (order lines sold in the window, net of what they
 *    gave back). A product with no recorded start has no bar; nothing is ever estimated;
 *  • the dashboard refuses, on the field, the states the storefront could not show honestly: two live
 *    campaigns of one presentation, a product the shop does not carry, an end before the start.
 *
 * Every subject is derived from the data (a visible product with stock and no variants), never named.
 */

beforeEach(function () {
    // Whatever campaigns the copy carries would be "the live Flash Sale" instead of the test's own.
    DB::table('storefront_campaigns')->delete();
    app(StorefrontCache::class)->flush(1);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Products storefront 1 shows, in stock, sold at product level (no variants).
 *
 * @return list<array{id: int, stock: int}>
 */
function cmpProducts(int $count): array
{
    $out = [];
    foreach (
        DB::table('catalog_products as p')
            ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', 1)->where('sp.is_visible', 1))
            ->whereNull('p.deleted_at')->where('p.is_active', 1)->where('p.stock_express', '>=', 3)
            ->whereNotExists(function (Builder $q): void {
                $q->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id')->selectRaw('1');
            })
            ->orderBy('p.id')->limit($count)->get(['p.id', 'p.stock_express', 'p.stock_market']) as $raw
    ) {
        $out[] = ['id' => Row::int($raw, 'id'), 'stock' => Row::int($raw, 'stock_express') + Row::int($raw, 'stock_market')];
    }
    expect($out)->toHaveCount($count, 'the copy needs '.$count.' visible in-stock products without variants');

    return $out;
}

/**
 * @param  list<int>  $productIds
 * @return array<string, mixed>
 */
function cmpPayload(array $productIds, string $presentation = 'flash_sale', ?Carbon $start = null, ?Carbon $end = null, bool $active = true, string $name = 'Weekend sale'): array
{
    return [
        'name' => $name,
        'presentation' => $presentation,
        'starts_at' => ($start ?? now()->subHour())->format('Y-m-d\TH:i'),
        'ends_at' => ($end ?? now()->addDay())->format('Y-m-d\TH:i'),
        'is_active' => $active,
        'product_ids' => $productIds,
    ];
}

/**
 * Create through the WRITER (the dashboard's own door); returns the campaign id.
 *
 * @param  list<int>  $productIds
 */
function cmpCreate(array $productIds, string $presentation = 'flash_sale', ?Carbon $start = null, ?Carbon $end = null, bool $active = true): int
{
    return CampaignWriter::create(1, [
        'name' => 'Test campaign',
        'presentation' => $presentation,
        'starts_at' => ($start ?? now()->subHour())->format('Y-m-d H:i:00'),
        'ends_at' => ($end ?? now()->addDay())->format('Y-m-d H:i:00'),
        'is_active' => $active,
        'product_ids' => $productIds,
    ], null);
}

/** An order of `$quantity` units of one product, reserved through the service; returns the order id. */
function cmpSell(int $productId, int $quantity, bool $reward = false): int
{
    $orderId = PaymentFixture::order(total: 100.0, status: 'processing');
    DB::table('order_items')->insert([
        'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null, 'quantity' => $quantity,
        'piece_price' => '100.00', 'total_price' => '100.00', 'type_stock' => 'Express', 'is_reward' => $reward ? 1 : 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    app(InventoryService::class)->commitOrder($orderId, null, 1);

    return $orderId;
}

/** @return array<mixed> the endpoint's `data` */
function cmpLive(): array
{
    return T::arr(getJson('https://api.watchizereg.com/api/v2/watchizer/campaigns')->assertOk()->json('data'));
}

// ── 1. the dashboard page ────────────────────────────────────────────────────────────────────────

it('creates a campaign from the page with its five things, lists it with its state, and logs the write', function () {
    $products = cmpProducts(3);
    $ids = array_column($products, 'id');
    // each restocked once: the list's numbers count from there (2026-11-10)
    $shelves = array_map(fn (array $p): int => cmpRestock($p['id'], 1), $products);
    actingAs(Staff::admin());

    post('/manage/storefronts/1/campaigns', cmpPayload($ids, 'hot_deals'))->assertSessionHasNoErrors();

    $row = T::one(DB::table('storefront_campaigns')->where('storefront_id', 1));
    expect(Row::str($row, 'name'))->toBe('Weekend sale')
        ->and(Row::str($row, 'presentation'))->toBe('hot_deals')
        ->and(Row::bool($row, 'is_active'))->toBeTrue()
        ->and(Campaigns::productIds(Row::int($row, 'id')))->toBe($ids);

    $listed = T::arr(Props::of(get('/manage/storefronts/1/campaigns')->assertOk())['campaigns'] ?? null);
    expect($listed)->toHaveCount(1)
        ->and(T::arr($listed[0])['state'])->toBe('live')
        ->and(T::arr($listed[0])['product_ids'])->toBe($ids)
        // every product restocked: the shelf right after each restock, nothing sold since
        ->and(T::arr($listed[0])['recorded'])->toBe(3)
        ->and(T::arr($listed[0])['started_units'])->toBe(array_sum($shelves))
        ->and(T::arr($listed[0])['sold_units'])->toBe(0);

    expect(DB::table('core_activity_log')->where('subject_type', 'storefront_campaigns')->where('subject_id', Row::int($row, 'id'))->count())->toBe(1);
});

it('REFUSES, on the field, what the storefront could not show honestly', function () {
    $ids = array_column(cmpProducts(3), 'id');
    actingAs(Staff::admin());
    post('/manage/storefronts/1/campaigns', cmpPayload([$ids[0]], 'flash_sale', name: 'First'))->assertSessionHasNoErrors();

    // A second ACTIVE campaign of the same presentation over the same hours: the header has one countdown.
    post('/manage/storefronts/1/campaigns', cmpPayload([$ids[1]], 'flash_sale', name: 'Second'))->assertSessionHasErrors('starts_at');
    expect(T::err('starts_at'))->toContain('First');

    // …the other presentation, or the same one switched off, overlaps nothing.
    post('/manage/storefronts/1/campaigns', cmpPayload([$ids[1]], 'hot_deals', name: 'Hot'))->assertSessionHasNoErrors();
    post('/manage/storefronts/1/campaigns', cmpPayload([$ids[1]], 'flash_sale', active: false, name: 'Parked'))->assertSessionHasNoErrors();

    // An end before the start; no products; a product this storefront does not carry.
    post('/manage/storefronts/1/campaigns', cmpPayload([$ids[2]], 'flash_sale', now()->addDays(3), now()->addDays(2), false))->assertSessionHasErrors('ends_at');
    post('/manage/storefronts/1/campaigns', cmpPayload([], 'flash_sale', active: false))->assertSessionHasErrors('product_ids');
    $unplaced = T::int(DB::table('catalog_products')->max('id')) + 1000;
    post('/manage/storefronts/1/campaigns', cmpPayload([$unplaced], 'flash_sale', active: false))->assertSessionHasErrors('product_ids');

    expect(DB::table('storefront_campaigns')->count())->toBe(3);

    // Switching the parked one ON would make two live Flash Sales: refused the same way, and it stays off.
    $parked = T::int(DB::table('storefront_campaigns')->where('name', 'Parked')->value('id'));
    put('/manage/storefronts/1/campaigns/'.$parked, cmpPayload([$ids[1]], 'flash_sale', name: 'Parked'))->assertSessionHasErrors('starts_at');
    expect(Row::bool(T::one(DB::table('storefront_campaigns')->where('id', $parked)), 'is_active'))->toBeFalse();

    delete('/manage/storefronts/1/campaigns/'.$parked)->assertSessionHasNoErrors();
    expect(DB::table('storefront_campaigns')->where('id', $parked)->exists())->toBeFalse()
        ->and(DB::table('storefront_campaign_products')->where('campaign_id', $parked)->exists())->toBeFalse();
});

/** A restock through the stock service (the dashboard's own door); returns the shelf right after it. */
function cmpRestock(int $productId, int $units, string $reason = 'restock'): int
{
    app(InventoryService::class)->adjust(StockTarget::product($productId), InventoryService::BUCKET_EXPRESS, $units, $reason);
    $row = T::one(DB::table('catalog_products')->where('id', $productId));

    return Row::int($row, 'stock_express') + Row::int($row, 'stock_market');
}

// ── 2. the bar counts from the product's LAST RESTOCK (Michael, 2026-11-10) ───────────────────────

it('counts the bar from the product\'s LAST RESTOCK — and a restock after the campaign started restarts it', function () {
    [$a] = cmpProducts(1);
    $shelf = cmpRestock($a['id'], 4);
    cmpSell($a['id'], 1);                                              // after the restock, before the campaign: counted
    $campaign = ['id' => cmpCreate([$a['id']], 'hot_deals')];
    expect(Campaigns::progress($campaign))->toBe([$a['id'] => ['start' => $shelf, 'sold' => 1]]);
    cmpSell($a['id'], 2);
    expect(Campaigns::progress($campaign)[$a['id']])->toBe(['start' => $shelf, 'sold' => 3]);

    // a restock DURING the campaign: the bar starts again from it — the shelf right after it, nothing sold yet
    $again = cmpRestock($a['id'], 6, 'manual');
    expect($again)->toBe($shelf - 3 + 6)
        ->and(Campaigns::progress($campaign)[$a['id']])->toBe(['start' => $again, 'sold' => 0]);
    cmpSell($a['id'], 1);
    expect(Campaigns::progress($campaign)[$a['id']])->toBe(['start' => $again, 'sold' => 1]);
});

it('does NOT restart the bar for units an order gave back or a move between the buckets; a gift is not a sale; sold is net of cancellations', function () {
    [$a] = cmpProducts(1);
    $shelf = cmpRestock($a['id'], 5);
    $campaign = ['id' => cmpCreate([$a['id']], 'flash_sale')];
    $kept = cmpSell($a['id'], 2);
    $cancelled = cmpSell($a['id'], 1);
    cmpSell($a['id'], 1, reward: true);                                // a gift line: units gone, not a sale
    expect(Campaigns::progress($campaign)[$a['id']])->toBe(['start' => $shelf, 'sold' => 3]);

    // the cancellation gives its unit back: the count follows it, and the bar does NOT restart from the return
    app(InventoryService::class)->releaseOrder($cancelled, 'order_cancel', null, 1);
    expect(Campaigns::progress($campaign)[$a['id']])->toBe(['start' => $shelf, 'sold' => 2]);

    // the dashboard's "move between buckets": two movements in one transaction, the plus is not new stock
    $target = StockTarget::product($a['id']);
    DB::transaction(function () use ($target): void {
        app(InventoryService::class)->adjust($target, InventoryService::BUCKET_EXPRESS, -1, 'adjustment', note: 'move');
        app(InventoryService::class)->adjust($target, InventoryService::BUCKET_MARKET, 1, 'adjustment', note: 'move');
    });
    expect(Campaigns::progress($campaign)[$a['id']])->toBe(['start' => $shelf, 'sold' => 2])
        ->and(DB::table('inventory_movements')->where('reference_id', $kept)->where('reason', 'order')->count())->toBe(1);
});

it('draws NO bar for a product with no stock addition in its ledger, counts from the opening balance when no restock came after it, and counts a variant product across its variants', function () {
    // no movement at all: no bar, never an invented number
    $bare = CatalogFixture::product();
    // only the opening balance carried over from the old system (D-47): the bar counts from it
    $opened = CatalogFixture::product();
    app(InventoryService::class)->set(StockTarget::product($opened), InventoryService::BUCKET_EXPRESS, 7, 'transform');
    // two variants, 5 + 3 units each (four additions): the last addition is on a variant, the shelf is all of them
    $v = VariantFixture::synthetic(2, express: 5, market: 3);
    app(InventoryService::class)->commitOrder(VariantFixture::order($v['product'], $v['variants'][0], 2), null, 1);

    expect(Campaigns::sinceRestock([$bare]))->toBe([])
        ->and(Campaigns::sinceRestock([$bare, $opened, $v['product']]))->toBe([$opened => ['start' => 7, 'sold' => 0], $v['product'] => ['start' => 16, 'sold' => 2]]);

    // a restock after the opening balance takes over from it
    $after = cmpRestock($opened, 3);
    expect(Campaigns::sinceRestock([$opened]))->toBe([$opened => ['start' => $after, 'sold' => 0]])
        ->and($after)->toBe(10);
});

// ── 4. the storefront endpoint ───────────────────────────────────────────────────────────────────

it('serves the live campaign with real numbers on each card, and CLOSES it at its real end', function () {
    $products = cmpProducts(3);
    [$a, $b, $c] = $products;
    $end = now()->addHours(2)->startOfMinute();
    $id = cmpCreate([$b['id'], $a['id'], $c['id']], 'flash_sale', now()->subHour(), $end);
    // a and b restocked (each bar counts from there); c's additions rewritten as order returns — no addition, no bar
    $shelfA = cmpRestock($a['id'], 1);
    $shelfB = cmpRestock($b['id'], 1);
    DB::table('inventory_movements')->where('product_id', $c['id'])->whereIn('reason', Campaigns::RESTOCK_REASONS)->update(['reason' => 'order_cancel']);

    Carbon::setTestNow(now()->addSeconds(5));
    cmpSell($a['id'], 2);
    app(StorefrontCache::class)->flush(1);

    $data = cmpLive();
    $flash = T::arr($data['flash_sale']);
    expect($data['hot_deals'])->toBeNull()
        ->and(T::str($data['now']))->toBe(now()->toIso8601String())
        ->and(T::int($flash['id']))->toBe($id)
        ->and(T::str($flash['ends_at']))->toBe($end->toIso8601String())
        ->and(T::int($flash['count']))->toBe(3);

    // The cards, in the team's order, each with its own REAL bar — or none.
    $cards = T::arr($flash['cards']);
    expect(array_map(fn (mixed $card): int => T::int(T::arr($card)['id']), $cards))->toBe([$b['id'], $a['id'], $c['id']])
        ->and(T::arr($cards[1])['campaign'])->toBe(['start' => $shelfA, 'sold' => 2, 'left' => $shelfA - 2, 'pct' => min(99, (int) round(2 / $shelfA * 100)), 'state' => 'selling'])
        ->and(T::arr($cards[0])['campaign'])->toBe(['start' => $shelfB, 'sold' => 0, 'left' => $shelfB, 'pct' => 0, 'state' => 'selling'])
        ->and(T::arr($cards[2])['campaign'])->toBeNull('a product with no stock addition has NO bar — never an estimated one');
    expect(array_keys(T::arr($flash['progress'])))->toEqualCanonicalizing([$a['id'], $b['id']]);

    // One second before the end it is still live; AT the end it is gone — no grace, no rollover.
    Carbon::setTestNow($end->copy()->subSecond());
    expect(cmpLive()['flash_sale'])->not->toBeNull();
    Carbon::setTestNow($end->copy());
    expect(cmpLive()['flash_sale'])->toBeNull();
    Carbon::setTestNow($end->copy()->addDay());
    expect(cmpLive()['flash_sale'])->toBeNull();
});

it('shows a campaign only while it is ACTIVE and inside its window, one per presentation', function () {
    $ids = array_column(cmpProducts(3), 'id');

    cmpCreate([$ids[0]], 'flash_sale', now()->addHour(), now()->addDay());            // not started
    cmpCreate([$ids[1]], 'hot_deals', now()->subHour(), now()->addDay(), active: false); // switched off
    expect(cmpLive()['flash_sale'])->toBeNull()->and(cmpLive()['hot_deals'])->toBeNull();

    $hot = cmpCreate([$ids[2]], 'hot_deals', now()->subHour(), now()->addDay());
    expect(T::int(T::arr(cmpLive()['hot_deals'])['id']))->toBe($hot)->and(cmpLive()['flash_sale'])->toBeNull();

    // The scheduled Flash Sale starts: both presentations are live together, each its own campaign.
    Carbon::setTestNow(now()->addHours(2));
    $data = cmpLive();
    expect($data['flash_sale'])->not->toBeNull()->and($data['hot_deals'])->not->toBeNull();
});

it('names the next START beside the clock, so a held copy of the answer is dropped at that second', function () {
    $ids = array_column(cmpProducts(3), 'id');
    expect(array_key_exists('next_start_at', cmpLive()))->toBeTrue()
        ->and(cmpLive()['next_start_at'])->toBeNull();

    $soon = now()->addHours(2)->startOfMinute();
    $later = now()->addHours(6)->startOfMinute();
    cmpCreate([$ids[0]], 'hot_deals', $later, $later->copy()->addDay());
    cmpCreate([$ids[1]], 'flash_sale', $soon, $soon->copy()->addHour());
    // switched off: it will not start, so it is not a start to wait for
    cmpCreate([$ids[2]], 'flash_sale', now()->addMinutes(20), now()->addMinutes(50), active: false);

    // nothing is live yet; the nearer of the two scheduled starts is named, in the clock's own form
    $data = cmpLive();
    expect($data['flash_sale'])->toBeNull()->and($data['hot_deals'])->toBeNull()
        ->and(T::str($data['next_start_at']))->toBe($soon->toIso8601String());

    // one second before it the answer is unchanged; AT it the sale is live and the next start is the one after
    Carbon::setTestNow($soon->copy()->subSecond());
    expect(cmpLive()['flash_sale'])->toBeNull()
        ->and(T::str(cmpLive()['next_start_at']))->toBe($soon->toIso8601String());
    Carbon::setTestNow($soon->copy());
    $data = cmpLive();
    expect($data['flash_sale'])->not->toBeNull()
        ->and(T::str($data['next_start_at']))->toBe($later->toIso8601String());

    // the last scheduled start has come: nothing more to wait for
    Carbon::setTestNow($later->copy());
    $data = cmpLive();
    expect($data['hot_deals'])->not->toBeNull()->and($data['next_start_at'])->toBeNull();
});

// ── 5. the listing filter (the campaign page, and Hot Deals mode) ─────────────────────────────────

it('narrows the listing to the LIVE campaign\'s products — and to nothing when no campaign is live', function () {
    $ids = array_column(cmpProducts(4), 'id');
    $end = now()->addHours(3)->startOfMinute();
    cmpCreate([$ids[0], $ids[1], $ids[2]], 'hot_deals', now()->subHour(), $end);

    $listed = fn (string $query): array => T::arr(getJson('https://api.watchizereg.com/api/v2/watchizer/products?'.$query)->assertOk()->json());
    $idsOf = fn (array $page): array => array_map(fn (mixed $card): int => T::int(T::arr($card)['id']), T::arr($page['data']));

    $all = T::int(T::arr($listed('per_page=1')['meta'])['total']);
    $page = $listed('campaign=hot_deals&per_page=50');
    expect($all)->toBeGreaterThan(3)
        ->and(T::int(T::arr($page['meta'])['total']))->toBe(3)
        ->and($idsOf($page))->toEqualCanonicalizing([$ids[0], $ids[1], $ids[2]])
        // every row it returns is in the campaign, and the product outside it is not there
        ->and($idsOf($page))->not->toContain($ids[3]);

    // The other presentation has no live campaign: an EMPTY list, never the catalogue.
    expect(T::int(T::arr($listed('campaign=flash_sale')['meta'])['total']))->toBe(0);

    // At the campaign's end the same URL is empty at once — not a cached page of a sale that is over.
    Carbon::setTestNow($end->copy());
    expect(T::int(T::arr($listed('campaign=hot_deals&per_page=50')['meta'])['total']))->toBe(0);

    // A value that is not a presentation is refused, not ignored (an ignored filter looks like a full list).
    getJson('https://api.watchizereg.com/api/v2/watchizer/products?campaign=clearance')->assertStatus(422);
});
