<?php

namespace App\Support;

use App\Models\Storefront\Storefront;

/**
 * Resolves the dashboard's marks and names — the one indirection between the shell and where
 * branding actually comes from.
 *
 * Today it answers from `config/branding.php`. From 4B a storefront may carry its own mark in
 * `storefronts.settings` (`settings.branding.logo`, `…logo_light`, `…name`), and the ONLY change
 * needed is inside {@see self::forStorefront()} — no component, no layout and no Blade template
 * mentions a file path or a brand name anywhere.
 *
 * @phpstan-type BrandingPayload array{name: string, suffix: string, logo: string, logo_light: string, logo_width: int, logo_height: int}
 * @phpstan-type CreditPayload array{text: string, url: string|null}
 */
final class Branding
{
    /**
     * @return BrandingPayload
     */
    public static function forStorefront(?Storefront $storefront = null): array
    {
        $settings = self::settings($storefront);

        return [
            'name' => self::str($settings['name'] ?? null) ?? ($storefront === null ? config()->string('branding.name') : $storefront->name),
            'suffix' => config()->string('branding.suffix'),
            'logo' => self::asset(self::str($settings['logo'] ?? null) ?? config()->string('branding.logo.default')),
            'logo_light' => self::asset(self::str($settings['logo_light'] ?? null) ?? config()->string('branding.logo.light')),
            'logo_width' => config()->integer('branding.logo.width'),
            'logo_height' => config()->integer('branding.logo.height'),
        ];
    }

    /**
     * The developer credit — separate from the branding payload ON PURPOSE.
     *
     * The brief puts it in the dashboard FOOTER and nowhere else: not the login screen, not the
     * header, not an operational screen. Keeping it out of `branding` is what makes that
     * structural instead of a convention: `HandleInertiaRequests` shares it only for an
     * authenticated session, so the login page's payload does not contain the string at all —
     * which a test asserts by reading the rendered HTML, not the component.
     *
     * @return CreditPayload
     */
    public static function credit(): array
    {
        return [
            'text' => config()->string('branding.credit.text'),
            'url' => self::str(config('branding.credit.url')),
        ];
    }

    /**
     * `settings.branding.*` of a storefront row, when it has any.
     *
     * @return array<string, mixed>
     */
    private static function settings(?Storefront $storefront): array
    {
        if ($storefront === null) {
            return [];
        }
        $settings = $storefront->settings;
        $branding = is_array($settings) ? ($settings['branding'] ?? null) : null;

        if (! is_array($branding)) {
            return [];
        }

        $out = [];
        foreach ($branding as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /** Absolute URL for a path inside core's own public tree; an absolute URL passes through. */
    private static function asset(string $path): string
    {
        return str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : asset($path);
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
