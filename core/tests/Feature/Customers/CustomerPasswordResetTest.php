<?php

use App\Domain\Access\UserWrites;
use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerTokens;
use App\Mail\CustomerPasswordReset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;

/*
 * `auth/forgot-password` and `auth/reset-password` (storefront Phase 1, piece 4).
 *
 * Two things carry this file, and neither is "does a reset work".
 *
 * The first is that the broker writes CORE's table. The legacy application is still running and
 * still resetting passwords, and `DatabaseTokenRepository::create()` deletes every existing row for
 * an address before inserting — so two brokers on one table would silently invalidate each other's
 * links, and the symptom would be "the reset e-mail never works" with nothing wrong in either app.
 *
 * The second is the HOSTILE set the developer named: a token reused, a token expired, a token for
 * somebody else's address. A reset endpoint's behaviour on those paths IS its behaviour.
 */

const RESET_API_KEY = 'customer-reset-test-key';

beforeEach(function () {
    config([
        'compat.api_key' => RESET_API_KEY,
        'compat.jwt_secret' => 'customer-reset-test-secret',
        'auth.passwords.users.table' => 'core_password_resets',
        'auth.passwords.users.expire' => 60,
        'auth.passwords.users.throttle' => 0,
        'customers.storefront_url' => 'https://watchizereg.test',
    ]);
    Mail::fake();
});

/** @return array<string, string> */
function resetKey(): array
{
    return ['Api-Code' => RESET_API_KEY];
}

/** Ask for a link and return the raw token the e-mail would carry. */
function requestResetToken(string $email): string
{
    withHeaders(resetKey())->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();

    // The stored value is a HASH, so the raw token is taken from the URL the mailable was built
    // with — the same string the customer would paste out of their inbox.
    $token = '';
    Mail::assertSent(CustomerPasswordReset::class, function (CustomerPasswordReset $mail) use (&$token): bool {
        $mail->build();
        $reflection = new ReflectionProperty($mail, 'url');
        parse_str((string) parse_url(T::str($reflection->getValue($mail)), PHP_URL_QUERY), $query);
        $token = is_string($query['token'] ?? null) ? $query['token'] : '';

        return true;
    });

    return $token;
}

// ── which table ──────────────────────────────────────────────────────────────────────────────

it('writes the token to CORE table and never touches the legacy one', function () {
    /*
     * The single most important assertion in this file. `config/auth.php` points the broker at
     * `core_password_resets`; if it ever points back at `password_reset_tokens`, core and the
     * legacy app start deleting each other's rows and both applications' reset flows break in a way
     * neither's logs would explain.
     */
    $email = Shopper::email();
    Shopper::register(['email' => $email]);

    $legacyBefore = DB::table('password_reset_tokens')->count();

    withHeaders(resetKey())->postJson('/api/auth/forgot-password', ['email' => $email])->assertOk();

    expect(DB::table('core_password_resets')->where('email', $email)->exists())->toBeTrue()
        ->and(DB::table('password_reset_tokens')->count())->toBe($legacyBefore)
        ->and(config('auth.passwords.users.table'))->toBe('core_password_resets');
});

it('stores the token HASHED, so the row cannot be turned back into a link', function () {
    $email = Shopper::email();
    Shopper::register(['email' => $email]);

    $token = requestResetToken($email);
    $stored = T::str(DB::table('core_password_resets')->where('email', $email)->value('token'));

    expect($token)->not->toBe('')
        ->and($stored)->not->toBe($token)
        ->and(Hash::check($token, $stored))->toBeTrue();
});

// ── enumeration ──────────────────────────────────────────────────────────────────────────────

it('answers the SAME thing for a registered address, an unknown one, and a throttled one', function () {
    /*
     * Any branch that answers differently turns this endpoint into a way to ask "does this person
     * shop here?". The legacy controller got this right and it is reproduced rather than improved.
     */
    $email = Shopper::email();
    Shopper::register(['email' => $email]);
    $expected = ['message' => 'If that email is registered, a password reset link has been sent.'];

    withHeaders(resetKey())->postJson('/api/auth/forgot-password', ['email' => $email])
        ->assertOk()->assertExactJson($expected);

    withHeaders(resetKey())->postJson('/api/auth/forgot-password', ['email' => Shopper::email()])
        ->assertOk()->assertExactJson($expected);

    // Throttled: the broker refuses a second link inside the window, and the answer does not change.
    config(['auth.passwords.users.throttle' => 60]);
    withHeaders(resetKey())->postJson('/api/auth/forgot-password', ['email' => $email])
        ->assertOk()->assertExactJson($expected);
});

it('sends NO e-mail for an address nobody has registered', function () {
    withHeaders(resetKey())->postJson('/api/auth/forgot-password', ['email' => Shopper::email()])->assertOk();

    Mail::assertNotSent(CustomerPasswordReset::class);
});

it('still validates the address itself, because a typo is not a secret', function () {
    withHeaders(resetKey())->postJson('/api/auth/forgot-password', ['email' => 'not-an-address'])
        ->assertStatus(422)->assertJsonValidationErrors('email');
});

// ── the happy path ───────────────────────────────────────────────────────────────────────────

it('resets the password with a valid token, and the new one signs the customer in', function () {
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email, 'password' => 'the-forgotten-password']);

    $token = requestResetToken($email);

    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => $token, 'email' => $email,
        'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertOk()->assertExactJson(['message' => 'Your password has been reset.']);

    expect(Hash::check('a-brand-new-password', T::str(DB::table('users')->where('id', $user->id)->value('password'))))
        ->toBeTrue();

    withHeaders(resetKey())->postJson('/api/login', ['email' => $email, 'password' => 'a-brand-new-password'])
        ->assertOk();
    withHeaders(resetKey())->postJson('/api/login', ['email' => $email, 'password' => 'the-forgotten-password'])
        ->assertStatus(401);
});

it('writes the password through the DOOR, with its own reason', function () {
    // Not `customer.password`: an audit that cannot tell "changed it while signed in" from "changed
    // it holding an e-mailed token" cannot answer the only question asked after an account is taken
    // over. The reason exists and the model guard is what makes the callback have to use it.
    expect(UserWrites::REASONS)->toHaveKey(CustomerAccounts::RESET);
});

// ── the hostile set ──────────────────────────────────────────────────────────────────────────

it('REFUSES a token that has already been used', function () {
    /*
     * The case the developer named. `Password::reset()` deletes the row on success, so the second
     * attempt finds nothing — but "the implementation happens to delete it" is not the assertion.
     * What is asserted is that the second attempt fails AND that the password it would have set is
     * not the one on the account.
     */
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email]);
    $token = requestResetToken($email);

    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => $token, 'email' => $email,
        'password' => 'the-first-reset', 'password_confirmation' => 'the-first-reset',
    ])->assertOk();

    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => $token, 'email' => $email,
        'password' => 'the-second-reset', 'password_confirmation' => 'the-second-reset',
    ])->assertStatus(422);

    $hash = T::str(DB::table('users')->where('id', $user->id)->value('password'));
    expect(Hash::check('the-first-reset', $hash))->toBeTrue()
        ->and(Hash::check('the-second-reset', $hash))->toBeFalse()
        ->and(DB::table('core_password_resets')->where('email', $email)->exists())->toBeFalse();
});

it('REFUSES an EXPIRED token', function () {
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email, 'password' => 'the-original-password']);
    $token = requestResetToken($email);

    // Age the row past the configured window rather than sleeping through it.
    DB::table('core_password_resets')->where('email', $email)
        ->update(['created_at' => Carbon::now()->subMinutes(61)->toDateTimeString()]);

    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => $token, 'email' => $email,
        'password' => 'too-late-for-this', 'password_confirmation' => 'too-late-for-this',
    ])->assertStatus(422);

    expect(Hash::check('the-original-password', T::str(DB::table('users')->where('id', $user->id)->value('password'))))
        ->toBeTrue();
});

it('REFUSES a token presented for a DIFFERENT address', function () {
    // The token is scoped to the address it was issued for; presenting somebody else's is the
    // account-takeover shape this endpoint exists to refuse.
    $victimEmail = Shopper::email();
    $victim = Shopper::register(['email' => $victimEmail, 'password' => 'the-victim-password']);

    $attackerEmail = Shopper::email();
    Shopper::register(['email' => $attackerEmail]);
    $attackerToken = requestResetToken($attackerEmail);

    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => $attackerToken, 'email' => $victimEmail,
        'password' => 'taken-over-now', 'password_confirmation' => 'taken-over-now',
    ])->assertStatus(422);

    expect(Hash::check('the-victim-password', T::str(DB::table('users')->where('id', $victim->id)->value('password'))))
        ->toBeTrue();
});

it('REFUSES a forged token for a real address', function () {
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email, 'password' => 'the-original-password']);
    requestResetToken($email);

    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => str_repeat('f', 64), 'email' => $email,
        'password' => 'guessed-my-way-in', 'password_confirmation' => 'guessed-my-way-in',
    ])->assertStatus(422);

    expect(Hash::check('the-original-password', T::str(DB::table('users')->where('id', $user->id)->value('password'))))
        ->toBeTrue();
});

it('answers ONE indistinguishable sentence for every kind of failure', function () {
    /*
     * ── The enumeration oracle this endpoint used to be (review 🟠-4) ────────────────────────
     *
     * `reset` returned the broker's own status, and the statuses differ in the one way that
     * matters: `passwords.user` is "We can't find a user with that email address" while
     * `passwords.token` is "This password reset token is invalid". A junk token plus any address
     * therefore answered the question `forgot` is carefully shaped never to answer.
     *
     * No valid link is needed to ask, which is why the old justification ("whoever holds the link
     * already knows the address") did not hold: the token is a free string in a POST body.
     *
     * So this compares the FOUR failure modes against each other. It fails if any of them can be
     * told apart by status, body, or the shape of the body.
     */
    $registered = Shopper::email();
    Shopper::register(['email' => $registered, 'password' => 'the-original-password']);
    $spent = requestResetToken($registered);
    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => $spent, 'email' => $registered,
        'password' => 'used-it-already', 'password_confirmation' => 'used-it-already',
    ])->assertOk();

    $expiredEmail = Shopper::email();
    Shopper::register(['email' => $expiredEmail]);
    $expired = requestResetToken($expiredEmail);
    DB::table('core_password_resets')->where('email', $expiredEmail)
        ->update(['created_at' => Carbon::now()->subMinutes(61)->toDateTimeString()]);

    $cases = [
        // An address that has never shopped here. THE leak: this was "we can't find a user".
        'unknown address' => ['token' => str_repeat('a', 64), 'email' => 'nobody-'.uniqid().'@example.com'],
        // A real address, a token that is not its token.
        'forged token' => ['token' => str_repeat('f', 64), 'email' => $registered],
        // A token that was genuinely issued and is past its window.
        'expired token' => ['token' => $expired, 'email' => $expiredEmail],
        // A token that worked once.
        'spent token' => ['token' => $spent, 'email' => $registered],
    ];

    $answers = [];
    foreach ($cases as $label => $payload) {
        $response = withHeaders(resetKey())->postJson('/api/auth/reset-password', $payload + [
            'password' => 'a-new-password-here', 'password_confirmation' => 'a-new-password-here',
        ]);
        $answers[$label] = [$response->status(), $response->json()];
    }

    // `json_encode` can return false; a failure to encode must not silently collapse two answers
    // into one and make this test pass.
    $distinct = array_unique(array_map(
        fn (array $a): string => (string) json_encode($a, JSON_THROW_ON_ERROR),
        $answers,
    ));

    expect($distinct)->toHaveCount(1, 'every failure must be indistinguishable: '.json_encode($answers))
        ->and(array_values($answers)[0][0])->toBe(422);

    // And the one sentence must be true of all four, not just accurate for one of them.
    expect(array_values($answers)[0][1])
        ->toBe(['error' => 'This password reset link is invalid or has expired. Please request a new one.']);
});

it('throttles reset-password per endpoint, not only by the global 60 a minute', function () {
    /*
     * A one-hour token behind a 60-guesses-a-minute ceiling is a guessing budget nobody granted,
     * and `reset` had no counter of its own at all — unlike `login`, which has had one since
     * piece 3. Keyed by IP and NOT by e-mail: an e-mail key would let an attacker exhaust a real
     * customer's budget and lock them out of their own reset.
     */
    $email = Shopper::email();
    Shopper::register(['email' => $email, 'password' => 'the-original-password']);

    $statuses = [];
    for ($i = 0; $i < 7; $i++) {
        $statuses[] = withHeaders(resetKey())->postJson('/api/auth/reset-password', [
            'token' => str_repeat('b', 64), 'email' => $email,
            'password' => 'guess-number-'.$i, 'password_confirmation' => 'guess-number-'.$i,
        ])->status();
    }

    // Five guesses, then the door. The exact cut is configuration; that it CLOSES is the property.
    expect($statuses)->toContain(429)
        ->and(array_slice($statuses, 0, 5))->each->toBe(422);
});

it('throttles register per endpoint, because it creates an account AND sends mail', function () {
    // At the global 60/min one IP could mint 3,600 accounts an hour and have this application send
    // 3,600 e-mails from the shop's own domain — somebody else destroying its sender reputation.
    $statuses = [];
    for ($i = 0; $i < 5; $i++) {
        $statuses[] = withHeaders(resetKey())->postJson('/api/register', [
            'first_name' => 'Flood', 'last_name' => 'Test',
            'email' => 'flood-'.$i.'-'.uniqid().'@example.com',
            'password' => 'a-valid-password', 'password_confirmation' => 'a-valid-password',
        ])->status();
    }

    expect($statuses)->toContain(429);
    // And the refusal is in the shape the storefront reads, not the framework default.
    expect(withHeaders(resetKey())->postJson('/api/register', [
        'first_name' => 'Flood', 'last_name' => 'Test', 'email' => 'one-more-'.uniqid().'@example.com',
        'password' => 'a-valid-password', 'password_confirmation' => 'a-valid-password',
    ])->assertStatus(429)->json())->toBe(['error' => 'Too many attempts. Please try again later.']);
});

it('REFUSES a short or unconfirmed new password before anything is spent', function (array $payload) {
    $email = Shopper::email();
    Shopper::register(['email' => $email]);
    $token = requestResetToken($email);

    withHeaders(resetKey())->postJson('/api/auth/reset-password', $payload + [
        'token' => $token, 'email' => $email,
    ])->assertStatus(422);

    // …and the token survives, so a customer who mistypes the confirmation can simply try again
    // rather than having to request a second link.
    expect(DB::table('core_password_resets')->where('email', $email)->exists())->toBeTrue();
})->with([
    'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
    'unconfirmed' => [['password' => 'long-enough-one', 'password_confirmation' => 'a-different-one']],
]);

it('issuing a SECOND link invalidates the first, which is the broker own model', function () {
    // One outstanding token per address: asking again is how a customer recovers from a lost
    // e-mail, and the old link must stop working when they do.
    $email = Shopper::email();
    Shopper::register(['email' => $email]);

    $first = requestResetToken($email);
    Mail::fake();
    $second = requestResetToken($email);

    expect($second)->not->toBe($first);

    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => $first, 'email' => $email,
        'password' => 'using-the-old-link', 'password_confirmation' => 'using-the-old-link',
    ])->assertStatus(422);

    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => $second, 'email' => $email,
        'password' => 'using-the-new-link', 'password_confirmation' => 'using-the-new-link',
    ])->assertOk();
});

it('INVALIDATES every token the customer already holds', function () {
    /*
     * This test used to assert the OPPOSITE, and the note beside it named the gap: a reset did not
     * revoke tokens already issued, because core had no per-user epoch. Developer decision
     * 2026-09-22 — *"the reset flow exists for exactly the case where someone else has the account;
     * leaving the thief's token valid for thirty days defeats it"* — and M1v built the epoch.
     *
     * Flipped rather than deleted, deliberately: the history of a security property is worth more
     * in the file that asserts it than in a commit message nobody will read.
     */
    $email = Shopper::email();
    [$user, $stolen] = Shopper::withToken(['email' => $email, 'password' => 'the-old-password']);

    // The token works before the reset — otherwise the assertion below proves nothing.
    withHeaders(['Api-Code' => RESET_API_KEY, 'Authorization' => 'Bearer '.$stolen])
        ->getJson('/api/auth/me')->assertOk();

    // A second apart, so the token's whole-second `iat` is strictly before the epoch.
    Carbon::setTestNow(Carbon::now()->addSeconds(2));
    $resetToken = requestResetToken($email);
    withHeaders(resetKey())->postJson('/api/auth/reset-password', [
        'token' => $resetToken, 'email' => $email,
        'password' => 'a-brand-new-password', 'password_confirmation' => 'a-brand-new-password',
    ])->assertOk();
    Carbon::setTestNow();

    withHeaders(['Api-Code' => RESET_API_KEY, 'Authorization' => 'Bearer '.$stolen])
        ->getJson('/api/auth/me')
        ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);

    // …and the epoch records WHICH action took the account back, not merely that something did.
    expect(T::str(DB::table(CustomerTokens::EPOCH_TABLE)->where('user_id', $user->id)->value('reason')))
        ->toBe(CustomerAccounts::RESET);
});

// ── the route surface ────────────────────────────────────────────────────────────────────────

it('requires the API key on both reset endpoints', function (string $path) {
    postJson($path)->assertStatus(401)->assertExactJson(['error' => 'Unauthorized']);
})->with([['/api/auth/forgot-password'], ['/api/auth/reset-password']]);

it('does not publish any route Laravel password BROKER owns', function () {
    // The broker is used as a LIBRARY, never as a set of published routes. `AuthTest` holds the
    // same line for the dashboard; repeated here because piece 4 is when the broker arrived.
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn ($route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => $name !== '')
        ->all();

    expect(array_values(array_intersect($names, [
        'password.request', 'password.email', 'password.reset', 'password.update', 'password.confirm',
    ])))->toBe([]);

    expect(Password::broker())->not->toBeNull();
});
