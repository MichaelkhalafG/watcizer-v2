<?php

namespace App\Http\Middleware;

use App\Compat\CartIdentity;
use App\Support\LegacyJwt;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of the legacy `GuestCartMiddleware`, behaviour for behaviour (wave 3).
 *
 * Resolves the caller from either a bearer JWT (logged-in) or an `X-Guest-Token` header (guest);
 * mints a UUID when a guest arrives without one and echoes it back on the response so the next
 * request carries it. An INVALID JWT falls through to the guest branch rather than 401 — that is
 * the legacy behaviour and the frontend depends on it (an expired session keeps its cart).
 *
 * The one deliberate difference is where the identity is put. The legacy middleware merges it
 * into the request INPUT (`$request->identity`); core puts it on the request ATTRIBUTES, which
 * no client can write and no validator can see. Same resolution, same responses, no path by
 * which a crafted body could name someone else's cart.
 */
final class CompatGuestCart
{
    public const ATTRIBUTE = 'compat.identity';

    public const HEADER = 'X-Guest-Token';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        // subject(), not userId(): the legacy middleware reads the `sub` claim straight off the
        // payload without touching the user provider, so a token whose account has since been
        // deleted still resolves to that id here. Reproduced exactly rather than improved — the
        // enforced-auth paths below DO check the provider, because `auth:api` does.
        $userId = LegacyJwt::subject($request->bearerToken());

        if ($userId !== null) {
            $request->attributes->set(self::ATTRIBUTE, CartIdentity::user($userId));

            return $next($request);
        }

        $guestToken = $request->header(self::HEADER);
        if (! is_string($guestToken) || $guestToken === '') {
            $guestToken = (string) Str::uuid();
        }
        $request->attributes->set(self::ATTRIBUTE, CartIdentity::guest($guestToken));

        $response = $next($request);
        $response->headers->set(self::HEADER, $guestToken);

        return $response;
    }
}
