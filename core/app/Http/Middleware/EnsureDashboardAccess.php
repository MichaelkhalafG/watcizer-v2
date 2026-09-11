<?php

namespace App\Http\Middleware;

use App\Domain\Access\Roles;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The dashboard's front door: authenticated AND holding at least one core role.
 *
 * Two separate refusals, deliberately different:
 *
 *  • Not signed in → redirect to the login screen (a person who bookmarked a page).
 *  • Signed in with NO role → **403**, not a redirect. This is a customer who has a perfectly
 *    valid storefront account and happens to hit `/manage`; bouncing them to a login form they
 *    can satisfy would loop forever. `users.type = SuperAdmin` grants nothing here — legacy's
 *    admin enum is not this application's authorisation (AGENTS §2.18).
 *
 * Route abilities (`can:manage-storefronts`) sit BEHIND this, so every `/manage` route is checked
 * twice: may you be here at all, and may you do this.
 */
final class EnsureDashboardAccess
{
    /** @param  Closure(Request): SymfonyResponse  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Unauthenticated.'], 401)
                : redirect()->guest(route('manage.login'));
        }

        if (! app(Roles::class)->hasAnyRole($user)) {
            abort(403, 'This account has no dashboard access.');
        }

        return $next($request);
    }
}
