<?php

use App\Console\Commands\CoreTransformCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

/*
 * Re-verification 2026-09-07: two concurrent core:transform processes doubled the inventory
 * baseline (no natural unique key on the ledger). The command now takes a server-side named
 * lock; a second invocation must refuse to start. The `legacy` connection is a separate PDO
 * session, so holding the lock through it is exactly "another process".
 */

afterEach(function () {
    DB::connection('legacy')->selectOne('SELECT RELEASE_LOCK(?) AS r', [CoreTransformCommand::RUN_LOCK]);
});

it('refuses to start while another session holds the transform lock', function () {
    expect(CoreTransformCommand::acquireRunLock(DB::connection('legacy')))->toBeTrue();

    $dir = storage_path('framework/testing/transform-lock-'.getmypid());
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pending = artisan('core:transform', ['--audit' => true, '--output' => $dir, '--force' => true]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->expectsOutputToContain('Another core:transform is running')->assertExitCode(1);
});

it('releases the lock after a run so the next run can start', function () {
    $dir = storage_path('framework/testing/transform-lock2-'.getmypid());
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pending = artisan('core:transform', ['--audit' => true, '--output' => $dir, '--force' => true]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertExitCode(0);
    $pending->run();

    expect(CoreTransformCommand::acquireRunLock(DB::connection('legacy')))->toBeTrue();
});
