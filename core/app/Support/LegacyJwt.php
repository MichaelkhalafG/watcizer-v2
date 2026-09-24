<?php

namespace App\Support;

use App\Domain\Customers\CustomerTokens;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Verifies a Watchizer customer token (tymon/jwt-auth v2 shape, HS256) against the shared
 * `JWT_SECRET`.
 *
 * Until 2026-09-21 issuance was the legacy host's alone — `login`, `register`, `logout` and
 * `auth/*` were proxied — and this class only ever VERIFIED. Phase 1 of the storefront migration
 * moved issuance into core ({@see CustomerTokens}), which mints the SAME claim set for the same
 * secret, so this class verifies both origins without knowing which it is holding. That is the
 * point: it is what makes Phase 2 a flip rather than a second migration.
 *
 * Deliberately hand-rolled rather than pulling tymon into core: core needs six lines of HMAC and
 * a claim check, not a guard, a provider and a refresh flow it must never use.
 *
 * What is verified: the `alg` header is the configured one, the signature matches, `exp` and
 * `nbf` hold against the clock, and every claim the legacy config lists as required is present.
 *
 * What is NOT verified, and why it is a flagged deviation:
 *  • the legacy **blacklist** — CLOSED on the core side 2026-09-21 (Phase 1, piece 2). Core now
 *    ISSUES these tokens ({@see CustomerTokens}) and records revocations in `core_revoked_tokens`,
 *    which {@see self::subject()} consults. What remains outside core's reach is the LEGACY app's
 *    own file-cache blacklist: a token invalidated over there is still valid here until it
 *    expires. That gap shuts when the storefront stops calling the legacy host at all (Phase 2),
 *    and until then it can only widen if somebody signs out on the old site.
 *  • the `prv` claim (provider hash). One application issues these tokens, so there is no second
 *    provider it could disambiguate.
 */
final class LegacyJwt
{
    /** Claims tymon lists in `jwt.required_claims`. */
    private const REQUIRED = ['iss', 'iat', 'exp', 'nbf', 'sub', 'jti'];

    /**
     * The `sub` of a valid bearer token, or null for a missing, malformed, expired, wrongly-signed
     * or REVOKED one. Never throws: the callers all treat "no valid token" as "guest".
     *
     * ── Why the revocation check is HERE and not in each caller ─────────────────────────────
     *
     * This is the one funnel every reader goes through: `userId()` calls it, `CompatAuth` calls
     * `userId()`, and `CompatGuestCart` calls this directly — which is exactly the caller a check
     * added "at the auth middleware" would have missed, handing a signed-out customer their own
     * cart back. {@see CustomerTokens::revoked()} is one primary-key lookup.
     *
     * It is deliberately NOT in {@see self::claims()}: that method answers "is this token
     * well-formed and correctly signed", it is pure, and its unit tests depend on that. Revocation
     * is a fact about the world, and it belongs at the boundary where the world is consulted.
     */
    public static function subject(?string $token): ?int
    {
        $claims = self::claims($token);
        if ($claims === null) {
            return null;
        }
        $sub = $claims['sub'] ?? null;
        if (! is_scalar($sub)) {
            return null;
        }
        $id = (int) $sub;
        if ($id <= 0) {
            return null;
        }

        $jti = $claims['jti'] ?? null;
        if (is_string($jti) && CustomerTokens::revoked($jti)) {
            return null;
        }

        /*
         * ── "Log out everywhere": the per-customer epoch (M1v, 2026-09-22) ───────────────────
         *
         * Revocation answers "was THIS token signed out". The epoch answers "were ALL of this
         * customer's tokens invalidated", which is the question a password reset asks — and it has
         * to be a separate mechanism because core has never seen most of the tokens it needs to
         * kill. A `jti` is recorded only when somebody signs out, so the stolen phone, the laptop
         * left at work and the session an attacker opened are all absent from that table.
         *
         * STRICT `<`, and the one-second window is deliberate: `iat` is whole seconds, so a token
         * minted in the same second as the epoch survives it. A non-strict comparison would refuse
         * the REPLACEMENT token issued microseconds after a password change along with the old
         * ones, which would make the feature unusable to fix a bounded exposure against somebody
         * who has already been holding a token for hours.
         */
        $epoch = CustomerTokens::epochFor($id);
        $issued = $claims['iat'] ?? null;
        if ($epoch !== null && is_numeric($issued) && (int) $issued < $epoch) {
            return null;
        }

        return $id;
    }

    /**
     * The authenticated user id for a request: a valid token whose subject is a row that still
     * exists in `users`. The legacy `auth('api')->id()` resolves the user through the Eloquent
     * provider, so a token for a deleted account resolves to null there too.
     */
    public static function userId(Request $request): ?int
    {
        $sub = self::subject($request->bearerToken());
        if ($sub === null) {
            return null;
        }

        return DB::table('users')->where('id', $sub)->exists() ? $sub : null;
    }

    /**
     * Decoded, verified claims — or null. Public so a test can assert on a specific claim.
     *
     * @return array<string, mixed>|null
     */
    public static function claims(?string $token): ?array
    {
        $secret = config('compat.jwt_secret');
        if (! is_string($secret) || $secret === '' || ! is_string($token) || $token === '') {
            return null;
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = self::decodeSegment($headerB64);
        $payload = self::decodeSegment($payloadB64);
        $signature = self::base64UrlDecode($signatureB64);
        if ($header === null || $payload === null || $signature === null) {
            return null;
        }

        $algo = config('compat.jwt_algo');
        $algo = is_string($algo) ? $algo : 'HS256';
        $hash = match ($algo) {
            'HS256' => 'sha256',
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => null,
        };
        if ($hash === null || ($header['alg'] ?? null) !== $algo) {
            return null;
        }

        $expected = hash_hmac($hash, $headerB64.'.'.$payloadB64, $secret, true);
        if (! hash_equals($expected, $signature)) {
            return null;
        }

        foreach (self::REQUIRED as $claim) {
            if (! array_key_exists($claim, $payload)) {
                return null;
            }
        }

        $leeway = config('compat.jwt_leeway');
        $leeway = is_numeric($leeway) ? (int) $leeway : 0;
        /*
         * `Carbon::now()`, not `time()` — ONE clock for this seam (2026-09-22).
         *
         * {@see CustomerTokens::mint()} stamps `iat`, `nbf` and `exp` from `Carbon::now()`. This
         * method read the raw `time()`, so the issuer and the verifier ran on different clocks the
         * moment anything mocked the application's. In production they are the same number and this
         * changes nothing; under a mocked clock they disagreed, and the visible symptom was a token
         * minted two seconds ahead being refused as not-yet-valid by a verifier still in the past.
         *
         * That found itself in a test, which is the cheap place. The expensive version of the same
         * inconsistency is a host whose PHP timezone or clock handling differs from the framework's.
         */
        $now = Carbon::now()->getTimestamp();
        $exp = $payload['exp'];
        $nbf = $payload['nbf'];
        if (! is_numeric($exp) || $now >= ((int) $exp + $leeway)) {
            return null;
        }
        if (is_numeric($nbf) && $now < ((int) $nbf - $leeway)) {
            return null;
        }

        return $payload;
    }

    /** @return array<string, mixed>|null */
    private static function decodeSegment(string $segment): ?array
    {
        $json = self::base64UrlDecode($segment);
        if ($json === null) {
            return null;
        }
        /** @var mixed $decoded */
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $padded = strtr($value, '-_', '+/');
        $remainder = strlen($padded) % 4;
        if ($remainder > 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
