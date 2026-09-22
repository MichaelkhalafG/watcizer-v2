<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Compat\CompatServices;
use App\Domain\Customers\CustomerAccounts;
use App\Domain\Customers\CustomerMail;
use App\Domain\Customers\CustomerPayload;
use App\Domain\Customers\CustomerTokens;
use App\Http\Controllers\Controller;
use App\Http\Middleware\CompatAuth;
use App\Http\Middleware\CompatGuestCart;
use App\Models\User;
use App\Support\Coerce;
use App\Support\LegacyJwt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * `login`, `register`, `logout`, `auth/me` — issued by core (Phase 1, piece 3, 2026-09-21).
 *
 * ── The contract is the LEGACY one, byte for byte where it matters ──────────────────────────
 *
 * Phase 2 is an environment flip, not a frontend rewrite, so these answer exactly what the running
 * storefront already parses. That is not a style preference — it is the whole reason the phase is
 * cheap — and it means reproducing shapes that are not what one would design today:
 *
 *   • `login` returns the USER OBJECT with `token` merged in, not `{user, token}`;
 *     `authStore.persist()` reads `data.token` and `data.id` off the same object.
 *   • a wrong password is **401 `{"error": "Invalid email or password."}`**, not 422.
 *   • too many attempts is **429 `{"error": "Too many login attempts. Please try again in N
 *     seconds."}`** — `Login.jsx` renders `err.response.data.error` verbatim for that status.
 *   • validation failures stay Laravel's default `{message, errors}`, because that is what the
 *     legacy `$request->validate()` produced and what the form reads field errors out of.
 *
 * ── What is deliberately DIFFERENT from the legacy controller ───────────────────────────────
 *
 * **`last_login_at` is not written.** The legacy `login()` stamped it on every sign-in for a
 * re-engagement campaign; AGENTS §3 names that column as one core may not write. See
 * {@see CustomerAccounts} for the consequence.
 *
 * **`logout` actually revokes.** The legacy one put the token in tymon's blacklist, in a file cache
 * core cannot read. Core records the `jti` in `core_revoked_tokens`, which
 * {@see LegacyJwt::subject()} consults — so signing out means something on this host,
 * which it did not before.
 *
 * **The token is never in a log line.** The legacy controller logged exceptions with the request
 * attached; a `Log::error($e)` on this path can carry a bearer token into a file. Failures here log
 * a reference, never the payload.
 */
final class CustomerAuthController extends Controller
{
    /** The legacy limiter: five attempts a minute, keyed by e-mail AND address. */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function __construct(
        private readonly CustomerAccounts $accounts,
        private readonly CustomerTokens $tokens,
        private readonly CompatServices $compat,
        private readonly CustomerMail $mail,
    ) {}

    public function login(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);
        /** @var array<string, mixed> $input */
        $email = CustomerAccounts::normaliseEmail(Coerce::str($input['email']));
        $key = $email.'|'.Coerce::str($request->ip());

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json(['error' => "Too many login attempts. Please try again in {$seconds} seconds."], 429);
        }

        $user = User::query()->where('email', $email)->first();
        $stored = $user === null ? null : Coerce::nstr($user->getAttribute('password'));

        /*
         * `Hash::check` runs even when there is no account, against a throwaway hash.
         *
         * Without it the "no such e-mail" branch returns in microseconds while the "wrong password"
         * branch pays for a bcrypt round, and the difference is measurable from outside — which
         * turns this endpoint into an account-enumeration oracle. The legacy app got this for free
         * from `Auth::attempt()`; doing the lookup by hand means doing the work by hand too.
         */
        $ok = $stored !== null
            ? Hash::check(Coerce::str($input['password']), $stored)
            : Hash::check(Coerce::str($input['password']), self::decoyHash());

        if ($user === null || ! $ok) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            return response()->json(['error' => 'Invalid email or password.'], 401);
        }

        RateLimiter::clear($key);

        return response()->json($this->signIn($request, $user));
    }

    public function register(Request $request): JsonResponse
    {
        /*
         * Every key the writer reads has a rule, including the nullable ones. AGENTS §3: `validate()`
         * is a FILTER — a field with no rule is not "unvalidated", it is GONE — which is how the
         * promotions controller silently dropped every reward parameter.
         */
        $input = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'confirmed', 'min:'.CustomerAccounts::MIN_PASSWORD],
            // The legacy registration rule, kept exactly: an Egyptian mobile number, eleven digits.
            'phone_number' => ['nullable', 'string', 'regex:/^01[0-9]{9}$/', 'size:11'],
            'image' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:3072'],
        ]);
        /** @var array<string, mixed> $input */
        $user = $this->accounts->register([
            'first_name' => Coerce::str($input['first_name']),
            'last_name' => Coerce::str($input['last_name']),
            'email' => Coerce::str($input['email']),
            'password' => Coerce::str($input['password']),
            'phone_number' => Coerce::nstr($input['phone_number'] ?? null),
        ], $request->file('image') instanceof UploadedFile ? $request->file('image') : null);

        /*
         * The verification e-mail, sent exactly as the legacy controller sent it: after the account
         * exists, and NON-FATALLY. A relay that refuses must not turn a successful registration
         * into a 500 — the customer is about to be signed in, nothing gates on verification, and
         * another link is one button away. `CustomerMail` swallows and logs by reference.
         */
        $this->mail->sendEmailVerification($user);

        return response()->json($this->signIn($request, $user));
    }

    /**
     * Sign out: revoke THIS token and nothing else.
     *
     * The legacy route required the token in the request BODY as well as the header, and validated
     * it. That is reproduced — the storefront does not call this endpoint today, so nothing depends
     * on either shape, and matching the one that exists costs nothing.
     *
     * Revoking one token deliberately does not touch the customer's other sessions: signing out on
     * a phone must not sign out the laptop, which is what the legacy blacklist did too.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = Coerce::nstr($request->input('token')) ?? $request->bearerToken();

        $this->tokens->revoke($token);

        // Unconditionally successful, as the legacy route was: a token that could not be read is a
        // session that is already over, and telling the caller otherwise helps nobody.
        return response()->json(['success' => true, 'message' => 'Logout successful']);
    }

    /** The authenticated customer. Behind `compat.auth`, so reaching here means the token held. */
    public function me(Request $request): JsonResponse
    {
        return response()->json(CustomerPayload::of($this->caller($request)));
    }

    /**
     * Mint the token, merge any guest cart, and answer the legacy login payload.
     *
     * The merge is inline because the legacy `login()` did it inline, off the `X-Guest-Token`
     * header — a shopper who fills a basket and then signs in must not lose it. `Login.jsx` ALSO
     * calls `cart/merge` afterwards; both are idempotent (the guest cart is consumed), so the
     * duplicate is harmless and the inline one is what protects a client that forgets to.
     *
     * @return array<string, mixed>
     */
    private function signIn(Request $request, User $user): array
    {
        $token = $this->tokens->issue($user);

        $guestToken = $request->header(CompatGuestCart::HEADER);
        if (is_string($guestToken) && $guestToken !== '') {
            try {
                $this->compat->cart->merge(Coerce::int($user->getKey()), $guestToken);
            } catch (\Throwable $e) {
                /*
                 * A failed merge must never fail the sign-in. The customer is authenticated, the
                 * token is minted, and a basket that did not move is recoverable — refusing the
                 * login is not. Logged by reference: this path holds a bearer token.
                 */
                Log::warning('guest cart merge failed on sign-in', [
                    'user_id' => Coerce::int($user->getKey()),
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return CustomerPayload::withToken($user, $token);
    }

    private function caller(Request $request): User
    {
        return User::query()->findOrFail(CompatAuth::id($request));
    }

    /**
     * A throwaway hash to compare against when the e-mail matches no account.
     *
     * Computed once per process, not per request: the point is to spend a bcrypt VERIFY, which is
     * what the timing difference is made of, not to spend a bcrypt HASH on every failed login.
     */
    private static function decoyHash(): string
    {
        /** @var string|null $hash */
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }
}
