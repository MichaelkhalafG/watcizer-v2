<?php

namespace App\Domain\Access;

use App\Models\Access\UserRole;
use App\Models\Storefront\Storefront;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single door for reading and writing role grants — the `InventoryService` pattern applied to
 * access: one class, one table, no second path.
 *
 * Reads are memoised PER REQUEST (not cached in the app cache): a grant revoked in one request must
 * take effect in the next one, and a role table with four rows does not need a cache. The dashboard
 * shell asks for a user's roles two or three times per render (route gate, nav filter, user menu),
 * which the memo collapses into one query.
 */
final class Roles
{
    /** @var array<int, list<UserRole>> user id => grants, for this request only */
    private array $memo = [];

    /** @return list<UserRole> */
    public function for(User $user): array
    {
        $id = $user->id;
        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        /** @var list<UserRole> $grants */
        $grants = UserRole::query()->where('user_id', $id)->orderBy('id')->get()->all();

        return $this->memo[$id] = $grants;
    }

    /**
     * The roles this user holds, ignoring scope.
     *
     * @return list<Role>
     */
    public function rolesFor(User $user): array
    {
        $roles = [];
        foreach ($this->for($user) as $grant) {
            $role = $grant->asRole();
            if ($role !== null && ! in_array($role, $roles, true)) {
                $roles[] = $role;
            }
        }

        return $roles;
    }

    /** May this user open the dashboard at all? */
    public function hasAnyRole(User $user): bool
    {
        return $this->rolesFor($user) !== [];
    }

    public function isAdmin(User $user): bool
    {
        return in_array(Role::Admin, $this->rolesFor($user), true);
    }

    /**
     * Does this user hold an ability — optionally on ONE storefront?
     *
     * A grant with `storefront_id = NULL` covers every storefront. A scoped grant covers only its
     * own, and when the caller names no storefront a scoped grant still counts: "may this user edit
     * placement anywhere" is the question the nav asks, and the screen asks the scoped question
     * again with the storefront in hand.
     */
    public function can(User $user, string $ability, ?int $storefrontId = null): bool
    {
        foreach ($this->for($user) as $grant) {
            $role = $grant->asRole();
            if ($role === null || ! $role->can($ability)) {
                continue;
            }
            $scope = $grant->storefront_id;
            if ($scope === null || $storefrontId === null || $scope === $storefrontId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Storefront ids this user is scoped to, or null when they are unscoped (all storefronts).
     *
     * @return list<int>|null
     */
    public function storefrontScope(User $user): ?array
    {
        $ids = [];
        foreach ($this->for($user) as $grant) {
            if ($grant->storefront_id === null) {
                return null;
            }
            $ids[] = $grant->storefront_id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Grant a role. Idempotent: the same (user, role, scope) twice is one row, which is what M1g's
     * unique index enforces underneath.
     *
     * @throws InvalidArgumentException when the storefront does not exist
     */
    public function assign(User $user, Role $role, ?int $storefrontId = null, ?User $grantedBy = null): UserRole
    {
        if ($storefrontId !== null && ! Storefront::query()->whereKey($storefrontId)->exists()) {
            throw new InvalidArgumentException("Storefront {$storefrontId} does not exist.");
        }

        $grant = UserRole::query()->firstOrCreate(
            ['user_id' => $user->id, 'role' => $role->value, 'storefront_id' => $storefrontId],
            ['granted_by' => $grantedBy?->id],
        );
        unset($this->memo[$user->id]);

        return $grant;
    }

    /** Revoke one role (all scopes, or one). Returns how many grants were removed. */
    public function revoke(User $user, Role $role, ?int $storefrontId = null, bool $allScopes = false): int
    {
        $query = UserRole::query()->where('user_id', $user->id)->where('role', $role->value);
        if (! $allScopes) {
            $storefrontId === null ? $query->whereNull('storefront_id') : $query->where('storefront_id', $storefrontId);
        }
        $removed = $query->delete();
        unset($this->memo[$user->id]);

        return is_int($removed) ? $removed : 0;
    }

    /**
     * Every user who holds a role, for the future users screen and for the `manage:role list`
     * command. Joins the shared `users` table read-only.
     *
     * @return list<array{user_id: int, name: string, email: string, role: string, storefront_id: int|null}>
     */
    public function grants(): array
    {
        $rows = DB::table('core_user_roles as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->orderBy('u.id')->orderBy('r.role')
            ->get(['r.user_id', 'r.role', 'r.storefront_id', 'u.first_name', 'u.last_name', 'u.email']);

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'user_id' => (int) (is_numeric($row->user_id) ? $row->user_id : 0),
                'name' => trim((is_scalar($row->first_name) ? (string) $row->first_name : '').' '.(is_scalar($row->last_name) ? (string) $row->last_name : '')),
                'email' => is_scalar($row->email) ? (string) $row->email : '',
                'role' => is_scalar($row->role) ? (string) $row->role : '',
                'storefront_id' => is_numeric($row->storefront_id) ? (int) $row->storefront_id : null,
            ];
        }

        return $out;
    }

    /** Drop the per-request memo (tests that grant and then assert in one request). */
    public function forget(?User $user = null): void
    {
        $user === null ? $this->memo = [] : ($this->memo = array_diff_key($this->memo, [$user->id => true]));
    }
}
