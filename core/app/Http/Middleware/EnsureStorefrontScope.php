<?php

namespace App\Http\Middleware;

use App\Domain\Access\Roles;
use App\Models\Storefront\Storefront;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The SCOPE half of a storefront-scoped route: "may you act on THIS storefront?" — the rule wave
 * 4A's review recorded for 4B to inherit (study §3.11.14, 🟡-6).
 *
 * ── What it is for ───────────────────────────────────────────────────────────────────────────
 *
 * `core_user_roles.storefront_id` can scope a grant to one storefront, which Brand Fashion will
 * need. `can:manage-catalog` on a route asks the UNSCOPED question — "may this user edit a
 * catalogue anywhere" — and answers yes for a user scoped to storefront 1 even when the URL names
 * storefront 2. This middleware asks the scoped question with the storefront from the ROUTE in
 * hand, and it must sit on every route whose URL names one.
 *
 * ── Why it answers 404 and not 403 ───────────────────────────────────────────────────────────
 *
 * A 403 confirms the row exists. For a resource whose ids are not public — every catalogue id
 * here — that turns the URL into an oracle: walk the ids, read the status codes, learn the shape
 * of another storefront's catalogue. So "not yours" and "not there" are the same answer, which is
 * also what makes it safe for the CONTROLLERS to resolve child rows with a storefront-scoped
 * `findOrFail` and get the correct code for free.
 *
 * The one deliberate exception stays wave 4A's: an authenticated user with NO role at all gets
 * 403 on the whole dashboard (`EnsureDashboardAccess`), because there is nothing to enumerate and
 * bouncing them to a login form they can satisfy would loop forever.
 *
 * ── Why not "gate inside the controller" ─────────────────────────────────────────────────────
 *
 * Because it would be written once per screen, and 4B has six of them. As middleware it is
 * declared beside `can:` in the route file, where `RouteAuthorizationTest`'s structural check can
 * see it — and a 4B/4C route that names `{storefront}` without it is caught by the test that
 * asserts exactly that.
 *
 * Usage: `->middleware(['can:'.Role::MANAGE_CATALOG, EnsureStorefrontScope::with(Role::MANAGE_CATALOG)])`
 */
final class EnsureStorefrontScope
{
    /** The route segment that names the storefront. One name, so the test can look for it. */
    public const PARAMETER = 'storefront';

    /** `EnsureStorefrontScope::with('manage-catalog')` → the middleware string with its argument. */
    public static function with(string $ability): string
    {
        return self::class.':'.$ability;
    }

    /** @param  Closure(Request): SymfonyResponse  $next */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            // Unreachable behind `auth`, and still handled: a middleware that assumes a user is a
            // middleware that fails open when someone reorders the stack.
            abort(401);
        }

        $storefrontId = self::storefrontIdFrom($request);

        if (! app(Roles::class)->can($user, $ability, $storefrontId)) {
            // 404, not 403 — see the class docblock.
            abort(404);
        }

        return $next($request);
    }

    /**
     * The storefront id this request is about.
     *
     * Accepts a bound {@see Storefront} model or a raw segment, because a route may bind or not;
     * a route that reaches this middleware without the segment is a WIRING BUG and says so rather
     * than silently passing everyone. Failing loudly here is the difference between a mistake
     * caught by a test and a screen that never checked anything.
     */
    public static function storefrontIdFrom(Request $request): int
    {
        $value = $request->route(self::PARAMETER);

        if ($value instanceof Storefront) {
            return $value->id;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }

        throw new RuntimeException(
            'EnsureStorefrontScope is on a route with no {'.self::PARAMETER.'} segment: '
            .(string) $request->route()?->uri()
        );
    }
}
