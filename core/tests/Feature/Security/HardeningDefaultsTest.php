<?php

use function Pest\Laravel\withHeaders;

/*
 * ── Two configuration defaults that must not depend on remembering (audit Finding 9) ──────────
 */

/**
 * Evaluate config/session.php under a given environment, then put the real one back.
 *
 * The running config cannot prove a DEFAULT — the suite boots with whatever the local `.env` says —
 * so the file itself is evaluated with the variables set exactly as a host would have them.
 *
 * @param  array<string, string|null>  $env  null = the variable is absent
 */
function sessionConfigUnder(array $env): array
{
    $saved = [];
    foreach ($env as $key => $value) {
        $saved[$key] = [$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)];
        unset($_ENV[$key], $_SERVER[$key]);
        putenv($key);
        if ($value !== null) {
            $_ENV[$key] = $_SERVER[$key] = $value;
            putenv($key.'='.$value);
        }
    }

    try {
        return require config_path('session.php');
    } finally {
        foreach ($saved as $key => [$e, $srv, $g]) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);
            if ($e !== null) {
                $_ENV[$key] = $e;
            }
            if ($srv !== null) {
                $_SERVER[$key] = $srv;
            }
            if ($g !== false) {
                putenv($key.'='.$g);
            }
        }
    }
}

it('makes the session cookie Secure on a host whose .env never mentions it', function () {
    // Production safe by OMISSION — the whole point of a default.
    expect(sessionConfigUnder(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => null])['secure'])->toBeTrue()
        ->and(sessionConfigUnder(['APP_ENV' => 'staging', 'SESSION_SECURE_COOKIE' => null])['secure'])->toBeTrue();
});

it('leaves it off for APP_ENV=local, so http:// development signs in without editing anything', function () {
    expect(sessionConfigUnder(['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => null])['secure'])->toBeFalse();
});

it('still honours an explicit SESSION_SECURE_COOKIE either way', function () {
    expect(sessionConfigUnder(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false'])['secure'])->toBeFalse()
        ->and(sessionConfigUnder(['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => 'true'])['secure'])->toBeTrue();
});

it('lets a cross-origin storefront READ the guest token, or the basket empties itself', function () {
    /*
     * Browsers hide every non-safelisted response header from a cross-origin caller unless the
     * server exposes it. After the flip the storefront calls `api.watchizereg.com` from
     * `watchizereg.com`, and `api.jsx` keeps the guest cart by reading `X-Guest-Token` off each
     * response — so without this every request would mint a new guest cart.
     */
    config(['compat.api_key' => 'cors-test-key']);

    $response = withHeaders([
        'Api-Code' => 'cors-test-key',
        'Origin' => 'https://watchizereg.com',
    ])->getJson('/api/me/cart');

    $exposed = strtolower((string) $response->headers->get('Access-Control-Expose-Headers'));

    expect($exposed)->toContain('x-guest-token')
        ->and($response->headers->get('X-Guest-Token'))->not->toBeNull();
});
