<?php

namespace App\Storefront;

/**
 * WHO a storefront is, for everything it sends (W2, A2 — developer, 2026-10-13).
 *
 * The host says WHICH storefront; the storefront says who it is. Everything here is read from the
 * storefront row — `storefronts.name` and `storefronts.settings.identity` — never from a constant,
 * never from the request host, never from `.env`. Until now the mail chrome hard-coded Watchizer:
 * the text wordmark in the header and footer, the WhatsApp number and the copyright line in
 * `config/notifications.php`, and "Watchizer" in six subjects and ten template titles. A Brand
 * Fashion customer would have received Watchizer's receipt.
 *
 * ── `settings.identity`, set by SQL when a storefront is created (backlog SF-IDENTITY-SQL) ──
 *
 *   mail_wordmark        path under `public/` of the mail wordmark PNG, 400x122 on an opaque #111
 *                        ground, displayed at 200x61 (spec §22.3); e.g. `assets/brand/<code>-mail-wordmark@2x.png`
 *   mail_wordmark_1x     the 200x61 fallback (optional)
 *   whatsapp_support     the CUSTOMER-SERVICE number, international digits — the mail footer's link
 *   whatsapp_developer   the developer's number, international digits — the storefront app's header
 *                        and footer (BF handover D22: two numbers, two roles, never unified)
 *   copyright            the footer's line, e.g. "© 2026 Watchizer. All rights reserved."
 *
 * ── No fallback (the rule from A2 batch 1) ───────────────────────────────────────────────────
 *
 * A storefront with no identity, or whose wordmark file is not on disk, gets {@see StorefrontHasNoIdentity}
 * naming what is missing. The mailers PARK the row with that message; nothing renders another
 * shop's wordmark and nothing renders a blank bar. One behaviour, two presentations: the templates
 * are the same for both shops, and only this object differs.
 */
final class StorefrontIdentity
{
    public function __construct(
        public readonly int $storefrontId,
        public readonly string $brandName,
        public readonly string $wordmarkPath,
        public readonly ?string $wordmark1xPath,
        public readonly string $whatsappSupport,
        public readonly string $whatsappDeveloper,
        public readonly string $copyright,
    ) {}

    public static function for(int $storefrontId): self
    {
        $name = StorefrontUrls::nameOf($storefrontId);
        if ($name === null || trim($name) === '') {
            throw StorefrontHasNoIdentity::for($storefrontId, 'the storefront has no name (or no row)');
        }

        $raw = StorefrontUrls::settingsOf($storefrontId)['identity'] ?? null;
        $identity = is_array($raw) ? $raw : [];

        $wordmark = self::str($identity, 'mail_wordmark');
        if ($wordmark === null) {
            throw StorefrontHasNoIdentity::for($storefrontId, 'settings.identity.mail_wordmark is not set');
        }
        if (! is_file(public_path($wordmark))) {
            throw StorefrontHasNoIdentity::for($storefrontId, "the mail wordmark file is missing under public/ ({$wordmark})");
        }
        $wordmark1x = self::str($identity, 'mail_wordmark_1x');
        if ($wordmark1x !== null && ! is_file(public_path($wordmark1x))) {
            throw StorefrontHasNoIdentity::for($storefrontId, "the 1x mail wordmark file is missing under public/ ({$wordmark1x})");
        }

        $support = self::digits($identity, 'whatsapp_support');
        if ($support === null) {
            throw StorefrontHasNoIdentity::for($storefrontId, 'settings.identity.whatsapp_support is not set (international digits)');
        }
        $developer = self::digits($identity, 'whatsapp_developer');
        if ($developer === null) {
            throw StorefrontHasNoIdentity::for($storefrontId, 'settings.identity.whatsapp_developer is not set (international digits)');
        }
        $copyright = self::str($identity, 'copyright');
        if ($copyright === null) {
            throw StorefrontHasNoIdentity::for($storefrontId, 'settings.identity.copyright is not set');
        }

        return new self($storefrontId, trim($name), ltrim($wordmark, '/'), $wordmark1x === null ? null : ltrim($wordmark1x, '/'), $support, $developer, $copyright);
    }

    /** The wordmark's absolute URL, on THIS storefront's own asset host — never another shop's. */
    public function wordmarkUrl(): string
    {
        return StorefrontUrls::assetBase($this->storefrontId).'/'.$this->wordmarkPath;
    }

    /** The mail footer's customer-service WhatsApp link, with the Arabic prompt the footer has always carried. */
    public function whatsappSupportUrl(): string
    {
        return 'https://wa.me/'.$this->whatsappSupport.'?text='.rawurlencode('للاستعلام عن طلبك تواصل معنا على واتساب');
    }

    /**
     * What every e-mail template reads. The keys are the ones the ported templates already read
     * (`brandName`, `copyright`, `whatsappUrl`) plus the wordmark's URL.
     *
     * @return array{brandName: string, wordmarkUrl: string, whatsappUrl: string, copyright: string}
     */
    public function viewData(): array
    {
        return [
            'brandName' => $this->brandName,
            'wordmarkUrl' => $this->wordmarkUrl(),
            'whatsappUrl' => $this->whatsappSupportUrl(),
            'copyright' => $this->copyright,
        ];
    }

    /** @param  array<array-key, mixed>  $identity */
    private static function str(array $identity, string $key): ?string
    {
        $value = $identity[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param  array<array-key, mixed>  $identity */
    private static function digits(array $identity, string $key): ?string
    {
        $value = self::str($identity, $key);

        return $value !== null && preg_match('/^[0-9]{8,15}$/', $value) === 1 ? $value : null;
    }
}
