<?php

use App\Domain\Access\UserWriteGuard;
use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerPayload;
use App\Domain\Customers\CustomerTokens;
use App\Support\LegacyJwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\json;
use function Pest\Laravel\withHeaders;

/*
 * `login`, `register`, `logout`, `auth/me` on core (storefront Phase 1, piece 3).
 *
 * What is under test is a CONTRACT, not a feature. The storefront is not changed in Phase 1, so
 * these endpoints have to answer what `Login.jsx`, `Register.jsx`, `AuthCallback.jsx` and
 * `authStore.js` already parse — including the parts nobody would design today: the user object
 * with `token` merged into it rather than `{user, token}`, 401 rather than 422 for bad credentials,
 * and the 429 sentence rendered verbatim.
 *
 * The hostile cases are here rather than in a separate file on purpose: an auth endpoint's
 * behaviour on the bad path IS its behaviour.
 */

const AUTH_API_KEY = 'customer-auth-test-key';

beforeEach(function () {
    config([
        'compat.api_key' => AUTH_API_KEY,
        'compat.jwt_secret' => 'customer-auth-test-secret',
        'compat.jwt_algo' => 'HS256',
        'compat.jwt_ttl' => 43200,
    ]);
});

/** @return array<string, string> */
function apiKey(): array
{
    return ['Api-Code' => AUTH_API_KEY];
}

/** @return array<string, string> */
function bearer(string $token): array
{
    return ['Api-Code' => AUTH_API_KEY, 'Authorization' => 'Bearer '.$token];
}

// ── register ─────────────────────────────────────────────────────────────────────────────────

it('registers a customer and answers the LEGACY payload, user object with the token merged in', function () {
    $email = Shopper::email();

    $response = withHeaders(apiKey())->postJson('/api/register', [
        'first_name' => 'Mona',
        'last_name' => 'Said',
        'email' => $email,
        'password' => 'a-good-password',
        'password_confirmation' => 'a-good-password',
        'phone_number' => '01012345678',
    ])->assertOk();

    /*
     * The key set, pinned. `authStore.persist()` reads `token`, `id`, `first_name`, `last_name`,
     * `email`, `phone_number` and `image` off THIS object; the account screen reads `has_password`.
     * Dropping a key nobody appears to read is how a screen breaks three weeks later.
     */
    /** @var array<string, mixed> $body */
    $body = $response->json();

    expect(array_keys($body))->toBe([
        'id', 'first_name', 'last_name', 'email', 'type', 'email_verified_at', 'phone_number',
        'image', 'last_login_at', 'last_reengagement_at', 'created_at', 'updated_at',
        'has_password', 'token',
    ]);

    expect($response->json('email'))->toBe($email)
        ->and($response->json('type'))->toBe(CustomerAccounts::CREATED_TYPE)
        ->and($response->json('has_password'))->toBeTrue()
        // The credential itself never appears, under any key.
        ->and(json_encode($body))->not->toContain('a-good-password');

    // …and the token it just handed out is one this application accepts.
    expect(LegacyJwt::subject(T::str($response->json('token'))))->toBe(T::int($response->json('id')));
});

it('lower-cases the e-mail, so two people cannot own the same address in different case', function () {
    $email = Shopper::email();

    withHeaders(apiKey())->postJson('/api/register', [
        'first_name' => 'Case', 'last_name' => 'Test',
        'email' => strtoupper($email),
        'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
    ])->assertOk();

    expect(DB::table('users')->where('email', $email)->exists())->toBeTrue();

    withHeaders(apiKey())->postJson('/api/register', [
        'first_name' => 'Second', 'last_name' => 'Person',
        'email' => $email,
        'password' => 'another-password', 'password_confirmation' => 'another-password',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('REFUSES a short password, an unconfirmed one, and a malformed telephone', function (array $payload, string $field) {
    withHeaders(apiKey())->postJson('/api/register', $payload + [
        'first_name' => 'Bad', 'last_name' => 'Input', 'email' => Shopper::email(),
    ])->assertStatus(422)->assertJsonValidationErrors($field);
})->with([
    'short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'unconfirmed' => [['password' => 'a-good-password', 'password_confirmation' => 'different-one'], 'password'],
    'telephone' => [['password' => 'a-good-password', 'password_confirmation' => 'a-good-password', 'phone_number' => '00123'], 'phone_number'],
]);

it('writes `type` as the least-privileged enum value and grants no dashboard role', function () {
    /*
     * The one thing a customer-registration endpoint must never do. `type` grants power in the
     * LEGACY application, and `core_user_roles` is what opens `/manage` here — a registration that
     * touched either would be a self-service admin account.
     */
    $user = Shopper::register();

    expect(T::str(DB::table('users')->where('id', $user->id)->value('type')))->toBe('User')
        ->and(DB::table('core_user_roles')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does NOT write last_login_at, remember_token or last_reengagement_at', function () {
    // The three framework/legacy columns AGENTS §3 keeps core out of. A fresh row leaves them null,
    // and the deviation is that the re-engagement column stops advancing after Phase 2.
    $user = Shopper::register();
    $row = T::one(DB::table('users')->where('id', $user->id));

    expect($row->last_login_at)->toBeNull()
        ->and($row->remember_token)->toBeNull()
        ->and($row->last_reengagement_at)->toBeNull();
});

// ── login ────────────────────────────────────────────────────────────────────────────────────

it('signs a customer in with the right password', function () {
    $email = Shopper::email();
    Shopper::register(['email' => $email, 'password' => 'the-right-password']);

    $response = withHeaders(apiKey())->postJson('/api/login', [
        'email' => $email, 'password' => 'the-right-password',
    ])->assertOk();

    expect($response->json('email'))->toBe($email)
        ->and(LegacyJwt::subject(T::str($response->json('token'))))->toBe(T::int($response->json('id')));
});

it('answers 401 with the legacy sentence for a wrong password AND for an unknown e-mail', function () {
    /*
     * Both branches, same body, same status — an endpoint that answers differently for "no such
     * account" than for "wrong password" tells an attacker which addresses are registered.
     */
    $email = Shopper::email();
    Shopper::register(['email' => $email, 'password' => 'the-right-password']);

    withHeaders(apiKey())->postJson('/api/login', ['email' => $email, 'password' => 'not-it'])
        ->assertStatus(401)->assertExactJson(['error' => 'Invalid email or password.']);

    withHeaders(apiKey())->postJson('/api/login', ['email' => Shopper::email(), 'password' => 'anything'])
        ->assertStatus(401)->assertExactJson(['error' => 'Invalid email or password.']);
});

it('RATE-LIMITS after five failures, keyed by e-mail and address, and clears on success', function () {
    $email = Shopper::email();
    Shopper::register(['email' => $email, 'password' => 'the-right-password']);
    RateLimiter::clear($email.'|127.0.0.1');

    for ($i = 0; $i < 5; $i++) {
        withHeaders(apiKey())->postJson('/api/login', ['email' => $email, 'password' => 'wrong'])
            ->assertStatus(401);
    }

    $blocked = withHeaders(apiKey())->postJson('/api/login', ['email' => $email, 'password' => 'the-right-password'])
        ->assertStatus(429);

    // The storefront renders this sentence verbatim for a 429, so its SHAPE is the contract.
    expect(T::str($blocked->json('error')))->toStartWith('Too many login attempts. Please try again in ');

    // A DIFFERENT account is unaffected: the key is per e-mail, not per address alone.
    $other = Shopper::email();
    Shopper::register(['email' => $other, 'password' => 'the-right-password']);
    withHeaders(apiKey())->postJson('/api/login', ['email' => $other, 'password' => 'the-right-password'])
        ->assertOk();

    RateLimiter::clear($email.'|127.0.0.1');
    withHeaders(apiKey())->postJson('/api/login', ['email' => $email, 'password' => 'the-right-password'])->assertOk();
});

it('costs about the same whether the account exists or not', function () {
    /*
     * The enumeration oracle this guards against: `Auth::attempt()` gave the legacy app a constant
     * cost for free, and resolving the user by hand takes it away — a missing account would return
     * in microseconds while a wrong password pays for a bcrypt verify. The decoy hash restores it.
     *
     * Asserted as an ORDER OF MAGNITUDE, not a tight bound: this runs on a developer workstation
     * alongside whatever else is on it, and a test that fails on a busy afternoon is a test somebody
     * deletes. What it catches is the decoy being removed, which takes the ratio to ~50x.
     */
    $email = Shopper::email();
    Shopper::register(['email' => $email, 'password' => 'the-right-password']);

    $time = function (string $address): float {
        $start = microtime(true);
        withHeaders(apiKey())->postJson('/api/login', ['email' => $address, 'password' => 'wrong-password'])
            ->assertStatus(401);
        RateLimiter::clear(strtolower($address).'|127.0.0.1');

        return microtime(true) - $start;
    };

    $known = $time($email);
    $unknown = $time(Shopper::email());

    expect(max($known, $unknown) / max(0.0001, min($known, $unknown)))->toBeLessThan(10.0);
});

it('merges a guest cart into the account on sign-in, off the X-Guest-Token header', function () {
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email, 'password' => 'the-right-password']);
    $guest = 'guest-'.bin2hex(random_bytes(6));

    // A guest cart with something in it.
    $product = T::row(DB::table('catalog_products as cp')
        ->join('storefront_product as sp', 'sp.product_id', '=', 'cp.id')
        ->where('sp.storefront_id', 1)->whereNull('cp.deleted_at')
        ->where('cp.stock_express', '>=', 1)->orderBy('cp.id')->first(['cp.id', 'sp.effective_price']));

    withHeaders(apiKey() + ['X-Guest-Token' => $guest])->postJson('/api/add_to_cart', [
        'product_id' => T::int($product->id), 'quantity' => 1,
        'piece_price' => T::str($product->effective_price), 'total_price' => T::str($product->effective_price),
        'type_stock' => 'Express',
    ])->assertOk();

    withHeaders(apiKey() + ['X-Guest-Token' => $guest])->postJson('/api/login', [
        'email' => $email, 'password' => 'the-right-password',
    ])->assertOk();

    $cartId = DB::table('carts')->where('user_id', $user->id)->value('id');
    expect($cartId)->not->toBeNull()
        ->and(DB::table('cart_items')->where('cart_id', $cartId)->count())->toBeGreaterThan(0);
});

it('signs the customer in even when the cart merge fails', function () {
    /*
     * A basket that did not move is recoverable; a refused sign-in is not. The merge is wrapped, and
     * this drives the failure by naming a guest token whose cart does not exist — the cheapest
     * version of "something went wrong in there".
     */
    $email = Shopper::email();
    Shopper::register(['email' => $email, 'password' => 'the-right-password']);

    withHeaders(apiKey() + ['X-Guest-Token' => 'no-such-cart-'.bin2hex(random_bytes(4))])
        ->postJson('/api/login', ['email' => $email, 'password' => 'the-right-password'])
        ->assertOk();
});

// ── logout ───────────────────────────────────────────────────────────────────────────────────

it('REVOKES the token on logout, so the session really ends', function () {
    [$user, $token] = Shopper::withToken();

    withHeaders(bearer($token))->getJson('/api/auth/me')->assertOk();

    withHeaders(bearer($token))->postJson('/api/logout', ['token' => $token])
        ->assertOk()->assertExactJson(['success' => true, 'message' => 'Logout successful']);

    withHeaders(bearer($token))->getJson('/api/auth/me')
        ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);

    expect(DB::table(CustomerTokens::TABLE)->where('user_id', $user->id)->count())->toBe(1);
});

it('reports success for a logout it cannot act on, rather than an error nobody can use', function () {
    withHeaders(apiKey())->postJson('/api/logout', ['token' => 'not-a-real-token'])
        ->assertOk()->assertExactJson(['success' => true, 'message' => 'Logout successful']);
});

// ── auth/me ──────────────────────────────────────────────────────────────────────────────────

it('answers auth/me from the TOKEN, never from anything the caller sent', function () {
    [$user, $token] = Shopper::withToken(['first_name' => 'Rania']);
    $other = Shopper::register();

    $response = withHeaders(bearer($token))->getJson('/api/auth/me?id='.$other->id)->assertOk();

    expect(T::int($response->json('id')))->toBe((int) $user->id)
        ->and($response->json('first_name'))->toBe('Rania');
});

it('requires the API key and a token on the authenticated routes', function (string $method, string $path) {
    /*
     * Two different gates, two different bodies, in the order a request meets them.
     *
     * No `Api-Code` is the COMPAT gate and answers the legacy `{"error":"Unauthorized"}` — also a
     * 401, byte-identical to what the legacy host sends, which is why it is asserted by body rather
     * than by status alone. Past that gate, no token is the AUTH gate and answers the framework's
     * own `{"message":"Unauthenticated."}`. Confusing the two is how a misconfigured API key looks
     * exactly like an expired session.
     */
    json($method, $path)->assertStatus(401)->assertExactJson(['error' => 'Unauthorized']);

    withHeaders(apiKey())->json($method, $path)
        ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
})->with([
    ['GET', '/api/auth/me'],
    ['POST', '/api/updateProfile'],
    ['POST', '/api/updatePassword'],
    ['DELETE', '/api/me/avatar'],
]);

// ── the payload, on its own ──────────────────────────────────────────────────────────────────

it('never serialises a credential column, whatever is in the row', function () {
    $user = Shopper::register(['password' => 'a-secret-nobody-should-see']);

    $payload = CustomerPayload::of($user->refresh());
    $json = (string) json_encode($payload);

    expect($payload)->not->toHaveKey('password')
        ->and($payload)->not->toHaveKey('remember_token')
        ->and($json)->not->toContain('a-secret-nobody-should-see')
        ->and($json)->not->toContain('$2y$');
});

it('reports has_password FALSE for a social-shaped account, which is what the screen switches on', function () {
    $user = Shopper::register();

    // A password-less account is what piece 5 creates; the payload has to describe one correctly
    // before then, because the account screen decides "Set password" vs "Change password" on it.
    // The social-only account shape. Core never writes a NULL password; the legacy app did.
    UserWriteGuard::fixture(fn () => DB::table('users')->where('id', $user->id)
        ->update(['password' => null]));

    expect(CustomerPayload::of($user->refresh())['has_password'])->toBeFalse();
});

it('stores a real bcrypt hash at the CONFIGURED cost, as the dashboard door does', function () {
    /*
     * The cost this run uses is 4 — `phpunit.xml` sets `BCRYPT_ROUNDS=4` so the suite is not mostly
     * bcrypt. Asserting 10 here would be asserting the test environment, not the product. That
     * PRODUCTION uses 10, to match every hash the legacy app wrote, is guarded where it belongs:
     * `AuthTest` reads the default out of `config/hashing.php` itself, because an env value
     * overrides config and both files said 12 until the 2026-09-11 review caught it.
     *
     * What is worth pinning HERE is that a customer row is formed the same way a dashboard row is:
     * a genuine `$2y$` bcrypt at whatever cost is configured, never a plaintext column and never
     * some other algorithm.
     */
    $user = Shopper::register(['password' => 'a-good-password']);
    $hash = T::str(DB::table('users')->where('id', $user->id)->value('password'));

    expect(Hash::check('a-good-password', $hash))->toBeTrue()
        ->and($hash)->toStartWith('$2y$')
        ->and(password_get_info($hash)['options']['cost'] ?? null)
        ->toBe(T::int(config('hashing.bcrypt.rounds')));
});
