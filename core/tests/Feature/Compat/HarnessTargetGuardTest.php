<?php

use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;

use function Pest\Laravel\artisan;

/*
 * `compat:diff` refuses a target that is not a local host.
 *
 * ── Why the guard is on the TARGET and not on the account ───────────────────────────────────
 *
 * The harness writes. Its own class docblock has said so since wave 3 — "the checkout sequence
 * really does place orders and really does decrement stock, on BOTH hosts" — and two runs on
 * 2026-09-15 left 12 orders, 12 order_items, 6 carts and 6 addresses in this database.
 *
 * That is the right price on a copy and the wrong thing entirely on a live host, and no guard on
 * the ACCOUNT path can prevent it: the orders are not the account. A run that invented no user at
 * all would still manufacture a dozen real commerce records. So the question the guard asks is
 * "which database am I about to write to", which is a question about the URL.
 *
 * The sanctioned remote run is the one the runbook describes — an explicit `--allow-remote` plus
 * `--subject-user`, an account that already exists and that the operator names.
 */

/**
 * `Pest\Laravelrtisan()` returns `PendingCommand|int`, so the narrowing is explicit — the same
 * helper shape `EnvParityCheckTest` and `TransformCommandTest` use.
 *
 * @param  array<string, mixed>  $args
 */
function harness(array $args): PendingCommand
{
    $pending = artisan('compat:diff', $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

it('REFUSES a production-looking target', function () {
    harness([
        '--legacy' => 'https://dash.watchizereg.com',
        '--compat' => 'https://api.watchizereg.com',
    ])
        ->expectsOutputToContain('REFUSING to run')
        ->expectsOutputToContain('dash.watchizereg.com')
        ->assertExitCode(Command::INVALID);
});

it('refuses when only ONE side is remote', function () {
    // The asymmetric case, which is the plausible mistake: the local core host diffed against the
    // live legacy one. It still places a real order on the live host.
    harness([
        '--legacy' => 'https://dash.watchizereg.com',
        '--compat' => 'http://127.0.0.1:8001',
    ])
        ->expectsOutputToContain('REFUSING to run')
        ->assertExitCode(Command::INVALID);
});

it('refuses --allow-remote on its own, without a named account', function () {
    /*
     * The half-measure that would otherwise feel like enough. Acknowledging the host says nothing
     * about WHOSE record the authenticated cases attach to — `readOnlyUser()` picks a subject by
     * query, and on a live database that subject is a real customer.
     */
    harness([
        '--legacy' => 'https://dash.watchizereg.com',
        '--compat' => 'https://api.watchizereg.com',
        '--allow-remote' => true,
    ])
        ->expectsOutputToContain('REFUSING to run')
        ->expectsOutputToContain('--subject-user')
        ->assertExitCode(Command::INVALID);
});

it('names what a run would create, so the refusal teaches rather than blocks', function () {
    harness([
        '--legacy' => 'https://dash.watchizereg.com',
        '--compat' => 'https://api.watchizereg.com',
    ])
        ->expectsOutputToContain('a genuine COD order per checkout case')
        ->expectsOutputToContain('REHEARSAL COPY')
        ->assertExitCode(Command::INVALID);
});

it('does NOT refuse a loopback target — the guard must not break the normal run', function () {
    /*
     * The case that matters most: the launcher runs on 127.0.0.1 and must be untouched.
     *
     * Proving a NEGATIVE here needs care. No host is listening on these ports during a test, so a
     * run that gets past the guard dies trying to connect — and that failure IS the proof: the
     * command reached the network, which it only does after every refusal has declined to fire.
     * Asserting on the exit code instead would pass just as well if the guard had refused, because
     * both paths end unhappily.
     */
    $reachedTheNetwork = false;

    try {
        harness([
            '--legacy' => 'http://127.0.0.1:8011',
            '--compat' => 'http://127.0.0.1:8001',
        ])->run();
    } catch (Throwable $e) {
        $reachedTheNetwork = str_contains($e->getMessage(), '127.0.0.1');
    }

    expect($reachedTheNetwork)->toBeTrue('a loopback target was stopped before it reached the network');
});
