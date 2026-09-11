<?php

namespace App\Domain\Access;

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Registers every dashboard ability with Laravel's Gate, so `->middleware('can:manage-storefronts')`
 * on a route and `Gate::allows()` in a controller both go through {@see Roles}.
 *
 * `Gate::before` short-circuits for an administrator. That is the one place admin power is
 * expressed, which means a screen added in 4B is reachable by an admin the moment its ability
 * exists, and NOT reachable by data-entry until someone adds it to {@see Role::abilities()} on
 * purpose. Deny-by-default for the role that has fewer rights is the right way round.
 */
final class Abilities
{
    public static function register(): void
    {
        $roles = app(Roles::class);

        Gate::before(function (User $user, string $ability) use ($roles): ?bool {
            // Only ever GRANTS. Returning false here would deny everything else outright and make
            // every later check unreachable; returning null falls through to the ability closure.
            if (in_array($ability, Role::ABILITIES, true) && $roles->isAdmin($user)) {
                return true;
            }

            return null;
        });

        foreach (Role::ABILITIES as $ability) {
            Gate::define($ability, fn (User $user, ?int $storefrontId = null): bool => $roles->can($user, $ability, $storefrontId));
        }
    }
}
