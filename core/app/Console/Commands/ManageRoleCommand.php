<?php

namespace App\Console\Commands;

use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * manage:role — grant, revoke and list dashboard access.
 *
 * This command exists because of a bootstrap problem with no other honest answer: access to
 * `/manage` requires a `core_user_roles` row, nobody has one on a fresh install, and the dashboard
 * cannot be the place to grant the first one. A seeder is not the answer either — `users` holds
 * real customer accounts in every environment and nothing in this application may fabricate a row
 * in it (see App\Models\User). So the first administrator is granted from the server's shell, to an
 * account that ALREADY exists, by someone with shell access.
 *
 *   php artisan manage:role list
 *   php artisan manage:role grant  admin@example.com admin
 *   php artisan manage:role grant  entry@example.com data_entry --storefront=2
 *   php artisan manage:role revoke entry@example.com data_entry --all-scopes
 *
 * It never creates or edits a user, never touches a password, and never writes to any legacy table
 * apart from reading `users` to find the account.
 */
final class ManageRoleCommand extends Command
{
    protected $signature = 'manage:role
        {action : list|grant|revoke}
        {email? : the EXISTING account to grant to or revoke from}
        {role? : admin|data_entry}
        {--storefront= : scope the grant to one storefront id (default: all storefronts)}
        {--all-scopes : revoke the role on every storefront, not just the named scope}';

    protected $description = 'Grant, revoke or list dashboard roles (admin | data_entry) for existing accounts';

    public function handle(Roles $roles): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'list' => $this->listGrants($roles),
            'grant' => $this->grant($roles),
            'revoke' => $this->revoke($roles),
            default => $this->refuse("action must be list, grant or revoke (got [{$action}])."),
        };
    }

    private function listGrants(Roles $roles): int
    {
        $grants = $roles->grants();
        if ($grants === []) {
            $this->warn('No dashboard roles are granted — nobody can open /manage.');
            $this->line('Grant the first administrator with: php artisan manage:role grant <email> admin');

            return self::SUCCESS;
        }

        $this->table(
            ['user', 'name', 'email', 'role', 'storefront'],
            array_map(fn (array $g): array => [
                $g['user_id'], $g['name'], $g['email'], $g['role'], $g['storefront_id'] ?? 'all',
            ], $grants),
        );

        return self::SUCCESS;
    }

    private function grant(Roles $roles): int
    {
        [$user, $role] = $this->resolve();
        if ($user === null || $role === null) {
            return self::INVALID;
        }

        $storefrontId = $this->storefrontOption();
        $grant = $roles->assign($user, $role, $storefrontId);

        $this->info(sprintf(
            'granted %s to %s (%s)%s — grant #%d',
            $role->value, $user->email, $role->label('en'),
            $storefrontId === null ? ' on ALL storefronts' : " on storefront {$storefrontId}",
            $grant->id,
        ));
        $this->line('abilities: '.implode(', ', $role->abilities()));

        return self::SUCCESS;
    }

    private function revoke(Roles $roles): int
    {
        [$user, $role] = $this->resolve();
        if ($user === null || $role === null) {
            return self::INVALID;
        }

        $removed = $roles->revoke($user, $role, $this->storefrontOption(), (bool) $this->option('all-scopes'));
        $removed > 0
            ? $this->info("revoked {$role->value} from {$user->email} ({$removed} grant(s)).")
            : $this->warn("nothing to revoke: {$user->email} does not hold {$role->value} in that scope.");

        // Locking everyone out is a foot-gun worth a warning, not a refusal: it is legitimate when
        // a rebuild follows, and the command is only reachable from a shell.
        if ($roles->grants() === []) {
            $this->warn('No dashboard roles remain — nobody can open /manage until one is granted.');
        }

        return self::SUCCESS;
    }

    /** @return array{0: User|null, 1: Role|null} */
    private function resolve(): array
    {
        $email = $this->argument('email');
        $roleName = $this->argument('role');
        if (! is_string($email) || $email === '' || ! is_string($roleName) || $roleName === '') {
            $this->error('grant and revoke need an email and a role: manage:role grant <email> <'.implode('|', Role::values()).'>');

            return [null, null];
        }

        $role = Role::tryFromValue($roleName);
        if ($role === null) {
            $this->error("[{$roleName}] is not a role. Use one of: ".implode(', ', Role::values()));

            return [null, null];
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user instanceof User) {
            $this->error("No account with email [{$email}]. This command never creates users — the account must exist first.");

            return [null, null];
        }

        return [$user, $role];
    }

    private function storefrontOption(): ?int
    {
        $value = $this->option('storefront');

        return is_numeric($value) ? (int) $value : null;
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::INVALID;
    }
}
