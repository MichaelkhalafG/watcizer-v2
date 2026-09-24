<?php

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerTokens;
use App\Support\LegacyJwt;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * "Log out everywhere" — the per-customer token epoch (M1v, Phase 1 piece 4b, 2026-09-22).
 *
 * ── Why this is a SECOND mechanism beside revocation, and not a tidier version of it ─────────
 *
 * `core_revoked_tokens` answers "was THIS token signed out". It cannot answer "invalidate all of
 * them", because core has never seen most of them: a `jti` is recorded only when somebody signs
 * out, so the stolen phone, the laptop left at work and the session an attacker opened are all
 * absent from that table. The epoch needs to know nothing about them — it moves the line every
 * token is measured against.
 *
 * What is asserted below is the pair of properties that make it a security control rather than a
 * gesture: it kills tokens it has never seen, and it kills ONLY this customer's.
 */

const EPOCH_API_KEY = 'token-epoch-test-key';

beforeEach(function () {
    config([
        'compat.api_key' => EPOCH_API_KEY,
        'compat.jwt_secret' => 'token-epoch-test-secret',
        'compat.jwt_algo' => 'HS256',
        'compat.jwt_ttl' => 43200,
    ]);
});

/** @return array<string, string> */
function epochBearer(string $token): array
{
    return ['Api-Code' => EPOCH_API_KEY, 'Authorization' => 'Bearer '.$token];
}

/** Move a customer's epoch this many seconds into the past, and forget the memo of it. */
function ageEpoch(int $userId, int $seconds): void
{
    DB::table(CustomerTokens::EPOCH_TABLE)->where('user_id', $userId)
        ->update(['not_before' => Carbon::now()->subSeconds($seconds)->toDateTimeString()]);
    CustomerTokens::forgetEpochs();
}

// ── the property ─────────────────────────────────────────────────────────────────────────────

it('kills a token the epoch has NEVER SEEN, which is the whole point', function () {
    /*
     * The token here is never signed out, so it has no row in `core_revoked_tokens` and nothing
     * knows its `jti`. That is the shape of every token this feature actually has to kill.
     */
    [$user, $token] = Shopper::withToken();

    expect(LegacyJwt::subject($token))->toBe((int) $user->id)
        ->and(DB::table(CustomerTokens::TABLE)->where('user_id', $user->id)->exists())->toBeFalse();

    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    app(CustomerTokens::class)->invalidateAllFor($user, CustomerAccounts::PASSWORD);
    Carbon::setTestNow();

    expect(LegacyJwt::subject($token))->toBeNull()
        // …and still with no revocation row: the two mechanisms are independent.
        ->and(DB::table(CustomerTokens::TABLE)->where('user_id', $user->id)->exists())->toBeFalse();
});

it('kills EVERY session, not the one that asked', function () {
    $user = Shopper::register();
    $tokens = app(CustomerTokens::class);
    $phone = $tokens->issue($user);
    $laptop = $tokens->issue($user);
    $tablet = $tokens->issue($user);

    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    $tokens->invalidateAllFor($user, CustomerAccounts::RESET);
    Carbon::setTestNow();

    foreach (['phone' => $phone, 'laptop' => $laptop, 'tablet' => $tablet] as $label => $token) {
        expect(LegacyJwt::subject($token))->toBeNull($label.' survived the invalidation');
    }
});

it('touches NOBODY else, which is what stops this being a denial of service', function () {
    $victim = Shopper::register();
    $bystander = Shopper::register();
    $tokens = app(CustomerTokens::class);

    $victimToken = $tokens->issue($victim);
    $bystanderToken = $tokens->issue($bystander);

    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    $tokens->invalidateAllFor($victim, CustomerAccounts::RESET);
    Carbon::setTestNow();

    expect(LegacyJwt::subject($victimToken))->toBeNull()
        ->and(LegacyJwt::subject($bystanderToken))->toBe((int) $bystander->id);
});

it('lets a token issued AFTER the epoch through, or the customer could never sign in again', function () {
    $user = Shopper::register();
    $tokens = app(CustomerTokens::class);

    $tokens->invalidateAllFor($user, CustomerAccounts::RESET);

    /*
     * The epoch is aged BACKWARDS rather than the clock being moved forwards to mint the token.
     *
     * That is not a stylistic choice: a token minted under a moved clock carries an `nbf` in the
     * future, and once the clock is restored `LegacyJwt::claims()` refuses it for a reason that has
     * nothing to do with the epoch — which is how the first version of this test failed and looked
     * like a product bug. Moving the ROW keeps every token's `iat` and `nbf` in the real present.
     */
    ageEpoch((int) $user->id, 2);

    $fresh = $tokens->issue($user);

    expect(LegacyJwt::subject($fresh))->toBe((int) $user->id);
});

it('moves the line on a SECOND invalidation, unlike a revocation which keeps the first', function () {
    /*
     * The opposite of `revoke()`, deliberately, and the difference is the whole semantics: a
     * revocation records a fact about the past ("this token was signed out at 10:04"), while the
     * epoch is always "nothing older than NOW". A second reset must kill tokens issued between the
     * two, so the newest write wins.
     */
    $user = Shopper::register();
    $tokens = app(CustomerTokens::class);

    $tokens->invalidateAllFor($user, CustomerAccounts::PASSWORD);

    // Age the FIRST epoch into the past, so the token below is issued strictly after it.
    ageEpoch((int) $user->id, 2);
    $between = $tokens->issue($user);

    expect(LegacyJwt::subject($between))->toBe((int) $user->id);

    // The SECOND invalidation happens two seconds from now, so it is strictly after that token.
    // Safe to restore the clock afterwards: `$between`'s own `nbf` is already in the past.
    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    $tokens->invalidateAllFor($user, CustomerAccounts::RESET);
    Carbon::setTestNow();

    expect(LegacyJwt::subject($between))->toBeNull()
        ->and(T::str(DB::table(CustomerTokens::EPOCH_TABLE)->where('user_id', $user->id)->value('reason')))
        ->toBe(CustomerAccounts::RESET)
        // One row per customer, not one per invalidation.
        ->and(DB::table(CustomerTokens::EPOCH_TABLE)->where('user_id', $user->id)->count())->toBe(1);
});

// ── through the endpoints, which is where it has to actually work ────────────────────────────

it('signs the customer out of every device when they change their password while signed in', function () {
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email, 'password' => 'the-old-password']);
    $tokens = app(CustomerTokens::class);

    $current = $tokens->issue($user);
    $elsewhere = $tokens->issue($user);

    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    $response = withHeaders(epochBearer($current))->postJson('/api/updatePassword', [
        'current_password' => 'the-old-password',
        'new_password' => 'a-brand-new-password',
        'new_password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    /*
     * The clock stays forward through the assertions below, deliberately. The FRESH token in the
     * response was minted under it, so its `nbf` is two seconds from the real present — restoring
     * the clock first would have `claims()` refuse it as not-yet-valid, for a reason that has
     * nothing to do with the epoch.
     */
    // Both old tokens are dead — INCLUDING the one that made the request. That is the point: if
    // somebody else has the account, a change that leaves their session alive is worthless.
    withHeaders(epochBearer($current))->getJson('/api/auth/me')->assertStatus(401);
    withHeaders(epochBearer($elsewhere))->getJson('/api/auth/me')->assertStatus(401);

    /*
     * …and the response carries a FRESH one, additively. Today's storefront ignores it and the
     * customer signs in again; Phase 4 reads it and the session continues, with no backend change
     * needed then.
     */
    $fresh = T::str($response->json('token'));
    expect($response->json('message'))->toBe('Password updated successfully')
        ->and($fresh)->not->toBe($current);

    withHeaders(epochBearer($fresh))->getJson('/api/auth/me')->assertOk()
        ->assertJsonPath('id', (int) $user->id);

    Carbon::setTestNow();
});

it('records the epoch with the reason that caused it', function () {
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email, 'password' => 'the-old-password']);
    $token = app(CustomerTokens::class)->issue($user);

    withHeaders(epochBearer($token))->postJson('/api/updatePassword', [
        'current_password' => 'the-old-password',
        'new_password' => 'a-brand-new-password',
        'new_password_confirmation' => 'a-brand-new-password',
    ])->assertOk();

    expect(T::str(DB::table(CustomerTokens::EPOCH_TABLE)->where('user_id', $user->id)->value('reason')))
        ->toBe(CustomerAccounts::PASSWORD);
});

it('leaves the epoch alone for operations that are not about credentials', function () {
    // Editing a name or removing a photo must not sign anybody out. The epoch is for the two
    // operations that change who can get in, and listing the others here is what keeps it so.
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email]);
    $token = app(CustomerTokens::class)->issue($user);

    withHeaders(epochBearer($token))->postJson('/api/updateProfile', [
        'first_name' => 'Changed', 'last_name' => 'Name', 'phone_number' => '01000000000',
    ])->assertOk();
    withHeaders(epochBearer($token))->deleteJson('/api/me/avatar')->assertOk();

    expect(DB::table(CustomerTokens::EPOCH_TABLE)->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(LegacyJwt::subject($token))->toBe((int) $user->id);
});

// ── the memo, which is the part that could be wrong without failing ──────────────────────────

it('does not serve a STALE epoch from the memo after an invalidation', function () {
    /*
     * `epochFor()` caches per process because `LegacyJwt::subject()` runs twice on the cart path.
     * A cache that outlived a write would keep a just-invalidated token working — a permissive
     * failure, which is the kind that passes silently.
     *
     * `invalidateAllFor()` forgets its own entry for exactly this reason, and this drives the order
     * that would break it: read first (populating the memo with "no epoch"), then invalidate.
     */
    [$user, $token] = Shopper::withToken();

    expect(CustomerTokens::epochFor((int) $user->id))->toBeNull()
        ->and(LegacyJwt::subject($token))->toBe((int) $user->id);

    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    app(CustomerTokens::class)->invalidateAllFor($user, CustomerAccounts::RESET);
    Carbon::setTestNow();

    expect(CustomerTokens::epochFor((int) $user->id))->not->toBeNull()
        ->and(LegacyJwt::subject($token))->toBeNull();
});

it('reads the epoch table ONCE per process for a given customer', function () {
    // The reason the memo exists at all: `subject()` runs twice per cart request, so without it
    // this is four index lookups per request on the hottest authenticated GET in the application.
    [$user, $token] = Shopper::withToken();
    CustomerTokens::forgetEpochs();

    $reads = 0;
    DB::listen(function (QueryExecuted $query) use (&$reads): void {
        if (str_contains($query->sql, CustomerTokens::EPOCH_TABLE)) {
            $reads++;
        }
    });

    LegacyJwt::subject($token);
    LegacyJwt::subject($token);
    LegacyJwt::subject($token);

    expect($reads)->toBe(1);
});

// ── pruning ──────────────────────────────────────────────────────────────────────────────────

it('does NOT prune an epoch that can still refuse a token', function () {
    /*
     * The dangerous direction. Deleting an epoch early re-validates precisely the tokens a reset
     * was performed to kill, and the symptom would be a thief signed back in with nothing to show
     * why. A fresh epoch must survive a prune.
     */
    [$user, $token] = Shopper::withToken();

    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    app(CustomerTokens::class)->invalidateAllFor($user, CustomerAccounts::RESET);
    Carbon::setTestNow();

    CustomerTokens::pruneEpochs();
    CustomerTokens::forgetEpochs();

    expect(DB::table(CustomerTokens::EPOCH_TABLE)->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(LegacyJwt::subject($token))->toBeNull();
});

it('prunes an epoch older than a full token lifetime, when it can no longer refuse anything', function () {
    $user = Shopper::register();
    app(CustomerTokens::class)->invalidateAllFor($user, CustomerAccounts::RESET);

    // Older than the TTL plus the day of slack: every token it could refuse has expired anyway.
    $ttl = T::int(config('compat.jwt_ttl'));
    DB::table(CustomerTokens::EPOCH_TABLE)->where('user_id', $user->id)
        ->update(['not_before' => Carbon::now()->subMinutes($ttl)->subDays(2)->toDateTimeString()]);

    expect(CustomerTokens::pruneEpochs())->toBeGreaterThanOrEqual(1)
        ->and(DB::table(CustomerTokens::EPOCH_TABLE)->where('user_id', $user->id)->exists())->toBeFalse();
});
