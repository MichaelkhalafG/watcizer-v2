<?php

declare(strict_types=1);

namespace App\Domain\Customers;

use Illuminate\Support\Facades\Config;
use RuntimeException;

/**
 * The OAuth `state` parameter for social sign-in — one construction site, one verifier (🟠-8).
 *
 * ── What `->stateless()` gave away ───────────────────────────────────────────────────────────
 *
 * Socialite's `stateless()` does not merely move the state check elsewhere; it removes it. No
 * `state` was sent to Google and none was checked on the way back, so the callback accepted any
 * `code` from anyone. That is **login CSRF**: an attacker starts a Google sign-in as themselves,
 * keeps the resulting `code`, and gets a victim's browser to visit
 * `…/auth/google/callback?code=<theirs>`. The victim's storefront stores a token for the
 * ATTACKER'S account — and then shops, saves an address, places an order, all inside somebody
 * else's account, with no sign anything is wrong.
 *
 * The legacy application had the same hole. That is a reason to close it here, not a reason to
 * keep it: the flow is being rewritten either way, and this is the moment it costs nothing.
 *
 * ── Why `stateless()` was reached for, and what replaces it ──────────────────────────────────
 *
 * Socialite's own state lives in the SESSION. The storefront is an SPA on another origin and
 * `config/cors.php` sets `supports_credentials => false`, so no session cookie reaches these
 * routes and the session-backed check cannot work. That part of the reasoning was right.
 *
 * So the nonce is the SPA's, not the server's (developer decision, 2026-09-22):
 *
 *  1. `SocialButtons.jsx` generates a random nonce, keeps it in `sessionStorage`, and asks for the
 *     redirect URL with it;
 *  2. core signs it and sends it as the OAuth `state`, which the provider echoes back verbatim;
 *  3. on the callback core VERIFIES the signature, refuses outright if it is absent or wrong, and
 *     returns the nonce to the storefront beside the token;
 *  4. `AuthCallback.jsx` compares the returned nonce with the one it stored, and only then keeps
 *     the token.
 *
 * Step 4 is what closes the hole, and it is why the nonce belongs to the SPA: the attacker's flow
 * carries the ATTACKER'S nonce, so the victim's browser has nothing to match it against and
 * refuses the token. Step 3 is not redundant either — it is what stops a callback arriving with no
 * state at all, which is the naive version of the same attack.
 *
 * ── The nonce is NOT a secret ────────────────────────────────────────────────────────────────
 *
 * It travels in a URL, through Google, and back through a redirect. Its job is to be
 * UNPREDICTABLE and to be compared, not to be hidden. The signature is there so core can tell a
 * nonce it issued from one somebody appended by hand, which is the difference between refusing a
 * malformed callback and passing garbage to the storefront to sort out.
 */
final class SocialNonce
{
    /**
     * What a storefront-generated nonce may look like.
     *
     * URL-safe base64 alphabet: the value is put in a query string twice and `+`/`/` would have to
     * survive two rounds of encoding intact. 22 characters is 128 bits at the low end.
     */
    public const PATTERN = '/^[A-Za-z0-9_-]{22,128}$/';

    /** The separator between the nonce and its signature. Not in {@see self::PATTERN}. */
    private const SEPARATOR = '.';

    /** Is this a nonce the storefront could legitimately have generated? */
    public static function valid(string $nonce): bool
    {
        return preg_match(self::PATTERN, $nonce) === 1;
    }

    /**
     * The signed `state` to send to the provider.
     *
     * @throws RuntimeException when the nonce is not of the documented shape — a caller's bug, and
     *                          never something to sign anyway
     */
    public static function state(string $nonce): string
    {
        if (! self::valid($nonce)) {
            throw new RuntimeException('A social sign-in nonce must match '.self::PATTERN.'.');
        }

        return $nonce.self::SEPARATOR.self::sign($nonce);
    }

    /**
     * The nonce inside a `state` this application signed, or null.
     *
     * Null covers every failure with no distinction: absent, malformed, wrong signature, a nonce
     * of the wrong shape. The caller's answer is one error code for all of them, because the
     * customer's action is the same and the difference belongs in a log.
     */
    public static function fromState(?string $state): ?string
    {
        if ($state === null || $state === '') {
            return null;
        }

        $cut = strrpos($state, self::SEPARATOR);
        if ($cut === false || $cut === 0 || $cut === strlen($state) - 1) {
            return null;
        }

        $nonce = substr($state, 0, $cut);
        $signature = substr($state, $cut + 1);

        if (! self::valid($nonce)) {
            return null;
        }

        return hash_equals(self::sign($nonce), $signature) ? $nonce : null;
    }

    /**
     * The signature.
     *
     * Keyed on `APP_KEY`, which every deploy already has and which is already the root of every
     * other signature this application makes (signed routes, encrypted columns). A second secret
     * would be a second thing to set on switch night for no gain.
     */
    private static function sign(string $nonce): string
    {
        $key = Config::string('app.key');

        if ($key === '') {
            // Refusing beats signing with an empty key: an empty-key HMAC is a constant, so every
            // nonce would verify and the check would be decoration.
            throw new RuntimeException('APP_KEY is not set, so a social sign-in state cannot be signed.');
        }

        return hash_hmac('sha256', $nonce, $key);
    }
}
