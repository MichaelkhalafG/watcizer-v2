<?php

declare(strict_types=1);

namespace App\Storefront;

use App\Models\Storefront\StorefrontMailbox;
use App\Support\Coerce;

/**
 * The storefront settings screen's three SQL-only sections, validated UP FRONT (§4.1, 2026-10-13).
 *
 * ── What this reverses, and why ─────────────────────────────────────────────────────────────
 *
 * SF-URL-SETTINGS (2026-10-12) decided these values were set by SQL only: the domain, the four
 * `settings.urls.*` overrides, the five `settings.identity.*` values. On 2026-10-13 that was
 * reversed (backlog SF-SETTINGS-SCREEN): a statement typed into PowerShell is escaped by the shell
 * (an identity statement would have written a literal `\$` as a JSON path), and a value that can
 * only be set by a developer with a database client is a value the shop cannot correct on a Friday
 * evening. SQL stays the path for the FIRST production run; afterwards this screen is.
 *
 * ── Why the checks are here and not only in the readers ─────────────────────────────────────
 *
 * `StorefrontUrls` throws {@see StorefrontHasNoHost} and `StorefrontIdentity` throws
 * {@see StorefrontHasNoIdentity} at READ time — in a mailer, a sitemap, a checkout. A save that
 * passed form validation and failed the first order would be the worst place to learn. So the
 * conditions those two readers refuse are checked HERE, before the write, in the same words:
 *
 *   • a storefront with no domain must name every URL override (else no host);
 *   • a URL override is `https://`, host only (the payment return may carry a path), no trailing slash;
 *   • the mail wordmark path must be a PNG that exists under `public/` — checked against disk;
 *   • the two WhatsApp numbers are international digits; the copyright line is not empty.
 *
 * The mailbox is validated for SHAPE; whether it is USABLE for a real send (host, login, password)
 * is reported on the screen as `missing`, not refused — a shop may save half a mailbox on Monday
 * and the password on Tuesday, and the order mailer retries meanwhile ({@see StorefrontMailer}).
 */
final class StorefrontSettingsForm
{
    public const URL_KEYS = ['frontend', 'payment_return', 'api_host', 'asset_base'];

    public const IDENTITY_KEYS = ['mail_wordmark', 'mail_wordmark_1x', 'whatsapp_support', 'whatsapp_developer', 'copyright'];

    /** @return array<string, list<mixed>> Laravel rules for the three sections, all optional as a whole */
    public static function rules(): array
    {
        return [
            'urls' => ['sometimes', 'array'],
            'urls.frontend' => ['nullable', 'string', 'max:255'],
            'urls.payment_return' => ['nullable', 'string', 'max:255'],
            'urls.api_host' => ['nullable', 'string', 'max:255'],
            'urls.asset_base' => ['nullable', 'string', 'max:255'],
            'identity' => ['sometimes', 'array'],
            'identity.mail_wordmark' => ['nullable', 'string', 'max:255'],
            'identity.mail_wordmark_1x' => ['nullable', 'string', 'max:255'],
            'identity.whatsapp_support' => ['nullable', 'string', 'max:20'],
            'identity.whatsapp_developer' => ['nullable', 'string', 'max:20'],
            'identity.copyright' => ['nullable', 'string', 'max:200'],
            'mailbox' => ['sometimes', 'array'],
            'mailbox.is_enabled' => ['nullable', 'boolean'],
            'mailbox.host' => ['nullable', 'string', 'max:255'],
            'mailbox.port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'mailbox.encryption' => ['nullable', 'string', 'in:tls,ssl,none'],
            'mailbox.username' => ['nullable', 'string', 'max:255'],
            'mailbox.password' => ['nullable', 'string', 'max:255'],
            'mailbox.from_address' => ['nullable', 'string', 'email', 'max:255'],
            'mailbox.from_name' => ['nullable', 'string', 'max:100'],
            'mailbox.daily_cap' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'mailbox.transactional_reserve' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'mailbox.order_recipients' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * The up-front checks: the readers' refusals, as form errors. Empty means the save may go.
     *
     * @param  array<string, mixed>|null  $urls  the submitted overrides, or null when the section was not sent
     * @param  array<string, mixed>|null  $identity
     * @param  array<string, mixed>  $currentSettings  the row's settings today, for a section not sent
     * @return array<string, string> field => message (ManageText keys resolved by the caller)
     */
    public static function problems(int $storefrontId, string $domain, ?array $urls, ?array $identity, array $currentSettings): array
    {
        $problems = [];

        $effectiveUrls = $urls ?? Coerce::arr($currentSettings['urls'] ?? []);
        foreach (self::URL_KEYS as $key) {
            $value = self::clean($effectiveUrls[$key] ?? null);
            if ($value === null) {
                continue;
            }
            $hostOnly = $key !== 'payment_return';
            if (! self::isHttpsUrl($value, $hostOnly)) {
                $problems["urls.{$key}"] = $hostOnly ? 'url_host_only' : 'url_https';
            }
        }
        // StorefrontHasNoHost, exactly as the readers decide it: with no domain, `api_host` always
        // derives from it and throws; `frontend` and `asset_base` do too EXCEPT for the default
        // storefront, whose env values (`customers.storefront_url`, `storefront.asset_base`) still
        // answer — so for Watchizer only the API host is demanded, for any other shop all three.
        if ($domain === '') {
            $isDefault = $storefrontId === config()->integer('compat.storefront_id');
            foreach ($isDefault ? ['api_host'] : ['frontend', 'api_host', 'asset_base'] as $key) {
                if (self::clean($effectiveUrls[$key] ?? null) === null && ! isset($problems["urls.{$key}"])) {
                    $problems["urls.{$key}"] = 'no_host';
                }
            }
        } elseif (! self::isHostname($domain)) {
            $problems['domain'] = 'domain_shape';
        }

        if ($identity !== null) {
            // StorefrontHasNoIdentity, condition by condition, before the write.
            $wordmark = self::clean($identity['mail_wordmark'] ?? null);
            if ($wordmark === null) {
                $problems['identity.mail_wordmark'] = 'required';
            } elseif (! self::isPublicPng($wordmark)) {
                $problems['identity.mail_wordmark'] = 'wordmark_missing';
            }
            $wordmark1x = self::clean($identity['mail_wordmark_1x'] ?? null);
            if ($wordmark1x !== null && ! self::isPublicPng($wordmark1x)) {
                $problems['identity.mail_wordmark_1x'] = 'wordmark_missing';
            }
            foreach (['whatsapp_support', 'whatsapp_developer'] as $key) {
                $digits = self::clean($identity[$key] ?? null);
                if ($digits === null) {
                    $problems["identity.{$key}"] = 'required';
                } elseif (preg_match('/^[1-9][0-9]{9,14}$/', $digits) !== 1) {
                    $problems["identity.{$key}"] = 'whatsapp_digits';
                }
            }
            if (self::clean($identity['copyright'] ?? null) === null) {
                $problems['identity.copyright'] = 'required';
            }
        }

        return $problems;
    }

    /**
     * The mailbox section's own checks (shape, not usability).
     *
     * @param  array<string, mixed>  $mailbox
     * @return array<string, string>
     */
    public static function mailboxProblems(array $mailbox): array
    {
        $problems = [];
        $cap = Coerce::nint($mailbox['daily_cap'] ?? null);
        $reserve = Coerce::nint($mailbox['transactional_reserve'] ?? null);
        if ($cap !== null && $reserve !== null && $reserve >= $cap) {
            $problems['mailbox.transactional_reserve'] = 'reserve_below_cap';
        }
        foreach (self::recipients(Coerce::str($mailbox['order_recipients'] ?? '')) as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
                $problems['mailbox.order_recipients'] = 'recipient_email';
                break;
            }
        }

        return $problems;
    }

    /**
     * `settings` with the two sections replaced and everything else kept. A blank value removes
     * its key, so the readers fall back to the derived value (URLs) or refuse (identity).
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>|null  $urls
     * @param  array<string, mixed>|null  $identity
     * @return array<string, mixed>
     */
    public static function merge(array $settings, ?array $urls, ?array $identity): array
    {
        if ($urls !== null) {
            $clean = [];
            foreach (self::URL_KEYS as $key) {
                $value = self::clean($urls[$key] ?? null);
                if ($value !== null) {
                    $clean[$key] = rtrim($value, '/');
                }
            }
            if ($clean === []) {
                unset($settings['urls']);
            } else {
                $settings['urls'] = $clean;
            }
        }
        if ($identity !== null) {
            $clean = [];
            foreach (self::IDENTITY_KEYS as $key) {
                $value = self::clean($identity[$key] ?? null);
                if ($value !== null) {
                    $clean[$key] = in_array($key, ['mail_wordmark', 'mail_wordmark_1x'], true) ? ltrim($value, '/') : $value;
                }
            }
            $settings['identity'] = $clean;
        }

        return $settings;
    }

    /**
     * Apply the submitted mailbox section to the row. The password is WRITE-ONLY: blank keeps what
     * is stored, exactly as a payment contract's credentials behave.
     *
     * @param  array<string, mixed>  $mailbox
     */
    public static function applyMailbox(StorefrontMailbox $row, array $mailbox): void
    {
        $row->setAttribute('is_enabled', Coerce::bool($mailbox['is_enabled'] ?? $row->getAttribute('is_enabled') ?? true));
        foreach (['host', 'username', 'from_address', 'from_name'] as $field) {
            $row->setAttribute($field, self::clean($mailbox[$field] ?? null));
        }
        $row->setAttribute('port', Coerce::int($mailbox['port'] ?? null, 587));
        $encryption = self::clean($mailbox['encryption'] ?? null);
        $row->setAttribute('encryption', in_array($encryption, StorefrontMailbox::ENCRYPTIONS, true) ? $encryption : 'tls');
        $row->setAttribute('daily_cap', Coerce::int($mailbox['daily_cap'] ?? null, 800));
        $row->setAttribute('transactional_reserve', Coerce::int($mailbox['transactional_reserve'] ?? null, 200));
        $row->setAttribute('order_recipients', self::recipients(Coerce::str($mailbox['order_recipients'] ?? '')));
        $password = self::clean($mailbox['password'] ?? null);
        if ($password !== null) {
            $row->setAttribute('credentials', ['password' => $password]);
        }
    }

    /**
     * One address per line or comma, trimmed, de-duplicated, order kept.
     *
     * @return list<string>
     */
    public static function recipients(string $raw): array
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) ?: [] as $part) {
            $part = trim($part);
            if ($part !== '' && ! in_array($part, $out, true)) {
                $out[] = $part;
            }
        }

        return $out;
    }

    private static function clean(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /** A trailing slash is tolerated and dropped on save (`merge()`); everything else is exact. */
    private static function isHttpsUrl(string $value, bool $hostOnly): bool
    {
        $value = rtrim($value, '/');
        $parts = parse_url($value);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || ! is_string($parts['host'] ?? null) || isset($parts['query']) || isset($parts['fragment'])) {
            return false;
        }
        if (! self::isHostname($parts['host'])) {
            return false;
        }
        $path = ($parts + ['path' => ''])['path'];           // parse_url omits the key for a bare host
        if ($hostOnly && $path !== '' && $path !== '/') {
            return false;
        }

        return true;
    }

    private static function isHostname(string $host): bool
    {
        return preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', strtolower($host)) === 1;
    }

    /** A relative path under public/ naming a PNG that exists — the same check StorefrontIdentity makes at read time. */
    private static function isPublicPng(string $path): bool
    {
        $path = ltrim($path, '/');
        if (str_contains($path, '..') || ! str_ends_with(strtolower($path), '.png')) {
            return false;
        }

        return is_file(public_path($path));
    }
}
