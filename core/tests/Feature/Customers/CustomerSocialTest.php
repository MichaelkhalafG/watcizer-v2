<?php

use App\Domain\Access\UserWrites;
use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerSocial;
use App\Mail\CustomerEmailVerification;
use App\Models\User;
use App\Support\LegacyJwt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * "Sign in with Google" (storefront Phase 1, piece 5).
 *
 * The file is organised around the FIVE BRANCHES `CustomerSocial` documents, because the branch
 * table is the feature — the OAuth exchange itself is Socialite's and is not what can go wrong
 * here. What can go wrong is deciding which shop account a provider's answer means, and the
 * expensive mistake has one shape: attaching an unverified claim to an account somebody else owns.
 */

const SOCIAL_API_KEY = 'customer-social-test-key';

beforeEach(function () {
    config([
        'compat.api_key' => SOCIAL_API_KEY,
        'compat.jwt_secret' => 'customer-social-test-secret',
        'compat.jwt_algo' => 'HS256',
        'customers.storefront_url' => 'https://watchizereg.test',
        'services.google.client_id' => 'test-client-id',
        'services.google.client_secret' => 'test-client-secret',
        'services.google.redirect' => 'https://api.watchizereg.test/api/auth/google/callback',
    ]);
    Mail::fake();
});

/** A provider answer, shaped exactly as Socialite hands one over. */
function socialAccount(string $id, string $email, ?string $name = 'Mona Said', bool $verified = true): SocialiteUser
{
    $user = new SocialiteUser;
    $user->map(['id' => $id, 'email' => $email, 'name' => $name]);
    // `$user->user` is the RAW provider payload; `email_verified` is where Google puts it.
    $user->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified]);

    return $user;
}

/**
 * Narrow a SUCCESSFUL outcome, and fail loudly rather than quietly if it was a refusal.
 *
 * `resolve()` returns a union on purpose — a refusal is a value, not an exception — so a test that
 * reaches for `['user']` has to say which half it expected. Saying it here, once, keeps every call
 * site readable and turns "it refused when it should not have" into a sentence rather than an
 * undefined-index notice.
 *
 * @param  array{user: User, created: bool}|array{refused: string}  $outcome
 * @return array{user: User, created: bool}
 */
function signedIn(array $outcome): array
{
    if (array_key_exists('refused', $outcome)) {
        throw new RuntimeException('expected a sign-in, but the resolver refused with ['.$outcome['refused'].'].');
    }

    return $outcome;
}

function social(): CustomerSocial
{
    return app(CustomerSocial::class);
}

// ── branch 1: already linked ─────────────────────────────────────────────────────────────────

it('signs into the LINKED account, without looking at the e-mail at all', function () {
    /*
     * The identity is the `(provider, provider_id)` pair, not the address — so a customer who
     * changed the e-mail on their Google account still lands in the same shop account. Keying on
     * the address would disconnect them the day they change it.
     */
    $user = Shopper::register();
    DB::table(CustomerSocial::TABLE)->insert([
        'provider' => 'google', 'provider_id' => 'google-abc', 'user_id' => $user->id,
        'linked_email' => 'the-old-address@example.test',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);

    $outcome = signedIn(social()->resolve('google', socialAccount('google-abc', 'a-completely-new-address@example.test')));

    expect(T::int($outcome['user']->getKey()))->toBe((int) $user->id)
        ->and($outcome['created'])->toBeFalse();
});

// ── branch 2: verified e-mail matching an existing account ───────────────────────────────────

it('ATTACHES to an existing account when the provider address is VERIFIED', function () {
    /*
     * The seamless case, and also the LEGACY RE-LINK path: somebody who linked Google on the old
     * host has no row here, presses the same button, and is put back into their own account with
     * their orders — without being asked anything.
     */
    $email = Shopper::email();
    $existing = Shopper::register(['email' => $email, 'password' => 'their-real-password']);

    $outcome = signedIn(social()->resolve('google', socialAccount('google-xyz', $email, verified: true)));

    expect(T::int($outcome['user']->getKey()))->toBe((int) $existing->id)
        ->and($outcome['created'])->toBeFalse()
        ->and(DB::table(CustomerSocial::TABLE)
            ->where('provider', 'google')->where('provider_id', 'google-xyz')
            ->where('user_id', $existing->id)->exists())->toBeTrue();

    // The password is untouched: linking a provider does not take a credential away.
    expect(T::str(DB::table('users')->where('id', $existing->id)->value('password')))->not->toBe('');
});

it('marks an attached account VERIFIED, because the provider proved the same thing', function () {
    $email = Shopper::email();
    $existing = Shopper::register(['email' => $email]);
    expect(DB::table('users')->where('id', $existing->id)->value('email_verified_at'))->toBeNull();

    social()->resolve('google', socialAccount('google-xyz', $email, verified: true));

    expect(DB::table('users')->where('id', $existing->id)->value('email_verified_at'))->not->toBeNull();
});

// ── branch 3: THE UNSAFE ONE ─────────────────────────────────────────────────────────────────

it('REFUSES to attach an UNVERIFIED provider address to an account somebody already owns', function () {
    /*
     * The account-takeover shape this whole class exists to refuse. Anybody can create an account
     * at a provider claiming an address they do not own; what they cannot do is prove it. If an
     * unverified claim were enough, registering the victim's address at any lax provider would hand
     * over their orders, their addresses and their saved telephone.
     *
     * Nothing is created either: the address is taken, so creating is impossible, and attaching is
     * the attack.
     */
    $email = Shopper::email();
    $victim = Shopper::register(['email' => $email, 'password' => 'the-victim-password']);
    $before = T::one(DB::table('users')->where('id', $victim->id));

    $outcome = social()->resolve('google', socialAccount('attacker-1', $email, verified: false));

    expect($outcome)->toBe(['refused' => CustomerSocial::REFUSED_UNVERIFIED])
        ->and(DB::table(CustomerSocial::TABLE)->where('provider_id', 'attacker-1')->exists())->toBeFalse()
        ->and(DB::table('users')->where('email', $email)->count())->toBe(1);

    // The victim's row is byte-identical: not verified by the attempt, not re-pointed, not touched.
    $after = T::one(DB::table('users')->where('id', $victim->id));
    expect($after->email_verified_at)->toBe($before->email_verified_at)
        ->and(T::str($after->password))->toBe(T::str($before->password))
        ->and(T::str($after->first_name))->toBe(T::str($before->first_name));
});

it('treats a provider that reports NOTHING about verification as unverified', function () {
    // The safe direction, and the one that matters: assuming `true` for silence is exactly how the
    // branch above would be bypassed by a provider whose payload simply omits the flag.
    $email = Shopper::email();
    Shopper::register(['email' => $email]);

    $account = new SocialiteUser;
    $account->map(['id' => 'silent-1', 'email' => $email, 'name' => 'No Flag']);
    $account->setRaw(['sub' => 'silent-1', 'email' => $email]);   // no `email_verified` key at all

    expect(social()->resolve('google', $account))->toBe(['refused' => CustomerSocial::REFUSED_UNVERIFIED]);
});

// ── branches 4 and 5: nobody owns the address ────────────────────────────────────────────────

it('CREATES a password-less, pre-verified account when the address is free and verified', function () {
    $email = Shopper::email();

    $outcome = signedIn(social()->resolve('google', socialAccount('google-new', $email, 'Rania El Sayed', verified: true)));

    $row = T::one(DB::table('users')->where('email', $email));

    expect($outcome['created'])->toBeTrue()
        ->and($row->password)->toBeNull()
        ->and($row->email_verified_at)->not->toBeNull()
        ->and(T::str($row->type))->toBe(CustomerAccounts::CREATED_TYPE)
        ->and(T::str($row->first_name))->toBe('Rania')
        ->and(T::str($row->last_name))->toBe('El Sayed')
        // Password-less is what the account screen switches on to offer "Set your password".
        ->and(DB::table('core_user_roles')->where('user_id', $row->id)->exists())->toBeFalse();

    Mail::assertNotSent(CustomerEmailVerification::class);
});

it('CREATES but does NOT verify, and asks its own question, when the provider did not', function () {
    /*
     * Refusing here would lock a customer out of a shop for a property of their PROVIDER they can
     * neither see nor fix, and nothing in this application gates on verification. What core will
     * not do is take an unverified word for it and stamp the column.
     */
    $email = Shopper::email();

    $outcome = signedIn(social()->resolve('google', socialAccount('google-new', $email, verified: false)));

    expect($outcome['created'])->toBeTrue()
        ->and(DB::table('users')->where('email', $email)->value('email_verified_at'))->toBeNull();

    Mail::assertSent(CustomerEmailVerification::class, fn (CustomerEmailVerification $m): bool => $m->hasTo($email));
});

it('REFUSES a provider that returns no address, because nothing can be matched or created', function () {
    $account = new SocialiteUser;
    $account->map(['id' => 'no-email-1', 'email' => null, 'name' => 'Anonymous']);
    $account->setRaw(['sub' => 'no-email-1']);

    expect(social()->resolve('google', $account))->toBe(['refused' => CustomerSocial::REFUSED_NO_EMAIL]);
});

it('falls back to the address local part when the provider sends no name', function () {
    // `users.first_name` and `last_name` are both NOT NULL, so two strings have to come from
    // somewhere. Ported from the legacy `splitName()`, which had the same job.
    expect(CustomerSocial::splitName(null, 'mona.said@example.test'))->toBe(['mona.said', ''])
        ->and(CustomerSocial::splitName('   ', 'nobody@example.test'))->toBe(['nobody', ''])
        ->and(CustomerSocial::splitName('Mona', 'x@example.test'))->toBe(['Mona', ''])
        ->and(CustomerSocial::splitName('Mona El Sayed Ahmed', 'x@example.test'))->toBe(['Mona', 'El Sayed Ahmed']);
});

// ── idempotence and isolation ────────────────────────────────────────────────────────────────

it('is idempotent: signing in twice is one identity row, not a duplicate-key 500', function () {
    $email = Shopper::email();

    social()->resolve('google', socialAccount('google-twice', $email));
    social()->resolve('google', socialAccount('google-twice', $email));

    expect(DB::table(CustomerSocial::TABLE)->where('provider_id', 'google-twice')->count())->toBe(1)
        ->and(DB::table('users')->where('email', $email)->count())->toBe(1);
});

it('keeps two providers on ONE account, which is why user_id is not unique', function () {
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email]);

    social()->resolve('google', socialAccount('g-1', $email));
    social()->resolve('microsoft', socialAccount('m-1', $email));

    expect(DB::table(CustomerSocial::TABLE)->where('user_id', $user->id)->count())->toBe(2);
});

it('writes the account through the DOOR, with its own reason', function () {
    // Not `customer.register`: this row is shaped differently — `password` is NULL and
    // `email_verified_at` may be stamped at birth — and an audit that could not tell them apart
    // could not answer "how did this password-less account come to exist".
    expect(UserWrites::REASONS)->toHaveKey(CustomerSocial::REGISTER);
});

// ── the endpoints ────────────────────────────────────────────────────────────────────────────

it('answers the provider URL as JSON, and does not redirect', function () {
    // `SocialButtons.jsx` reads `data.url` and navigates itself; a 302 here would be followed by
    // axios and the customer would never leave the page.
    $response = withHeaders(['Api-Code' => SOCIAL_API_KEY])->getJson('/api/auth/google/redirect')->assertOk();

    expect(T::str($response->json('url')))->toContain('accounts.google.com')
        ->and(T::str($response->json('url')))->toContain('test-client-id');
});

it('REFUSES an unsupported provider at the door', function () {
    withHeaders(['Api-Code' => SOCIAL_API_KEY])->getJson('/api/auth/facebook/redirect')
        ->assertStatus(422)->assertExactJson(['error' => 'Unsupported provider.']);
});

it('REFUSES to start a flow for a provider that is not configured', function () {
    /*
     * Checked before the customer leaves. Without it they authenticate at Google, come back, and
     * only then discover the shop cannot finish — having handed a third party their consent for
     * nothing.
     */
    config(['services.google.client_secret' => null]);

    withHeaders(['Api-Code' => SOCIAL_API_KEY])->getJson('/api/auth/google/redirect')
        ->assertStatus(500)->assertExactJson(['error' => 'Could not start social login.']);
});

it('sends the CALLBACK back to the storefront with an error code, never as JSON', function () {
    // The browser arrives here from Google, so whatever is returned is rendered as a page. An
    // unsupported provider is the branch that needs no OAuth exchange to reach.
    withHeaders([])->get('/api/auth/facebook/callback')
        ->assertRedirect('https://watchizereg.test/auth/callback?error=unsupported_provider');
});

it('carries NO API key requirement on the callback, because a redirect cannot send one', function () {
    // Reached without `Api-Code` and still answered — the provider's signed exchange is what
    // authenticates it. A 401 here would break every social login silently.
    withHeaders([])->get('/api/auth/google/callback')->assertRedirect();
});

it('signs the customer in through the callback, handing the token to the storefront URL', function () {
    /*
     * Socialite is swapped for a fake at the driver boundary, so what is exercised is everything
     * core owns — the branch table, the identity row, the token — and nothing of the OAuth exchange
     * itself, which is Socialite's and is not what can go wrong here.
     */
    $email = Shopper::email();
    $existing = Shopper::register(['email' => $email]);

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(socialAccount('google-cb', $email, verified: true));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = withHeaders([])->get('/api/auth/google/callback');

    $location = T::str($response->headers->get('Location'));
    expect($location)->toStartWith('https://watchizereg.test/auth/callback?token=');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    expect(LegacyJwt::subject(T::str($query['token'] ?? null)))->toBe((int) $existing->id);
});

it('sends the REFUSAL code back through the same door, with no token', function () {
    $email = Shopper::email();
    Shopper::register(['email' => $email]);

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andReturn(socialAccount('attacker-2', $email, verified: false));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    withHeaders([])->get('/api/auth/google/callback')
        ->assertRedirect('https://watchizereg.test/auth/callback?error='.CustomerSocial::REFUSED_UNVERIFIED);
});

it('never puts a provider exception message into the redirect', function () {
    /*
     * A driver exception can carry the client secret. The customer gets a stable CODE; the reason
     * goes to the log, by reference, exactly as `CustomerMail` does with a reset link.
     */
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('stateless')->andReturnSelf();
    $provider->shouldReceive('user')->andThrow(new RuntimeException('client_secret=test-client-secret rejected'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $response = withHeaders([])->get('/api/auth/google/callback');
    $location = T::str($response->headers->get('Location'));

    expect($location)->toBe('https://watchizereg.test/auth/callback?error=social_failed')
        ->and($location)->not->toContain('client_secret')
        ->and($location)->not->toContain('test-client-secret');
});

// ── the proxy list, which piece 5 empties ────────────────────────────────────────────────────

it('leaves NO auth path on the reverse proxy, which is what Phase 2 depends on', function () {
    /*
     * The closing property of Phase 1. An auth path that could still reach the legacy host would
     * create the account over THERE, and the customer would come back holding a token whose `sub`
     * does not exist in this database — 401 on every call, with nothing in any log to explain it.
     */
    $offenders = array_values(array_filter(
        config()->array('compat.proxy_paths'),
        fn (mixed $path): bool => is_string($path)
            && (str_starts_with($path, 'auth/') || in_array($path, ['login', 'register', 'logout'], true)),
    ));

    expect($offenders)->toBe([]);
});

it('still proxies what piece 5 did NOT move, so the list is not merely empty', function () {
    // The sensitivity check: an assertion that the auth paths are gone proves nothing if the whole
    // list is gone. Wishlist and the legacy content paths are still the legacy host's.
    $paths = config()->array('compat.proxy_paths');

    expect($paths)->toContain('all_wishlist')
        ->and($paths)->toContain('all_blog');
});
