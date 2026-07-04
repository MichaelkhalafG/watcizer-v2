<?php

namespace App\Http\Controllers\Api;

use App\Models\User;
use App\Models\SocialAccount;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use Tymon\JWTAuth\Facades\JWTAuth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'microsoft'];

    /**
     * Return the provider's OAuth URL as JSON. The SPA performs the redirect,
     * so the flow is stateless (no server session).
     */
    public function redirect($provider)
    {
        if (! in_array($provider, self::PROVIDERS, true)) {
            return response()->json(['error' => 'Unsupported provider.'], 422);
        }

        try {
            $url = Socialite::driver($provider)->stateless()->redirect()->getTargetUrl();
            return response()->json(['url' => $url]);
        } catch (\Exception $e) {
            Log::error('Social redirect failed (' . $provider . '): ' . $e->getMessage());
            return response()->json(['error' => 'Could not start social login.'], 500);
        }
    }

    /**
     * Handle the provider callback: find or create the user, link the social
     * account, mint a JWT (same token mechanism as email/password login), then
     * redirect back to the SPA with the token in the URL.
     */
    public function callback($provider)
    {
        $frontend = rtrim(config('services.frontend_url') ?: config('app.url'), '/');

        if (! in_array($provider, self::PROVIDERS, true)) {
            return redirect($frontend . '/auth/callback?error=unsupported_provider');
        }

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            Log::error('Social callback failed (' . $provider . '): ' . $e->getMessage());
            return redirect($frontend . '/auth/callback?error=social_failed');
        }

        $email = $socialUser->getEmail();
        if (! $email) {
            return redirect($frontend . '/auth/callback?error=no_email');
        }

        // 1) Already linked via this provider?
        $link = SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();
        $user = $link?->user;

        // 2) Otherwise match by email (link to the existing account).
        if (! $user) {
            $user = User::where('email', $email)->first();
        }

        // 3) Otherwise create a fresh, password-less, pre-verified account.
        if (! $user) {
            [$first, $last] = $this->splitName($socialUser->getName(), $email);
            $user = new User();
            $user->first_name       = $first;
            $user->last_name        = $last;
            $user->email            = $email;
            $user->password         = null; // social-only account
            $user->email_verified_at = now(); // the provider already verified it
            $user->save();
        } elseif (! $user->email_verified_at) {
            // Existing local account logging in via a verified provider.
            $user->email_verified_at = now();
            $user->save();
        }

        // Link the social account if not already linked.
        if (! $link) {
            SocialAccount::firstOrCreate(
                ['provider' => $provider, 'provider_id' => $socialUser->getId()],
                ['user_id' => $user->id],
            );
        }

        // Track activity for the re-engagement campaign.
        $user->forceFill(['last_login_at' => now()])->save();

        $token = JWTAuth::fromUser($user);

        return redirect($frontend . '/auth/callback?token=' . urlencode($token));
    }

    /**
     * Split a provider display name into first/last; fall back to the email
     * local-part when no name is supplied.
     */
    private function splitName(?string $name, string $email): array
    {
        $name = trim((string) $name);
        if ($name === '') {
            $name = explode('@', $email)[0];
        }
        $parts = preg_split('/\s+/', $name);
        $first = array_shift($parts) ?: 'User';
        $last  = $parts ? implode(' ', $parts) : '';
        return [$first, $last];
    }
}
