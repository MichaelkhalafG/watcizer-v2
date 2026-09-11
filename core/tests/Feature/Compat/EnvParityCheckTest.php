<?php

use App\Compat\Diff\HarnessJwt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

/*
 * `compat:env-parity` is the switch-night smoke check for prerequisite (f) (study §3.4.1): core
 * must carry the legacy app's real `PUBLIC_API_KEY` and `JWT_SECRET`, or the flip rejects every
 * storefront request and logs out every signed-in customer.
 *
 * A check that reports READY when it is not is worse than no check, so all four of its outcomes
 * are exercised here — including the two that must NOT be a pass:
 *
 *   • the legacy host answers 401 to core's key            → NOT READY
 *   • the tested path is not guarded at all (200 without)  → INCONCLUSIVE, not a pass
 *
 * The HTTP side is faked rather than aimed at a booted legacy instance on purpose: `backend/.env`
 * holds PRODUCTION database credentials, and booting that application to answer two GETs is not a
 * risk worth taking for a test (AGENTS §3). What the fake cannot prove — that the legacy
 * middleware really answers 401 to a wrong key — was established against the running legacy app
 * earlier in this wave, and is recorded in §3.12.7 flag 2.
 */

/**
 * `Pest\Laravelrtisan()` returns `PendingCommand|int`, so the narrowing is explicit rather than
 * a chain PHPStan has to guess at — the same helper shape `TransformCommandTest` uses.
 *
 * @param  array<string, mixed>  $args
 */
function parity(array $args = []): PendingCommand
{
    $pending = artisan('compat:env-parity', $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

beforeEach(function (): void {
    config()->set('compat.legacy_base', 'http://legacy.test');
    config()->set('compat.api_key', 'the-real-public-api-key');
    config()->set('compat.jwt_secret', str_repeat('s', 64));
    config()->set('compat.jwt_algo', 'HS256');
});

it('passes only when the key is accepted AND the unguarded call is refused', function () {
    Http::fake(function (Request $request) {
        return $request->hasHeader('Api-Code', 'the-real-public-api-key')
            ? Http::response(['ok' => true], 200)
            : Http::response(['message' => 'Unauthorized'], 401);
    });

    parity()
        ->expectsOutputToContain('[key] OK')
        ->assertExitCode(0);
});

it('FAILS when the legacy host rejects the key core is configured with', function () {
    // The whole reason the prerequisite exists: on this workstation core's key is 13 characters
    // and the legacy app's is 64, and `CheckApiMiddleware` runs before anything else.
    Http::fake(['*' => Http::response(['message' => 'Unauthorized'], 401)]);

    parity()
        ->expectsOutputToContain('[key] MISMATCH')
        ->assertExitCode(1);
});

it('refuses to call an UNGUARDED path a pass', function () {
    // Both calls succeed, which means the path proves nothing about the key. Reporting READY here
    // would be the single most dangerous thing this command could do on switch night.
    Http::fake(['*' => Http::response(['ok' => true], 200)]);

    parity()
        ->expectsOutputToContain('[key] INCONCLUSIVE')
        ->assertExitCode(1);
});

it('FAILS when the legacy host cannot be reached, rather than staying silent', function () {
    Http::fake(function () {
        throw new ConnectionException('connection refused');
    });

    parity()
        ->expectsOutputToContain('nothing was proved')
        ->assertExitCode(1);
});

it('verifies a token signed with the shared secret, and rejects one that is not', function () {
    Http::fake(function (Request $request) {
        return $request->hasHeader('Api-Code', 'the-real-public-api-key')
            ? Http::response(['ok' => true], 200)
            : Http::response([], 401);
    });

    // A token the legacy host would have minted: same secret, same claim set.
    $good = HarnessJwt::mint(1);
    expect($good)->toBeString();

    parity(['--token' => $good])
        ->expectsOutputToContain('[jwt] OK')
        ->assertExitCode(0);

    // …and one minted under a DIFFERENT secret: the state the two hosts are in today.
    config()->set('compat.jwt_secret', str_repeat('x', 64));
    $foreign = HarnessJwt::mint(1);
    config()->set('compat.jwt_secret', str_repeat('s', 64));

    parity(['--token' => $foreign])
        ->expectsOutputToContain('[jwt] MISMATCH')
        ->assertExitCode(1);
});

it('does not claim the JWT half when no token was supplied', function () {
    Http::fake(function (Request $request) {
        return $request->hasHeader('Api-Code', 'the-real-public-api-key')
            ? Http::response(['ok' => true], 200)
            : Http::response([], 401);
    });

    parity()
        ->expectsOutputToContain('[jwt] NOT CHECKED')
        ->assertExitCode(0);
});
