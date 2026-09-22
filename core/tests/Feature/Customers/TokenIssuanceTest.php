<?php

use App\Compat\Diff\HarnessJwt;
use App\Domain\Customers\CustomerTokens;
use App\Http\Middleware\CompatGuestCart;
use App\Models\User;
use App\Support\LegacyJwt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * Core ISSUES the customer token now (storefront Phase 1, piece 2).
 *
 * The developer's requirement, and the reason this file leads with a round trip rather than with a
 * feature: *"Issued tokens must be accepted by everything that verifies them today, so Phase 2 is a
 * flip, not a second migration."* Two things verify a Watchizer token — `LegacyJwt` here, and
 * tymon/jwt-auth on the legacy host — so what is asserted below is SAMENESS, claim by claim,
 * against the shape tymon emits, and then the behaviour that shape has to carry: revocation, which
 * core had no way to record until now.
 */

const TOKEN_SECRET = 'token-issuance-test-secret';

beforeEach(function () {
    config([
        'compat.jwt_secret' => TOKEN_SECRET,
        'compat.jwt_algo' => 'HS256',
        'compat.jwt_leeway' => 0,
        'compat.api_key' => 'token-test-api-code',
    ]);
});

/**
 * Decode a token's payload WITHOUT verifying, so a claim can be inspected on its own terms.
 *
 * @return array<string, mixed>
 */
function payloadOf(string $token): array
{
    $segment = explode('.', $token)[1];
    $padded = strtr($segment, '-_', '+/').str_repeat('=', (4 - strlen($segment) % 4) % 4);
    /** @var mixed $decoded */
    $decoded = json_decode((string) base64_decode($padded, true), true);

    /** @var array<string, mixed> $payload */
    $payload = is_array($decoded) ? $decoded : [];

    return $payload;
}

function tokens(): CustomerTokens
{
    return app(CustomerTokens::class);
}

// ── the round trip, which is the whole requirement ───────────────────────────────────────────

it('issues a token this application ACCEPTS, which is the property Phase 2 depends on', function () {
    $user = Staff::customer();

    $token = tokens()->issue($user);

    expect(LegacyJwt::subject($token))->toBe((int) $user->id)
        ->and(LegacyJwt::claims($token))->not->toBeNull();
});

it('emits exactly the claim set tymon emits, with `sub` as an INT and `prv` as the model hash', function () {
    /*
     * Not "a reasonable claim set" — THE claim set. `prv` is the one worth pinning: the legacy
     * config sets `lock_subject => true`, so its guard compares `sha1(get_class($subject))` against
     * this claim, and both applications call the model `App\Models\User`. A wrong `prv` would be a
     * 401 on the legacy host and nowhere else, which is the hardest kind of difference to find.
     *
     * `sub` as an int rather than a string is the claim the harness had drifted on for two waves
     * without anything failing — recorded here so the drift cannot come back silently.
     */
    $user = Staff::customer();

    $claims = payloadOf(tokens()->issue($user));

    expect(array_keys($claims))->toEqualCanonicalizing(['iss', 'iat', 'nbf', 'exp', 'jti', 'sub', 'prv'])
        ->and($claims['sub'])->toBe((int) $user->id)
        ->and($claims['sub'])->toBeInt()
        ->and($claims['prv'])->toBe(sha1('App\Models\User'))
        ->and(strlen(T::str($claims['jti'])))->toBe(CustomerTokens::JTI_LENGTH);
});

it('expires exactly `compat.jwt_ttl` minutes out, the legacy default of thirty days', function () {
    config(['compat.jwt_ttl' => 43200]);
    $before = Carbon::now()->getTimestamp();

    $claims = payloadOf(tokens()->issue(Staff::customer()));

    // A second of slack for the clock between the two reads; the assertion is the THIRTY DAYS.
    expect(T::int($claims['exp']) - T::int($claims['iat']))->toBe(43200 * 60)
        ->and(T::int($claims['iat']))->toBeGreaterThanOrEqual($before)
        ->and($claims['nbf'])->toBe($claims['iat']);
});

it('signs with the SHARED secret, so a token signed with another one is refused', function () {
    $token = tokens()->issue(Staff::customer());

    config(['compat.jwt_secret' => 'a-completely-different-secret']);

    expect(LegacyJwt::subject($token))->toBeNull();
});

it('REFUSES to issue an unsigned token when no secret is configured', function () {
    /*
     * The asymmetry worth stating: `LegacyJwt` treats a missing secret as "every token is invalid",
     * which is safe for a verifier. For an ISSUER the safe direction is the opposite — a token
     * signed with an empty string is one anybody who guesses that can forge — so this throws rather
     * than handing out a credential nobody can trust.
     */
    config(['compat.jwt_secret' => null]);

    expect(fn () => tokens()->issue(Staff::customer()))
        ->toThrow(RuntimeException::class, 'cannot issue a customer token');
});

// ── revocation, which the legacy app had and core did not ────────────────────────────────────

it('REVOKES a token, so a signed-out customer stops being that customer', function () {
    $user = Staff::customer();
    $token = tokens()->issue($user);

    expect(LegacyJwt::subject($token))->toBe((int) $user->id);

    expect(tokens()->revoke($token))->toBeTrue();

    expect(LegacyJwt::subject($token))->toBeNull()
        ->and(LegacyJwt::claims($token))->not->toBeNull(
            'the token is still well-formed and correctly signed — it is REVOKED, which is a '
            .'different fact, and claims() is deliberately still pure'
        );
});

it('refuses a revoked token at the AUTHENTICATED compat routes, not just in the helper', function () {
    $user = Staff::customer();
    $token = tokens()->issue($user);
    $headers = ['Api-Code' => 'token-test-api-code', 'Authorization' => 'Bearer '.$token];

    withHeaders($headers)->getJson('/api/me/orders')->assertOk();

    tokens()->revoke($token);

    withHeaders($headers)->getJson('/api/me/orders')
        ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
});

it('refuses a revoked token on the GUEST-CART path too, which reads the subject directly', function () {
    /*
     * The caller a revocation check bolted onto the auth middleware would have missed.
     * `CompatGuestCart` calls `LegacyJwt::subject()` itself to decide whose cart this is, so a
     * signed-out customer would have been handed their own cart back — logged out everywhere except
     * the one place that holds what they were buying.
     *
     * Putting the check in `subject()` is what makes this pass without a second line of code, and
     * this test is what stops somebody "tidying" it up into the middleware later.
     */
    $user = Staff::customer();
    $token = tokens()->issue($user);
    $headers = ['Api-Code' => 'token-test-api-code', 'Authorization' => 'Bearer '.$token];

    /*
     * The discriminator is the middleware's own behaviour rather than the cart's contents: an
     * IDENTIFIED request is answered without an `X-Guest-Token` header, a guest one is answered
     * with the token echoed back so the browser can keep using it. That distinction holds whether
     * or not this customer has ever put anything in a cart — which the first version of this test
     * assumed and this database does not oblige.
     */
    $before = withHeaders($headers)->getJson('/api/me/cart');
    $before->assertOk()->assertHeaderMissing(CompatGuestCart::HEADER);

    tokens()->revoke($token);

    $after = withHeaders($headers)->getJson('/api/me/cart');
    $after->assertOk();
    expect($after->headers->get(CompatGuestCart::HEADER))->not->toBeNull(
        'a revoked token must fall through to a GUEST identity, not keep the customer signed in'
    );
});

it('is idempotent about a double sign-out, and keeps the FIRST revocation time', function () {
    $token = tokens()->issue(Staff::customer());
    $jti = payloadOf($token)['jti'];

    tokens()->revoke($token);
    $first = T::str(DB::table(CustomerTokens::TABLE)->where('jti', $jti)->value('revoked_at'));

    Carbon::setTestNow(Carbon::now()->addMinutes(5));
    tokens()->revoke($token);
    Carbon::setTestNow();

    expect(DB::table(CustomerTokens::TABLE)->where('jti', $jti)->count())->toBe(1)
        ->and(T::str(DB::table(CustomerTokens::TABLE)->where('jti', $jti)->value('revoked_at')))->toBe($first);
});

it('reports false and writes NOTHING for a token it cannot read', function () {
    $mark = DB::table(CustomerTokens::TABLE)->count();

    expect(tokens()->revoke(null))->toBeFalse()
        ->and(tokens()->revoke(''))->toBeFalse()
        ->and(tokens()->revoke('not-a-token'))->toBeFalse()
        ->and(tokens()->revoke('aaa.bbb.ccc'))->toBeFalse()
        // An ALREADY-EXPIRED token: there is nothing to revoke, and saying so is not an error.
        ->and(tokens()->revoke((string) CustomerTokens::mint(1, -120)))->toBeFalse();

    expect(DB::table(CustomerTokens::TABLE)->count())->toBe($mark);
});

it('revokes one token without touching the customer OTHER sessions', function () {
    // Signing out on a phone must not sign out the laptop — the behaviour being replaced, and the
    // reason "log out everywhere" is named as a separate feature rather than quietly assumed.
    $user = Staff::customer();
    $phone = tokens()->issue($user);
    $laptop = tokens()->issue($user);

    tokens()->revoke($phone);

    expect(LegacyJwt::subject($phone))->toBeNull()
        ->and(LegacyJwt::subject($laptop))->toBe((int) $user->id);
});

// ── pruning ──────────────────────────────────────────────────────────────────────────────────

it('prunes revocations whose tokens have expired, and keeps the ones still doing work', function () {
    $user = Staff::customer();
    $live = tokens()->issue($user);
    tokens()->revoke($live);

    // A revocation for a token that expired an hour ago, written the way `revoke()` writes one.
    $stale = 'stale'.bin2hex(random_bytes(4));
    DB::table(CustomerTokens::TABLE)->insert([
        'jti' => $stale,
        'user_id' => (int) $user->id,
        'expires_at' => Carbon::now()->subHour()->toDateTimeString(),
        'revoked_at' => Carbon::now()->subHours(2)->toDateTimeString(),
    ]);

    expect(CustomerTokens::prune())->toBeGreaterThanOrEqual(1);

    expect(DB::table(CustomerTokens::TABLE)->where('jti', $stale)->exists())->toBeFalse()
        // …and the live one still revokes, which is the half a careless pruner would break.
        ->and(LegacyJwt::subject($live))->toBeNull();
});

it('cannot sign anybody back in by pruning, because the clock refuses the token first', function () {
    /*
     * The property that makes `tokens:prune` safe to run unattended at 03:15. A pruned row always
     * describes a token that is already past `exp`, so `claims()` refuses it before revocation is
     * consulted at all — deleting the row changes nothing about what that token can do.
     */
    $expired = (string) CustomerTokens::mint(1, -120);

    DB::table(CustomerTokens::TABLE)->insert([
        'jti' => payloadOf($expired)['jti'],
        'user_id' => 1,
        'expires_at' => Carbon::now()->subHours(2)->toDateTimeString(),
        'revoked_at' => Carbon::now()->subHours(3)->toDateTimeString(),
    ]);

    CustomerTokens::prune();

    expect(LegacyJwt::subject($expired))->toBeNull();
});

// ── one minter, not two ──────────────────────────────────────────────────────────────────────

it('mints the harness token through the SAME constructor, so the two cannot drift', function () {
    /*
     * `HarnessJwt` built its own token until 2026-09-21 and had already drifted one claim (`sub` as
     * a string). Nothing failed, which is the point: a difference nothing catches does not stay
     * small. Both now go through `CustomerTokens::mint()`, and what the harness keeps is the part
     * that is genuinely its own — a short TTL and an `iss` naming the legacy host.
     */
    $user = Staff::customer();
    $harness = payloadOf((string) HarnessJwt::mint((int) $user->id));
    $issued = payloadOf(tokens()->issue($user));

    expect(array_keys($harness))->toEqualCanonicalizing(array_keys($issued))
        ->and($harness['sub'])->toBe($issued['sub'])
        ->and($harness['sub'])->toBeInt()
        ->and($harness['prv'])->toBe($issued['prv'])
        ->and($harness['iss'])->toContain('/api/login');
});

it('has exactly one construction site for a customer token', function () {
    // The grep that keeps the sentence above true. `mint()` is the door; nothing else may sign one.
    $offenders = [];
    foreach (['app', 'routes', 'database'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'CustomerTokens.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            /*
             * A MINTER both HMACs and base64url-ENCODES; a verifier HMACs and only ever decodes.
             * That pair is the discriminator, and it is why `LegacyJwt` — which computes the
             * expected signature in order to COMPARE it — is not an offender without having to be
             * named as an exception. A named-exception list is the thing that rots.
             */
            if (str_contains($source, 'hash_hmac(') && str_contains($source, 'base64_encode(')) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([], 'a second place signs customer tokens; CustomerTokens::mint() is the only one');
});

it('refuses a token whose account no longer exists, unchanged by any of the above', function () {
    /*
     * Pre-existing behaviour, re-asserted because issuance and revocation both moved underneath it.
     * `subject()` reads the claim and answers the id — the legacy middleware does the same, and
     * reproducing it was deliberate — while `userId()` additionally asks whether the row is still
     * there, which is what `auth:api` does and what the authenticated routes rely on.
     */
    $orphan = (string) CustomerTokens::mint(999999, 60);

    expect(LegacyJwt::subject($orphan))->toBe(999999);

    withHeaders(['Api-Code' => 'token-test-api-code', 'Authorization' => 'Bearer '.$orphan])
        ->getJson('/api/me/orders')->assertStatus(401);
})->skip(fn () => User::query()->whereKey(999999)->exists(), 'id 999999 exists in this database');
