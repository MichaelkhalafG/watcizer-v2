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

/*
 * ── the image bases ──────────────────────────────────────────────────────────────────────────
 *
 * The third thing this command proves. `STOREFRONT_ASSET_BASE` / `COMPAT_ASSET_BASE` /
 * `MEDIA_URL_BASE` decide the host of every rendered `<img src>` — dashboard, v2 API, compat
 * payloads and order e-mails — and they are exactly the keys a developer overrides to see LOCAL
 * files while working. That is the `ORDER_MAIL_INLINE` shape (AGENTS §3): a key edited once for a
 * local convenience and carried to production with the rest of the file, whose only symptom is
 * that every photograph in the shop is broken.
 *
 * All three outcomes are exercised, because a check that cannot fail is not a check: a development
 * value PASSES on a developer's machine, the same value FAILS on a production host, and a
 * `MEDIA_URL_BASE` that does not follow `STOREFRONT_ASSET_BASE` fails everywhere (it makes the
 * just-uploaded preview and the reloaded preview name different hosts — study §3.14.6).
 */

/** The key and JWT halves pass, so the asset check is the only thing that can decide the run. */
function parityWithHalvesPassing(): PendingCommand
{
    Http::fake(function (Request $request) {
        return $request->hasHeader('Api-Code', 'the-real-public-api-key')
            ? Http::response(['ok' => true], 200)
            : Http::response([], 401);
    });

    return parity(['--token' => HarnessJwt::mint(1)]);
}

it('allows a LOCAL asset base on a developer machine, and says out loud that it is local', function () {
    config()->set('storefront.asset_base', 'http://127.0.0.1:8099');
    config()->set('compat.asset_base', 'http://127.0.0.1:8099');
    config()->set('media.url_base', 'http://127.0.0.1:8099/Uploads_Images');

    parityWithHalvesPassing()
        ->expectsOutputToContain('NEVER copy this file to production')
        ->assertExitCode(0);
});

it('REFUSES the same local asset base on a production host', function () {
    // `Application::environment()` reads the container's `env` binding, not live config.
    app()->instance('env', 'production');

    config()->set('storefront.asset_base', 'http://127.0.0.1:8099');
    config()->set('compat.asset_base', 'http://127.0.0.1:8099');
    config()->set('media.url_base', 'http://127.0.0.1:8099/Uploads_Images');

    parityWithHalvesPassing()
        ->expectsOutputToContain('[assets] LOCAL VALUE IN PRODUCTION')
        ->assertExitCode(1);
});

it('REFUSES a relative MEDIA_ROOT on a production host', function () {
    // The write side of the same failure, and the quiet one: off this workstation the default
    // `../backend/public/Uploads_Images` does not resolve to the shared mount, and
    // `MediaStore::directory()` CREATES whatever it resolves to instead of refusing. Every upload
    // then succeeds into a tree nothing serves.
    app()->instance('env', 'production');
    config()->set('media.root', '../backend/public/Uploads_Images');

    parityWithHalvesPassing()
        ->expectsOutputToContain('[assets] RELATIVE MEDIA_ROOT')
        ->assertExitCode(1);
});

it('FAILS when MEDIA_URL_BASE does not follow STOREFRONT_ASSET_BASE, in any environment', function () {
    // The one that has no "correct on a developer's machine" reading: the upload preview and the
    // reloaded preview would point at two different hosts, and nothing on the screen says which.
    config()->set('media.url_base', 'https://cdn.example.net/Uploads_Images');

    parityWithHalvesPassing()
        ->expectsOutputToContain('[assets] SPLIT PREVIEW')
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
