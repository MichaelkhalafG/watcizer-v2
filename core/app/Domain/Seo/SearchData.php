<?php

declare(strict_types=1);

namespace App\Domain\Seo;

use App\Support\Coerce;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * SEARCH ENGINE DATA for the weekly report and the safety check (SEO Phase 5, 2026-11-14): Google Search Console
 * (search analytics, through a service account) and Bing Webmaster Tools (traffic stats, through an API key). Both are
 * OFF until their credentials are set (config/seo.php); every method then answers null, and the report says "not
 * connected". A failure is logged and answered null too — the report and the safety check never break on it.
 */
final class SearchData
{
    public static function gscConnected(): bool
    {
        return self::serviceAccount() !== null;
    }

    public static function bingConnected(): bool
    {
        return trim(Coerce::str(config('seo.bing_api_key'))) !== '';
    }

    /**
     * Search Console totals for a site between two dates (Y-m-d), optionally only pages whose address contains `$path`
     * (the blog: '/blog/'), and the top pages.
     *
     * @return array{clicks: int, impressions: int, position: float, pages: list<array{page: string, clicks: int, impressions: int, position: float}>}|null
     */
    public static function gsc(string $site, string $from, string $to, ?string $path = null): ?array
    {
        $token = self::gscToken();
        if ($token === null) {
            return null;
        }
        $host = (string) parse_url($site, PHP_URL_HOST);
        $filters = $path === null ? [] : ['dimensionFilterGroups' => [['filters' => [['dimension' => 'page', 'operator' => 'contains', 'expression' => $path]]]]];
        foreach (['sc-domain:'.preg_replace('/^www\./', '', $host), rtrim($site, '/').'/'] as $property) {
            try {
                $res = Http::withToken($token)->timeout(20)->post('https://www.googleapis.com/webmasters/v3/sites/'.rawurlencode($property).'/searchAnalytics/query',
                    ['startDate' => $from, 'endDate' => $to, 'dimensions' => ['page'], 'rowLimit' => 250] + $filters);
                if ($res->status() === 403 || $res->status() === 404) {
                    continue;                                                     // not this property form: try the other
                }
                if (! $res->successful()) {
                    Log::warning('gsc: query failed', ['property' => $property, 'status' => $res->status()]);

                    return null;
                }
                $rows = Coerce::arr($res->json('rows'));
                $out = ['clicks' => 0, 'impressions' => 0, 'position' => 0.0, 'pages' => []];
                $weighted = 0.0;
                foreach ($rows as $row) {
                    $r = Coerce::arr($row);
                    $c = Coerce::int($r['clicks'] ?? 0);
                    $i = Coerce::int($r['impressions'] ?? 0);
                    $p = Coerce::float($r['position'] ?? 0);
                    $out['clicks'] += $c;
                    $out['impressions'] += $i;
                    $weighted += $p * $i;
                    $keys = array_values(Coerce::arr($r['keys'] ?? []));
                    $out['pages'][] = ['page' => Coerce::str($keys[0] ?? ''), 'clicks' => $c, 'impressions' => $i, 'position' => round($p, 1)];
                }
                $out['position'] = $out['impressions'] > 0 ? round($weighted / $out['impressions'], 1) : 0.0;

                return $out;
            } catch (Throwable $e) {
                Log::warning('gsc: query failed', ['property' => $property, 'error' => $e->getMessage()]);

                return null;
            }
        }

        return null;
    }

    /**
     * Bing's daily clicks and impressions for a site, summed over the last 7 days it reports.
     *
     * @return array{clicks: int, impressions: int}|null
     */
    public static function bing(string $site): ?array
    {
        if (! self::bingConnected()) {
            return null;
        }
        try {
            $res = Http::timeout(20)->get('https://ssl.bing.com/webmaster/api.svc/json/GetRankAndTrafficStats', ['siteUrl' => rtrim($site, '/').'/', 'apikey' => trim(Coerce::str(config('seo.bing_api_key')))]);
            if (! $res->successful()) {
                Log::warning('bing: query failed', ['status' => $res->status()]);

                return null;
            }
            $days = Coerce::arr($res->json('d'));
            usort($days, fn ($a, $b): int => strcmp(Coerce::str(Coerce::arr($b)['Date'] ?? ''), Coerce::str(Coerce::arr($a)['Date'] ?? '')));
            $out = ['clicks' => 0, 'impressions' => 0];
            foreach (array_slice($days, 0, 7) as $d) {
                $d = Coerce::arr($d);
                $out['clicks'] += Coerce::int($d['Clicks'] ?? 0);
                $out['impressions'] += Coerce::int($d['Impressions'] ?? 0);
            }

            return $out;
        } catch (Throwable $e) {
            Log::warning('bing: query failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /** @return array{client_email: string, private_key: string}|null */
    private static function serviceAccount(): ?array
    {
        $raw = trim(Coerce::str(config('seo.gsc_service_account')));
        if ($raw === '') {
            return null;
        }
        if (! str_starts_with($raw, '{') && is_file($raw)) {
            $raw = (string) file_get_contents($raw);
        }
        $json = json_decode($raw, true);
        if (! is_array($json) || ! is_string($json['client_email'] ?? null) || ! is_string($json['private_key'] ?? null)) {
            return null;
        }

        return ['client_email' => $json['client_email'], 'private_key' => $json['private_key']];
    }

    /** An OAuth token for the service account (a signed JWT exchanged at Google), cached for 50 minutes. */
    private static function gscToken(): ?string
    {
        $sa = self::serviceAccount();
        if ($sa === null) {
            return null;
        }
        $cached = Cache::get('seo:gsc:token');
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }
        $b64 = fn (string $s): string => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $now = time();
        $unsigned = $b64((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT'])).'.'.$b64((string) json_encode([
            'iss' => $sa['client_email'], 'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud' => 'https://oauth2.googleapis.com/token', 'iat' => $now, 'exp' => $now + 3600,
        ]));
        $signature = '';
        if (! openssl_sign($unsigned, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256) || ! is_string($signature)) {
            Log::warning('gsc: the service account key could not sign');

            return null;
        }
        try {
            $res = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $unsigned.'.'.$b64($signature),
            ]);
            $token = Coerce::str($res->json('access_token'));
            if ($token === '') {
                Log::warning('gsc: no token', ['status' => $res->status()]);

                return null;
            }
            Cache::put('seo:gsc:token', $token, 3000);

            return $token;
        } catch (Throwable $e) {
            Log::warning('gsc: token request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
