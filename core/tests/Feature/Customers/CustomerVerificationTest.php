<?php

use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerMail;
use App\Mail\CustomerEmailVerification;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\withHeaders;

/*
 * `auth/verify-email/{id}/{hash}` and `auth/resend-verification` (storefront Phase 1, piece 4).
 *
 * The property this file exists for is that the SIGNATURE is the real check. The legacy endpoint
 * compared `sha1($email)`, which anybody who knows a customer's address can compute in one line —
 * and carried Laravel's `signed` middleware on top for exactly that reason. Both checks are kept,
 * and the tests below drive each of them failing on its own.
 */

const VERIFY_API_KEY = 'customer-verify-test-key';

beforeEach(function () {
    config([
        'compat.api_key' => VERIFY_API_KEY,
        'compat.jwt_secret' => 'customer-verify-test-secret',
        'customers.storefront_url' => 'https://watchizereg.test',
    ]);
    Mail::fake();
});

/** The signed link core would mail for this account. */
function verificationLink(int $id, string $email, ?Carbon $expires = null): string
{
    return URL::temporarySignedRoute(
        CustomerMail::VERIFY_ROUTE,
        $expires ?? now()->addHours(CustomerMail::VERIFY_HOURS),
        ['id' => $id, 'hash' => sha1($email)],
    );
}

// ── the link is sent ─────────────────────────────────────────────────────────────────────────

it('sends a verification e-mail when a customer registers', function () {
    $email = Shopper::email();

    withHeaders(['Api-Code' => VERIFY_API_KEY])->postJson('/api/register', [
        'first_name' => 'New', 'last_name' => 'Customer', 'email' => $email,
        'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
    ])->assertOk();

    Mail::assertSent(CustomerEmailVerification::class, fn (CustomerEmailVerification $mail): bool => $mail->hasTo($email));
});

it('registers successfully even when the mail transport REFUSES', function () {
    /*
     * The legacy controller wrapped the send in a try/catch for this reason, and so does
     * `CustomerMail`. A relay that is down must not turn a successful registration into a 500: the
     * account exists, the customer is about to be signed in, and nothing gates on verification.
     */
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp: connection refused'));

    withHeaders(['Api-Code' => VERIFY_API_KEY])->postJson('/api/register', [
        'first_name' => 'Unlucky', 'last_name' => 'Timing', 'email' => Shopper::email(),
        'password' => 'a-good-password', 'password_confirmation' => 'a-good-password',
    ])->assertOk();
});

// ── the link works, once ─────────────────────────────────────────────────────────────────────

it('verifies the address from a signed link', function () {
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email]);

    get(verificationLink((int) $user->id, $email))
        ->assertOk()->assertExactJson(['message' => 'Email verified successfully.']);

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->not->toBeNull();
});

it('is idempotent, and a second click does NOT move the timestamp', function () {
    // A link can sit in an inbox for days and be clicked twice. "Verified since" is the useful half
    // of the column, and re-stamping it would quietly destroy the only fact it records.
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email]);
    $link = verificationLink((int) $user->id, $email);

    get($link)->assertOk();
    $first = T::str(DB::table('users')->where('id', $user->id)->value('email_verified_at'));

    Carbon::setTestNow(Carbon::now()->addHour());
    get($link)->assertOk()->assertExactJson(['message' => 'Email already verified.']);
    Carbon::setTestNow();

    expect(T::str(DB::table('users')->where('id', $user->id)->value('email_verified_at')))->toBe($first);
});

// ── both checks, each failing on its own ─────────────────────────────────────────────────────

it('REFUSES an UNSIGNED link, even when the id and hash are perfectly correct', function () {
    /*
     * The half that actually protects the account. `sha1($email)` is computable by anybody who
     * knows the address, so without the signature this URL could be constructed for any customer.
     */
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email]);

    get('/api/auth/verify-email/'.$user->id.'/'.sha1($email))->assertStatus(403);

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->toBeNull();
});

it('REFUSES a link whose signature was computed for a DIFFERENT account', function () {
    // The half the signature alone would not catch: a valid signature replayed against another id.
    // This is why the `sha1` check stays even though the signature is the stronger one.
    $victim = Shopper::register();
    $attackerEmail = Shopper::email();
    $attacker = Shopper::register(['email' => $attackerEmail]);

    $link = verificationLink((int) $attacker->id, $attackerEmail);
    $swapped = str_replace('/'.$attacker->id.'/', '/'.$victim->id.'/', $link);

    get($swapped)->assertStatus(403);

    expect(DB::table('users')->where('id', $victim->id)->value('email_verified_at'))->toBeNull();
});

it('REFUSES a signed link whose HASH does not match the stored address', function () {
    /*
     * The address-change case. A link issued before the customer changed their e-mail carries the
     * OLD `sha1`, and using it would verify an address they no longer own — which is the whole
     * reason Laravel puts a hash in the URL as well as a signature.
     */
    $user = Shopper::register();

    get(verificationLink((int) $user->id, 'somebody-else@example.test'))
        ->assertStatus(403)->assertExactJson(['error' => 'Invalid or expired verification link.']);

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->toBeNull();
});

it('REFUSES an EXPIRED link', function () {
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email]);

    $link = verificationLink((int) $user->id, $email, now()->addHours(CustomerMail::VERIFY_HOURS));

    Carbon::setTestNow(now()->addHours(CustomerMail::VERIFY_HOURS + 1));
    get($link)->assertStatus(403);
    Carbon::setTestNow();

    expect(DB::table('users')->where('id', $user->id)->value('email_verified_at'))->toBeNull();
});

it('REFUSES a link for an account that does not exist, without saying which it was', function () {
    // Same body as a bad hash: an endpoint that distinguishes "no such account" from "wrong link"
    // answers a question nobody browsing links should get to ask.
    get(verificationLink(999999, 'nobody@example.test'))
        ->assertStatus(403)->assertExactJson(['error' => 'Invalid or expired verification link.']);
});

// ── resend ───────────────────────────────────────────────────────────────────────────────────

it('resends a link to the AUTHENTICATED account and nobody else', function () {
    $email = Shopper::email();
    [$user, $token] = Shopper::withToken(['email' => $email]);
    RateLimiter::clear('resend-verification|'.$user->id);

    withHeaders(['Api-Code' => VERIFY_API_KEY, 'Authorization' => 'Bearer '.$token])
        ->postJson('/api/auth/resend-verification', ['id' => 1, 'email' => 'someone.else@example.test'])
        ->assertOk()->assertExactJson(['message' => 'Verification email sent.']);

    // Addressed to the token holder, not to anything the request body named.
    Mail::assertSent(CustomerEmailVerification::class, fn (CustomerEmailVerification $mail): bool => $mail->hasTo($email));
});

it('THROTTLES resends, because each one is a real e-mail to a real inbox', function () {
    /*
     * An unthrottled resend button is a way to use this shop to post mail to somebody else — and
     * the somebody else is whoever owns the address on the account, who may be the person being
     * harassed rather than the person clicking.
     */
    [$user, $token] = Shopper::withToken();
    RateLimiter::clear('resend-verification|'.$user->id);
    $headers = ['Api-Code' => VERIFY_API_KEY, 'Authorization' => 'Bearer '.$token];

    withHeaders($headers)->postJson('/api/auth/resend-verification')->assertOk();

    $blocked = withHeaders($headers)->postJson('/api/auth/resend-verification')->assertStatus(429);
    expect(T::str($blocked->json('error')))->toStartWith('Please wait ');

    RateLimiter::clear('resend-verification|'.$user->id);
});

it('does not resend to an account that is ALREADY verified', function () {
    [$user, $token] = Shopper::withToken();
    app(CustomerAccounts::class)->markVerified($user);
    RateLimiter::clear('resend-verification|'.$user->id);

    withHeaders(['Api-Code' => VERIFY_API_KEY, 'Authorization' => 'Bearer '.$token])
        ->postJson('/api/auth/resend-verification')
        ->assertOk()->assertExactJson(['message' => 'Email already verified.']);

    Mail::assertNotSent(CustomerEmailVerification::class);
});

it('requires a token to resend', function () {
    withHeaders(['Api-Code' => VERIFY_API_KEY])->postJson('/api/auth/resend-verification')
        ->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
});

// ── the credential never reaches a log ───────────────────────────────────────────────────────

it('has ONE sender for account e-mail, so the no-logging rule is written once', function () {
    /*
     * Both e-mails carry a credential in a URL. `CustomerMail` logs a failure by reference — user
     * id, kind, transport message — and never the link. That discipline only holds if there is one
     * sender; a second `Mail::to(...)->send(new Customer…)` elsewhere is a second place that can
     * log the wrong thing.
     */
    $offenders = [];
    foreach (['app', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory)));
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php' || str_ends_with($file->getPathname(), 'CustomerMail.php')) {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/new\s+Customer(PasswordReset|EmailVerification)\s*\(/', $source) === 1) {
                $offenders[] = $file->getPathname();
            }
        }
    }

    expect($offenders)->toBe([], 'a second place builds an account e-mail; CustomerMail is the only sender');
});

it('logs a failed send by REFERENCE, never with the link', function () {
    /*
     * A reset URL is a bearer credential for sixty minutes: in a log line, anybody with the log
     * file owns the account. Driven rather than asserted about the source, so it is the BEHAVIOUR
     * that is pinned.
     */
    $email = Shopper::email();
    $user = Shopper::register(['email' => $email]);

    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp: connection refused'));

    $captured = [];
    Log::listen(function (MessageLogged $logged) use (&$captured): void {
        $captured[] = ['message' => $logged->message, 'context' => $logged->context];
    });

    expect(app(CustomerMail::class)->sendEmailVerification($user))->toBeFalse();

    $json = (string) json_encode($captured);
    expect($captured)->not->toBe([])
        ->and($json)->toContain('customer account e-mail failed')
        ->and($json)->toContain(CustomerMail::KIND_VERIFY)
        // Neither the address nor anything signature-shaped.
        ->and($json)->not->toContain($email)
        ->and($json)->not->toContain('signature=')
        ->and($json)->not->toContain('verify-email');
});
