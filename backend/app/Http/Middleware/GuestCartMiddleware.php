<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Tymon\JWTAuth\Facades\JWTAuth;

class GuestCartMiddleware
{
    /**
     * Resolve the caller's identity from either a JWT (logged-in user) or an
     * X-Guest-Token header (guest). The resolved identity is merged onto the
     * request as ['identity' => ['user_id' => …]] or ['guest_token' => …].
     * For guests, a token is minted when absent and echoed back on the response.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if ($token) {
            try {
                $payload = JWTAuth::setToken($token)->getPayload();
                $userId  = $payload->get('sub');
                $request->merge(['identity' => ['user_id' => $userId]]);
            } catch (\Exception $e) {
                // invalid JWT — fall through to guest
                $token = null;
            }
        }

        if (!$token) {
            $guestToken = $request->header('X-Guest-Token');

            if (!$guestToken) {
                $guestToken = (string) Str::uuid();
            }

            $request->merge(['identity' => ['guest_token' => $guestToken]]);

            $response = $next($request);
            $response->headers->set('X-Guest-Token', $guestToken);
            return $response;
        }

        return $next($request);
    }
}
