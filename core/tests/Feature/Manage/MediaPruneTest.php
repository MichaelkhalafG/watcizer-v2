<?php

use App\Console\Commands\MediaPruneCommand;
use App\Domain\Media\MediaStore;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\T;

/*
 * `media:prune` deletes customer-visible assets from a directory the LIVE legacy application
 * serves, so the tests worth writing are about the REFUSALS, not the happy path.
 *
 * Every guard below exists because of something that actually happened while building it:
 *  • the first dry run revealed `DB::connection('default')` throws (the connection is named
 *    `mariadb`), so the whole clean-side scan failed while the legacy side still returned 2 323
 *    references — a count-only sanity floor would have waved a half-blind delete through;
 *  • the same run showed that NONE of the referenced files exist on the developer's workstation,
 *    because its `Uploads_Images` is a partial copy — so a `--delete` there would have wiped the
 *    entire local tree while reporting itself correct.
 */

it('is a DRY RUN by default and deletes nothing', function () {
    $directory = MediaStore::directory('Brand');
    $orphan = $directory.'/prune-probe-'.uniqid().'.webp';
    file_put_contents($orphan, 'not-a-real-image');
    // Old enough to pass the age floor. `clearstatcache` matters HERE and only here: PHP caches
    // stat results per process, so a test that writes then touches then asks the command to read
    // `filemtime` in the SAME process sees the write's timestamp and calls the file "too young".
    // In production the command is its own process and there is no cache to clear.
    touch($orphan, time() - (30 * 86400));
    clearstatcache();

    try {
        expect(Artisan::call('media:prune', ['--type' => ['brand']]))->toBe(0);
        expect(Artisan::output())->toContain('DRY RUN')
            ->and(file_exists($orphan))->toBeTrue('a dry run must not delete anything');
    } finally {
        @unlink($orphan);
    }
});

it('REFUSES to delete when no referenced file exists in the tree', function () {
    // The workstation's condition, asserted: a partial copy means everything looks orphaned.
    expect(Artisan::call('media:prune', ['--delete' => true, '--type' => ['brand']]))->toBe(1);

    // One read: `Artisan::output()` drains the buffer, so a second call returns an empty string.
    $output = Artisan::output();
    expect($output)->toContain('REFUSING to delete')
        ->and($output)->toContain('describing different worlds');
});

it('keeps a rendition with its master instead of judging it alone', function () {
    // No row ever references `x-320.webp`, so a per-file scan would delete every rendition in the
    // tree. They are grouped under the master and share its fate.
    $directory = MediaStore::directory('Brand');
    $base = 'prune-group-'.uniqid();
    $master = $directory.'/'.$base.'.webp';
    $rendition = $directory.'/'.$base.'-320.webp';
    file_put_contents($master, 'master');
    file_put_contents($rendition, 'rendition');
    touch($master, time() - (30 * 86400));
    touch($rendition, time() - (30 * 86400));
    clearstatcache();

    try {
        Artisan::call('media:prune', ['--type' => ['brand'], '--json' => true]);
        // `--json` emits ONLY the payload, so this parses as-is. It did not at first — the scan
        // line and the DRY RUN footer were mixed in, `json_decode` returned null, and this test
        // read an empty orphan list as "the grouping is broken". The command was fixed, not the
        // parsing.
        $payload = T::arr(json_decode(Artisan::output(), true));
        $orphans = T::rows($payload['orphans'] ?? []);

        $mine = array_values(array_filter($orphans, fn (array $o): bool => ($o['master'] ?? null) === $base.'.webp'));
        expect($mine)->toHaveCount(1, 'the master must appear once, not once per file')
            ->and($mine[0]['files'])->toBe(2, 'the rendition is counted with its master');
    } finally {
        @unlink($master);
        @unlink($rendition);
    }
});

it('never lists a file younger than the age floor', function () {
    $directory = MediaStore::directory('Brand');
    $fresh = $directory.'/prune-fresh-'.uniqid().'.webp';
    file_put_contents($fresh, 'just uploaded');

    try {
        Artisan::call('media:prune', ['--type' => ['brand']]);
        expect(Artisan::output())->not->toContain(basename($fresh));
    } finally {
        @unlink($fresh);
    }
});

it('exits NON-ZERO on every refusal, and on a report it could not complete', function () {
    // 🟡-5. A cron or a deploy step reads the exit code, so "I refused" and "I could not look"
    // must never look like "nothing to do".
    expect(Artisan::call('media:prune', ['--delete' => true, '--type' => ['brand']]))
        ->toBe(1, 'a coverage refusal must be non-zero');

    // An unknown type is a usage error…
    expect(Artisan::call('media:prune', ['--type' => ['not-a-type']]))
        ->toBe(2, 'an unknown --type is INVALID, not success');

    // …and a dry run that completed cleanly is a success.
    expect(Artisan::call('media:prune', ['--type' => ['brand'], '--json' => true]))->toBe(0);
});

it('scans BOTH schemas, because the tree is shared with the legacy app', function () {
    expect(array_keys(MediaPruneCommand::REFERENCE_COLUMNS))->toBe(['clean', 'legacy'])
        // The clean side is the default connection, which is NOT named "default".
        ->and(MediaPruneCommand::REFERENCE_COLUMNS['legacy'])->toHaveKey('products')
        ->and(MediaPruneCommand::REFERENCE_COLUMNS['legacy'])->toHaveKey('blogs')
        ->and(MediaPruneCommand::REFERENCE_COLUMNS['clean'])->toHaveKey('catalog_product_images');
});

it('names only tables and columns that exist, or it would delete what it cannot see', function () {
    // A forgotten column is a deleted file, so the list is asserted against the live schema.
    foreach ([MediaPruneCommand::REFERENCE_COLUMNS, MediaPruneCommand::INLINE_TEXT_COLUMNS] as $set) {
        foreach ($set as $schema => $tables) {
            $connection = $schema === 'legacy' ? 'legacy' : null;
            foreach ($tables as $table => $columns) {
                expect(Schema::connection($connection)->hasTable($table))->toBeTrue("{$schema}.{$table} is missing");
                foreach ($columns as $column) {
                    expect(Schema::connection($connection)->hasColumn($table, $column))->toBeTrue("{$schema}.{$table}.{$column} is missing");
                }
            }
        }
    }
});

it('finds a reference EMBEDDED in long text, which a column scan cannot see', function () {
    // Nothing in today's data pastes an <img> into a description; this proves the cover exists
    // before someone does.
    $file = 'inline-probe-'.uniqid().'.webp';
    DB::table('catalog_product_translations')
        ->where('id', DB::table('catalog_product_translations')->orderBy('id')->value('id'))
        ->update(['long_description' => '<p><img src="https://dash.watchizereg.com/Uploads_Images/Product/'.$file.'"></p>']);

    $referenced = (new MediaPruneCommand)->referencedFiles();

    expect($referenced)->toHaveKey($file);
});

it('reports a real orphan when one exists, with its size', function () {
    $directory = MediaStore::directory('Brand');
    $orphan = $directory.'/prune-report-'.uniqid().'.webp';
    file_put_contents($orphan, str_repeat('x', 2048));
    touch($orphan, time() - (30 * 86400));
    clearstatcache();

    try {
        Artisan::call('media:prune', ['--type' => ['brand']]);
        $output = Artisan::output();

        expect($output)->toContain(basename($orphan))
            ->and($output)->toContain('orphan master(s)');
    } finally {
        @unlink($orphan);
    }
});
