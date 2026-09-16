<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Access\Preferences;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Apply the signed-in operator's chosen dashboard language to the request (i18n step 0).
 *
 * ── Why a middleware and not a line in the Inertia share ────────────────────────────────────
 *
 * `__()` resolves against the locale that is set when the CONTROLLER runs, and the Inertia share
 * happens after. Setting it there would give translated props and untranslated flash messages —
 * the kind of half-applied locale that is worse than none.
 *
 * ── What this does NOT do ───────────────────────────────────────────────────────────────────
 *
 * It does not change what anybody sees today, because `lang/en/manage.php` is empty and the 1 422
 * dashboard strings are still inline literals (docs/wave4d/I18N_BACKLOG_2026-09-14.md). It is the
 * seam: from here, a new string can be written translatable for free, and the backlog stops
 * growing while the translation work waits its turn.
 *
 * The one thing it deliberately leaves alone is the writing DIRECTION — see
 * {@see Preferences::TEXT_LOCALE}. Flipping the shell to `ltr` while every label in it is still
 * Arabic would be a regression dressed as progress.
 */
final class SetDashboardLocale
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User) {
            app()->setLocale(Preferences::localeFor($user));
        }

        return $next($request);
    }
}
