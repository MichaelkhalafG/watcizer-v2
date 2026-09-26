<?php

declare(strict_types=1);

namespace App\Http\Controllers\Customer;

use App\Domain\Customers\CustomerSocial;
use App\Domain\Customers\CustomerTokens;
use App\Domain\Customers\SocialNonce;
use App\Http\Controllers\Controller;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
 * the flow keeps no SERVER session — `->stateless()` on the driver, which is what lets an SPA on
 * another origin drive it at all.
 *
 * **It is not state-LESS any more (review 🟠-8).** `stateless()` removed the OAuth `state` check
 * outright, which left login CSRF wide open: an attacker's `code`, fed to a victim's browser,
 * signed that browser into the ATTACKER'S account. The state is now a nonce the STOREFRONT
 * generates, signed by this application and verified on the way back — see {@see SocialNonce} for
 * the whole argument, including why the comparison has to happen in the SPA and not here.
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
 * Every failure lands on `/auth/callback?error=<code>&nonce=<the nonce>`. The codes are stable and
 * the storefront
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

    public function redirect(Request $request, string $provider): JsonResponse
    {
        if (! CustomerSocial::supports($provider)) {
            return response()->json(['error' => 'Unsupported provider.'], 422);
        }

        /*
         * ── The nonce, before anything else (review 🟠-8) ────────────────────────────────────
         *
         * Required, not optional. An optional nonce is a flow an attacker simply starts without
         * one, and then the protection exists only for the honest caller.
         */
        $nonce = Coerce::str($request->query('nonce'));
        if (! SocialNonce::valid($nonce)) {
            return response()->json([
                'error' => 'Could not start social login.',
            ], 422);
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
            /*
             * `with(['state' => …])` rather than Socialite's own state.
             *
             * Under `stateless()` Socialite adds no `state` at all (`getCodeFields()` includes it
             * only when `usesState()`), but it does merge whatever `with()` was given — so this is
             * how a state parameter reaches the provider on a flow that has no session to keep one
             * in. Google echoes it back verbatim on the callback.
             */
            $url = $this->driver($provider)
                ->with(['state' => SocialNonce::state($nonce)])
                ->redirect()
                ->getTargetUrl();

            return response()->json(['url' => $url]);
        } catch (Throwable $e) {
            // By reference: a driver exception can carry the client secret in its message.
            Log::error('social redirect failed', ['provider' => $provider, 'reason' => $e->getMessage()]);

            return response()->json(['error' => 'Could not start social login.'], 500);
        }
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        if (! CustomerSocial::supports($provider)) {
            return $this->back($request, 'unsupported_provider');
        }

        /*
         * ── The state check, BEFORE the code is exchanged (review 🟠-8) ──────────────────────
         *
         * First, because exchanging the `code` tells the provider this application accepted the
         * callback, and there is no reason to do that for a callback we are about to refuse.
         *
         * A missing or unsigned state is the naive login-CSRF attempt and is refused here. The
         * nonce that survives is handed back to the storefront, which compares it with the one it
         * generated — that comparison is what defeats the attack where the attacker supplies a
         * state of their own, because it will be THEIR nonce and the victim's browser has nothing
         * matching it. See {@see SocialNonce}.
         */
        $nonce = SocialNonce::fromState(Coerce::nstr($request->query('state')));
        if ($nonce === null) {
            Log::warning('social callback rejected: missing or invalid state', ['provider' => $provider]);

            return $this->back($request, 'invalid_state');
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

            return $this->back($request, 'social_failed', null, $nonce);
        }

        $outcome = $this->social->resolve($provider, $account);

        if (array_key_exists('refused', $outcome)) {
            return $this->back($request, Coerce::str($outcome['refused']), null, $nonce);
        }

        return $this->back($request, null, $this->tokens->issue($outcome['user']), $nonce);
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

    /**
     * Back to the storefront's callback page, with a token or an error code — and the nonce.
     *
     * The nonce rides on EVERY answer, including the refusals (review 🟠-8). A storefront that
     * only got it on success could not tell "my flow was refused" from "somebody else's flow
     * landed in my tab", and the second is the case worth refusing. It is absent only when the
     * state itself did not verify, because then there is no nonce this application issued.
     */
    private function back(Request $request, ?string $error, ?string $token = null, ?string $nonce = null): RedirectResponse
    {
        // Back to the shop the sign-in STARTED from (L5, 2026-09-26): the provider calls back to
        // that shop's own API host. One global landed every storefront's shoppers on Watchizer.
        $base = StorefrontUrls::frontend(StorefrontUrls::storefrontOf($request)).'/auth/callback';
        $query = $error !== null ? ['error' => $error] : ['token' => $token];
        if ($nonce !== null) {
            $query['nonce'] = $nonce;
        }

        return redirect($base.'?'.http_build_query($query));
    }
}
