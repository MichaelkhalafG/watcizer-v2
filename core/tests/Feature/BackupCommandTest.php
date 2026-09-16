<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Testing\PendingCommand;
use Symfony\Component\Console\Command\Command;

use function Pest\Laravel\artisan;

/*
 * `core:backup` — the properties that make a dump safe to have (wave 4D).
 *
 * ── Why the refusal is the most important case here ─────────────────────────────────────────
 *
 * This dump is every customer's name, e-mail, phone and address, plus every password hash in
 * `users`. The likeliest way that leaks is not an attacker: it is somebody putting it "somewhere
 * convenient" — a folder they can reach over HTTP to download it. So the command refuses to write
 * anywhere under `public/`, and that refusal is tested before anything else.
 *
 * ── What is NOT asserted, and why ───────────────────────────────────────────────────────────
 *
 * The 0600 file mode. PHP's `chmod()` is a near no-op on Windows, where this suite runs, so an
 * assertion on the mode would fail locally for a reason that has nothing to do with the code, and
 * would pass on the host either way. A test that cannot fail honestly is worse than no test; the
 * mode is documented in the command and verified on the host by `ls -l`.
 */

/**
 * `Pest\Laravel\artisan()` returns `PendingCommand|int` — the house narrowing helper, as in
 * `EnvParityCheckTest` and `WriteTargetGuardTest`.
 *
 * @param  array<string, mixed>  $args
 */
function backup(array $args = []): PendingCommand
{
    $pending = artisan('core:backup', $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

it('REFUSES to write a dump inside the web root', function () {
    backup(['--path' => 'public/dumps'])
        ->expectsOutputToContain('REFUSING')
        ->expectsOutputToContain('password hash')
        ->assertExitCode(Command::FAILURE);
});

it('leaves NO directory behind when it refuses', function () {
    /*
     * The first version created the directory and then refused, leaving an empty `public/dumps`.
     * Harmless in itself, and exactly the sort of leftover that tells the next person the path is
     * supported and they simply got the invocation wrong.
     */
    $stray = public_path('dumps-probe');
    if (is_dir($stray)) {
        rmdir($stray);
    }

    backup(['--path' => 'public/dumps-probe'])->run();

    expect(is_dir($stray))->toBeFalse();
});

it('reports what it WOULD do without writing anything', function () {
    $before = glob(storage_path('app/backups/*.sql')) ?: [];

    backup(['--dry-run' => true])
        ->expectsOutputToContain('DRY RUN')
        ->assertExitCode(Command::SUCCESS);

    expect(glob(storage_path('app/backups/*.sql')) ?: [])->toBe($before);
});

it('keeps the dump out of git', function () {
    /*
     * The one property no amount of care at the keyboard can supply. `storage/app/.gitignore`
     * already ignores everything beneath it, and `core/.gitignore` names the directory explicitly
     * as well — belt and braces, because this repository is PUBLIC and a committed dump cannot be
     * un-published.
     */
    $root = dirname(base_path());
    $output = shell_exec('cd '.escapeshellarg($root).' && git check-ignore -v core/storage/app/backups/probe.sql 2>&1');

    expect($output)->toBeString();
    expect(trim((string) $output))->not->toBe('', 'a dump under storage/app/backups is NOT gitignored');
});

/**
 * The same narrowing, for the verifier.
 *
 * @param  array<string, mixed>  $args
 */
function verifyBackup(array $args = []): PendingCommand
{
    $pending = artisan('backup:verify', $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

/*
 * ── backup:verify ───────────────────────────────────────────────────────────────────────────
 *
 * A different question from "does core:backup work": it asks the DISK whether last night's dump is
 * there, is not a stub, and is from last night. Those fail in ways the command cannot report,
 * because when they fail it did not run — a crontab lost in a host migration, a full disk, a moved
 * PHP binary. Every one leaves the code working and the shop with no backups, and the only signal
 * is silence, which reads exactly like success.
 */

it('PASSES when a recent, plausible dump is on disk', function () {
    $dir = storage_path('app/backups');
    if ((glob($dir.DIRECTORY_SEPARATOR.'*.sql') ?: []) === []) {
        expect(true)->toBeTrue('no dump on this machine to verify — core:backup has not been run here');

        return;
    }

    verifyBackup()
        ->expectsOutputToContain('OK')
        ->assertExitCode(Command::SUCCESS);
});

it('FAILS on a stub — the shape a truncated dump has', function () {
    // An absurd minimum stands in for a 4 KB dump: the assertion is that SIZE is checked at all.
    verifyBackup(['--min-mb' => 999999])
        ->expectsOutputToContain('stub, not a backup')
        ->assertExitCode(Command::FAILURE);
});

it('FAILS on a stale dump — the schedule having quietly stopped', function () {
    /*
     * `--max-age=0` is meaningful because the clamp was removed: it used to be raised to 1 behind
     * the operator's back, so this case silently PASSED on a one-hour-old dump. A threshold the
     * command overrides is a threshold nobody can test.
     */
    verifyBackup(['--max-age' => 0])
        ->expectsOutputToContain('schedule has stopped running')
        ->assertExitCode(Command::FAILURE);
});

it('FAILS loudly when the directory holds nothing at all', function () {
    verifyBackup(['--path' => storage_path('app/backups-that-do-not-exist')])
        ->expectsOutputToContain('does not exist')
        ->assertExitCode(Command::FAILURE);
});

it('schedules itself nightly, riding the existing cron entry', function () {
    /*
     * A backup that exists and is not scheduled is a backup nobody takes. Asserted on the schedule
     * rather than on the file, so moving the registration would fail this.
     */
    $schedule = app(Schedule::class);

    $found = null;
    foreach ($schedule->events() as $event) {
        if (str_contains($event->command ?? '', 'core:backup')) {
            $found = $event;
        }
    }

    expect($found)->not->toBeNull('core:backup is not on the schedule');
    expect($found?->expression)->toBe('0 3 * * *');
});
