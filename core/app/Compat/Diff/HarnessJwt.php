<?php

namespace App\Compat\Diff;

/**
 * Mints a legacy-shaped JWT for the harness, so the authenticated compat paths can be diffed
 * against the legacy host that issues the real ones.
 *
 * This is TEST SCAFFOLDING, and it is deliberately narrow: it exists only in the diff harness,
 * it signs with the same `JWT_SECRET` both hosts already share, and core still has no way to
 * ISSUE a token in any request path — `login`, `register`, `logout` and `auth/*` stay proxied to
 * the legacy host for the whole compat period. Returns null when no secret is configured, so an
 * unconfigured environment simply skips the authenticated cases instead of comparing two 401s
 * and calling that a pass.
 *
 * The claim set is tymon/jwt-auth v2's: iss, iat, exp, nbf, sub, jti, prv. `prv` is
 * `sha1(App\Models\User)`, which is what the legacy `JWT::checkSubjectModel()` compares against
 * (a missing `prv` is accepted there, but a wrong one is not, so it is emitted correctly).
 */
final class HarnessJwt
{
    private const SUBJECT_MODEL = 'App\Models\User';

    /** @param  int  $ttlMinutes  short by design: a harness token should not outlive the run */
    public static function mint(int $userId, int $ttlMinutes = 60): ?string
    {
        $secret = config('compat.jwt_secret');
        if (! is_string($secret) || $secret === '') {
            return null;
        }
        $algo = config('compat.jwt_algo');
        $hash = match (is_string($algo) ? $algo : 'HS256') {
            'HS384' => 'sha384',
            'HS512' => 'sha512',
            default => 'sha256',
        };
        $now = time();

        $header = self::segment(['typ' => 'JWT', 'alg' => is_string($algo) ? $algo : 'HS256']);
        $payload = self::segment([
            'iss' => rtrim(config()->string('compat.legacy_base'), '/').'/api/login',
            'iat' => $now,
            'exp' => $now + ($ttlMinutes * 60),
            'nbf' => $now,
            'jti' => bin2hex(random_bytes(8)),
            'sub' => (string) $userId,
            'prv' => sha1(self::SUBJECT_MODEL),
        ]);
        $signature = self::base64Url(hash_hmac($hash, $header.'.'.$payload, $secret, true));

        return $header.'.'.$payload.'.'.$signature;
    }

    /** An expired token, for the 401 case. */
    public static function expired(int $userId): ?string
    {
        return self::mint($userId, -120);
    }

    /** @param  array<string, mixed>  $claims */
    private static function segment(array $claims): string
    {
        return self::base64Url((string) json_encode($claims, JSON_UNESCAPED_SLASHES));
    }

    private static function base64Url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
