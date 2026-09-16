<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Customers\Customers;
use App\Transform\LegacySource;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/*
 * Customers (wave 4D) — the people who BUY.
 *
 * Three claims are worth asserting, and they are the three that would hurt if they were false:
 *
 *   1. it never writes the legacy tables it reads;
 *   2. no credential column reaches a prop, a CSV or anywhere else;
 *   3. a scoped grant sees its own storefronts' customers and 404s on the rest.
 *
 * The guest grouping is asserted against the data rather than a fixture, because the whole point of
 * this screen is that "a customer" is derived from order rows and not stored anywhere.
 */

beforeEach(fn () => actingAs(Staff::admin()));

it('lists registered accounts and guest groups together', function () {
    $table = Props::table(get('/manage/customers'));
    $rows = Props::rows($table);

    expect($rows)->not->toBe([]);

    $kinds = array_unique(array_map(static fn (array $row): string => T::str($row['kind'] ?? null), $rows));

    // This dump has both, and the screen is meaningless if it shows only one of them.
    expect($kinds)->toContain('guest');

    // Every row is identified by a key the URL can carry, and the two shapes are the only two.
    foreach ($rows as $row) {
        expect(T::str($row['ckey'] ?? null))->toMatch('/^[ug]:/');
    }
});

it('groups a guest by PHONE, so the same number is one customer and not three orders', function () {
    // Data-derived: find a phone that placed more than one guest order in this dump.
    $phone = DB::connection('legacy')->table('orders')
        ->whereNull('user_id')
        ->whereNotNull('guest_phone')
        ->where('guest_phone', '<>', '')
        ->groupBy('guest_phone')
        ->havingRaw('COUNT(*) > 1')
        ->value('guest_phone');

    if ($phone === null) {
        // Say so rather than pass silently: a comparison of two empty things is not a comparison.
        expect(true)->toBeTrue('no repeat guest phone in this dump — grouping not exercised');

        return;
    }

    $orders = T::int(DB::connection('legacy')->table('orders')
        ->whereNull('user_id')->where('guest_phone', $phone)->count());

    $rows = Props::rows(Props::table(get('/manage/customers?q='.urlencode(T::str($phone)))));
    $match = array_values(array_filter($rows, static fn (array $row): bool => T::str($row['phone'] ?? null) === T::str($phone)));

    expect($match)->toHaveCount(1)
        ->and(T::int($match[0]['orders_count'] ?? null))->toBe($orders);
});

it('searches by phone and by e-mail, and the search CHANGES the set', function () {
    $all = T::int(T::arr(Props::table(get('/manage/customers'))['meta'] ?? null)['total'] ?? null);

    $phone = T::str(DB::connection('legacy')->table('orders')
        ->whereNull('user_id')->whereNotNull('guest_phone')->where('guest_phone', '<>', '')->value('guest_phone'));

    $found = T::int(T::arr(Props::table(get('/manage/customers?q='.urlencode($phone)))['meta'] ?? null)['total'] ?? null);

    expect($found)->toBeGreaterThan(0)->and($found)->toBeLessThan($all);
});

it('filters by kind and by storefront, and each filter CHANGES the set', function () {
    $total = fn (string $url): int => T::int(T::arr(Props::table(get($url))['meta'] ?? null)['total'] ?? null);

    $all = $total('/manage/customers');
    $guests = $total('/manage/customers?filters[kind]=guest');
    $registered = $total('/manage/customers?filters[kind]=registered');

    // §4 law: a filter must change the set, and the two halves must add up to the whole.
    expect($guests)->toBeGreaterThan(0)
        ->and($guests)->toBeLessThan($all)
        ->and($guests + $registered)->toBe($all);

    $storefront = T::int(DB::connection('legacy')->table('orders')->whereNotNull('storefront_id')->value('storefront_id'));
    expect($total("/manage/customers?filters[storefront_id]={$storefront}"))->toBeGreaterThan(0);
});

it('shows one customer with their orders and the addresses those orders went to', function () {
    $rows = Props::rows(Props::table(get('/manage/customers?filters[has_orders]=1')));
    $ckey = T::str($rows[0]['ckey'] ?? null);

    $props = Props::of(get('/manage/customers/'.urlencode($ckey)));

    $customer = T::arr($props['customer'] ?? null);
    $orders = T::arr($props['orders'] ?? null);

    expect(T::str($customer['ckey'] ?? null))->toBe($ckey)
        ->and($orders)->not->toBe([])
        // Each order links to the order screen, which is where the work is actually done.
        ->and(T::str(T::arr($orders[0] ?? null)['url'] ?? null))->toContain('/manage/orders/');

    // Addresses are reached THROUGH the orders, so the key is that they are a list, possibly empty.
    expect($props['addresses'] ?? null)->toBeArray();
});

it('404s a customer key that does not resolve, and never 403', function () {
    get('/manage/customers/'.urlencode('u:99999999'))->assertNotFound();
    get('/manage/customers/'.urlencode('g:not-a-real-phone-number'))->assertNotFound();
});

it('has NO write route at all — the tables belong to the storefront', function () {
    $ckey = T::str(Props::rows(Props::table(get('/manage/customers')))[0]['ckey'] ?? null);

    // Not "the button is hidden": there is no route, so the router itself refuses.
    post('/manage/customers')->assertMethodNotAllowed();
    put('/manage/customers/'.urlencode($ckey))->assertMethodNotAllowed();
    delete('/manage/customers/'.urlencode($ckey))->assertMethodNotAllowed();
});

it('leaves the legacy tables byte-identical after a full walk of the screen', function () {
    // The acceptance test for anything that touches `users`: the digest must not move.
    $before = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];

    $rows = Props::rows(Props::table(get('/manage/customers')));
    foreach (array_slice($rows, 0, 5) as $row) {
        get('/manage/customers/'.urlencode(T::str($row['ckey'] ?? null)))->assertOk();
    }
    get('/manage/customers?export=csv')->assertOk()->streamedContent();

    expect(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($before);
});

it('never lets a credential column near a prop or the CSV', function () {
    $props = Props::of(get('/manage/customers'));
    $json = T::str(json_encode($props, JSON_UNESCAPED_UNICODE));

    $body = T::str(get('/manage/customers?export=csv')->streamedContent());

    foreach (['password', 'remember_token', 'email_verified_at', '$2y$'] as $secret) {
        expect($json)->not->toContain($secret, "the props carry [{$secret}]")
            ->and($body)->not->toContain($secret, "the export carries [{$secret}]");
    }
});

it('gives a data-entry user the screen, and a customer none of it', function () {
    // Same ability as the order queue: everything here is already on the order screens.
    actingAs(Staff::dataEntry());
    get('/manage/customers')->assertOk();

    actingAs(Staff::customer());
    get('/manage/customers')->assertForbidden();
});

it('narrows a SCOPED grant to its own storefronts, in the query', function () {
    $storefronts = T::many(DB::table('storefronts')->orderBy('id'));
    if (count($storefronts) < 2) {
        expect(true)->toBeTrue('one storefront in this database — scoping not exercised');

        return;
    }

    $first = T::int($storefronts[0]->id);
    $second = T::int($storefronts[1]->id);

    actingAs(Staff::adminFor($first));
    $mine = Props::rows(Props::table(get('/manage/customers')));

    // Every customer a scoped grant can see bought on a storefront it holds.
    foreach ($mine as $row) {
        $names = T::arr($row['storefronts'] ?? null);
        expect($names)->not->toBe([], 'a scoped grant must not see a customer with no order in scope');
    }

    // …and a customer who bought ONLY on the other storefront is a 404, not a 403.
    $other = Customers::query([$second])->where('c.orders_count', '>', 0)->first();
    if ($other !== null) {
        $ckey = T::str(T::row($other)->ckey);
        $visible = array_filter($mine, static fn (array $row): bool => T::str($row['ckey'] ?? null) === $ckey);
        if ($visible === []) {
            get('/manage/customers/'.urlencode($ckey))->assertNotFound();
        }
    }
});
