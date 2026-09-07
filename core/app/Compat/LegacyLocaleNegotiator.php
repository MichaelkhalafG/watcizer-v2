<?php

namespace App\Compat;

use Locale;

/**
 * Port of mcamara/laravel-localization's LanguageNegotiator as configured on the legacy host
 * (supported en/ar with regional en_GB/ar_AE, useAcceptLanguageHeader = true, default en).
 *
 * Why this exists: the legacy web routes call LaravelLocalization::setLocale() while the route
 * files load, so EVERY legacy request — the API included — runs with the locale negotiated from
 * Accept-Language. astrotomic then appends the translated attributes of that locale
 * (`brand_name`, `product_title`, …) to every raw model row. Verified 2026-09-07 against the
 * running legacy app: `catalog/meta` and `all_product` change with `Accept-Language: ar`,
 * `products/{id}` (ProductResource, explicit locales) does not. Byte parity needs the same rule.
 */
final class LegacyLocaleNegotiator
{
    /**
     * @param  array<string, string>  $supported  locale => regional code
     */
    public static function negotiate(?string $acceptLanguage, array $supported, string $default): string
    {
        $useIntl = class_exists(Locale::class);
        $languages = [];
        foreach ($supported as $key => $regional) {
            $languages[$key] = [
                'lang' => $useIntl ? Locale::canonicalize($key) : $key,
                'regional' => $useIntl ? Locale::canonicalize($regional) : $regional,
            ];
        }

        $matches = self::matches($acceptLanguage);
        foreach ($matches as $key => $q) {
            $key = (string) $key;
            if (isset($languages[$key])) {
                return $key;
            }
            $canon = $useIntl ? Locale::canonicalize($key) : $key;
            foreach ($languages as $supportedKey => $locale) {
                if ($locale['regional'] === $canon || $locale['lang'] === $canon) {
                    return (string) $supportedKey;
                }
            }
        }
        if (isset($matches['*'])) {
            return (string) array_key_first($languages);
        }
        if ($useIntl && $acceptLanguage !== null && $acceptLanguage !== '') {
            $fromHttp = Locale::acceptFromHttp($acceptLanguage);
            if (is_string($fromHttp) && isset($languages[$fromHttp])) {
                return $fromHttp;
            }
        }

        return $default;
    }

    /**
     * The package's getMatchesFromAcceptedLanguages(), verbatim in behaviour.
     *
     * @return array<string, float>
     */
    private static function matches(?string $header): array
    {
        $matches = [];
        if ($header === null || $header === '') {
            return $matches;
        }
        $generic = [];
        foreach (explode(',', $header) as $option) {
            $parts = array_map('trim', explode(';', $option));
            $l = $parts[0];
            if (isset($parts[1])) {
                $q = (float) str_replace('q=', '', $parts[1]);
            } else {
                $q = null;
                if ($l === '*/*') {
                    $q = 0.01;
                } elseif (str_ends_with($l, '*')) {
                    $q = 0.02;
                }
            }
            $q ??= 1000 - count($matches);
            $matches[$l] = $q;
            $ops = explode('-', $l);
            array_pop($ops);
            while ($ops !== []) {
                $q -= 0.001;
                $op = implode('-', $ops);
                if (! isset($generic[$op]) || $generic[$op] > $q) {
                    $generic[$op] = $q;
                }
                array_pop($ops);
            }
        }
        $matches = array_merge($generic, $matches);
        arsort($matches, SORT_NUMERIC);

        return $matches;
    }
}
