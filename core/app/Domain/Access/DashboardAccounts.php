<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Activity\ActivityLog;
use App\Models\User;
use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The DASHBOARD's two `users` operations (AGENTS §2.18, rewritten 2026-09-20, amended 2026-09-21).
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
 * the permission was two operations wide and this class was the whole of it:
 *
 *   • {@see self::create()}         — a new dashboard account
 *   • {@see self::changePassword()} — an operator changing their own password
 *
 * ── What changed on 2026-09-21, and why it is not a loosening ───────────────────────────────
 *
 * This class used to say it was "the ONLY code in core that writes `users`", and that its
 * permission was void if a second writer appeared. Phase 1 of the storefront migration IS that
 * second writer: customer registration, profile edits and password changes move off the legacy host
 * and into core, because the storefront cannot be pointed at this database while its accounts are
 * created on another one.
 *
 * So the lock moved OUT of this class into {@see UserWrites}, which holds it once and declares
 * every operation permitted to open it. This class kept its two operations and lost only its
 * monopoly. The distinction matters: the permission did not become vaguer, it became enumerable —
 * `UserWrites::REASONS` is the whole answer to "may core write this table, and for what", and
 * widening it is one edit to one constant that a diff puts in front of a reviewer.
 *
 * ── The columns, and the ones deliberately left alone ───────────────────────────────────────
 *
 *   create          → first_name, last_name, email, password, type
 *   changePassword  → password
 *
 * NOT written by THIS class, ever: `remember_token`, `last_login_at` and `last_reengagement_at`
 * (the three framework defaults wave 4A turned off precisely to keep core out of them — see
 * {@see User}), and `image` / `phone_number`, which belong to the customer. A customer editing
 * their own profile writes the last two through their own door, never this one.
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

    /** The two reasons this class may open the {@see UserWrites} door with, and no others. */
    public const CREATE = 'dashboard.create';

    public const PASSWORD = 'dashboard.password';

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
            $user = UserWrites::open(self::CREATE, function () use ($data, $email): User {
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

        UserWrites::open(self::PASSWORD, function () use ($user, $replacement): void {
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
