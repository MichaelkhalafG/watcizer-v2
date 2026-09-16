<?php

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use Tests\Support\Props;
use Tests\Support\Staff;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;

/*
 * `media:prune` is gated by an ability NOBODY holds by default — not even an administrator
 * (review, 2026-09-15).
 *
 * ── Why this needed its own mechanism ───────────────────────────────────────────────────────
 *
 * It was behind `manage-settings`, which every administrator holds. The button deletes files the
 * LIVE legacy storefront is still serving, and there is no undo — "everyone with an admin grant"
 * is too many hands for that.
 *
 * The obvious shortcut would have been to check the developer's e-mail address in code. That is the
 * hard-coded pattern this project keeps removing: it breaks the day the address changes and the day
 * a second person needs it, and it is invisible to every audit that reads the role table. So the
 * grant goes through the mechanism that already exists — a role, in `core_user_roles`, granted from
 * the shell and revocable the same way.
 *
 * ── The two halves, and why BOTH are asserted ───────────────────────────────────────────────
 *
 * A restricted ability is only restricted if it is (a) refused to everyone who has not been granted
 * it, including admins, and (b) actually grantable. An ability that is merely undefined satisfies
 * (a) and fails (b) — it would look identical from the outside on the day somebody needed it, and
 * nothing would say why.
 */

it('is NOT in the administrator ability set, which is what keeps Gate::before off it', function () {
    /*
     * The load-bearing assertion. `Abilities::register()` short-circuits `Gate::before` for an
     * admin on every entry in `Role::ABILITIES`, so membership of that list IS the admin grant.
     */
    expect(Role::ABILITIES)->not->toContain(Role::MANAGE_MEDIA_PRUNE)
        ->and(Role::RESTRICTED)->toContain(Role::MANAGE_MEDIA_PRUNE)
        // …and it still EXISTS, or it could never be granted to anybody.
        ->and(Role::ALL)->toContain(Role::MANAGE_MEDIA_PRUNE);
});

it('refuses an ADMINISTRATOR the screen and both routes', function () {
    $admin = Staff::admin();
    actingAs($admin);

    expect(app(Roles::class)->can($admin, Role::MANAGE_MEDIA_PRUNE))->toBeFalse();

    get('/manage/media/prune')->assertForbidden();
    delete('/manage/media/prune')->assertForbidden();
});

it('refuses a data-entry user, and a customer', function () {
    actingAs(Staff::dataEntry());
    get('/manage/media/prune')->assertForbidden();

    actingAs(Staff::customer());
    get('/manage/media/prune')->assertForbidden();
});

it('hides the nav item from an administrator', function () {
    // Presentation follows authorisation: the item is absent, not disabled, because a disabled
    // control for something you can never be given is noise.
    expect(Props::navKeys(actingAs(Staff::admin())->get('/manage')))->not->toContain('media-prune');
});

it('OPENS once the role is granted — the ability is real, not merely undefined', function () {
    $user = Staff::admin();
    app(Roles::class)->assign($user, Role::MediaPruner);
    app(Roles::class)->forget($user);

    actingAs($user);

    expect(app(Roles::class)->can($user, Role::MANAGE_MEDIA_PRUNE))->toBeTrue();
    get('/manage/media/prune')->assertOk();

    // …and the nav item comes back with it.
    expect(Props::navKeys(actingAs($user)->get('/manage')))->toContain('media-prune');
});

it('grants ONLY the prune, so the role cannot be a back door to anything else', function () {
    $user = Staff::customer();                    // no other grant at all
    app(Roles::class)->assign($user, Role::MediaPruner);
    app(Roles::class)->forget($user);

    $roles = app(Roles::class);

    expect($roles->can($user, Role::MANAGE_MEDIA_PRUNE))->toBeTrue();

    foreach (Role::ABILITIES as $ability) {
        expect($roles->can($user, $ability))->toBeFalse("media_pruner must not carry [{$ability}]");
    }

    expect(Role::MediaPruner->abilities())->toBe([Role::MANAGE_MEDIA_PRUNE]);
});

it('is revocable the same way it was granted', function () {
    $user = Staff::admin();
    $roles = app(Roles::class);

    $roles->assign($user, Role::MediaPruner);
    $roles->forget($user);
    expect($roles->can($user, Role::MANAGE_MEDIA_PRUNE))->toBeTrue();

    $roles->revoke($user, Role::MediaPruner, allScopes: true);
    $roles->forget($user);

    expect($roles->can($user, Role::MANAGE_MEDIA_PRUNE))->toBeFalse();
    actingAs($user);
    get('/manage/media/prune')->assertForbidden();
});

it('is offered by the grant command, so the runbook line actually works', function () {
    // `manage:role` validates against `Role::values()`, so a new case is enough — but only if the
    // case is really there. This is the assertion that the documented command is not a fiction.
    expect(Role::values())->toContain('media_pruner')
        ->and(Role::tryFromValue('media_pruner'))->toBe(Role::MediaPruner);
});
