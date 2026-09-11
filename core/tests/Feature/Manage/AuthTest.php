<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Models\User;
use App\Transform\LegacySource;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Dashboard sign-in against the SHARED `users` table.
 *
 * The interesting assertions here are not "login works" — they are the three things that must NOT
 * happen, because `users` is a legacy table this application may not write (AGENTS §3): no
 * password re-hash, no remember_token, no last_login_at stamp. Each one is a framework default
 * that would have written the table silently.
 */

/**
 * A real account with a password we know, WITHOUT persisting one: the hash is written inside the
 * test's transaction and rolls back with it. Nothing here creates a user.
 */
function staffWithPassword(User $user, string $password = 'wave4a-test-password'): User
{
    DB::table('users')->where('id', $user->id)->update(['password' => Hash::make($password)]);

    return $user->refresh();
}

beforeEach(fn () => RateLimiter::clear('manage-login|'.strtolower((string) Staff::admin()->email).'|127.0.0.1'));

it('shows the login screen to a guest', function () {
    get('/manage/login')->assertOk()->assertSee('lang="ar"', false)->assertSee('dir="rtl"', false);
});

it('signs a staff account in with its EXISTING legacy password hash', function () {
    // The point of the shared table: the hash the legacy app wrote is the hash core verifies.
    // `$2y$` bcrypt at cost 10 — exactly what production holds.
    $user = Staff::admin();
    DB::table('users')->where('id', $user->id)->update(['password' => '$2y$10$'.substr(password_hash('legacy-secret', PASSWORD_BCRYPT, ['cost' => 10]), 7)]);

    post('/manage/login', ['email' => $user->email, 'password' => 'legacy-secret'])
        ->assertRedirect('/manage');

    expect(auth()->check())->toBeTrue()->and(auth()->id())->toBe($user->id);
});

it('does NOT re-hash the password on login, which would write a legacy table', function () {
    $user = staffWithPassword(Staff::admin());
    $before = T::str(DB::table('users')->where('id', $user->id)->value('password'));

    post('/manage/login', ['email' => $user->email, 'password' => 'wave4a-test-password'])->assertRedirect('/manage');

    expect(T::str(DB::table('users')->where('id', $user->id)->value('password')))->toBe($before)
        ->and(config('hashing.rehash_on_login'))->toBeFalse();
});

it('leaves an EXISTING remember_token untouched through login AND logout', function () {
    // 🔴-1 (review 2026-09-11). The wave-4A version of this test NULLed the column first, which
    // walked into the one branch of `SessionGuard::logout()` that does not write:
    //
    //     if (! is_null($this->user) && ! empty($user->getRememberToken())) { cycleRememberToken(); }
    //
    // A real staff account HOLDS a token (the legacy app's remember-me sets one), so logout wrote
    // `users.remember_token` on a legacy table every single time. Start from a token, and assert
    // the whole session leaves it alone.
    $user = staffWithPassword(Staff::admin());
    $token = 'legacy-remember-token-'.str_repeat('a', 40);
    DB::table('users')->where('id', $user->id)->update(['remember_token' => $token, 'last_login_at' => null]);

    $legacyBefore = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];

    post('/manage/login', ['email' => $user->email, 'password' => 'wave4a-test-password', 'remember' => '1'])
        ->assertRedirect('/manage');
    get('/manage')->assertOk();
    post('/manage/logout')->assertRedirect('/manage/login');

    $row = DB::table('users')->where('id', $user->id)->first(['remember_token', 'last_login_at', 'updated_at']);
    expect($row?->remember_token)->toBe($token, 'logout cycled the remember token on a LEGACY table')
        ->and($row?->last_login_at)->toBeNull('core must not stamp a legacy column')
        // The digest is the acceptance test for this wave: a login/dashboard/logout round trip
        // must leave all 65 legacy tables byte-identical.
        ->and(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($legacyBefore);
});

it('writes nothing even if someone later asks for remember-me explicitly', function () {
    // `Auth::login($user, remember: true)` is the mirror-image trapdoor:
    // `ensureRememberTokenIsSet()` cycles the token precisely when it is EMPTY. The model's
    // overrides make both branches unreachable, so a future caller cannot reopen the hole by
    // adding a checkbox.
    $user = Staff::admin();
    DB::table('users')->where('id', $user->id)->update(['remember_token' => null]);
    // Re-read: a model still holding a stale non-null token in memory would make the pre-fix
    // framework skip the cycling branch, and this test would pass for the wrong reason — the very
    // shape of mistake 🔴-1 was. Verified by hand: without the overrides this writes a 60-character
    // token; with them the column stays NULL.
    $user = User::query()->findOrFail($user->id);
    $legacyBefore = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];

    Auth::login($user, true);

    expect(DB::table('users')->where('id', $user->id)->value('remember_token'))->toBeNull()
        ->and(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($legacyBefore);
});

it('reports no remember token to the framework, which is what makes both doors shut', function () {
    $user = Staff::admin();
    DB::table('users')->where('id', $user->id)->update(['remember_token' => 'whatever-is-in-there']);
    $fresh = User::query()->findOrFail($user->id);

    expect($fresh->getRememberToken())->toBeNull()
        ->and($fresh->getRememberTokenName())->toBe('')
        // …and setting one leaves the model CLEAN, so the provider's save() issues no UPDATE.
        ->and(tap($fresh, fn (User $u) => $u->setRememberToken('new-token'))->isDirty())->toBeFalse();
});

it('keeps the 65-table legacy digest identical across a full dashboard session', function () {
    // THE acceptance test for wave 4A: sign in, work, sign out — the legacy side does not move.
    $user = staffWithPassword(Staff::admin());
    DB::table('users')->where('id', $user->id)->update(['remember_token' => 'held-token-'.str_repeat('b', 30)]);

    $legacyBefore = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];

    post('/manage/login', ['email' => $user->email, 'password' => 'wave4a-test-password'])->assertRedirect('/manage');
    get('/manage')->assertOk();
    get('/manage/storefronts')->assertOk();
    get('/manage/storefronts/1/edit')->assertOk();
    post('/manage/logout')->assertRedirect('/manage/login');

    expect(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($legacyBefore);
});

it('has no route that could reach the password broker, which WOULD write users.password', function () {
    // `password_reset_tokens` exists in the shared schema (the legacy app owns it), so the broker
    // is one route away from `UPDATE users SET password = …`. Core publishes no such route, and
    // this asserts it rather than trusting it.
    $names = collect(Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
        ->map(fn (Route $route): string => (string) $route->getName())
        ->filter(fn (string $name): bool => $name !== '')
        ->all();

    foreach ($names as $name) {
        expect($name)->not->toContain('password')
            ->and($name)->not->toContain('verification');
    }
});

it('cannot verify an e-mail either, because the model does not implement the contract', function () {
    // `markEmailAsVerified()` writes `users.email_verified_at`. The interface is what wires it into
    // the framework's verification flow; without it there is nothing to call.
    expect(class_implements(User::class))->not->toContain(MustVerifyEmail::class);
});

it('keeps the bcrypt cost PRODUCTION will use equal to the production hash cost', function () {
    // 🟠-2 (review 2026-09-11). `config/hashing.php` pinned rounds to 10 — and `.env` plus
    // `.env.example` both said 12, which overrides config, so the defence was dead on arrival.
    //
    // The runtime value cannot be asserted here: `phpunit.xml` sets BCRYPT_ROUNDS=4 so the suite
    // does not spend its life hashing. So assert the two things PRODUCTION actually reads — the
    // config file's own default with no env override, and the example file a new machine copies —
    // plus the defence that makes a mismatch harmless anyway.
    $saved = getenv('BCRYPT_ROUNDS');
    unset($_ENV['BCRYPT_ROUNDS'], $_SERVER['BCRYPT_ROUNDS']);
    putenv('BCRYPT_ROUNDS');

    try {
        /** @var array{bcrypt: array{rounds: mixed}} $config */
        $config = require base_path('config/hashing.php');
        $default = T::int($config['bcrypt']['rounds']);
    } finally {
        if (is_string($saved)) {
            putenv('BCRYPT_ROUNDS='.$saved);
            $_ENV['BCRYPT_ROUNDS'] = $saved;
            $_SERVER['BCRYPT_ROUNDS'] = $saved;
        }
    }

    expect($default)->toBe(10, 'production would hash at a cost the legacy app did not write')
        // The defence that makes the cost a comfort rather than a dependency: the framework never
        // asks "should I rehash?" at all.
        ->and(config('hashing.rehash_on_login'))->toBeFalse()
        // …and a cost-10 hash is genuinely fine at cost 10, which is what the config now produces.
        ->and(password_needs_rehash('$2y$10$'.str_repeat('a', 53), PASSWORD_BCRYPT, ['cost' => $default]))->toBeFalse();
});

it('does not let .env.example reintroduce a different cost', function () {
    // The committed example is what a new machine copies, and it is exactly what rotted: it said
    // 12 while the config comment claimed 10.
    $example = file_get_contents(base_path('.env.example'));
    expect($example)->toBeString();

    if (preg_match('/^BCRYPT_ROUNDS=(\d+)$/m', (string) $example, $matches) === 1) {
        expect((int) $matches[1])->toBe(10, '.env.example must not set a cost other than the production one');
    }
});

it('refuses a real account that has no dashboard role, and does not leave it signed in', function () {
    $customer = staffWithPassword(Staff::customer());

    post('/manage/login', ['email' => $customer->email, 'password' => 'wave4a-test-password'])
        ->assertSessionHasErrors('email');

    expect(auth()->check())->toBeFalse();
});

it('gives the same message for a wrong password and an unknown address', function () {
    $user = staffWithPassword(Staff::admin());

    // Identical wording, so the form cannot be used to enumerate which addresses exist.
    post('/manage/login', ['email' => $user->email, 'password' => 'not-the-password'])
        ->assertInvalid(['email' => trans('auth.failed')]);
    post('/manage/login', ['email' => 'nobody-'.uniqid().'@example.test', 'password' => 'whatever'])
        ->assertInvalid(['email' => trans('auth.failed')]);
});

it('throttles repeated failures for one address', function () {
    $user = staffWithPassword(Staff::admin());

    for ($attempt = 0; $attempt < 5; $attempt++) {
        post('/manage/login', ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
    }

    // The sixth attempt is refused by the limiter rather than by the password check.
    post('/manage/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertInvalid(['email' => 'Too many login attempts']);
});

it('validates the form before it touches the database', function () {
    post('/manage/login', [])->assertSessionHasErrors(['email', 'password']);
    post('/manage/login', ['email' => 'not-an-email', 'password' => 'x'])->assertSessionHasErrors('email');
});

it('signs out, clears the session and lands back on the login screen', function () {
    actingAs(Staff::admin())->post('/manage/logout')->assertRedirect('/manage/login');

    expect(auth()->check())->toBeFalse();
});

it('sends an already-signed-in user from the login screen to the dashboard', function () {
    actingAs(Staff::admin())->get('/manage/login')->assertRedirect('/manage');
});

it('keeps the dashboard session and the storefront JWT world separate', function () {
    // A dashboard session must grant nothing on the API. `/api/me/cart` is JWT/guest-token
    // territory (CompatAuth) and does not read the session guard at all.
    actingAs(Staff::admin())->getJson('/api/me/cart')->assertStatus(401);
});
