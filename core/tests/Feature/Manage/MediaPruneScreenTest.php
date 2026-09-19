<?php

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Domain\Activity\ActivityLog;
use App\Domain\Media\MediaAudit;
use App\Domain\Media\MediaStore;
use App\Http\Controllers\Manage\MediaPruneController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;

/*
 * The media cleanup SCREEN (wave 4D, task C4).
 *
 * The scan itself is proved by `MediaPruneTest`, which drives the command. What is proved here is
 * the part that is new and the part that is dangerous: the screen and the command ask the SAME
 * guard, only a holder of the RESTRICTED `manage-media-prune` ability can open it — an
 * administrator cannot — and the delete needs a number typed correctly against a FRESH scan.
 */

/**
 * A user holding the RESTRICTED prune ability.
 *
 * Since the 2026-09-15 review this is NOT an administrator: `manage-media-prune` lives in
 * `Role::RESTRICTED`, so `Gate::before` does not hand it to admins and it has to be granted by
 * name. `RestrictedAbilityTest` proves the refusal side; this file assumes the grant and tests the
 * screen behind it.
 */
function pruner(): User
{
    $user = Staff::admin();
    app(Roles::class)->assign($user, Role::MediaPruner);
    app(Roles::class)->forget($user);

    return $user;
}

it('needs the RESTRICTED ability — an administrator alone is not enough', function () {
    actingAs(Staff::dataEntry());

    get('/manage/media/prune')->assertForbidden();
    delete('/manage/media/prune', ['confirm' => '1'])->assertForbidden();

    // An administrator is refused too. This deletes files a live shop is still serving, with no
    // undo, and "everybody holding an admin grant" was too many hands for it.
    actingAs(Staff::admin());
    get('/manage/media/prune')->assertForbidden();

    actingAs(pruner());
    get('/manage/media/prune')->assertOk();
});

it('renders the same numbers the command reports, and the same refusal', function () {
    actingAs(pruner());

    $audit = app(MediaAudit::class);
    $scan = $audit->scan(MediaStore::types(), 7);

    $props = Props::of(get('/manage/media/prune'));

    // One implementation of "what is an orphan": the screen cannot show a different number from
    // the command, because it is not allowed to compute one.
    expect($props['referenced'])->toBe($scan['referenced'])
        ->and($props['orphan_files'])->toBe($scan['files'])
        ->and($props['orphan_masters'])->toBe(count($scan['orphans']))
        // …and one implementation of "may I delete": the screen shows the Arabic rendering of the
        // very same decision the command prints in English.
        ->and($props['refusal'])->toBe(MediaAudit::refusal($scan, 100)['ar'] ?? null);
});

it('refuses a confirmation number that does not match the CURRENT scan', function () {
    actingAs(pruner());

    // The number is checked against a fresh scan, not the one the page rendered: a tree that
    // changed between the page load and the click must delete nothing.
    delete('/manage/media/prune', ['confirm' => '999999'])
        ->assertSessionHasErrors('confirm');
});

it('states the three refusals in words an operator can act on', function () {
    // Guard 1 — a partial scan. One unreadable source still left 2 323 references on the first
    // real dry run, so a COUNT floor alone would have waved a half-blind delete through.
    expect(MediaAudit::refusal(['scan_failed' => true, 'referenced' => 5000, 'matched' => 4000], 100))
        ->toMatchArray(['code' => 'partial_scan']);

    // Guard 2 — no coverage. This is the developer's own workstation: 74 files on disk, none
    // referenced, and not one of the referenced files present.
    expect(MediaAudit::refusal(['scan_failed' => false, 'referenced' => 2323, 'matched' => 0], 100))
        ->toMatchArray(['code' => 'no_coverage']);

    // Guard 3 — too few references is a broken scan, not an empty shop.
    expect(MediaAudit::refusal(['scan_failed' => false, 'referenced' => 12, 'matched' => 12], 100))
        ->toMatchArray(['code' => 'too_few_references']);

    // …and a healthy scan refuses nothing.
    expect(MediaAudit::refusal(['scan_failed' => false, 'referenced' => 2323, 'matched' => 2000], 100))
        ->toBeNull();
});

it('groups a rendition with its master, so no rendition is ever judged alone', function () {
    $directory = sys_get_temp_dir().'/wz-prune-'.uniqid();
    mkdir($directory);

    foreach (['a.webp', 'a-320.webp', 'a-320.avif', 'a-640.webp', 'b.webp'] as $name) {
        file_put_contents($directory.'/'.$name, 'x');
    }

    $groups = MediaAudit::groupByMaster($directory);

    // Four files, ONE master. Judging `a-320.avif` on its own would delete every rendition in the
    // tree, because no row ever references one.
    expect(array_keys($groups))->toBe(['a.webp', 'b.webp'])
        ->and($groups['a.webp'])->toHaveCount(4)
        ->and($groups['b.webp'])->toHaveCount(1);

    foreach (glob($directory.'/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($directory);
});

// ── the record a prune leaves behind ─────────────────────────────────────────────────────────

/*
 * ── Why this one is not tested through the screen (2026-10-05) ───────────────────────────────
 *
 * Every other screen's audit row is proved end to end: make the change, read the row. A prune
 * cannot be. It is refused on any machine whose media tree is not the live one — the `no_coverage`
 * guard, which is what stops a developer's workstation deleting its own files — so reaching the
 * log call through an HTTP request would mean making a real prune succeed, i.e. really deleting
 * somebody's images. That is not a test, it is an incident.
 *
 * So the payload is asserted directly and then pushed through `ActivityLog::record()`, which is
 * every step except `unlink`. That split is what caught two real losses: the payload went in as
 * `$before` where `diff()` could not see it, and the path list went in as an array that
 * `readable()` clipped at 300 characters. The log swallows its own exceptions, so neither would
 * ever have announced itself — it would have been found by somebody asking which file had gone
 * and discovering that the row written for that question could not answer it.
 */

it('names every deleted file in the row, because nothing else will remember them', function () {
    // Two masters, each with renditions — the shape `MediaAudit::scan()` actually returns, with
    // ABSOLUTE paths in `files` because that is what `MediaAudit::delete()` unlinks.
    $scan = [
        'bytes' => 4096,
        'orphans' => [
            ['type' => 'product', 'folder' => 'product', 'master' => 'a.webp', 'bytes' => 3072, 'files' => [
                'D:/wherever/storage/product/a.webp',
                'D:/wherever/storage/product/a-320.webp',
            ]],
            ['type' => 'brand', 'folder' => 'brand', 'master' => 'b.webp', 'bytes' => 1024, 'files' => [
                'D:/wherever/storage/brand/b.webp',
            ]],
        ],
    ];

    $payload = MediaPruneController::auditPayload($scan, ['deleted' => 3, 'failed' => 0]);

    // `<folder>/<name>`, which is the shape the database stores and the shape somebody will paste
    // into a search — not this server's drive letter, which says nothing to anybody.
    expect($payload['paths'])->toBe("product/a.webp\nproduct/a-320.webp\nbrand/b.webp")
        ->and($payload['files_deleted'])->toBe(3)
        ->and($payload['bytes_reclaimed'])->toBe(4096)
        ->and($payload['paths_truncated'])->toBe(0);
});

it('caps the list and SAYS it capped it, rather than trimming in silence', function () {
    $files = [];
    for ($i = 0; $i < 600; $i++) {
        $files[] = 'D:/wherever/storage/product/file-'.$i.'.webp';
    }

    $payload = MediaPruneController::auditPayload(
        ['bytes' => 1, 'orphans' => [['folder' => 'product', 'files' => $files]]],
        ['deleted' => 600, 'failed' => 0],
    );

    // The COUNT is never trimmed — only the list, and the row says by how much. A prune of ten
    // thousand orphans must not write a megabyte into a column a person is meant to read, but it
    // must also never leave a reader thinking the list is complete when it is not.
    expect(substr_count(T::str($payload['paths']), "\n") + 1)->toBe(500)
        ->and($payload['paths_truncated'])->toBe(100)
        ->and($payload['files_deleted'])->toBe(600);
});

it('gets the paths through ActivityLog intact — the trip that lost them twice', function () {
    $admin = Staff::admin();
    actingAs($admin);

    $scan = ['bytes' => 2048, 'orphans' => [
        ['folder' => 'product', 'files' => ['D:/x/storage/product/needle-8f2a.webp']],
    ]];

    ActivityLog::record(
        'media_files',
        null,
        ActivityLog::DELETED,
        after: MediaPruneController::auditPayload($scan, ['deleted' => 1, 'failed' => 0]),
        label: 'حذف 1 ملف يتيم',
    );

    $row = T::one(DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'media_files')->orderByDesc('id'));

    expect(T::str($row->user_name))->toBe(Staff::nameOf($admin))
        ->and(T::str($row->action))->toBe(ActivityLog::DELETED)
        // No row to point at: the files are gone and there was never a record of them.
        ->and($row->subject_id)->toBeNull();

    $changes = T::arr(json_decode(T::str($row->changes), true));

    // The whole point. `changes` was NULL here until `diff()` walked both sides, and the filename
    // was clipped away by `readable()` until the list became a string.
    expect(T::str(T::arr($changes['paths'] ?? null)['to'] ?? null))->toBe('product/needle-8f2a.webp')
        ->and(T::int(T::arr($changes['files_deleted'] ?? null)['to'] ?? null))->toBe(1);

    // …and it is findable the way somebody would actually look for it: one filename, one query.
    expect(DB::table(ActivityLog::TABLE)->where('subject_type', 'media_files')
        ->where('changes', 'like', '%needle-8f2a.webp%')->count())->toBeGreaterThan(0);
});

it('writes NOTHING when the prune was refused, because nothing was deleted', function () {
    actingAs(pruner());

    $before = DB::table(ActivityLog::TABLE)->where('subject_type', 'media_files')->count();

    // Refused twice over on any machine that is not the live one: the coverage guard first, and
    // the confirmation number second. Neither is an event.
    delete('/manage/media/prune', ['confirm' => '999999'])->assertSessionHasErrors('confirm');

    expect(DB::table(ActivityLog::TABLE)->where('subject_type', 'media_files')->count())->toBe($before);
});
