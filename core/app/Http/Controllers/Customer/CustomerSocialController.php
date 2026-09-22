<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\CustomerSocial;
use App\Domain\Customers\CustomerTokens;
use App\Http\Controllers\Controller;
use App\Support\Coerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use RuntimeException;
use Throwable;

/**
 * `auth/{provider}/redirect` and `auth/{provider}/callback` (Phase 1, piece 5, 2026-09-22).
 *
 * ── Socialite, not a hand-rolled flow ───────────────────────────────────────────────────────
 *
 * Developer decision, 2026-09-22: *"use Socialite. It's Laravel's own, it's what the legacy app
 * used, and hand-rolling OAuth is exactly the kind of code where security breaks in a detail nobody
 * reviews."* `laravel/socialite` ^5.31 is in `composer.json`; `composer audit` was clean when it
 * was added.
 *
 * ── Two shapes, and each is what the running storefront already expects ─────────────────────
 *
 * **`redirect` answers JSON**, `{"url": "https://accounts.google.com/…"}`, and does NOT redirect.
 * `SocialButtons.jsx` fetches it with the API key and then sets `window.location.href` itself, so
 * the flow is stateless — `->stateless()` on the driver, no server session, which is what lets an
 * SPA on another origin drive it at all.
 *
 * **`callback` REDIRECTS to the storefront**, never JSON: the browser arrives here from Google, not
 * from the application, so whatever is returned is rendered as a page. It lands on
 * `/auth/callback?token=…` or `/auth/callback?error=…`, which `AuthCallback.jsx` already parses.
 *
 * ── Neither route can carry the API key, and that is why they sit outside that group ────────
 *
 * `callback` is a browser navigation from a third party. The legacy routes file puts its own pair
 * outside `CheckApi` in the same words. What stands in place of the key is the provider's own
 * signed exchange — Socialite trades the `code` with Google over TLS using the client secret, and a
 * caller who cannot complete that exchange gets nothing.
 *
 * `redirect` KEEPS the key (the storefront calls it with `Api-Code` set), because it can.
 *
 * ── Failures redirect with a CODE and never with a reason ───────────────────────────────────
 *
 * Every failure lands on `/auth/callback?error=<code>`. The codes are stable and the storefront
 * renders one generic message for all of them today — see {@see CustomerSocial} for what the
 * customer actually experiences, particularly in the refused-unverified branch, and why saying more
 * would be the account-enumeration answer `login` and `forgot-password` both withhold.
 */
final class CustomerSocialController extends Controller
{
    public function __construct(
        private readonly CustomerSocial $social,
        private readonly CustomerTokens $tokens,
    ) {}

    public function redirect(string $provider): JsonResponse
    {
        if (! CustomerSocial::supports($provider)) {
            return response()->json(['error' => 'Unsupported provider.'], 422);
        }

        /*
         * Configuration is checked BEFORE the customer leaves. Without this they authenticate at
         * Google, come back, and only then discover the shop cannot finish — having given a third
         * party their consent for nothing. Refusing here costs one click and no trust.
         */
        if (! CustomerSocial::configured($provider)) {
            Log::warning('social sign-in requested for an unconfigured provider', ['provider' => $provider]);

            return response()->json(['error' => 'Could not start social login.'], 500);
        }

        try {
            $url = $this->driver($provider)->redirect()->getTargetUrl();

            return response()->json(['url' => $url]);
        } catch (Throwable $e) {
            // By reference: a driver exception can carry the client secret in its message.
            Log::error('social redirect failed', ['provider' => $provider, 'reason' => $e->getMessage()]);

            return response()->json(['error' => 'Could not start social login.'], 500);
        }
    }

    public function callback(string $provider): RedirectResponse
    {
        if (! CustomerSocial::supports($provider)) {
            return $this->back('unsupported_provider');
        }

        try {
            $account = $this->driver($provider)->user();
        } catch (Throwable $e) {
            /*
             * A `code` that was replayed, expired, or never issued lands here, and so does a real
             * outage. They are not distinguished on purpose: the customer's action is the same
             * (try again), and the difference is in the log rather than in the URL.
             */
            Log::error('social callback failed', ['provider' => $provider, 'reason' => $e->getMessage()]);

            return $this->back('social_failed');
        }

        $outcome = $this->social->resolve($provider, $account);

        if (array_key_exists('refused', $outcome)) {
            return $this->back(Coerce::str($outcome['refused']));
        }

        return $this->back(null, $this->tokens->issue($outcome['user']));
    }

    /**
     * The configured driver, STATELESS.
     *
     * `Socialite::driver()` is typed as the `Provider` CONTRACT, which has no `stateless()` — that
     * lives on the OAuth2 `AbstractProvider`. Narrowing here rather than suppressing the analyser
     * keeps one real property checkable: if a future driver is not an OAuth2 one, the flow cannot
     * silently fall back to a SESSION-backed state check, which would break an SPA on another
     * origin in a way that only shows up as an intermittent `InvalidStateException`.
     */
    private function driver(string $provider): AbstractProvider
    {
        $driver = Socialite::driver($provider);

        if (! $driver instanceof AbstractProvider) {
            throw new RuntimeException("The [{$provider}] Socialite driver is not an OAuth2 provider, so it cannot run stateless.");
        }

        return $driver->stateless();
    }

    /** Back to the storefront's callback page, with a token or an error code and nothing else. */
    private function back(?string $error, ?string $token = null): RedirectResponse
    {
        $base = rtrim(config()->string('customers.storefront_url'), '/').'/auth/callback';

        return redirect($base.'?'.http_build_query($error !== null ? ['error' => $error] : ['token' => $token]));
    }
}
