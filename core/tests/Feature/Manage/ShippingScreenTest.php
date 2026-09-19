<?php

use App\Domain\Access\Role;
use App\Domain\Activity\ActivityLog;
use App\Domain\Shipping\ShippingCities;
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
 * Shipping prices — the screen that unblocks the handover (wave 4D).
 *
 * ── What this has to prove ──────────────────────────────────────────────────────────────────
 *
 * 1. The team can change a delivery price at all. That is the whole reason the screen exists: the
 *    Blade `shipping_city` screen was the only editor anywhere, and retiring it without this leaves
 *    the next courier price rise to hand-typed SQL.
 * 2. Only an administrator can. A shipping price is money — server-side, at the route, not by a
 *    hidden button.
 * 3. A governorate customers have addresses in is never deleted. `addresses.shipping_city_id`
 *    carries no database constraint, so the delete would SUCCEED and silently strip the city from
 *    every saved address in it.
 * 4. The storefront keeps reading what it read before. The compat payload is a contract; a screen
 *    that writes a field into it is a screen that can break it.
 */

/** A governorate nobody has an address in, so the delete cases have a legitimate subject. */
function unusedCity(): int
{
    $cities = app(ShippingCities::class);
    $id = $cities->create(['name_ar' => 'محافظة اختبار', 'name_en' => 'Test Governorate', 'shipping_cost' => '55.00']);

    return $id;
}

it('lets an ADMINISTRATOR change a delivery price', function () {
    actingAs(Staff::admin());

    $id = T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));
    $before = T::str(DB::table('shipping_cities')->where('id', $id)->value('shipping_cost'));

    put("/manage/shipping/{$id}", [
        'name_ar' => 'القاهرة',
        'name_en' => 'Cairo',
        'shipping_cost' => '99.50',
        '_complete' => 1,
    ])->assertRedirect();

    $after = T::str(DB::table('shipping_cities')->where('id', $id)->value('shipping_cost'));

    expect($after)->toBe('99.50')
        ->and($after)->not->toBe($before);
});

it('REFUSES a price change from data-entry, called directly', function () {
    /*
     * The half of the decision that a hidden button would not deliver. Data-entry keep the SCREEN —
     * they quote delivery prices on the telephone — and may not change one.
     */
    actingAs(Staff::dataEntry());

    $id = T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));
    $before = T::str(DB::table('shipping_cities')->where('id', $id)->value('shipping_cost'));

    put("/manage/shipping/{$id}", [
        'name_ar' => 'القاهرة',
        'name_en' => 'Cairo',
        'shipping_cost' => '1.00',
        '_complete' => 1,
    ])->assertForbidden();

    post('/manage/shipping', ['name_ar' => 'x', 'name_en' => 'x', 'shipping_cost' => '1'])->assertForbidden();
    delete("/manage/shipping/{$id}")->assertForbidden();

    expect(T::str(DB::table('shipping_cities')->where('id', $id)->value('shipping_cost')))->toBe($before);
});

it('still SHOWS data-entry the whole list, with every price on it', function () {
    actingAs(Staff::dataEntry());

    $cities = T::arr(Props::of(get('/manage/shipping'))['cities'] ?? null);

    expect($cities)->not->toBe([]);
    $first = T::arr($cities[0]);
    expect($first)->toHaveKey('shipping_cost')
        ->and($first)->toHaveKey('name_ar');

    // …and no buttons.
    expect(T::arr(Props::of(get('/manage/shipping'))['abilities'] ?? null)['manage'] ?? null)->toBeFalse();
});

it('creates a governorate with both names and a price', function () {
    actingAs(Staff::admin());

    $before = T::int(DB::table('shipping_cities')->count());

    post('/manage/shipping', [
        'name_ar' => 'الأقصر',
        'name_en' => 'Luxor',
        'shipping_cost' => '85.00',
    ])->assertRedirect();

    expect(T::int(DB::table('shipping_cities')->count()))->toBe($before + 1);

    $id = T::int(DB::table('shipping_cities')->orderByDesc('id')->value('id'));
    $names = DB::table('shipping_city_translations')->where('shipping_city_id', $id)
        ->pluck('city_name', 'locale');

    expect($names['ar'] ?? null)->toBe('الأقصر')
        ->and($names['en'] ?? null)->toBe('Luxor');
});

it('REFUSES to delete a governorate customers have addresses in', function () {
    /*
     * The case that protects real customer data. There is no foreign key here, so nothing below
     * this refusal would stop the delete.
     */
    actingAs(Staff::admin());

    $id = T::int(DB::table('addresses')->whereNotNull('shipping_city_id')->value('shipping_city_id'));
    $addresses = T::int(DB::table('addresses')->where('shipping_city_id', $id)->count());
    expect($addresses)->toBeGreaterThan(0);

    delete("/manage/shipping/{$id}")->assertRedirect();

    expect(T::int(DB::table('shipping_cities')->where('id', $id)->count()))->toBe(1)
        ->and(T::int(DB::table('addresses')->where('shipping_city_id', $id)->count()))->toBe($addresses);
});

it('deletes a governorate nobody uses, translations included', function () {
    actingAs(Staff::admin());

    $id = unusedCity();

    delete("/manage/shipping/{$id}")->assertRedirect();

    expect(T::int(DB::table('shipping_cities')->where('id', $id)->count()))->toBe(0)
        ->and(T::int(DB::table('shipping_city_translations')->where('shipping_city_id', $id)->count()))->toBe(0);
});

it('allows a price of ZERO — free delivery is a real offer', function () {
    actingAs(Staff::admin());

    $id = unusedCity();

    put("/manage/shipping/{$id}", [
        'name_ar' => 'محافظة اختبار',
        'name_en' => 'Test Governorate',
        'shipping_cost' => '0',
        '_complete' => 1,
    ])->assertRedirect();

    expect(T::str(DB::table('shipping_cities')->where('id', $id)->value('shipping_cost')))->toBe('0.00');
});

it('refuses a NEGATIVE price, which is a credit nobody can honour', function () {
    actingAs(Staff::admin());

    $id = unusedCity();

    put("/manage/shipping/{$id}", [
        'name_ar' => 'محافظة اختبار',
        'name_en' => 'Test Governorate',
        'shipping_cost' => '-10',
        '_complete' => 1,
    ])->assertSessionHasErrors('shipping_cost');
});

it('keeps the STOREFRONT payload shape and id order intact', function () {
    /*
     * The compat contract. `CompatMeta::shippingCities()` emits `{id, name_en, name_ar,
     * shipping_cost}` and the harness compares it byte-for-byte against the legacy host, so a
     * screen that put a fifth field into that payload would break the contract somewhere no screen
     * test looks.
     *
     * ── Why this checks the SHAPE and not the row just edited ───────────────────────────────
     *
     * `CompatMeta` reads through the `legacy` CONNECTION, and this screen writes through the
     * default one. Inside a test both are in their own open transaction, so the legacy connection
     * cannot see a row the default connection has not committed — and `show_shipping_city` is
     * cached for ten minutes on top of that. Asserting "my new city appears" would therefore be
     * asserting something about transaction isolation, and it would fail for a reason that has
     * nothing to do with the contract. The contract is the SHAPE, and the shape is checkable.
     */
    actingAs(Staff::admin());

    $payload = get('/api/show_shipping_city', ['Api-Code' => config()->string('compat.api_key')])
        ->assertOk()->json();

    expect($payload)->toBeArray();
    expect(T::arr($payload))->not->toBe([]);

    $rows = T::arr($payload);
    $first = T::arr($rows[0] ?? null);

    /*
     * The endpoint's shape is the LEGACY resource, not the four-field block `catalog/meta` carries:
     * it also emits both timestamps, the current-locale `city_name`, and the translation rows with
     * their OWN ids. Worth writing down, because "the storefront only needs a name and a price" is
     * true of the data and false of the payload — and the payload is what the harness compares.
     */
    expect(array_keys($first))->toBe([
        'id', 'shipping_cost', 'created_at', 'updated_at', 'city_name', 'translations',
    ]);

    // And it stays ordered by id, which the storefront's dropdown relies on.
    $ids = [];
    foreach ($rows as $row) {
        $ids[] = T::arr($row)['id'] ?? null;
    }
    $sorted = $ids;
    sort($sorted);
    expect($ids)->toBe($sorted);
});

it('names MANAGE_SHIPPING as an admin ability that data-entry does not hold', function () {
    expect(Role::ABILITIES)->toContain(Role::MANAGE_SHIPPING)
        ->and(Role::Admin->abilities())->toContain(Role::MANAGE_SHIPPING)
        ->and(Role::DataEntry->abilities())->not->toContain(Role::MANAGE_SHIPPING);
});

it('records the governorate and the price it used to charge, with who changed them', function () {
    /*
     * The gap this closes (2026-10-05). The delivery price is money charged to every customer in
     * the governorate, on every order, until somebody reads the accounts — and this screen, the
     * only editor of it anywhere, recorded nothing at all. A wrong number had no author, no
     * previous value and no date.
     */
    $admin = Staff::admin();
    actingAs($admin);

    post('/manage/shipping', [
        'name_ar' => 'محافظة السجل',
        'name_en' => 'Log Governorate',
        'shipping_cost' => '45.00',
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('shipping_cities')->orderByDesc('id')->value('id'));

    put("/manage/shipping/{$id}", [
        'name_ar' => 'محافظة السجل',
        'name_en' => 'Log Governorate',
        'shipping_cost' => '85.00',
        '_complete' => 1,
    ])->assertRedirect();

    $actions = DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'shipping_cities')->where('subject_id', $id)
        ->orderBy('id')->pluck('action')->all();

    expect($actions)->toContain(ActivityLog::CREATED);
    expect($actions)->toContain(ActivityLog::UPDATED);

    $row = T::one(DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'shipping_cities')->where('subject_id', $id)
        ->where('action', ActivityLog::UPDATED)->orderByDesc('id'));

    // WHO, by the name the log captured at the time — not merely "somebody".
    expect(T::int($row->user_id))->toBe(T::int($admin->getAttribute('id')))
        ->and(T::str($row->user_name))->toBe(Staff::nameOf($admin))
        // …and WHICH governorate, in the word an operator uses for it.
        ->and(T::str($row->subject_label))->toBe('محافظة السجل');

    $changes = T::arr(json_decode(T::str($row->changes), true));
    $cost = T::arr($changes['shipping_cost'] ?? null);

    // Compared as money: the point of the row is that the old price survived the write.
    expect(T::float($cost['from'] ?? null))->toBe(45.0)
        ->and(T::float($cost['to'] ?? null))->toBe(85.0);
});
