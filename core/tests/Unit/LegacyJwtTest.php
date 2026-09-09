<?php

use App\Compat\Diff\HarnessJwt;
use App\Support\LegacyJwt;

/*
 * Core VERIFIES the legacy application's tokens and never issues one. That makes this class the
 * whole authentication boundary of the wave-3 compat surface, so every way a token can be wrong
 * is pinned here: wrong signature, wrong algorithm, expired, not yet valid, missing a required
 * claim, structurally broken, and the `alg: none` downgrade.
 */

beforeEach(function () {
    config(['compat.jwt_secret' => 'unit-test-secret', 'compat.jwt_algo' => 'HS256', 'compat.jwt_leeway' => 0, 'compat.legacy_base' => 'http://legacy.test']);
});

/** @param  callable(array<array-key, mixed>): array<array-key, mixed>  $mutate */
function tamper(string $token, callable $mutate): string
{
    [$header, $payload, $signature] = explode('.', $token);
    $claims = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
    $claims = $mutate(is_array($claims) ? $claims : []);
    $payload = rtrim(strtr(base64_encode((string) json_encode($claims)), '+/', '-_'), '=');

    return $header.'.'.$payload.'.'.$signature;      // signature no longer matches, on purpose
}

/** @param  array<string, mixed>  $claims */
function resign(array $claims, string $secret = 'unit-test-secret', string $alg = 'HS256'): string
{
    $encode = fn (array $v): string => rtrim(strtr(base64_encode((string) json_encode($v)), '+/', '-_'), '=');
    $header = $encode(['typ' => 'JWT', 'alg' => $alg]);
    $payload = $encode($claims);
    $hash = ['HS256' => 'sha256', 'HS384' => 'sha384', 'HS512' => 'sha512'][$alg] ?? 'sha256';

    return $header.'.'.$payload.'.'.rtrim(strtr(base64_encode(hash_hmac($hash, $header.'.'.$payload, $secret, true)), '+/', '-_'), '=');
}

/** @return array<string, mixed> */
function validClaims(int $sub = 42): array
{
    $now = time();

    return ['iss' => 'http://legacy.test/api/login', 'iat' => $now, 'exp' => $now + 3600, 'nbf' => $now, 'jti' => 'abc123', 'sub' => (string) $sub, 'prv' => sha1('App\Models\User')];
}

it('accepts a well-formed token and returns its subject', function () {
    expect(LegacyJwt::subject(HarnessJwt::mint(42)))->toBe(42);
});

it('rejects a token signed with a different secret', function () {
    expect(LegacyJwt::subject(resign(validClaims(), 'a-different-secret')))->toBeNull();
});

it('rejects a tampered payload', function () {
    $token = tamper(HarnessJwt::mint(42) ?? '', fn (array $c): array => ['sub' => '1'] + $c);

    expect(LegacyJwt::subject($token))->toBeNull();
});

it('rejects an expired token and one that is not yet valid', function () {
    $now = time();

    expect(LegacyJwt::subject(resign(['exp' => $now - 1] + validClaims())))->toBeNull()
        ->and(LegacyJwt::subject(resign(['nbf' => $now + 600] + validClaims())))->toBeNull();
});

it('honours the configured leeway on both edges', function () {
    $now = time();
    config(['compat.jwt_leeway' => 120]);

    expect(LegacyJwt::subject(resign(['exp' => $now - 60] + validClaims())))->toBe(42)
        ->and(LegacyJwt::subject(resign(['nbf' => $now + 60] + validClaims())))->toBe(42)
        ->and(LegacyJwt::subject(resign(['exp' => $now - 300] + validClaims())))->toBeNull();
});

it('rejects a token missing any claim the legacy config requires', function (string $claim) {
    $claims = validClaims();
    unset($claims[$claim]);

    expect(LegacyJwt::subject(resign($claims)))->toBeNull();
})->with(['iss', 'iat', 'exp', 'nbf', 'sub', 'jti']);

it('rejects the alg:none downgrade and a mismatched algorithm', function () {
    $encode = fn (array $v): string => rtrim(strtr(base64_encode((string) json_encode($v)), '+/', '-_'), '=');
    $none = $encode(['typ' => 'JWT', 'alg' => 'none']).'.'.$encode(validClaims()).'.';

    expect(LegacyJwt::subject($none))->toBeNull()
        // Signed correctly, but with an algorithm the configuration does not name.
        ->and(LegacyJwt::subject(resign(validClaims(), 'unit-test-secret', 'HS512')))->toBeNull();
});

it('rejects structurally broken input without throwing', function (?string $token) {
    expect(LegacyJwt::subject($token))->toBeNull();
})->with([null, '', 'not-a-token', 'a.b', 'a.b.c.d', '...', 'ïïï.ïïï.ïïï']);

it('rejects every token when no secret is configured, rather than accepting any', function () {
    $token = HarnessJwt::mint(42);
    config(['compat.jwt_secret' => null]);

    expect(LegacyJwt::subject($token))->toBeNull();
});

it('rejects a subject that is not a positive integer', function (mixed $sub) {
    expect(LegacyJwt::subject(resign(['sub' => $sub] + validClaims())))->toBeNull();
})->with([['0'], ['-3'], ['not-a-number'], ['']]);
