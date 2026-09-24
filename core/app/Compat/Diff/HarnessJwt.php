<?php

namespace App\Compat\Diff;

use App\Domain\Customers\CustomerTokens;

/**
 * Mints a customer JWT for the harness, so the authenticated compat paths can be diffed against
 * the legacy host that issues the real ones.
 *
 * ── This is now a FORWARDER, and that is the point (2026-09-21) ──────────────────────────────
 *
 * It used to build the token itself: its own header, its own claim array, its own HMAC. That was
 * correct when core could not issue a token in any request path — the scaffolding was the only
 * minter, so there was nothing for it to disagree with. Phase 1 changed that, and two minters for
 * one token format is the shape this project removes on sight.
 *
 * It had already drifted, harmlessly and instructively: it emitted `sub` as a STRING, where tymon
 * emits whatever `getJWTIdentifier()` returns — the integer primary key. Both applications coerce,
 * so no test and no diff ever failed. A difference that nothing catches is not a difference that
 * stays small.
 *
 * So the claim set lives in {@see CustomerTokens::mint()} and this keeps only what is genuinely the
 * HARNESS's: a short TTL, because a harness token should not outlive the run, and an `iss` naming
 * the legacy host it is impersonating.
 *
 * Returns null when no secret is configured, so an unconfigured environment SKIPS the authenticated
 * cases rather than comparing two 401s and calling that a pass.
 */
final class HarnessJwt
{
    /** @param  int  $ttlMinutes  short by design: a harness token should not outlive the run */
    public static function mint(int $userId, int $ttlMinutes = 60): ?string
    {
        return CustomerTokens::mint($userId, $ttlMinutes, self::issuer());
    }

    /** An expired token, for the 401 case. */
    public static function expired(int $userId): ?string
    {
        return self::mint($userId, -120);
    }

    /** The legacy host's own login URL — what a real token of this shape would carry. */
    private static function issuer(): string
    {
        return rtrim(config()->string('compat.legacy_base'), '/').'/api/login';
    }
}
