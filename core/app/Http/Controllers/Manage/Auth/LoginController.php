<?php

namespace App\Http\Controllers\Manage\Auth;

use App\Domain\Access\Roles;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Dashboard sign-in (CLEAN_CORE_STUDY §1: the Inertia dashboard lives at `/manage` on the core
 * host).
 *
 * ── The mechanism, and how it relates to the legacy JWT world ────────────────────────────────
 *
 * **Session cookie on the `web` guard, against the SHARED `users` table.** There is no second user
 * store and no password migration: the legacy application hashes with bcrypt, `users.password`
 * holds `$2y$10$…`, and PHP's `password_verify` accepts those hashes unchanged. An administrator
 * signs in here with the same credentials they use on the Blade dashboard today.
 *
 * The storefront API is a different world and stays that way. `Frontend-next` authenticates with a
 * tymon JWT minted by the legacy app; the compat layer verifies those tokens (`CompatAuth`) for
 * cart and account endpoints. Those two facts do not meet:
 *
 *  • A dashboard session grants NOTHING on `/api/*` — no route reads the session for API auth.
 *  • A JWT grants NOTHING on `/manage` — `EnsureDashboardAccess` reads the session guard only.
 *  • Signing out here does not invalidate a customer's JWT, and a legacy `logout` does not end a
 *    dashboard session. That is the correct behaviour for two different surfaces, and it sidesteps
 *    the wave-3 blacklist gap (AGENTS §6 prerequisite (b)) rather than inheriting it.
 *
 * **Nothing here writes to `users`.** Three deliberate omissions, each of which the framework would
 * otherwise do for us:
 *
 *  1. **No remember-me.** `Auth::attempt($credentials, remember: true)` persists
 *     `users.remember_token` — a write to a legacy table (§3). A 120-minute session is the trade.
 *  2. **No password re-hashing.** `hashing.rehash_on_login` is false; see `config/hashing.php`.
 *  3. **No `last_login_at` stamp.** The column exists and the legacy app maintains it; core reading
 *     the table does not entitle it to write the column. If the team wants "last seen" in the
 *     dashboard, it belongs in a core-owned table, and that is a 4D decision rather than a
 *     convenient one-liner here.
 */
final class LoginController
{
    /** Attempts per minute, keyed by email + IP: generous for a typo, useless for a spray. */
    private const MAX_ATTEMPTS = 5;

    public function show(Request $request): InertiaResponse|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('manage.home');
        }

        return Inertia::render('Auth/Login', [
            'status' => $request->session()->get('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $email = $request->string('email')->toString();
        $password = $request->string('password')->toString();

        $key = $this->throttleKey($request, $email);
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => trans('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        // No `remember` argument on purpose (see the class docblock): it would write to `users`.
        if (! Auth::attempt(['email' => $email, 'password' => $password])) {
            RateLimiter::hit($key, 60);

            // One message for "no such account" and for "wrong password", so the form cannot be
            // used to enumerate which addresses are real.
            throw ValidationException::withMessages(['email' => trans('auth.failed')]);
        }

        $user = Auth::user();
        if (! $user instanceof User || ! app(Roles::class)->hasAnyRole($user)) {
            // The credentials were right but the account is not staff. Sign it straight back out,
            // and say only what is true: this account cannot use the dashboard. Nothing here
            // reveals whether the address exists as a customer.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'email' => 'هذا الحساب لا يملك صلاحية الدخول إلى لوحة التحكم.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('manage.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('manage.login')->with('status', 'تم تسجيل الخروج.');
    }

    private function throttleKey(Request $request, string $email): string
    {
        return 'manage-login|'.Str::lower($email).'|'.($request->ip() ?? 'unknown');
    }
}
