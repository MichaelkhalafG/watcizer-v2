<?php

namespace App\Models;

use App\Domain\Access\DashboardAccounts;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * The SHARED `users` table (D5: accounts are shared across storefronts and with the
 * legacy app). Column names follow the legacy schema: first_name, last_name, type.
 *
 * No factory on purpose: this table holds real customer accounts in every environment,
 * so nothing in the core app may fabricate rows in it. The core roles (admin | data-entry)
 * live in `core_user_roles` and are NEVER derived from `type` (AGENTS §2.18, wave 4A).
 *
 * The properties are annotated because this application reads them at PHPStan level 10 and
 * because the column list is a FACT about a legacy table — writing it down here is the same
 * discipline as the explicit column lists rule 9 asks for.
 *
 * @property int $id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string $type legacy enum: User|Admin|SuperAdmin — grants nothing in the dashboard
 * @property string|null $password
 * @property string|null $phone_number
 * @property string|null $image
 * @property string|null $remember_token
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property Carbon|null $last_reengagement_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    use Notifiable;

    protected $table = 'users';

    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'email', 'password', 'type', 'phone_number', 'image'];

    /** @var list<string> */
    protected $hidden = ['password', 'remember_token'];

    /*
     * ── The remember-token trapdoor (review 2026-09-11, 🔴-1) ────────────────────────────────
     *
     * Not passing `remember: true` to `Auth::attempt()` is NOT enough to keep core out of
     * `users.remember_token`. `SessionGuard::logout()` does this:
     *
     *     if (! is_null($this->user) && ! empty($user->getRememberToken())) {
     *         $this->cycleRememberToken($user);          // → UPDATE users SET remember_token = …
     *     }
     *
     * So ANY staff account that already carries a token — one the LEGACY app's remember-me set, and
     * every returning customer has one — gets a write to a legacy table on every dashboard logout.
     * `SessionGuard::login(…, remember: true)` has the mirror-image trapdoor through
     * `ensureRememberTokenIsSet()`, which cycles the token precisely when it is EMPTY.
     *
     * Wave 4A's test missed this by NULLing the column before signing in, which walked straight
     * into the one branch that does not write. The three overrides below close both doors at the
     * model, so the guard's own conditions can never be satisfied:
     *
     *   • `getRememberToken()` → null      : logout's `empty(…)` is true, so it never cycles; and
     *                                        `EloquentUserProvider::retrieveByToken()` returns null,
     *                                        so a recaller cookie can never authenticate either.
     *   • `setRememberToken()` → no-op     : `updateRememberToken()` leaves the model CLEAN, so the
     *                                        `save()` that follows issues no UPDATE at all.
     *   • `getRememberTokenName()` → ''    : the trait's own accessors become no-ops, which is what
     *                                        makes the two above true rather than merely polite.
     *
     * The column keeps working for the legacy application, which owns it. Core simply never has an
     * opinion about it. `tests/Feature/Manage/AuthTest.php` drives a login→logout for a user who
     * HOLDS a token and asserts both the column and the 65-table legacy digest are untouched.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Deliberately nothing: see the block above.
    }

    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * ── The write guard (AGENTS §2.18, rewritten 2026-09-20) ────────────────────────────────
     *
     * Core writes this legacy table for exactly two operations — creating a dashboard account and
     * changing a password — and both live in {@see DashboardAccounts}. Everything else is refused
     * HERE rather than by convention, because a rule enforced by a docblock is one the next
     * contributor breaks without noticing, and the symptom would be a silently modified row in a
     * table holding every customer account.
     *
     * `saving` covers insert and update both. `deleting` is separate and absolute: core deletes no
     * account, ever — a person who should lose dashboard access has their ROLE revoked, which is a
     * `core_user_roles` row and nothing to do with this table.
     *
     * ── It guards a CHANGE, not a call ──────────────────────────────────────────────
     *
     * `isDirty()` is load-bearing, not a nicety. Eloquent fires `saving` before it checks whether
     * anything is actually dirty, and the three overrides above work precisely by leaving the model
     * CLEAN — `setRememberToken()` is a no-op, so the `save()` the framework issues inside
     * `updateRememberToken()` writes no columns. A guard on the call rather than the change turned
     * that documented no-op into an exception and broke `Auth::login($user, remember: true)`, which
     * is the one path wave 4A's overrides exist to render harmless.
     *
     * So the overrides stay the first line of defence — they stop the framework reaching for
     * `remember_token` at all — and this refuses anything that would genuinely alter a row.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            if ($user->isDirty() && ! DashboardAccounts::permitted()) {
                DashboardAccounts::refuse('write');
            }
        });

        static::deleting(function (): void {
            DashboardAccounts::refuse('delete from');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_reengagement_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
