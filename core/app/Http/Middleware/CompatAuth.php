<?php

namespace App\Http\Middleware;

use App\Support\LegacyJwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The core equivalent of the legacy `auth:api` guard on the compat routes: a valid legacy JWT
 * whose subject still exists in `users`, or 401.
 *
 * The 401 body is the framework's own `{"message":"Unauthenticated."}` — which is exactly what
 * the legacy app emits, because its custom `Authenticate` middleware only overrides the
 * non-JSON redirect and its handler passes an `AuthenticationException` straight to the parent.
 * The harness compares the body byte for byte on the no-token, bad-token and expired-token
 * cases, so this is asserted rather than assumed.
 */
final class CompatAuth
{
    public const ATTRIBUTE = 'compat.user_id';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $userId = LegacyJwt::userId($request);
        if ($userId === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }
        $request->attributes->set(self::ATTRIBUTE, $userId);

        return $next($request);
    }

    /** The authenticated id, for a controller behind this middleware. */
    public static function id(Request $request): int
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_int($id) ? $id : 0;
    }
}
