<?php

declare(strict_types=1);

namespace App\Domain\Payment;

use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontPaymentProvider;
use App\Support\Coerce;
use Illuminate\Http\Request;

/**
 * Where a provider must call back to — decided HERE, and sent with every intention (review 🔴-2).
 *
 * ── The defect this closes ───────────────────────────────────────────────────────────────────
 *
 * Until 2026-09-22 core sent neither `notification_url` nor `redirection_url` on a Paymob
 * intention, so the destination was whatever the **merchant portal** held. That is a URL in
 * somebody else's web interface, edited by hand, with no atomic cutover and nothing in this
 * repository that can assert what it says.
 *
 * It becomes a live hazard at the flip, not a tidiness point. The portal's URL names the legacy
 * host, and `.htaccess` §4 closes `/api` on `eleganceeg.com` — so a callback sent there after the
 * window **404s**: money taken, order left pending, stock left reserved, and the only symptom a
 * customer who paid and was never confirmed.
 *
 * The decision (developer, 2026-09-22, option (a)): **the destination travels with the
 * transaction.** Every intention carries both URLs, so a payment started by this application can
 * only ever call back to this application, whatever any portal says.
 *
 * ── Where the base comes from, and why the REQUEST is the primary source ─────────────────────
 *
 * The intention is created while serving `add_order` on the host the storefront is already
 * talking to — the same host whose `/api/pay/` path §4's allow-list opens. So the request's own
 * scheme and host is not a guess about the right destination: it IS the destination, observed
 * rather than configured, for the same reason `CompatStorefront` resolves the shop from the host
 * instead of from a setting. A configured base is a second place to be wrong, and the failure it
 * produces is the one above.
 *
 * `storefront_payment_providers.settings->callback_base` overrides it, for the case where the
 * public API host and the host serving the request genuinely differ (a private origin behind a
 * proxy). It is per contract, so two storefronts cannot share one wrong answer.
 *
 * ── It FAILS the intention rather than omitting the URLs ─────────────────────────────────────
 *
 * If no usable https base can be determined, `for()` returns null and the caller refuses to start
 * the payment. Omitting the URLs instead would silently hand the decision back to the portal —
 * which is the defect. A customer told "payment could not be started" has lost nothing; a customer
 * who paid into a 404 has lost money nobody can see.
 */
final class CallbackDestination
{
    /**
     * Both URLs for this contract, or null when no usable base exists.
     *
     * @return array{notification_url: string, redirection_url: string}|null
     */
    public static function for(Request $request, Storefront $storefront, StorefrontPaymentProvider $contract): ?array
    {
        $base = self::base($request, $contract);
        if ($base === null) {
            return null;
        }

        $url = $base.self::path(
            Coerce::str($storefront->getAttribute('code')),
            Coerce::str($contract->getAttribute('provider')),
        );

        /*
         * ONE url for both, which is what the existing alias already does. Paymob distinguishes
         * them by METHOD — the processed callback is a POST, the shopper's return is a GET — and
         * `PaymentCallbackController::done()` keys its answer on that, not on an Accept header a
         * server-to-server caller has no reason to send.
         */
        return ['notification_url' => $url, 'redirection_url' => $url];
    }

    /**
     * The callback PATH for a (storefront, provider) pair.
     *
     * Built literally rather than through `route()`, because `route()` resolves its host from
     * `APP_URL` — a value this deploy has already been bitten by (review 🟠-5), and one that says
     * nothing about the host actually serving the request. `CallbackDestinationTest` asserts this
     * string still matches the registered `pay.callback` route, so the two cannot drift apart
     * without a test failing.
     */
    public static function path(string $storefrontCode, string $provider): string
    {
        return '/api/pay/'.$storefrontCode.'/'.$provider.'/callback';
    }

    /** The scheme-and-host to hang the path on, validated, or null. */
    private static function base(Request $request, StorefrontPaymentProvider $contract): ?string
    {
        $configured = Coerce::nstr(data_get(Coerce::arr($contract->getAttribute('settings')), 'callback_base'));
        if ($configured !== null && self::usable($configured)) {
            return rtrim($configured, '/');
        }

        $observed = $request->getSchemeAndHttpHost();

        return self::usable($observed) ? rtrim($observed, '/') : null;
    }

    /**
     * A base a payment provider on the public internet can actually reach.
     *
     * https only — a provider will refuse a plaintext callback URL, and a redirect that downgrades
     * the shopper is its own problem. A host with no dot, an IP literal, or anything `.local` /
     * `.test` / `.invalid` is a workstation or a container, never somewhere Paymob can call.
     */
    private static function usable(string $base): bool
    {
        $parts = parse_url($base);
        if (! is_array($parts)) {
            return false;
        }
        if (($parts['scheme'] ?? null) !== 'https') {
            return false;
        }
        $host = strtolower(Coerce::str($parts['host'] ?? ''));
        if ($host === '' || ! str_contains($host, '.')) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        foreach (['.local', '.test', '.localhost', '.invalid', '.example'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return false;
            }
        }

        return true;
    }
}
