<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Validates the JWT the legacy application issues (tymon/jwt-auth v2, HS256), using the shared
 * `JWT_SECRET`. Token ISSUANCE stays on the legacy host for the whole compat period — `login`,
 * `register`, `logout` and `auth/*` are proxied — so core only ever needs to VERIFY.
 *
 * Deliberately hand-rolled rather than pulling tymon into core: core needs six lines of HMAC and
 * a claim check, not a guard, a provider, a blacklist and a refresh flow it must never use.
 *
 * What is verified: the `alg` header is the configured one, the signature matches, `exp` and
 * `nbf` hold against the clock, and every claim the legacy config lists as required is present.
 *
 * What is NOT verified, and why it is a flagged deviation:
 *  • the legacy **blacklist** (a token invalidated by `logout`) lives in the legacy app's file
 *    cache, which core cannot read. Until auth moves to core or the two share a cache, a token
 *    invalidated on the legacy host stays valid here until it expires. `logout` itself is
 *    proxied, so the blacklist is still written — it is only unreadable from this side.
 *  • the `prv` claim (provider hash). One application issues these tokens, so there is no second
 *    provider it could disambiguate.
 */
final class LegacyJwt
{
    /** Claims tymon lists in `jwt.required_claims`. */
    private const REQUIRED = ['iss', 'iat', 'exp', 'nbf', 'sub', 'jti'];

    /**
     * The `sub` of a valid bearer token, or null for a missing, malformed, expired or
     * wrongly-signed one. Never throws: the callers all treat "no valid token" as "guest".
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

        return $id > 0 ? $id : null;
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
        $now = time();
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
