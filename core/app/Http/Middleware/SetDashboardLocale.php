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
 * When this was written it changed nothing anybody could see: `lang/en/manage.php` was empty and the
 * 1,422 dashboard strings were inline literals (docs/wave4d/I18N_BACKLOG_2026-09-14.md). It was the
 * seam — from there, a new string could be written translatable for free.
 *
 * **Both halves are live now (2026-09-17).** The English file is filled (99.8% coverage) and the
 * writing DIRECTION follows this locale too, through {@see Preferences::directionFor()}. The
 * paragraph that used to stand here explained why direction was deliberately pinned to `rtl`: the
 * shell was still Arabic whatever the operator picked, so an `ltr` layout would have mirrored the
 * furniture around Arabic text. That condition no longer holds, and the pin became the defect it was
 * guarding against — English words in a right-to-left shell.
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
