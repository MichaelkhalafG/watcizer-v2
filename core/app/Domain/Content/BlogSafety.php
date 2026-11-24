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
 *    by blog:import), or — once Search Console is connected — on a REAL fall of the blog in search ({@see self::judge()},
 *    Michael 2026-11-24: the old rule paused Watchizer twice on 12 → 7 pages): the earlier week had at least
 *    `seo.safety.min_impressions` blog impressions and `seo.safety.min_pages` pages with impressions, and EITHER both
 *    measures fell by more than `seo.safety.max_weekly_drop` this week, OR impressions fell by more than
 *    `seo.safety.two_week_drop` two weeks in a row. A fall below that volume is an ALERT only ("watch"), never a pause.
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
            $verdict = self::searchDrop($sf);
            if ($verdict !== null && $verdict['message'] !== null) {
                $found[] = $verdict['message'];
                $verdict['pause']
                    ? self::alert($sf, 'search_drop', $verdict['message'], true)
                    : self::alert($sf, 'search_watch', $verdict['message'], false);
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

    /**
     * Search Console (when connected): the blog's last three weeks, judged by {@see self::judge()}.
     *
     * @return array{pause: bool, message: string|null}|null
     */
    private static function searchDrop(int $sf): ?array
    {
        if (! SearchData::gscConnected()) {
            return null;
        }
        $site = StorefrontUrls::frontend($sf);
        $end = now()->subDays(3);                                                   // Search Console's data lags ~2 days
        $weeks = [];
        foreach ([14, 7, 0] as $back) {                                             // oldest first: two weeks ago, last week, this week
            $to = $end->copy()->subDays($back);
            $week = SearchData::gsc($site, $to->copy()->subDays(6)->format('Y-m-d'), $to->format('Y-m-d'), '/blog/');
            if ($week === null) {
                return null;
            }
            $weeks[] = ['impressions' => Coerce::int($week['impressions']), 'pages' => count(Coerce::arr($week['pages']))];
        }

        return self::judge($weeks[0], $weeks[1], $weeks[2]);
    }

    /**
     * THE RULE (Michael, 2026-11-24), on three weeks of the blog in search, oldest first, each {impressions, pages}:
     *  - PAUSE when last week had the volume (`min_impressions` impressions and `min_pages` pages) and either BOTH this
     *    week's impressions and pages fell by more than `max_weekly_drop`, or impressions fell by more than
     *    `two_week_drop` this week AND last week (when two weeks ago had the volume too);
     *  - WATCH (an alert, publishing goes on) when a measure fell by more than `max_weekly_drop` but the rule above does
     *    not hold — too little volume to judge, or only one of the two measures fell;
     *  - nothing otherwise.
     *
     * @param  array{impressions: int, pages: int}  $twoAgo
     * @param  array{impressions: int, pages: int}  $last
     * @param  array{impressions: int, pages: int}  $now
     * @return array{pause: bool, message: string|null}
     */
    public static function judge(array $twoAgo, array $last, array $now): array
    {
        $max = Coerce::float(config('seo.safety.max_weekly_drop', 0.4));
        $two = Coerce::float(config('seo.safety.two_week_drop', 0.25));
        $minImpressions = Coerce::int(config('seo.safety.min_impressions', 1000));
        $minPages = Coerce::int(config('seo.safety.min_pages', 20));
        $fall = static fn (int $from, int $to): float => $from <= 0 ? 0.0 : 1 - $to / $from;
        $volume = static fn (array $w): bool => $w['impressions'] >= $minImpressions && $w['pages'] >= $minPages;

        $impressions = $fall($last['impressions'], $now['impressions']);
        $pages = $fall($last['pages'], $now['pages']);
        $before = $fall($twoAgo['impressions'], $last['impressions']);
        $said = sprintf('blog impressions %d → %d → %d, pages with impressions %d → %d → %d over the last three weeks',
            $twoAgo['impressions'], $last['impressions'], $now['impressions'], $twoAgo['pages'], $last['pages'], $now['pages']);

        if ($volume($last) && $impressions > $max && $pages > $max) {
            return ['pause' => true, 'message' => $said.sprintf(' (both fell by more than %d%% this week) — publishing paused', (int) round($max * 100))];
        }
        if ($volume($last) && $volume($twoAgo) && $impressions > $two && $before > $two) {
            return ['pause' => true, 'message' => $said.sprintf(' (impressions fell by more than %d%% two weeks in a row) — publishing paused', (int) round($two * 100))];
        }
        if ($impressions > $max || $pages > $max) {
            return ['pause' => false, 'message' => $said.' — a fall to watch, publishing continues ('.($volume($last)
                ? 'only one measure fell'
                : sprintf('under %d impressions and %d pages last week, too little to judge', $minImpressions, $minPages)).')'];
        }

        return ['pause' => false, 'message' => null];
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
