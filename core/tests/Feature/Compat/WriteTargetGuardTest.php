<?php

use App\Console\Commands\CompatDiffCommand;
use App\Console\Commands\InventoryProveReleaseRaceCommand;
use App\Console\Commands\PaymentProveCallbackRaceCommand;
use App\Support\WriteTarget;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;

use function Pest\Laravel\artisan;

/*
 * ONE guard, three tools: every command that writes rows as a side effect asks the same question.
 *
 * ── The pattern this encodes ────────────────────────────────────────────────────────────────
 *
 * The 2026-09-15 sweep found four tools that create rows in the shared legacy commerce tables just
 * by being run — `compat:diff` (and its launcher), `inventory:prove-release-race`, and
 * `payment:prove-callback-race`. All four were invisible for the same reason: none had ever
 * actually been run, so nothing any of them did had ever been seen.
 *
 * The first guard asked only about the target URL, which was right for `compat:diff` and wrong as a
 * pattern — the probes take no URL, and a loopback run still writes production rows when `DB_HOST`
 * points at production. `App\Support\WriteTarget` asks the real question, once, for all three.
 *
 * This file's job is to keep them one mechanism rather than three that drift.
 */

/**
 * @param  array<string, mixed>  $args
 */
function guarded(string $command, array $args = []): PendingCommand
{
    $pending = artisan($command, $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

it('treats loopback as local and anything else as not', function () {
    expect(WriteTarget::isLocalHost('127.0.0.1'))->toBeTrue()
        ->and(WriteTarget::isLocalHost('localhost'))->toBeTrue()
        ->and(WriteTarget::isLocalHost('127.0.0.53'))->toBeTrue()
        ->and(WriteTarget::isLocalHost('::1'))->toBeTrue()
        // An allow-list, not a deny-list: the point is that everything unknown is refused, so the
        // staging clone nobody remembered to list is refused too.
        ->and(WriteTarget::isLocalHost('dash.watchizereg.com'))->toBeFalse()
        ->and(WriteTarget::isLocalHost('10.0.0.5'))->toBeFalse()
        ->and(WriteTarget::isLocalHost('db.internal'))->toBeFalse();
});

it('reports a remote URL and says which option carried it', function () {
    $remote = WriteTarget::remote(['legacy' => 'https://dash.watchizereg.com', 'compat' => 'http://127.0.0.1:8001']);

    expect($remote)->toHaveKey('--legacy')
        ->and($remote)->not->toHaveKey('--compat');
});

it('finds this machine local, so the guard does not break the normal run', function () {
    // The premise every other case rests on. If this ever fails, the suite is running somewhere the
    // probes must not be run at all, and that is worth failing loudly for.
    expect(WriteTarget::remote())->toBe([]);
});

it('REFUSES the release probe against a non-local target', function () {
    /*
     * Simulated by pointing the default connection's configured host somewhere else. The probe is
     * never actually executed here — the guard fires before it writes, which is the whole contract.
     */
    config(['database.connections.'.DB::getDefaultConnection().'.host' => 'db.production.internal']);

    guarded('inventory:prove-release-race')
        ->expectsOutputToContain('REFUSING to run')
        ->expectsOutputToContain('db.production.internal')
        ->expectsOutputToContain('one real order and its order_items')
        ->assertExitCode(Command::INVALID);
});

it('REFUSES the callback probe against a non-local target, and names --keep', function () {
    config(['database.connections.'.DB::getDefaultConnection().'.host' => 'db.production.internal']);

    guarded('payment:prove-callback-race')
        ->expectsOutputToContain('REFUSING to run')
        // `--keep` leaves the rows behind ON PURPOSE, so the refusal has to say so: it is the one
        // flag that turns "tidied up afterwards" into "kept forever".
        ->expectsOutputToContain('--keep')
        ->assertExitCode(Command::INVALID);
});

it('REFUSES the harness when the DATABASE is remote even though the URLs are loopback', function () {
    /*
     * The case the first, URL-only guard would have waved straight through — and the one most
     * likely to happen by accident, because the URLs look exactly right.
     */
    config(['database.connections.'.DB::getDefaultConnection().'.host' => 'db.production.internal']);

    guarded('compat:diff', [
        '--legacy' => 'http://127.0.0.1:8011',
        '--compat' => 'http://127.0.0.1:8001',
    ])
        ->expectsOutputToContain('REFUSING to run')
        ->expectsOutputToContain('DB_HOST is not this machine')
        ->assertExitCode(Command::INVALID);
});

it('refuses in production regardless of how local the host looks', function () {
    /*
     * A production box runs its own database on 127.0.0.1, where a host test alone says "local"
     * about the live shop. `APP_ENV` is the second half, and either one is enough to refuse.
     */
    app()->detectEnvironment(fn (): string => 'production');

    expect(WriteTarget::remote())->toHaveKey('APP_ENV');

    guarded('inventory:prove-release-race')
        ->expectsOutputToContain('REFUSING to run')
        ->assertExitCode(Command::INVALID);
});

it('keeps ONE mechanism: all three commands share the opt-in flag', function () {
    /*
     * The structural guard, and the reason this file exists rather than three files. A fourth tool
     * that writes gets `WriteTarget` too — and a third variation of the same idea, spelled
     * `--force` or `--yes-really`, is exactly the drift this asserts against.
     */
    $commands = [
        'compat:diff' => CompatDiffCommand::class,
        'inventory:prove-release-race' => InventoryProveReleaseRaceCommand::class,
        'payment:prove-callback-race' => PaymentProveCallbackRaceCommand::class,
    ];

    /*
     * Collected rather than asserted in the loop, because Pest's `toContain()` is VARIADIC: a
     * second argument is another NEEDLE, not a message, so `toContain($flag, "[x] is missing")`
     * quietly searches the source for its own failure text and fails for the wrong reason. This
     * project has now been bitten by that three times; the shape below cannot be bitten by it.
     */
    $missing = [];

    foreach ($commands as $name => $class) {
        $source = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());

        foreach ([
            'the shared opt-in flag' => '--'.WriteTarget::ALLOW_REMOTE,
            'the shared question' => 'WriteTarget::remote(',
            'the shared refusal' => 'WriteTarget::refuse(',
        ] as $what => $needle) {
            if (! str_contains($source, $needle)) {
                $missing[] = "{$name} is missing {$what} ({$needle})";
            }
        }
    }

    expect($missing)->toBe([]);
});
