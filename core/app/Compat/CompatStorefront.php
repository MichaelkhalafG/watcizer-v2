<?php

namespace App\Compat;

use App\Storefront\StorefrontHost;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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
 * **The prerequisite this carries:** the two storefronts must answer on DISTINCT hosts. A host that
 * names no storefront is REFUSED with 404 in production (A2 no-fallback rule, developer
 * 2026-10-12) — the same rule and the same local/testing exception as `StorefrontHost::serves()`,
 * so the configured pin exists for the suite, `artisan serve` and the compat harness (which calls
 * `127.0.0.1`) only. It used to fall through to the pin everywhere, which was a guess with a
 * sensible default while only Watchizer was live and a silent misattribution — orders, methods,
 * the payment contract, all Watchizer's — the moment a second shop's host was routed here without
 * its row matching (backlog BF-HOST-STEP-D). In production an unknown host does not reach PHP at
 * all (the CDN and the Apache default arm refuse it), so this is the second lock. `unresolved()`
 * still reports a pinned request where the pin applies.
 */
final class CompatStorefront
{
    /** Resolved once per instance: the host cannot change inside one request. */
    private ?int $resolved = null;

    private bool $matched = false;

    public function __construct(private readonly Request $request) {}

    /**
     * A given storefront, for work that has no shopper's request: `catalog:warm` builds each
     * storefront's caches through the same `CompatServices` door with this.
     */
    public static function pinned(int $storefrontId): self
    {
        $s = new self(Request::create('/'));
        $s->resolved = $storefrontId;
        $s->matched = true;

        return $s;
    }

    /** The storefront this request belongs to. */
    public function id(): int
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $match = StorefrontHost::storefrontIdFor($this->request->getHost());
        $this->matched = $match !== null;
        if ($match === null && ! app()->environment('local', 'testing')) {
            throw new NotFoundHttpException('Not Found');
        }
        $this->resolved = $match ?? config()->integer('compat.storefront_id');

        return $this->resolved;
    }

    /**
     * True when the host named no storefront and the configured pin was used instead — which, since
     * 2026-10-12, can only happen in local and testing: in production such a request is a 404 before
     * this is ever asked. The harness calls `127.0.0.1` and is meant to answer as the default shop.
     */
    public function unresolved(): bool
    {
        $this->id();

        return ! $this->matched;
    }
}
