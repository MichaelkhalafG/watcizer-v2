<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Notifications\StorefrontMailer;
use App\Domain\Seo\SearchData;
use App\Mail\SeoAlertMail;
use App\Models\Storefront\StorefrontMailbox;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * THE SAFETY NET OF AUTOMATIC PUBLISHING (SEO Phase 4 item 5 and Phase 5 item 3, 2026-11-14) — `blog:safety`, daily.
 *
 *  - PAUSE + ALERT when more than `seo.safety.max_held_share` of an imported batch was held by the quality gate (checked
 *    by blog:import), or — once Search Console is connected — when the blog's impressions or its pages with impressions
 *    fall by more than `seo.safety.max_weekly_drop` week on week.
 *  - ALERT (no pause) when a shop has fewer than `seo.safety.min_queue_days` days of scheduled articles left.
 *
 * An alert is shown on the dashboard's articles screen (`settings.blog.alerts`, the newest five) and e-mailed to the
 * shop's order recipients (the mailbox's team list), at most once a day per reason. A pause is the same switch as the
 * dashboard's "pause publishing" (settings.blog.paused); the team switches it back on.
 */
final class BlogSafety
{
    /** @return array<int, list<string>> storefront id => what was found */
    public static function check(): array
    {
        $out = [];
        $queues = BlogPublisher::queueDays();
        $min = Coerce::int(config('seo.safety.min_queue_days', 30));
        foreach (DB::table('storefronts')->where('is_active', 1)->pluck('id') as $rawId) {
            $sf = Coerce::int($rawId);
            $found = [];
            $scheduled = DB::table('core_blogs')->where('storefront_id', $sf)->where('status', 'scheduled')->exists();
            $published = DB::table('core_blogs')->where('storefront_id', $sf)->where('status', 'published')->whereNotNull('publish_at')->exists();
            // the queue alert only once a shop runs on the queue (it has published or scheduled articles from it)
            if (($scheduled || $published) && ($queues[$sf] ?? 0.0) < $min) {
                $found[] = sprintf('only %.1f days of scheduled articles left (at least %d wanted)', $queues[$sf] ?? 0.0, $min);
                self::alert($sf, 'queue', end($found), false);
            }
            $drop = self::searchDrop($sf);
            if ($drop !== null) {
                $found[] = $drop;
                self::alert($sf, 'search_drop', $drop, true);
            }
            $out[$sf] = $found;
        }

        return $out;
    }

    /** blog:import's check: too many of a batch held → pause and alert. Returns true when it paused. */
    public static function afterImport(int $storefrontId, int $passed, int $held): bool
    {
        $total = $passed + $held;
        $max = Coerce::float(config('seo.safety.max_held_share', 0.3));
        if ($total === 0 || $held / $total <= $max) {
            return false;
        }
        self::alert($storefrontId, 'held_share', sprintf('%d of %d articles of the last batch were held by the quality gate (more than %d%%) — publishing paused', $held, $total, (int) round($max * 100)), true);

        return true;
    }

    /**
     * Record an alert (and pause when asked) on the shop's settings, and e-mail the team — once a day per reason.
     */
    public static function alert(int $storefrontId, string $reason, string $message, bool $pause): void
    {
        $settings = json_decode(Coerce::str(DB::table('storefronts')->where('id', $storefrontId)->value('settings')), true);
        $settings = is_array($settings) ? $settings : [];
        $blog = Coerce::arr($settings['blog'] ?? null);
        $alerts = array_values(array_filter(Coerce::arr($blog['alerts'] ?? []), 'is_array'));
        $today = now()->format('Y-m-d');
        $already = array_filter($alerts, fn (array $a): bool => ($a['reason'] ?? '') === $reason && str_starts_with(Coerce::str($a['at'] ?? ''), $today));
        if ($pause) {
            $blog['paused'] = true;
        }
        if ($already === []) {
            array_unshift($alerts, ['reason' => $reason, 'message' => $message, 'at' => now()->toIso8601String(), 'paused' => $pause]);
        }
        $blog['alerts'] = array_slice($alerts, 0, 5);
        $settings['blog'] = $blog;
        DB::table('storefronts')->where('id', $storefrontId)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        Log::warning('blog safety: '.$message, ['storefront' => $storefrontId, 'reason' => $reason, 'paused' => $pause]);
        if ($already !== []) {
            return;
        }
        try {
            $mailbox = StorefrontMailbox::query()->where('storefront_id', $storefrontId)->first();
            foreach ($mailbox instanceof StorefrontMailbox ? $mailbox->orderRecipients() : [] as $to) {
                StorefrontMailer::send($storefrontId, $to, new SeoAlertMail(StorefrontUrls::nameOf($storefrontId) ?? 'Shop', $message, $pause));
            }
        } catch (Throwable $e) {
            Log::warning('blog safety: the alert e-mail failed', ['storefront' => $storefrontId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The shop's alerts for the dashboard, newest first.
     *
     * @return list<array{reason: string, message: string, at: string, paused: bool}>
     */
    public static function alerts(int $storefrontId): array
    {
        $settings = json_decode(Coerce::str(DB::table('storefronts')->where('id', $storefrontId)->value('settings')), true);
        $out = [];
        foreach (Coerce::arr(Coerce::arr(is_array($settings) ? ($settings['blog'] ?? null) : null)['alerts'] ?? []) as $a) {
            $a = Coerce::arr($a);
            $out[] = ['reason' => Coerce::str($a['reason'] ?? ''), 'message' => Coerce::str($a['message'] ?? ''), 'at' => Coerce::str($a['at'] ?? ''), 'paused' => (bool) ($a['paused'] ?? false)];
        }

        return $out;
    }

    /** Search Console (when connected): the blog's impressions and pages with impressions, this week against last. */
    private static function searchDrop(int $sf): ?string
    {
        if (! SearchData::gscConnected()) {
            return null;
        }
        $site = StorefrontUrls::frontend($sf);
        $end = now()->subDays(3);                                                   // Search Console's data lags ~2 days
        $now = SearchData::gsc($site, $end->copy()->subDays(6)->format('Y-m-d'), $end->format('Y-m-d'), '/blog/');
        $was = SearchData::gsc($site, $end->copy()->subDays(13)->format('Y-m-d'), $end->copy()->subDays(7)->format('Y-m-d'), '/blog/');
        if ($now === null || $was === null || $was['impressions'] < 100) {
            return null;                                                            // too little to judge a fall
        }
        $max = Coerce::float(config('seo.safety.max_weekly_drop', 0.4));
        $impressions = 1 - $now['impressions'] / max(1, $was['impressions']);
        $pages = 1 - count($now['pages']) / max(1, count($was['pages']));
        if ($impressions > $max || $pages > $max) {
            return sprintf('blog impressions %d → %d, pages with impressions %d → %d week on week (more than a %d%% fall) — publishing paused',
                $was['impressions'], $now['impressions'], count($was['pages']), count($now['pages']), (int) round($max * 100));
        }

        return null;
    }

    /**
     * For the report: how many of a shop's articles are in each state.
     *
     * @return array<string, int>
     */
    public static function counts(int $sf): array
    {
        $out = [];
        foreach (DB::table('core_blogs')->where('storefront_id', $sf)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->get() as $raw) {
            $r = Row::cast($raw);
            $out[Row::str($r, 'status')] = Row::int($r, 'n');
        }

        return $out;
    }
}
