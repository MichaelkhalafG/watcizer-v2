<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use App\Compat\Diff\HarnessJwt;
use App\Models\User;
use App\Support\Coerce;
use App\Support\LegacyJwt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Core ISSUES the customer's token now (storefront Phase 1, piece 2, 2026-09-21).
 *
 * ── The one hard requirement, and why it drives every choice below ──────────────────────────
 *
 * *"Issued tokens must be accepted by everything that verifies them today, so Phase 2 is a flip,
 * not a second migration."* (developer, 2026-09-21.) Two things verify a Watchizer token today:
 * {@see LegacyJwt} on this host, and `tymon/jwt-auth` v2 on the legacy host. So a token minted here
 * is not "core's own format" — it is **the same token**, and the shape is copied from tymon rather
 * than invented:
 *
 *   | claim | tymon                                   | here                                     |
 *   |-------|-----------------------------------------|------------------------------------------|
 *   | `iss` | `$request->url()`                       | the same — the URL of the issuing request |
 *   | `iat` | now                                     | the same                                  |
 *   | `exp` | now + `jwt.ttl` minutes (43 200 = 30 d) | the same, from `compat.jwt_ttl`           |
 *   | `nbf` | now                                     | the same                                  |
 *   | `jti` | `Str::random()` (16 chars)              | the same                                  |
 *   | `sub` | `$user->getKey()` — an INT              | the same, and an int, not a string        |
 *   | `prv` | `sha1(get_class($subject))`             | the same VALUE: both apps call the class  |
 *   |       |                                         | `App\Models\User`, so the hash matches    |
 *
 * `prv` is the one worth spelling out. The legacy config sets `lock_subject => true`, so its tokens
 * carry `prv` and its guard checks it. Core's user model has the same fully-qualified name, so
 * `sha1('App\Models\User')` is the same forty hex characters on both sides and a token minted here
 * satisfies that check over there. Emitting it costs nothing and removes a question that would
 * otherwise only be answered by a 401 in production.
 *
 * `LegacyJwt` does not require `prv` (one application issued these tokens, so there was no second
 * provider to disambiguate). It is emitted for the OTHER verifier, not for this one.
 *
 * ── Revocation, which the legacy app had and core did not ───────────────────────────────────
 *
 * `logout` on the legacy host put the token in tymon's blacklist, in that app's file cache.
 * `LegacyJwt`'s docblock flagged the consequence as a deviation: core could not read that cache, so
 * a token invalidated over there stayed valid here until it expired. Core now issues the tokens, so
 * core owns the other half — and without it `logout` would return 200 and revoke nothing, which is
 * a regression against the behaviour being replaced rather than a missing extra.
 *
 * The record is a TABLE, not the cache (M1t explains why: `cache:clear` is a routine deploy step
 * and would un-revoke everybody). {@see LegacyJwt::subject()} consults it, so every caller is
 * covered by construction — including `CompatGuestCart`, which reads the subject directly and would
 * otherwise have handed a signed-out customer their own cart back.
 *
 * ── What this class deliberately does NOT do ────────────────────────────────────────────────
 *
 * **No refresh.** tymon has one; nothing in this project has ever called it, the storefront has no
 * refresh path, and a thirty-day token that the customer re-earns by signing in is the behaviour
 * being replaced. Adding a refresh flow is a decision about sessions, not a detail of issuance.
 *
 * **No "log out everywhere".** That would mean revoking tokens this table has never seen — the
 * ones minted before a `jti` was recorded — which cannot be done by `jti` at all. It needs a
 * per-user epoch column and it is a feature, not a fix.
 */
final class CustomerTokens
{
    /** The revocation record (M1t). */
    public const TABLE = 'core_revoked_tokens';

    /** The per-customer "log out everywhere" epoch (M1v). */
    public const EPOCH_TABLE = 'core_user_token_epochs';

    /** tymon's `Str::random()` default, copied so the identifiers are the same shape. */
    public const JTI_LENGTH = 16;

    /**
     * What `prv` hashes. Written as a STRING rather than `User::class` on purpose: the value has to
     * be the class name the LEGACY application hashes, and the two only coincide because both apps
     * happen to call their model `App\Models\User`. Spelling it out means renaming core's class
     * cannot silently invalidate every token on the other host.
     */
    private const SUBJECT_MODEL = 'App\Models\User';

    /**
     * Mint a token for this account.
     *
     * @throws RuntimeException when no signing secret is configured — never a silent unsigned token
     */
    public function issue(User $user, ?string $issuer = null): string
    {
        $token = self::mint(
            Coerce::int($user->getKey()),
            max(1, Coerce::int(config('compat.jwt_ttl'), 43200)),
            $issuer,
        );

        if ($token === null) {
            /*
             * Loud rather than lenient, and this is the one place in the seam where that is right.
             * `LegacyJwt` treats a missing secret as "every token is invalid", which is the safe
             * direction for a VERIFIER. For an ISSUER the safe direction is the opposite: a token
             * signed with an empty string would be accepted by anybody who guessed that, so minting
             * one is worse than refusing to sign in.
             */
            throw new RuntimeException(
                'No JWT secret is configured, so core cannot issue a customer token. Set JWT_SECRET '
                .'— it is the same shared secret compat.jwt_secret verifies with.'
            );
        }

        return $token;
    }

    /**
     * The ONE place a Watchizer customer token is constructed — by id, by minutes, and returning
     * null rather than throwing when there is no secret.
     *
     * Public for the diff harness ({@see HarnessJwt}), which needs a short-lived
     * token for an arbitrary user id and an issuer naming the legacy host. It used to build its
     * own, and had drifted one claim: it emitted `sub` as a STRING, where tymon emits whatever
     * `getJWTIdentifier()` returns — the integer primary key. Both hosts coerce, so nothing broke,
     * which is exactly why a second construction site is worth removing before it drifts somewhere
     * that does break.
     *
     * A NEGATIVE `$ttlMinutes` mints an already-expired token. That is the harness's 401 case and
     * it is deliberate: an expired token has to be MADE, not waited for.
     */
    public static function mint(int $userId, int $ttlMinutes, ?string $issuer = null): ?string
    {
        $secret = Coerce::nstr(config('compat.jwt_secret'));
        if ($secret === null) {
            return null;
        }

        $now = Carbon::now()->getTimestamp();

        return self::sign([
            'iss' => $issuer ?? Coerce::str(request()->url(), config()->string('app.url')),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + ($ttlMinutes * 60),
            'jti' => Str::random(self::JTI_LENGTH),
            'sub' => $userId,
            'prv' => sha1(self::SUBJECT_MODEL),
        ], $secret);
    }

    /**
     * Revoke a token by its own `jti`, until the moment it would have expired anyway.
     *
     * Returns false for a token that cannot be read — a malformed string, a wrong signature, one
     * already expired. Those are not errors: there is nothing to revoke, and reporting them as
     * failures would make a double-click on "sign out" look broken.
     */
    public function revoke(?string $token): bool
    {
        $claims = LegacyJwt::claims($token);
        if ($claims === null) {
            return false;
        }

        $jti = Coerce::nstr($claims['jti'] ?? null);
        $exp = Coerce::nint($claims['exp'] ?? null);
        $sub = Coerce::nint($claims['sub'] ?? null);
        if ($jti === null || $exp === null || $sub === null) {
            return false;
        }

        /*
         * `insertOrIgnore`, and the choice is load-bearing rather than stylistic.
         *
         * Signing out twice — two tabs, a double click, a retried request — must be one row and not
         * a 1062 the customer sees as a 500. The obvious `upsert()` is WRONG here and was written
         * first: Laravel builds `ON DUPLICATE KEY UPDATE` from the update column list, and an EMPTY
         * list (there is nothing to update — the row already says everything it will ever say)
         * makes it fall back to a plain `insert`, which throws on the second call. A test caught it.
         *
         * Ignoring the duplicate also keeps the FIRST `revoked_at`, which is the correct answer to
         * the audit question: the moment the token stopped being usable is the earlier one.
         */
        DB::table(self::TABLE)->insertOrIgnore([
            'jti' => $jti,
            'user_id' => $sub,
            'expires_at' => Carbon::createFromTimestamp($exp)->toDateTimeString(),
            'revoked_at' => Carbon::now()->toDateTimeString(),
        ]);

        return true;
    }

    /** Has this `jti` been revoked? One primary-key lookup. */
    public static function revoked(string $jti): bool
    {
        return DB::table(self::TABLE)->where('jti', $jti)->exists();
    }

    /**
     * Invalidate EVERY token this customer holds — "log out everywhere" (M1v, 2026-09-22).
     *
     * ── Why this exists, in the developer's words ───────────────────────────────────────────
     *
     * *"A password reset — and a password change while signed in — must invalidate every token the
     * customer already holds. The reset flow exists for exactly the case where someone else has the
     * account; leaving the thief's token valid for thirty days defeats it."*
     *
     * ── Why it is an EPOCH and not a sweep of `core_revoked_tokens` ─────────────────────────
     *
     * Revoking one by one is impossible: core has never seen most of the customer's tokens. A `jti`
     * is recorded only when somebody signs out, so the phone that was stolen, the laptop left at
     * work and the session an attacker opened are all absent from that table. An epoch needs to
     * know nothing about them — it moves the line every token is measured against.
     *
     * The comparison is STRICT (`iat < not_before`), which leaves a one-second window: `iat` is
     * whole seconds, so a token minted in the same second as the epoch survives. That is the right
     * trade rather than an oversight — a non-strict comparison would refuse the REPLACEMENT token
     * issued microseconds later along with the old ones, and the exposure is one second against an
     * attacker who has been holding a token for hours.
     */
    public function invalidateAllFor(User $user, string $reason): void
    {
        $now = Carbon::now()->toDateTimeString();

        DB::table(self::EPOCH_TABLE)->upsert(
            [[
                'user_id' => Coerce::int($user->getKey()),
                'not_before' => $now,
                'reason' => $reason,
                'updated_at' => $now,
            ]],
            ['user_id'],
            // Unlike a revocation, a SECOND invalidation must move the line — the point is always
            // "nothing older than now", so the newest write is the one that counts.
            ['not_before', 'reason', 'updated_at'],
        );

        unset(self::$epochs[Coerce::int($user->getKey())]);
    }

    /**
     * The instant before which this customer's tokens are refused, as a Unix timestamp — or null
     * when they have never invalidated anything, which is almost everybody.
     *
     * Memoised per PROCESS-and-request because {@see LegacyJwt::subject()} is called twice on the
     * cart path: `CompatGuestCart` reads the subject to decide whose cart this is, and `CompatAuth`
     * reads it again through `userId()`. Without the memo that is four index lookups per request on
     * the hottest authenticated GET the application has, for a table that is almost always empty.
     *
     * @var array<int, int|null>
     */
    private static array $epochs = [];

    public static function epochFor(int $userId): ?int
    {
        if (array_key_exists($userId, self::$epochs)) {
            return self::$epochs[$userId];
        }

        $value = DB::table(self::EPOCH_TABLE)->where('user_id', $userId)->value('not_before');
        $epoch = Coerce::nstr($value);

        return self::$epochs[$userId] = $epoch === null ? null : Carbon::parse($epoch)->getTimestamp();
    }

    /**
     * Forget the memo. Called between requests by nothing in production — a request is a process
     * here — and by tests, which drive several "requests" inside one.
     */
    public static function forgetEpochs(): void
    {
        self::$epochs = [];
    }

    /**
     * Delete revocations for tokens that have expired anyway, and report how many.
     *
     * A revocation is only load-bearing until its token's own `exp`: after that the token is
     * refused by the clock and the row answers a question nobody can ask. Called from the schedule
     * so the table stays bounded by the TTL rather than by how often people sign out.
     */
    public static function prune(): int
    {
        return DB::table(self::TABLE)->where('expires_at', '<', Carbon::now()->toDateTimeString())->delete();
    }

    /**
     * Delete epochs that can no longer refuse anything, and report how many.
     *
     * An epoch stops doing work once every token that could have been issued before it has expired
     * anyway — one full TTL after it was set. Until then it must stay: deleting it early would
     * silently re-validate the very tokens a password reset was performed to kill, which is the
     * failure the whole feature exists to prevent.
     *
     * A day of slack on top, because the TTL is read from configuration and a shortened one must
     * not retroactively make yesterday's epochs prunable.
     */
    public static function pruneEpochs(): int
    {
        $ttl = max(1, Coerce::int(config('compat.jwt_ttl'), 43200));
        $cutoff = Carbon::now()->subMinutes($ttl)->subDay()->toDateTimeString();

        return DB::table(self::EPOCH_TABLE)->where('not_before', '<', $cutoff)->delete();
    }

    /**
     * HS256 over `base64url(header) . '.' . base64url(payload)`, the same six lines
     * {@see LegacyJwt} verifies with — deliberately hand-rolled for the same reason it is there:
     * this needs an HMAC and a claim set, not a guard, a provider, a blacklist and a refresh flow
     * it must never use.
     *
     * @param  array<string, mixed>  $claims
     */
    private static function sign(array $claims, string $secret): string
    {
        $algo = Coerce::str(config('compat.jwt_algo'), 'HS256');
        $hash = match ($algo) {
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => throw new RuntimeException("compat.jwt_algo [{$algo}] is not an HMAC algorithm core can sign with."),
        };

        $header = self::segment(['typ' => 'JWT', 'alg' => $algo]);
        $payload = self::segment($claims);
        $signature = hash_hmac($hash, $header.'.'.$payload, $secret, true);

        return $header.'.'.$payload.'.'.self::base64Url($signature);
    }

    /** @param  array<string, mixed>  $data */
    private static function segment(array $data): string
    {
        return self::base64Url((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
