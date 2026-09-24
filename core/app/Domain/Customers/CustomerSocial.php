<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Domain\Access\UserWrites;
use App\Models\User;
use App\Support\Coerce;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\User as SocialUser;

/**
 * "Sign in with Google" — who the person is, and which shop account that makes them.
 *
 * ── The five branches, and WHAT THE CUSTOMER EXPERIENCES in each ────────────────────────────
 *
 * This class is one decision expressed five ways, so the branches are listed here in the order
 * {@see self::resolve()} tries them. The customer-facing column is the point: a rule nobody can
 * describe from the shopper's side is a rule that will be changed by whoever finds it inconvenient.
 *
 * | # | Situation | What happens | What the customer sees |
 * |---|---|---|---|
 * | 1 | The provider identity is already linked here | signed into the linked account | presses the button, is signed in. Nothing else |
 * | 2 | Not linked; provider e-mail **verified** and an account already has it | the identity is attached to that account, which is also marked verified if it was not | presses the button, is signed in **to their existing account, with their orders**. This is also the LEGACY RE-LINK path: somebody who linked Google on the old host links again here without noticing |
 * | 3 | Not linked; provider e-mail **NOT verified** and an account already has it | **REFUSED** — nothing is created, nothing is attached, nothing is signed in | "Sign-in failed", then the login page. See below |
 * | 4 | Not linked; no account has that e-mail; provider says **verified** | a new password-less account, pre-verified | presses the button, is signed in to a brand-new account |
 * | 5 | Not linked; no account has that e-mail; provider **unverified** | a new password-less account, NOT verified, and core sends its own verification e-mail | signed in, and receives a "confirm your address" e-mail |
 *
 * ── Branch 3 is the unsafe one, and refusing is the whole point ─────────────────────────────
 *
 * Anybody can create an account at a provider claiming an address they do not own; what they cannot
 * do is prove it. If an UNVERIFIED claim were enough to attach, then registering
 * `victim@example.com` at any provider that does not check would hand over the victim's shop
 * account — their orders, their addresses, their saved telephone. So an unverified address that
 * matches an existing account is refused outright rather than attached, and no account is created
 * either: creating one is impossible (the address is taken) and attaching is the attack.
 *
 * **What the customer actually experiences, stated honestly.** They are redirected back to
 * `/auth/callback?error=email_unverified`, and today's storefront renders one generic
 * *"Sign-in failed. Redirecting…"* for ANY error and sends them to the login page after 1.5
 * seconds. They are not told that their address is already registered, nor that they should sign in
 * with their password — which is a poor experience and a DELIBERATE one: telling an unauthenticated
 * caller "that address has an account here" is the account-enumeration answer `login` and
 * `forgot-password` both go out of their way not to give.
 *
 * The recovery is the ordinary one and it works today: sign in with the password, or use "forgot
 * password". The error CODE is already in the URL, so when the storefront is next touched (Phase 4)
 * it can render something better with no change here — the same additive shape `updatePassword`'s
 * fresh token uses.
 *
 * ── Why the identity is keyed on the PROVIDER's id and not the e-mail ───────────────────────
 *
 * People change the address on a Google account. Keying the link on the address would disconnect
 * them when they do, and — worse — would let a second provider reporting a shared address resolve
 * to somebody else's shop account. `(provider, provider_id)` is the identity; the address is only
 * ever used to FIND an account to attach to, once, under the verification rule above.
 */
final class CustomerSocial
{
    public const TABLE = 'core_social_identities';

    /** The reason {@see UserWrites} is opened with when a social login creates an account. */
    public const REGISTER = 'customer.social_register';

    /**
     * The providers this application will start a flow with.
     *
     * An allow-list, not a deny-list: the route segment is user input, and `Socialite::driver()`
     * on an unknown string is an exception rather than a refusal. The legacy controller carried the
     * same pair.
     *
     * @var list<string>
     */
    public const PROVIDERS = ['google', 'microsoft'];

    /** What `resolve()` reports when it refuses. Also the `?error=` code the storefront receives. */
    public const REFUSED_UNVERIFIED = 'email_unverified';

    public const REFUSED_NO_EMAIL = 'no_email';

    public function __construct(private readonly CustomerMail $mail) {}

    public static function supports(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true);
    }

    /**
     * Is this provider actually configured? Asked BEFORE a redirect is offered.
     *
     * Without it the customer leaves the shop, authenticates at Google, comes back, and only then
     * discovers the shop cannot finish — having handed a third party their consent for nothing.
     * Refusing at the door costs them one click and no trust.
     */
    public static function configured(string $provider): bool
    {
        if (! self::supports($provider)) {
            return false;
        }

        foreach (['client_id', 'client_secret', 'redirect'] as $key) {
            if (Coerce::nstr(config("services.{$provider}.{$key}")) === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Turn a provider's answer into a signed-in shop account, or a refusal code.
     *
     * @return array{user: User, created: bool}|array{refused: string}
     */
    public function resolve(string $provider, SocialUser $account): array
    {
        $providerId = Coerce::nstr($account->getId());
        $email = Coerce::nstr($account->getEmail());
        $email = $email === null ? null : CustomerAccounts::normaliseEmail($email);

        if ($providerId === null || $email === null) {
            // A provider that returns no address cannot be matched to anything and cannot create an
            // account either — `users.email` is required and unique. The legacy controller answered
            // the same code.
            return ['refused' => self::REFUSED_NO_EMAIL];
        }

        // ── 1. already linked ────────────────────────────────────────────────────────────────
        $linked = $this->linkedUser($provider, $providerId);
        if ($linked !== null) {
            return ['user' => $linked, 'created' => false];
        }

        $verified = self::providerVerifiedEmail($account);
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            // ── 3. the unsafe branch ─────────────────────────────────────────────────────────
            if (! $verified) {
                return ['refused' => self::REFUSED_UNVERIFIED];
            }

            // ── 2. attach to the account that already owns this verified address ─────────────
            $this->link($provider, $providerId, $existing, $email);

            /*
             * A provider that has verified the address is proof of the same thing core's own
             * verification e-mail asks for, so an account arriving this way stops being unverified.
             * `markVerified()` is idempotent and opens the door with `customer.verified`.
             */
            app(CustomerAccounts::class)->markVerified($existing);

            return ['user' => $existing, 'created' => false];
        }

        // ── 4 and 5. nobody owns this address ────────────────────────────────────────────────
        $user = $this->create($account, $email);
        $this->link($provider, $providerId, $user, $email);

        if ($verified) {
            /*
             * Marked rather than stamped at birth, at the cost of one UPDATE on a row milliseconds
             * old. {@see CustomerAccounts::markVerified()} is the ONE place an address becomes
             * verified, and guest-order attachment hangs off it — an account pre-stamped inside
             * `create()` would be verified without ever passing that hook, and its owner's past
             * guest orders would never find them.
             */
            app(CustomerAccounts::class)->markVerified($user);
        }

        if (! $verified) {
            /*
             * Created, signed in, and asked to confirm. The alternative — refusing — would lock a
             * customer out of a shop for a property of their PROVIDER that they cannot see or fix,
             * and nothing in this application gates on verification anyway. What core will not do
             * is take the provider's word for it and stamp the column.
             */
            $this->mail->sendEmailVerification($user);
        }

        return ['user' => $user, 'created' => true];
    }

    private function linkedUser(string $provider, string $providerId): ?User
    {
        $userId = Coerce::nint(
            DB::table(self::TABLE)->where('provider', $provider)->where('provider_id', $providerId)->value('user_id')
        );

        return $userId === null ? null : User::query()->find($userId);
    }

    private function link(string $provider, string $providerId, User $user, string $email): void
    {
        $now = Carbon::now()->toDateTimeString();

        /*
         * `insertOrIgnore`, for the reason `revoke()` learned the hard way: two tabs, a double
         * click or a retried callback must be one row and not a 1062 the customer sees as a 500.
         * The unique key is `(provider, provider_id)`, so a second attempt is a no-op.
         */
        DB::table(self::TABLE)->insertOrIgnore([
            'provider' => $provider,
            'provider_id' => $providerId,
            'user_id' => Coerce::int($user->getKey()),
            'linked_email' => $email,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * A password-less account.
     *
     * `password` stays NULL, which is what `has_password: false` reports and what lets the account
     * screen offer "Set your password" rather than "Change password" — the only route such a
     * customer has to ever having one.
     */
    private function create(SocialUser $account, string $email): User
    {
        [$first, $last] = self::splitName(Coerce::nstr($account->getName()), $email);

        return UserWrites::open(self::REGISTER, function () use ($email, $first, $last): User {
            $user = new User;
            $user->forceFill([
                'first_name' => $first,
                'last_name' => $last,
                'email' => $email,
                'password' => null,
                'type' => CustomerAccounts::CREATED_TYPE,
            ]);
            $user->save();

            return $user;
        });
    }

    /**
     * Did the PROVIDER say this address is verified?
     *
     * Google puts `email_verified` in the userinfo payload Socialite exposes as `$user->user`.
     * A provider that does not report it is treated as NOT verified, which is the safe direction:
     * the unverified branches either refuse (an address somebody already owns) or create an account
     * and ask core's own question (an address nobody owns). Neither of those can hand over an
     * account, and assuming `true` for silence is exactly how branch 3 would be bypassed.
     */
    public static function providerVerifiedEmail(SocialUser $account): bool
    {
        $raw = $account->user ?? null;
        if (! is_array($raw)) {
            return false;
        }

        $flag = $raw['email_verified'] ?? $raw['verified_email'] ?? null;

        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
    }

    /**
     * Split a provider display name into first and last, falling back to the address local part.
     *
     * Ported from the legacy `SocialAuthController::splitName()`, which had the same job and the
     * same fallback: `users.first_name` and `last_name` are both NOT NULL, so a provider that
     * returns no name at all still has to produce two strings.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitName(?string $name, string $email): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            $name = explode('@', $email)[0];
        }

        $parts = preg_split('/\s+/u', $name) ?: [];
        $first = array_shift($parts);
        $first = is_string($first) && $first !== '' ? $first : 'User';

        return [$first, $parts === [] ? '' : implode(' ', $parts)];
    }
}
