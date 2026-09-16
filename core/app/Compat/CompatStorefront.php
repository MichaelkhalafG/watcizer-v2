<?php

namespace App\Compat;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which storefront is this compat request for? (review 🔴-4)
 *
 * ── The defect this exists to close ──────────────────────────────────────────────────────────
 *
 * `CompatServices` used to read `config('compat.storefront_id')` — the literal `1`, commented
 * "Always Watchizer" — and hand it to every builder it constructs. That one value is the storefront
 * identity for the WHOLE live surface, so every request through the compat layer was attributed to
 * Watchizer no matter who was shopping:
 *
 *   • `PromotionEngine` was asked for storefront 1's rules, so a Brand Fashion shopper would be
 *     offered Watchizer's promotions and never their own;
 *   • `CompatCheckout` stamped `orders.storefront_id = 1` on every order (the 🟠 item "orders must
 *     record their real storefront" is the same defect seen from the other end);
 *   • `commitOrder()` attributed every INVENTORY MOVEMENT to storefront 1;
 *   • a storefront-scoped dashboard grant (§2.18) would therefore see the wrong orders — all of
 *     them, or none.
 *
 * The engine itself was never wrong: it joins `promotion_rule_storefront` as an INNER JOIN and a
 * rule not enabled for the cart's storefront is not a candidate (`StorefrontIsolationTest` proves
 * both directions). What was wrong was the identity handed to it. So the fix belongs at exactly one
 * place — here — and every consumer downstream is corrected by correcting this.
 *
 * ── How the identity is resolved, and what that assumes ─────────────────────────────────────
 *
 * From the request's HOST, matched against `storefronts.domain`, which is already populated
 * (`watchizereg.com`, `brandfashionegy.com`). The host is chosen over the `Origin` header
 * deliberately: `Origin` is absent on the storefront's server-side render calls and is set by the
 * client on the rest, so a shopper could ask for another shop's discounts by editing one header.
 * The host a request arrived on is set by the reverse proxy, not by the caller.
 *
 * The match is exact or by sub-domain, so the API host under a storefront's own domain resolves to
 * it (`dash.watchizereg.com` → Watchizer, `api.brandfashionegy.com` → Brand Fashion). The longest
 * domain wins, so a storefront on a sub-domain of another's domain is still read correctly.
 *
 * **The prerequisite this carries:** the two storefronts must answer on DISTINCT hosts. While both
 * are served from one API host there is nothing in the request to tell them apart, the resolver
 * falls through to the configured pin, and the identity is a guess with a sensible default. That is
 * today's situation and today it is correct, because only Watchizer is live. `unresolved()` reports
 * it so the condition is visible rather than assumed.
 */
final class CompatStorefront
{
    /** Resolved once per instance: the host cannot change inside one request. */
    private ?int $resolved = null;

    private bool $matched = false;

    public function __construct(private readonly Request $request) {}

    /** The storefront this request belongs to. */
    public function id(): int
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $match = $this->fromHost($this->request->getHost());
        $this->matched = $match !== null;
        $this->resolved = $match ?? config()->integer('compat.storefront_id');

        return $this->resolved;
    }

    /**
     * True when the host named no storefront and the configured pin was used instead.
     *
     * Not an error today: the harness calls `127.0.0.1`, a health check calls whatever the load
     * balancer uses, and both are meant to answer as the default storefront. It matters when a
     * SECOND storefront goes live, because from that moment an unattributed request is an order,
     * a promotion and a stock movement recorded against the wrong shop.
     */
    public function unresolved(): bool
    {
        $this->id();

        return ! $this->matched;
    }

    /**
     * Host → storefront id, or null when nothing matches.
     *
     * Only ACTIVE storefronts are considered: a shop that has been switched off must not start
     * claiming requests because its domain is still in the row.
     */
    private function fromHost(string $host): ?int
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return null;
        }

        // A port never reaches getHost(), but a trailing dot (the fully-qualified form) can.
        $host = rtrim($host, '.');
        $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;

        $best = null;
        $bestLength = 0;

        foreach (DB::table('storefronts')->where('is_active', 1)->get(['id', 'domain']) as $row) {
            $raw = $row->domain ?? null;
            $domain = is_string($raw) ? strtolower(trim($raw)) : '';
            if ($domain === '') {
                continue;
            }

            // Exact, or a sub-domain of it — never a bare suffix match, which would let
            // `notwatchizereg.com` resolve to Watchizer.
            $isMatch = $host === $domain || str_ends_with($host, '.'.$domain);

            if ($isMatch && strlen($domain) > $bestLength && is_numeric($row->id)) {
                $best = (int) $row->id;
                $bestLength = strlen($domain);
            }
        }

        return $best;
    }
}
