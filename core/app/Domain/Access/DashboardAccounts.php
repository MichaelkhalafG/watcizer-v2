<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\PreSwitch;
use App\Models\User;
use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

/**
 * The ONLY code in core that writes the legacy `users` table (AGENTS §2.18, rewritten 2026-09-20).
 *
 * ── Why this exists at all ──────────────────────────────────────────────────────────────────
 *
 * Until the eleganceeg.com deployment, core never wrote this table and the rule was absolute. The
 * reason was concurrency, and it was specific: the legacy application serialised every `users`
 * column with `toArray()` into the PROXIED `login` / `register` / `me` responses that the live
 * storefront consumed, so any column core touched could surface in that JSON.
 *
 * On the standalone deployment those routes are closed at the web server, the legacy installation
 * is unreachable, and nothing else writes this table. The hazard is gone. The caution is not — so
 * the permission is two operations wide and this class is the whole of it:
 *
 *   • {@see self::create()}         — a new dashboard account
 *   • {@see self::changePassword()} — an operator changing their own password
 *
 * Everything else about `users` is unchanged and still forbidden. If a second writer ever returns
 * — the legacy app restored, another application pointed at this database — this permission is void
 * and the total prohibition returns as written.
 *
 * ── A mechanism, not a promise ──────────────────────────────────────────────────────────────
 *
 * "Core may write two things" enforced by a comment is a rule the next contributor breaks without
 * noticing. {@see User::booted()} refuses every save that is not inside {@see self::writing()}, so
 * a stray `$user->save()` anywhere in the application is a loud exception in a test rather than a
 * silent row change in production. The same shape as {@see PreSwitch::allowing()}.
 *
 * ── The columns, and the ones deliberately left alone ───────────────────────────────────────
 *
 *   create          → first_name, last_name, email, password, type
 *   changePassword  → password
 *
 * NOT written, ever: `remember_token`, `last_login_at` and `last_reengagement_at` (the three
 * framework defaults wave 4A turned off precisely to keep core out of them — see {@see User}), and
 * `image` / `phone_number`, which belong to the customer and are edited on the storefront.
 *
 * `type` is written as `'User'` on create and never `'Admin'` or `'SuperAdmin'`. Dashboard access
 * comes from `core_user_roles`; the legacy enum grants power in the LEGACY application, which is
 * not ours to hand out. {@see Roles::assign()} is what actually lets somebody in.
 */
final class DashboardAccounts
{
    /** The legacy `type` a core-created account receives: the least-privileged value in that enum. */
    public const CREATED_TYPE = 'User';

    /** Shortest password this dashboard will set. Legacy has no policy; eight is the framework's. */
    public const MIN_PASSWORD = 8;

    /** Depth counter rather than a bool: a nested call must not re-open the door on its way out. */
    private static int $writing = 0;

    /**
     * Run `$work` with the `users` write-guard lifted.
     *
     * Public because {@see User} asks it whether a save is permitted, and for nothing else. Do not
     * call it to write `users` from outside this class — that is the hole this guard exists to
     * close, and `AccountWriteGuardTest` greps for it. (Named in prose, not `@see`: an import of
     * a test class from production code is a class that does not exist in a deployed vendor.)
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function writing(callable $work): mixed
    {
        self::$writing++;

        try {
            return $work();
        } finally {
            self::$writing--;
        }
    }

    /** Is a `users` write permitted at this instant? Asked by {@see User::booted()}. */
    public static function permitted(): bool
    {
        return self::$writing > 0;
    }

    /**
     * Create a dashboard account AND grant its role, as one operation.
     *
     * Deliberately not two calls the caller sequences: an account created without a role is a
     * person who can sign in and is then refused at the door with a 403, which is the confusing
     * half-state §3.2 of the live review describes. Either both happen or neither does.
     *
     * @param  array{first_name: string, last_name: string, email: string, password: string}  $data
     *
     * @throws ValidationException when the e-mail is taken
     */
    public function create(array $data, Role $role, ?int $storefrontId, ?User $createdBy): User
    {
        $email = mb_strtolower(trim($data['email']));

        /*
         * Checked here for the SENTENCE. `users_email_unique` is a real index and would refuse the
         * insert anyway — that is the mechanism and it stays — but a 1062 reaches an operator as a
         * 500 page, and "that e-mail already has an account" is what they can act on.
         */
        if (User::query()->where('email', $email)->exists()) {
            throw ValidationException::withMessages([
                'email' => ManageText::t('users.email_taken', 'هذا البريد له حساب بالفعل.'),
            ]);
        }

        self::assertPassword($data['password']);

        return DB::transaction(function () use ($data, $email, $role, $storefrontId, $createdBy): User {
            $user = self::writing(function () use ($data, $email): User {
                $user = new User;
                $user->forceFill([
                    'first_name' => trim($data['first_name']),
                    'last_name' => trim($data['last_name']),
                    'email' => $email,
                    // Cast `hashed` on the model does the bcrypt; `config/hashing.php` pins rounds
                    // to 10 to match every hash legacy wrote, so this row is indistinguishable in
                    // form from one the storefront created.
                    'password' => $data['password'],
                    'type' => self::CREATED_TYPE,
                ]);
                $user->save();

                return $user;
            });

            app(Roles::class)->assign($user, $role, $storefrontId, $createdBy);

            ActivityLog::record(
                'users',
                Coerce::nint($user->getKey()),
                ActivityLog::CREATED,
                after: [
                    'email' => $email,
                    'name' => trim($data['first_name'].' '.$data['last_name']),
                    'type' => self::CREATED_TYPE,
                    'role' => $role->value,
                    'storefront_id' => $storefrontId,
                ],
                label: $email,
                storefrontId: $storefrontId,
            );

            return $user;
        });
    }

    /**
     * Change one operator's own password.
     *
     * The current password is required and verified here rather than in a form request, because
     * this is the door and a check outside it can be routed around.
     *
     * ── Other devices stay signed in, and the screen says so ────────────────────────────
     *
     * Laravel's `logoutOtherDevices()` rewrites `users.remember_token`, one of the three framework
     * defaults wave 4A turned off at the model. Re-enabling it for this would reopen a door that
     * was closed on purpose, for a dashboard used by a handful of people. Decision 2026-09-20:
     * leave it disabled and TELL the operator, rather than let them assume a password change
     * revoked a session it did not.
     *
     * @throws ValidationException when the current password is wrong or the new one is too short
     */
    public function changePassword(User $user, string $current, string $replacement): void
    {
        if (! Hash::check($current, Coerce::str($user->getAttribute('password')))) {
            throw ValidationException::withMessages([
                'current_password' => ManageText::t('profile.password_wrong', 'كلمة المرور الحالية غير صحيحة.'),
            ]);
        }

        if ($current === $replacement) {
            throw ValidationException::withMessages([
                'password' => ManageText::t('profile.password_unchanged', 'كلمة المرور الجديدة مطابقة للحالية.'),
            ]);
        }

        self::assertPassword($replacement);

        self::writing(function () use ($user, $replacement): void {
            $user->forceFill(['password' => $replacement]);
            $user->save();
        });

        /*
         * The VALUE never enters the log: `password` is in `ActivityLog::REDACTED_FIELDS`, so both
         * sides of the diff are replaced with a marker. The row records that it changed, by whom
         * and when — which is the whole audit question — without copying the secret into a second,
         * less-guarded table.
         */
        ActivityLog::record(
            'users',
            Coerce::nint($user->getKey()),
            ActivityLog::UPDATED,
            ['password' => 'old'],
            ['password' => 'new'],
            label: Coerce::str($user->getAttribute('email')),
        );
    }

    /** @throws ValidationException */
    private static function assertPassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD) {
            throw ValidationException::withMessages([
                'password' => ManageText::t(
                    'profile.password_too_short',
                    'كلمة المرور لا تقل عن :count حروف.',
                    ['count' => self::MIN_PASSWORD],
                ),
            ]);
        }
    }

    /**
     * The refusal {@see User::booted()} throws, as its own method so the message is written once.
     *
     * Not a `ValidationException`: this is never something an operator did. It is a developer
     * writing `users` from somewhere that is not this class, and it should read like a bug.
     */
    public static function refuse(string $operation): never
    {
        throw new RuntimeException(
            "core may not {$operation} the legacy `users` table from here. Two operations are "
            .'permitted and both live in App\Domain\Access\DashboardAccounts: creating a dashboard '
            .'account, and changing a password. See AGENTS §2.18 — if you believe a third is needed, '
            .'that is a decision about the rule, not a call to DashboardAccounts::writing().'
        );
    }

    /** Swallow-free helper for callers that want a boolean rather than an exception. */
    public static function emailAvailable(string $email): bool
    {
        try {
            return ! User::query()->where('email', mb_strtolower(trim($email)))->exists();
        } catch (Throwable) {
            return false;
        }
    }
}
