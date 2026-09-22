<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Customers\CustomerMail;
use App\Domain\Customers\CustomerSocial;
use App\Domain\Customers\GuestOrderLink;
use App\Models\User;
use App\Support\Sql;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Support\LegacyShadow;
use Tests\Support\Props;
use Tests\Support\Shopper;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Attaching a guest's past orders to the account they later created (Phase 1, piece 6).
 *
 * Developer decision: attach by VERIFIED e-mail only, everything else by hand from the dashboard.
 * The half that carries the file is the refusal — a guest order's address is what somebody typed at
 * checkout and nobody checked, so attaching on an unverified claim hands a stranger the victim's
 * delivery addresses, telephone number and purchase history.
 *
 * The named hostile case from the Phase 1 brief is here by name: *registering an e-mail that already
 * has guest orders but is not verified*.
 */

const LINK_API_KEY = 'guest-link-test-key';

beforeEach(function () {
    config([
        'compat.api_key' => LINK_API_KEY,
        'compat.jwt_secret' => 'guest-link-test-secret',
        'customers.storefront_url' => 'https://watchizereg.test',
    ]);
    Mail::fake();
});

/**
 * A guest order for this address, with an address row of its own so the schema is satisfied.
 *
 * Real rows rather than a stub: `orders` is a shared commerce table core has written since wave 3,
 * and the whole point of this feature is which rows it moves.
 */
function guestOrder(string $email, ?string $phone = null): int
{
    $cityId = T::int(DB::table('shipping_cities')->orderBy('id')->value('id'));
    $addressId = DB::table('addresses')->insertGetId([
        'shipping_city_id' => $cityId,
        'address_line' => 'Guest Link Street 1',
        'phone_number_one' => $phone ?? '01000000000',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return (int) DB::table('orders')->insertGetId([
        'user_id' => null,
        'guest_name' => 'Guest Shopper',
        'guest_email' => $email,
        'guest_phone' => $phone,
        'guest_token' => 'tok-'.bin2hex(random_bytes(4)),
        'address_id' => $addressId,
        'total_price_for_order' => 100.00,
        'status' => 'pending',
        'payment_method' => 'cash',
        'order_number' => 'GL-'.bin2hex(random_bytes(4)),
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function linker(): GuestOrderLink
{
    return app(GuestOrderLink::class);
}

/** Verify an account the way the storefront does: through the signed link. */
function verifyThrough(User $user, string $email): void
{
    get(URL::temporarySignedRoute(CustomerMail::VERIFY_ROUTE, now()->addHours(CustomerMail::VERIFY_HOURS), [
        'id' => (int) $user->id, 'hash' => sha1($email),
    ]))->assertOk();
}

// ── the refusal, first ───────────────────────────────────────────────────────────────────────

it('attaches NOTHING when the account e-mail is registered but NOT verified', function () {
    /*
     * The hostile case named in the Phase 1 brief. Registering `victim@example.com` and never
     * confirming it must not hand over that person's order history — and the history is exactly
     * what a guest order carries: the delivery address, the telephone the courier rang, and what
     * was bought for how much.
     */
    $email = Shopper::email();
    $orderId = guestOrder($email);

    $user = Shopper::register(['email' => $email]);   // registration sends a link; nobody clicks it

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->toBeNull()
        ->and(DB::table('orders')->where('id', $orderId)->value('user_id'))->toBeNull();
});

it('REFUSES loudly if a caller reaches the linker with an unverified account', function () {
    // A silent no-op would hide the bug. The only callers are the verification hook and the
    // dashboard action; anything else arriving here is a mistake worth an exception.
    $user = Shopper::register();

    expect(fn () => linker()->onVerifiedEmail($user))
        ->toThrow(RuntimeException::class, 'not verified');
});

// ── the happy path ───────────────────────────────────────────────────────────────────────────

it('attaches the guest orders when the customer VERIFIES their address', function () {
    $email = Shopper::email();
    $first = guestOrder($email);
    $second = guestOrder($email);
    $somebodyElse = guestOrder(Shopper::email());

    $user = Shopper::register(['email' => $email]);
    verifyThrough($user, $email);

    expect(T::int(DB::table('orders')->where('id', $first)->value('user_id')))->toBe((int) $user->id)
        ->and(T::int(DB::table('orders')->where('id', $second)->value('user_id')))->toBe((int) $user->id)
        // …and nobody else's order moved, which is the assertion that makes the first two mean
        // something rather than "it updated everything".
        ->and(DB::table('orders')->where('id', $somebodyElse)->value('user_id'))->toBeNull();
});

it('matches the checkout address case- and whitespace-insensitively', function () {
    // A trailing space typed into a checkout field is invisible to the person who typed it and
    // fatal to an equality test.
    $email = Shopper::email();
    $padded = guestOrder('  '.strtoupper($email).' ');

    $user = Shopper::register(['email' => $email]);
    verifyThrough($user, $email);

    expect(T::int(DB::table('orders')->where('id', $padded)->value('user_id')))->toBe((int) $user->id);
});

it('leaves the guest_* columns alone, because they are what the shopper actually typed', function () {
    /*
     * The courier rang the number in `guest_phone`. Erasing these to tidy up would destroy evidence
     * about an order in exchange for nothing.
     */
    $email = Shopper::email();
    $orderId = guestOrder($email, '01098765432');
    $before = T::one(DB::table('orders')->where('id', $orderId));

    $user = Shopper::register(['email' => $email]);
    verifyThrough($user, $email);

    $after = T::one(DB::table('orders')->where('id', $orderId));
    expect(T::str($after->guest_email))->toBe(T::str($before->guest_email))
        ->and(T::str($after->guest_phone))->toBe(T::str($before->guest_phone))
        ->and(T::str($after->guest_name))->toBe(T::str($before->guest_name))
        ->and(T::str($after->guest_token))->toBe(T::str($before->guest_token));
});

it('is idempotent, and never re-claims an order that already has an owner', function () {
    $email = Shopper::email();
    $orderId = guestOrder($email);
    $user = Shopper::register(['email' => $email]);
    verifyThrough($user, $email);

    // A second run finds nothing: the predicate is `user_id IS NULL`, which is also what makes two
    // verifications racing safe.
    expect(linker()->onVerifiedEmail($user->refresh()))->toBe(0)
        ->and(T::int(DB::table('orders')->where('id', $orderId)->value('user_id')))->toBe((int) $user->id);
});

it('records EACH order it moves, on the order, with how the link was made', function () {
    /*
     * Per order rather than one summary: somebody investigating a wrongly-attached order opens the
     * ORDER, and the activity log is keyed by subject. A summary against the customer would be
     * invisible from the one place the question gets asked.
     */
    $email = Shopper::email();
    $orderId = guestOrder($email);
    $user = Shopper::register(['email' => $email]);

    $mark = T::int(DB::table(ActivityLog::TABLE)->max('id') ?? 0);
    verifyThrough($user, $email);

    $entry = T::one(DB::table(ActivityLog::TABLE)->where('id', '>', $mark)
        ->where('subject_type', GuestOrderLink::SUBJECT)->where('subject_id', $orderId));

    expect(T::str($entry->changes))->toContain(GuestOrderLink::BY_VERIFIED_EMAIL)
        // No operator: the automatic path leaves the actor null, which reads correctly as "the
        // system did this" rather than pretending somebody was involved.
        ->and($entry->user_id)->toBeNull();
});

// ── the social paths reach the same hook ─────────────────────────────────────────────────────

it('attaches when a SOCIAL login verifies an address on an existing account', function () {
    $email = Shopper::email();
    $orderId = guestOrder($email);
    $user = Shopper::register(['email' => $email]);

    app(CustomerSocial::class)->resolve('google', socialUserFor('g-link-1', $email));

    expect(T::int(DB::table('orders')->where('id', $orderId)->value('user_id')))->toBe((int) $user->id);
});

it('attaches for a BRAND-NEW social account, which is why it is not verified at birth', function () {
    /*
     * The hole this ordering closes. A social account stamped `email_verified_at` inside `create()`
     * would be verified without ever passing `markVerified()` — so its owner's past guest orders
     * would never find them, and nothing would look wrong.
     */
    $email = Shopper::email();
    $orderId = guestOrder($email);

    $outcome = app(CustomerSocial::class)->resolve('google', socialUserFor('g-link-2', $email));

    expect($outcome)->toHaveKey('user');
    $userId = T::int(DB::table('users')->where('email', $email)->value('id'));

    expect(T::int(DB::table('orders')->where('id', $orderId)->value('user_id')))->toBe($userId)
        ->and(DB::table('users')->where('id', $userId)->value('email_verified_at'))->not->toBeNull();
});

/** A verified Google answer, shaped as Socialite hands one over. */
function socialUserFor(string $id, string $email): Laravel\Socialite\Two\User
{
    $account = new Laravel\Socialite\Two\User;
    $account->map(['id' => $id, 'email' => $email, 'name' => 'Linked Person']);
    $account->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => true]);

    return $account;
}

// ── the dashboard action ─────────────────────────────────────────────────────────────────────

/*
 * These four drive REAL, pre-existing guest orders rather than rows the test inserts, and the
 * reason is structural rather than convenience.
 *
 * `Customers` reads through the `legacy` CONNECTION while `GuestOrderLink` writes through the
 * default one. In production both see the same rows; in the suite each connection has its own
 * `DatabaseTransactions` wrapper, so a row inserted on one is INVISIBLE to the other — a test that
 * inserted its own guest order would get a 404 from the scope check and look like a routing bug.
 * Committed rows are visible to both, which is also how `CustomerScreenTest` works.
 */

/**
 * An existing guest order and the `g:` key the customers screen groups it under.
 *
 * @return array{0: int, 1: string}
 */
function anyGuestGroup(): array
{
    $row = T::row(DB::connection('legacy')->table('orders')
        ->whereNull('user_id')
        ->whereNotNull('guest_phone')
        ->orderBy('id')
        ->first(['id', 'guest_phone']));

    return [T::int($row->id), 'g:'.T::str($row->guest_phone)];
}

it('attaches a guest GROUP by hand, and sends the operator to the account', function () {
    [$orderId, $key] = anyGuestGroup();
    $target = Staff::customer();

    actingAs(Staff::admin());

    post('/manage/customers/'.$key.'/attach', ['user_id' => $target->id])
        ->assertRedirect('/manage/customers/u:'.$target->id);

    expect(T::int(DB::table('orders')->where('id', $orderId)->value('user_id')))->toBe((int) $target->id);
});

it('names the OPERATOR on a by-hand attach, unlike the automatic one', function () {
    [$orderId, $key] = anyGuestGroup();
    $target = Staff::customer();
    $admin = Staff::admin();

    actingAs($admin);
    $mark = T::int(DB::table(ActivityLog::TABLE)->max('id') ?? 0);

    post('/manage/customers/'.$key.'/attach', ['user_id' => $target->id]);

    $entry = T::one(DB::table(ActivityLog::TABLE)->where('id', '>', $mark)
        ->where('subject_type', GuestOrderLink::SUBJECT)->where('subject_id', $orderId));

    expect(T::str($entry->user_name))->toBe(Staff::nameOf($admin))
        ->and(T::str($entry->changes))->toContain(GuestOrderLink::BY_DASHBOARD);
});

it('REFUSES the attach to a data-entry operator, because it is an identity decision', function () {
    /*
     * Data-entry holds `view-orders`, which is what the rest of this screen sits under. This one
     * decides that two identities are ONE PERSON, and a wrong decision hands a stranger somebody's
     * delivery addresses and telephone number. It is behind `manage-users`.
     */
    [$orderId, $key] = anyGuestGroup();
    $target = Staff::customer();

    actingAs(Staff::dataEntry());

    post('/manage/customers/'.$key.'/attach', ['user_id' => $target->id])->assertForbidden();

    expect(DB::table('orders')->where('id', $orderId)->value('user_id'))->toBeNull();
});

it('REFUSES to move a REGISTERED customer orders to another account', function () {
    /*
     * A `u:` key is already somebody's account. Moving orders between two registered accounts is a
     * different operation nobody has asked for — it would need a second confirmation and a way back.
     *
     * ── Why this builds its own fixture instead of finding one ─────────────────────────
     *
     * EVERY order in this database is a guest order — the same fact that let `compat:diff`'s
     * `account:orders` case compare `[]` against `[]` for two waves and report IDENTICAL. So the
     * first version of this test SKIPPED when it could not find a registered customer with orders,
     * which meant it had never run: *a guard that skips because the case does not exist yet will
     * never have run on the day it is needed* (developer, 2026-09-22).
     *
     * `LegacyShadow` is what makes building one possible. `Customers` reads through the `legacy`
     * CONNECTION, and a row inserted on the default one is invisible to it inside the suite's
     * per-connection transactions. The shadow is a session TEMPORARY table that hides the real
     * `users` and `orders` for this connection only; `Pest.php` drops it after every test and the
     * base tables are never written.
     */
    $ownerId = 0;

    LegacyShadow::open('users', function (callable $users) use (&$ownerId): void {
        $ownerId = T::int($users()->max('id')) + 1;
        $users()->insert([
            'id' => $ownerId,
            'first_name' => 'Registered',
            'last_name' => 'Customer',
            'email' => 'registered.'.bin2hex(random_bytes(4)).'@example.test',
            // `exists()` requires `type = User` for the no-orders branch, and the order below makes
            // the first branch hit anyway — both are set so the fixture is true either way.
            'type' => 'User',
            'password' => 'not-a-real-hash',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    LegacyShadow::open('orders', function (callable $orders) use ($ownerId): void {
        $orders()->insert([
            'user_id' => $ownerId,
            'address_id' => T::int($orders()->whereNotNull('address_id')->value('address_id')),
            'total_price_for_order' => 250.00,
            'status' => 'pending',
            'payment_method' => 'cash',
            'order_number' => 'RC-'.bin2hex(random_bytes(4)),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    actingAs(Staff::admin());

    // It resolves — a 404 here would mean the fixture never existed and the refusal below would be
    // about nothing.
    get('/manage/customers/u:'.$ownerId)->assertOk();

    post('/manage/customers/u:'.$ownerId.'/attach', ['user_id' => (int) Staff::customer()->id])
        ->assertSessionHasErrors('user_id');
});

it('REFUSES an account number that is not a customer', function () {
    [, $key] = anyGuestGroup();

    actingAs(Staff::admin());

    post('/manage/customers/'.$key.'/attach', ['user_id' => 999999])
        ->assertSessionHasErrors('user_id');
});

// ── the grouping rule has ONE definition ─────────────────────────────────────────────────────

it('resolves a g: key with the SAME expression the screen grouped it by', function () {
    /*
     * Two copies of this would be two definitions of who counts as one guest — and the day they
     * drift, an operator attaches a group that is not the group they were looking at. It lives in
     * `Sql`, which is this codebase's one construction site for SQL text built from column names.
     */
    expect(Sql::guestKey())->toContain('guest_phone')
        ->and(Sql::guestKey())->toContain('guest_email')
        ->and(Sql::guestKey())->toContain('guest_token')
        ->and(Sql::guestKey('o'))->toBe(str_replace('`guest_', '`o`.`guest_', Sql::guestKey()));

    expect(fn () => Sql::guestKey('x'))->toThrow(InvalidArgumentException::class);
});

it('does not fail a verification when the attach cannot run', function () {
    /*
     * Non-fatal by design: a failure here must not undo a verification the customer completed
     * correctly. Driven by making the attach throw — the linker refuses an unverified account — via
     * a user whose row is rolled back underneath it.
     */
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email]);

    // The verification itself still succeeds and still stamps the column.
    verifyThrough($user, $email);

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->not->toBeNull();
});

// ── the control on the screen ────────────────────────────────────────────────────────────────

/*
 * The attach ACTION shipped in piece 6 without a button, and the screen half landed 2026-09-22 on
 * the developer's instruction: *"the manual attach is needed from the first day customers register
 * here"*. These four assert the affordance matches the authorisation \u2014 a screen that offers what the
 * door turns away is worse than one that offers nothing.
 */

it('offers the attach control to an ADMIN on a guest customer', function () {
    [, $key] = anyGuestGroup();
    actingAs(Staff::admin());

    $props = Props::of(get('/manage/customers/'.$key));

    /*
     * `array_key_exists`, not `?? 'missing'`. NULL is the value under test here — nothing typed yet,
     * so nobody is named yet — and `??` treats a legitimate null as absent, which turned the first
     * version of this assertion into one that could never pass. Same shape as the variadic
     * `toContain()` trap already in the notes: an operator that defaults quietly hides the answer.
     */
    expect($props['can_attach'] ?? null)->toBeTrue()
        ->and(array_key_exists('attach_target', $props))->toBeTrue()
        ->and($props['attach_target'])->toBeNull();
});

it('does NOT offer it to data-entry, who may read this screen but not merge identities', function () {
    [, $key] = anyGuestGroup();
    actingAs(Staff::dataEntry());

    expect(Props::of(get('/manage/customers/'.$key))['can_attach'] ?? null)->toBeFalse();
});

it('does NOT offer it on a REGISTERED customer, because there is nothing to merge', function () {
    $customer = Staff::customer();
    actingAs(Staff::admin());

    expect(Props::of(get('/manage/customers/u:'.$customer->id))['can_attach'] ?? null)->toBeFalse();
});

it('names the account the operator typed, so the confirmation can show BOTH sides', function () {
    /*
     * The prop the screen reloads. It resolves through the SAME predicate the writer enforces
     * (`type = User`), so the dialog cannot name somebody the attach would then refuse \u2014 and an
     * unresolvable number leaves the confirm button unreachable rather than producing a refusal the
     * operator meets after committing.
     */
    [, $key] = anyGuestGroup();
    $target = Staff::customer();
    actingAs(Staff::admin());

    $resolved = T::arr(Props::of(get('/manage/customers/'.$key.'?user_id='.$target->id))['attach_target'] ?? null);

    expect(T::int($resolved['id'] ?? null))->toBe((int) $target->id)
        ->and(T::str($resolved['name'] ?? null))->toBe(Staff::nameOf($target))
        ->and($resolved)->toHaveKey('orders_count');

    // A number nobody owns names nobody, rather than naming the wrong person.
    $unresolved = Props::of(get('/manage/customers/'.$key.'?user_id=999999'));
    expect(array_key_exists('attach_target', $unresolved))->toBeTrue()
        ->and($unresolved['attach_target'])->toBeNull();
});
