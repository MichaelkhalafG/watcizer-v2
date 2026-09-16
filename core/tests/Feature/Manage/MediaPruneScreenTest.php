<?php

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Domain\Media\MediaAudit;
use App\Domain\Media\MediaStore;
use App\Models\User;
use Tests\Support\Props;
use Tests\Support\Staff;

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
