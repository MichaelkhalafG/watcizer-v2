<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Access\Navigation;
use App\Domain\Activity\ActivityLog;
use App\Domain\Pickup\Pickup;
use App\Domain\Pickup\PickupStores;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/*
 * "Pick up in store" (click & collect, 2026-11-14) — Part 1: the data and the dashboard page. The switch (off by
 * default, on only with an active store), the terms and their bounds, the stores per storefront (saved as one list,
 * activity-logged, never across shops, never removed while they hold open reservations), the ready window and the
 * customer's sentence, the screen's gates.
 */

/** @return list<array{id: int, position: int, name_en: string, name_ar: string, address_en: string, address_ar: string, hours_en: string|null, hours_ar: string|null, map_url: string|null, phone: string|null, whatsapp: string|null, is_active: bool}> */
function pkStores(int $storefrontId): array
{
    return PickupStores::forStorefront($storefrontId);
}

/** A reservation row at a store (Part 1 only needs the row; the checkout writes real ones in Part 2). */
function pkOpenReservation(int $storefrontId, int $storeId, string $status = 'reserved'): void
{
    $orderId = T::int(DB::table('orders')->where('storefront_id', $storefrontId)->value('id'));
    DB::table('order_pickups')->insert([
        'order_id' => $orderId, 'storefront_id' => $storefrontId, 'store_id' => $storeId,
        'store_name_en' => 'X', 'store_name_ar' => 'X', 'store_address_en' => 'X', 'store_address_ar' => 'X',
        'status' => $status, 'code' => '1234', 'stock_kind' => 'express', 'ready_min_days' => 1, 'ready_max_days' => 2,
        'reserved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Opening hours on every store of a shop — the switch refuses without them (Michael, 2026-11-14). */
function pkHours(int $storefrontId): void
{
    DB::table(PickupStores::TABLE)->where('storefront_id', $storefrontId)->update(['hours_en' => 'Daily 11:00–23:00', 'hours_ar' => 'يوميًا ١١ ص – ١١ م']);
}

/**
 * @param  array<string, mixed>  $patch
 * @return array<string, mixed>
 */
function pkTerms(array $patch = []): array
{
    return $patch + ['hold_hours' => 48, 'extend_hours' => 24, 'express_min' => 1, 'express_max' => 2, 'market_min' => 4, 'market_max' => 7, 'max_open_store_pay' => 2];
}

it('creates the tables, the fulfilment column and an optional address, seeds the two stores for every shop, and keeps both tables through a rebuild', function () {
    expect(Schema::hasTable('storefront_pickup_stores'))->toBeTrue()
        ->and(Schema::hasTable('order_pickups'))->toBeTrue()
        ->and(Schema::hasColumn('orders', 'fulfilment'))->toBeTrue();
    $address = T::arr(collect(Schema::getColumns('orders'))->firstWhere('name', 'address_id'));
    expect($address['nullable'] ?? null)->toBeTrue();
    expect(DB::table('orders')->where('fulfilment', '!=', 'delivery')->count())->toBe(0);
    foreach (DB::table('storefronts')->pluck('id') as $sf) {
        $names = array_column(pkStores(T::int($sf)), 'name_en');
        expect($names)->toContain('Arkadia Mall')->toContain('Heliopolis');
    }
    // the client's details (2026-11-14): each shop's own number on its stores, the two map links, the team's hours
    $byShop = ['1' => '01551096234', '2' => '01014179820'];
    foreach ($byShop as $sf => $number) {
        foreach (pkStores((int) $sf) as $store) {
            expect($store['phone'])->toBe($number)->and($store['whatsapp'])->toBe('2'.$number)
                ->and($store['hours_en'])->toBe('Every day, 10 am – midnight')->and($store['hours_ar'])->toBe('يوميًا من ١٠ صباحًا حتى ١٢ منتصف الليل');
        }
        $maps = array_column(pkStores((int) $sf), 'map_url', 'name_en');
        expect($maps['Arkadia Mall'] ?? null)->toBe($sf === 1 ? 'https://maps.app.goo.gl/VyiQpq2Ajh9B6y8f6' : 'https://maps.app.goo.gl/qqdVr4ymeXva3WDF8')
            ->and($maps['Heliopolis'] ?? null)->toBe('https://maps.app.goo.gl/c5wtDgsmLEP398ht7');
    }
    // never the old Brand Fashion number
    expect(DB::table(PickupStores::TABLE)->where('phone', '01283888490')->orWhere('whatsapp', '201283888490')->exists())->toBeFalse();
    expect(CoreChecksumCommand::DASHBOARD_TABLES)->toContain('storefront_pickup_stores')->toContain('order_pickups');
});

it('is OFF by default with the agreed terms, and falls back to them when a stored value is out of bounds', function () {
    $s = Pickup::settings(2);
    expect($s['enabled'])->toBeFalse()
        ->and($s['hold_hours'])->toBe(48)->and($s['extend_hours'])->toBe(24)
        ->and($s['express_days'])->toBe([1, 2])->and($s['market_days'])->toBe([4, 7])
        ->and($s['max_open_store_pay'])->toBe(2);
    $bad = Pickup::settingsFrom(['pickup' => ['enabled' => 'yes', 'hold_hours' => 0, 'extend_hours' => 999, 'express_days' => [3, 1], 'market_days' => [4], 'max_open_store_pay' => 0]]);
    expect($bad['enabled'])->toBeFalse()->and($bad['hold_hours'])->toBe(48)->and($bad['extend_hours'])->toBe(24)
        ->and($bad['express_days'])->toBe([1, 2])->and($bad['market_days'])->toBe([4, 7])->and($bad['max_open_store_pay'])->toBe(2);
});

it('promises the market window when any line is sourced first, and says it in both languages', function () {
    $s = Pickup::settingsFrom(null);
    expect(Pickup::readyWindow(['express', 'express'], $s))->toBe(['kind' => 'express', 'min' => 1, 'max' => 2])
        ->and(Pickup::readyWindow(['express', 'market'], $s))->toBe(['kind' => 'market', 'min' => 4, 'max' => 7]);
    expect(Pickup::readySentence(['min' => 1, 'max' => 2]))->toBe(['en' => 'Ready for pickup in 1–2 business days', 'ar' => 'جاهز للاستلام خلال ١–٢ يوم عمل'])
        ->and(Pickup::readySentence(['min' => 4, 'max' => 7]))->toBe(['en' => 'Ready for pickup in 4–7 business days', 'ar' => 'جاهز للاستلام خلال ٤–٧ أيام عمل'])
        ->and(Pickup::readySentence(['min' => 1, 'max' => 1])['en'])->toBe('Ready for pickup in 1 business day')
        ->and(Pickup::readySentence(['min' => 0, 'max' => 0])['en'])->toBe('Ready for pickup today');
});

it('shows the page to an admin with the switch, the terms, the sentences and the stores — and lists it in the menu', function () {
    actingAs(Staff::admin());
    $props = Props::of(get('/manage/storefronts/2/pickup')->assertOk());
    expect($props['enabled'] ?? null)->toBeFalse()
        ->and(T::arr($props['terms'] ?? null)['market_max'] ?? null)->toBe(7)
        ->and(T::arr(T::arr($props['sentences'] ?? null)['market'] ?? null)['en'] ?? null)->toBe('Ready for pickup in 4–7 business days')
        ->and(array_column(T::arr($props['stores'] ?? null), 'name_en'))->toBe(['Arkadia Mall', 'Heliopolis']);
    $keys = [];
    foreach (Navigation::for(Staff::admin()) as $group) {
        foreach (T::arr(T::arr($group)['items'] ?? null) as $item) {
            $keys[] = T::arr($item)['key'] ?? null;
        }
    }
    expect($keys)->toContain('pickup');
});

it('turns ON only with an active store, logs the switch, and turns OFF again', function () {
    actingAs(Staff::admin());
    DB::table(PickupStores::TABLE)->where('storefront_id', 2)->update(['is_active' => false]);
    post('/manage/storefronts/2/pickup/switch', ['enabled' => 1])->assertSessionHasErrors('enabled');
    expect(Pickup::enabled(2))->toBeFalse();

    DB::table(PickupStores::TABLE)->where('storefront_id', 2)->where('name_en', 'Heliopolis')->update(['is_active' => true, 'hours_ar' => null]);
    // an active store with no opening hours (here: no Arabic hours): refused, and the message names the store
    post('/manage/storefronts/2/pickup/switch', ['enabled' => 1])->assertSessionHasErrors('enabled');
    $errors = session('errors');
    expect($errors instanceof ViewErrorBag ? $errors->first('enabled') : '')->toContain('مصر الجديدة');
    expect(Pickup::enabled(2))->toBeFalse();
    pkHours(2);
    $before = DB::table(ActivityLog::TABLE)->where('subject_type', 'storefronts')->where('subject_id', 2)->count();
    post('/manage/storefronts/2/pickup/switch', ['enabled' => 1])->assertSessionHasNoErrors()->assertRedirect();
    expect(Pickup::enabled(2))->toBeTrue()->and(Pickup::enabled(1))->toBeFalse()
        ->and(DB::table(ActivityLog::TABLE)->where('subject_type', 'storefronts')->where('subject_id', 2)->count())->toBe($before + 1);
    post('/manage/storefronts/2/pickup/switch', ['enabled' => 0])->assertRedirect();
    expect(Pickup::enabled(2))->toBeFalse();
});

it('saves the terms all or nothing, each refusal on its field', function () {
    actingAs(Staff::admin());
    put('/manage/storefronts/2/pickup/terms', pkTerms(['hold_hours' => 0, 'market_min' => 6, 'market_max' => 5, 'max_open_store_pay' => 99]))
        ->assertSessionHasErrors(['hold_hours', 'market_max', 'max_open_store_pay']);
    expect(Pickup::settings(2)['market_days'])->toBe([4, 7]);

    put('/manage/storefronts/2/pickup/terms', pkTerms(['hold_hours' => 72, 'market_min' => 5, 'market_max' => 10, 'max_open_store_pay' => 3]))->assertSessionHasNoErrors();
    $s = Pickup::settings(2);
    expect($s['hold_hours'])->toBe(72)->and($s['market_days'])->toBe([5, 10])->and($s['max_open_store_pay'])->toBe(3)
        ->and(Pickup::settings(1)['hold_hours'])->toBe(48);
    expect(Pickup::readySentence(['min' => 5, 'max' => 10])['ar'])->toBe('جاهز للاستلام خلال ٥–١٠ أيام عمل');
});

it('saves a shop\'s stores as one list: edit, add, reorder, remove — logged, and never touching the other shop', function () {
    actingAs(Staff::admin());
    $other = pkStores(1);
    [$arkadia, $helio] = pkStores(2);
    $logBefore = DB::table(ActivityLog::TABLE)->where('subject_type', PickupStores::TABLE)->count();
    put('/manage/storefronts/2/pickup/stores', ['stores' => [
        array_merge($helio, ['hours_en' => 'Daily 11:00–23:00', 'hours_ar' => 'يوميًا ١١ ص – ١١ م', 'whatsapp' => '01283888490', 'map_url' => 'https://maps.app.goo.gl/x']),
        ['name_en' => 'Zamalek', 'name_ar' => 'الزمالك', 'address_en' => '1 Street, Zamalek', 'address_ar' => '١ شارع، الزمالك', 'is_active' => false],
    ]])->assertSessionHasNoErrors()->assertRedirect();
    $after = pkStores(2);
    expect(array_column($after, 'name_en'))->toBe(['Heliopolis', 'Zamalek'])
        ->and($after[0]['whatsapp'])->toBe('201283888490')
        ->and($after[0]['hours_ar'])->toBe('يوميًا ١١ ص – ١١ م')
        ->and($after[1]['is_active'])->toBeFalse()
        ->and(pkStores(1))->toBe($other);
    // Arkadia deleted, Heliopolis updated, Zamalek created
    expect(DB::table(ActivityLog::TABLE)->where('subject_type', PickupStores::TABLE)->count())->toBe($logBefore + 3);
    expect(DB::table(PickupStores::TABLE)->where('id', $arkadia['id'])->exists())->toBeFalse();
});

it('refuses another shop\'s store, a bad map link or phone, removing a store with an open reservation, and an enabled shop without an active store', function () {
    actingAs(Staff::admin());
    [$arkadia, $helio] = pkStores(2);
    $foreign = pkStores(1)[0];
    put('/manage/storefronts/2/pickup/stores', ['stores' => [$foreign, $helio]])->assertSessionHasErrors('stores.0.id');
    put('/manage/storefronts/2/pickup/stores', ['stores' => [['map_url' => 'http://maps.example', 'phone' => '12'] + $arkadia, $helio]])
        ->assertSessionHasErrors(['stores.0.map_url', 'stores.0.phone']);
    put('/manage/storefronts/2/pickup/stores', ['stores' => [['name_ar' => ''] + $arkadia, $helio]])->assertSessionHasErrors('stores.0.name_ar');

    pkOpenReservation(2, $arkadia['id'], 'ready');
    put('/manage/storefronts/2/pickup/stores', ['stores' => [$helio]])->assertSessionHasErrors('stores');
    expect(count(pkStores(2)))->toBe(2);
    // switching it off is allowed: it leaves the checkout, the reservation keeps its store
    put('/manage/storefronts/2/pickup/stores', ['stores' => [['is_active' => false] + $arkadia, $helio]])->assertSessionHasNoErrors();

    pkHours(2);
    [$arkadia, $helio] = pkStores(2);
    post('/manage/storefronts/2/pickup/switch', ['enabled' => 1])->assertSessionHasNoErrors();
    put('/manage/storefronts/2/pickup/stores', ['stores' => [['is_active' => false] + $arkadia, ['is_active' => false] + $helio]])->assertSessionHasErrors('stores');
    expect(PickupStores::forStorefront(2, activeOnly: true))->toHaveCount(1);
    // while it is on, an active store cannot lose its hours
    put('/manage/storefronts/2/pickup/stores', ['stores' => [$arkadia, array_merge($helio, ['hours_ar' => ''])]])->assertSessionHasErrors('stores.1.hours_ar');
});

it('keeps the page to those who manage storefronts, and a scoped admin to their own shop', function () {
    actingAs(Staff::dataEntry());
    get('/manage/storefronts/2/pickup')->assertForbidden();
    post('/manage/storefronts/2/pickup/switch', ['enabled' => 1])->assertForbidden();

    actingAs(Staff::adminFor(1));
    get('/manage/storefronts/1/pickup')->assertOk();
    // another shop's page is HIDDEN, not refused (EnsureStorefrontScope answers 404 on purpose)
    get('/manage/storefronts/2/pickup')->assertNotFound();
    put('/manage/storefronts/2/pickup/stores', ['stores' => []])->assertNotFound();
    expect(pkStores(2))->toHaveCount(2);
});
