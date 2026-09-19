<?php

namespace Tests\Support;

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Models\User;
use PHPUnit\Framework\Assert;

/**
 * Test accounts for the dashboard — grants, never users.
 *
 * `users` holds real customer accounts in every environment and this application may not fabricate
 * a row in it (App\Models\User has no factory, on purpose). So the suite does what production does:
 * it takes accounts that ALREADY exist and grants them a role. Every grant happens inside the
 * test's transaction and rolls back, so the local database's real grants are neither read nor
 * disturbed — each helper first clears whatever grants its account has, so a test's premise
 * ("this user is data-entry and nothing else") is true regardless of what the developer granted
 * from the shell.
 */
final class Staff
{
    /** An administrator: every ability, all storefronts. */
    public static function admin(): User
    {
        return self::withRole(0, Role::Admin);
    }

    /** A data-entry user: catalog, placement, legacy content, media — and nothing else. */
    public static function dataEntry(): User
    {
        return self::withRole(1, Role::DataEntry);
    }

    /**
     * A real account with NO dashboard grant — a customer. The interesting case: valid credentials,
     * possibly `users.type = SuperAdmin` in the legacy enum, and still no business in `/manage`.
     */
    public static function customer(): User
    {
        $user = self::nth(2);
        self::clear($user);

        return $user;
    }

    /**
     * An ADMIN grant scoped to ONE storefront — the case wave 4C needs for the payment screens.
     *
     * An administrator is not necessarily an administrator everywhere: a scoped admin grant holds
     * every ability inside its storefront and must answer 404 outside it, because "which account
     * takes this money" is a per-storefront question and the wrong answer routes real money.
     */
    public static function adminFor(int $storefrontId): User
    {
        $user = self::nth(0);
        self::clear($user);
        app(Roles::class)->assign($user, Role::Admin, $storefrontId);

        return $user;
    }

    /** A data-entry grant scoped to ONE storefront, for the scope tests. */
    public static function dataEntryFor(int $storefrontId): User
    {
        $user = self::nth(1);
        self::clear($user);
        app(Roles::class)->assign($user, Role::DataEntry, $storefrontId);

        return $user;
    }

    private static function withRole(int $nth, Role $role): User
    {
        $user = self::nth($nth);
        self::clear($user);
        app(Roles::class)->assign($user, $role);

        return $user;
    }

    /**
     * The name `core_activity_log.user_name` captures for an account — first and last, or the
     * email when the account carries neither.
     *
     * Composed here rather than asserted as "not empty", because the log CAPTURES the operator's
     * name at write time (so the row still reads correctly after the account is deleted) and a
     * test that only checked for a non-empty string would pass on anybody's name, including the
     * wrong one's.
     */
    public static function nameOf(User $user): string
    {
        $first = $user->getAttribute('first_name');
        $last = $user->getAttribute('last_name');
        $name = trim((is_string($first) ? $first : '').' '.(is_string($last) ? $last : ''));

        if ($name !== '') {
            return $name;
        }

        $email = $user->getAttribute('email');

        return is_string($email) ? $email : 'user';
    }

    private static function clear(User $user): void
    {
        foreach (Role::cases() as $role) {
            app(Roles::class)->revoke($user, $role, allScopes: true);
        }
        app(Roles::class)->forget($user);
    }

    /** The n-th real account by id — stable across machines, and never created by us. */
    private static function nth(int $nth): User
    {
        $user = User::query()->orderBy('id')->skip($nth)->first();
        Assert::assertInstanceOf(
            User::class,
            $user,
            'The local database needs at least '.($nth + 1).' accounts in `users` for the dashboard tests; it has '.User::query()->count().'.',
        );

        return $user;
    }
}
