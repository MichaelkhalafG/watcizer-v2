<?php

namespace App\Http\Controllers\V2;

use App\Domain\Customers\CustomerSocial;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontContext;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v2/{storefront}/auth/providers` — the social sign-in providers THIS storefront can actually run (backlog
 * L3, developer decision 2026-10-19): `{"providers": ["google"]}`, or `[]`.
 *
 * {@see CustomerSocial::configured()} per storefront, the same answer the redirect route gives when it is asked to
 * start a flow — so a shop whose own Google project is not set up yet (Brand Fashion until its credentials are in
 * core's `.env`) draws no Google button and its privacy page does not claim Google sign-in, instead of offering a
 * button that fails with "Could not start social sign-in". The moment the credentials land (and `config:cache`
 * runs), the next read lists Google; no storefront build is involved.
 *
 * Configuration only — no customer data — so it may be kept briefly, but privately: Hostinger's CDN keeps one copy
 * of a PUBLIC answer per URL and drops `Vary: Origin` (the 2026-09-29 CORS incident in routes/api.php).
 */
class AuthController extends Controller
{
    public function providers(StorefrontContext $ctx): JsonResponse
    {
        $storefrontId = $ctx->id();
        $providers = array_values(array_filter(
            CustomerSocial::PROVIDERS,
            fn (string $provider): bool => CustomerSocial::configured($provider, $storefrontId),
        ));

        return response()->json(['providers' => $providers], 200, ['Cache-Control' => 'private, max-age=60']);
    }
}
