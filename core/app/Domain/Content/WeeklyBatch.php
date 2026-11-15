<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Notifications\StorefrontMailer;
use App\Mail\WeeklyBatchReminderMail;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ARTICLES WEEK BY WEEK (Michael, 2026-11-14 — replaces the 30-day queue and the 120-article batch).
 *
 * One batch = 7 article pairs per shop: one week of publishing, one article per shop per day. The TOPIC MAP
 * (`database/seeders/content/articles/topic-map.csv`, SEO Phase 4) is the order; a topic is USED once an article file
 * of the shop names it (`topic_id:` in its English front matter, under `database/seeders/content/articles/<shop>/`).
 *
 * THE REMINDER (`blog:week-reminder`, daily at 09:00 Cairo): on day 6 of every publishing week — the weeks count from
 * the shop's first scheduled or published article — if the shop has fewer than 7 articles scheduled for NEXT week, an
 * e-mail goes to the shop's report address (settings.seo.report_email): "Next week's articles aren't written yet — run
 * the next batch", with next week's 7 topics from the map and how many approved articles are still queued. A shop whose
 * next week is already queued gets no reminder. An empty queue is not an error: publishing simply waits.
 */
final class WeeklyBatch
{
    public const PER_WEEK = 7;

    /** @return list<array<string, string>> the map's rows, in its order */
    public static function map(): array
    {
        $path = database_path('seeders/content/articles/topic-map.csv');
        if (! is_file($path)) {
            return [];
        }
        $fh = fopen($path, 'r');
        if ($fh === false) {
            return [];
        }
        $head = fgetcsv($fh, null, ',', '"', '');
        $head = is_array($head) ? array_map(fn ($h): string => trim((string) $h, "\u{FEFF} "), $head) : [];
        $rows = [];
        while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if (count($r) === count($head)) {
                $rows[] = array_combine($head, array_map(fn ($v): string => (string) $v, $r));
            }
        }
        fclose($fh);

        return $rows;
    }

    /** @return list<string> the topic ids a shop's article files already use */
    public static function usedTopics(string $shop): array
    {
        $out = [];
        foreach (glob(database_path('seeders/content/articles/'.$shop.'/*/*.en.md')) ?: [] as $file) {
            if (preg_match('/^topic_id:\s*(T\d+)\s*$/m', (string) file_get_contents($file), $m) === 1) {
                $out[] = $m[1];
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The next `$n` topics of a shop: batch-1 picks first (in their order), then the rest by priority, never a used one.
     *
     * @return list<array<string, string>>
     */
    public static function nextTopics(string $shop, int $n = self::PER_WEEK): array
    {
        $used = array_flip(self::usedTopics($shop));
        $mine = array_values(array_filter(self::map(), fn (array $r): bool => ($r['shop'] ?? '') === $shop && ! isset($used[$r['id'] ?? ''])));
        usort($mine, fn (array $a, array $b): int => [($a['batch'] ?? '') === '' ? 1 : 0, $a['batch'] ?? '', (int) ($a['priority'] ?? 9), $a['id'] ?? '']
            <=> [($b['batch'] ?? '') === '' ? 1 : 0, $b['batch'] ?? '', (int) ($b['priority'] ?? 9), $b['id'] ?? '']);

        return array_slice($mine, 0, $n);
    }

    /**
     * Today (Cairo) is day 6 of a publishing week of this shop → the window of NEXT week [start, end), else null.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function nextWeekWindow(int $storefrontId, ?Carbon $now = null): ?array
    {
        $now = ($now ?? now())->copy()->setTimezone('Africa/Cairo');
        $first = DB::table('core_blogs')->where('storefront_id', $storefrontId)->whereIn('status', ['scheduled', 'published'])
            ->whereNotNull('publish_at')->min('publish_at');
        if (! is_string($first)) {
            return null;
        }
        $anchor = Carbon::parse($first, config()->string('app.timezone'))->setTimezone('Africa/Cairo')->startOfDay();
        $days = (int) $anchor->diffInDays($now->copy()->startOfDay(), false);
        if ($days < 0 || $days % 7 !== 5) {
            return null;
        }
        $start = $anchor->copy()->addDays(($days - 5) + 7);

        return [$start, $start->copy()->addDays(7)];
    }

    /**
     * Send the day-6 reminders that are due. Returns per shop what happened.
     *
     * @return array<int, string>
     */
    public static function remind(?Carbon $now = null): array
    {
        $out = [];
        foreach (DB::table('storefronts')->where('is_active', 1)->get(['id', 'code', 'settings']) as $raw) {
            $r = Row::cast($raw);
            $sf = Row::int($r, 'id');
            $window = self::nextWeekWindow($sf, $now);
            if ($window === null) {
                $out[$sf] = 'not day 6';

                continue;
            }
            [$start, $end] = $window;
            $tz = config()->string('app.timezone');
            $nextWeek = DB::table('core_blogs')->where('storefront_id', $sf)->where('status', 'scheduled')
                ->where('publish_at', '>=', $start->copy()->setTimezone($tz))->where('publish_at', '<', $end->copy()->setTimezone($tz))->count();
            if ($nextWeek >= self::PER_WEEK) {
                $out[$sf] = 'next week queued';

                continue;
            }
            $queued = DB::table('core_blogs')->where('storefront_id', $sf)->where('status', 'scheduled')->where('publish_at', '>', now())->count();
            $settings = json_decode(Row::nstr($r, 'settings') ?? '{}', true);
            $to = trim(Coerce::str(Coerce::arr(Coerce::arr(is_array($settings) ? $settings : [])['seo'] ?? null)['report_email'] ?? ''));
            $topics = self::nextTopics(Row::str($r, 'code'));
            if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
                Log::warning('blog: next week is not written and the shop has no report address', ['storefront' => $sf]);
                $out[$sf] = 'reminder due, no address';

                continue;
            }
            try {
                StorefrontMailer::send($sf, $to, new WeeklyBatchReminderMail(StorefrontUrls::nameOf($sf) ?? Row::str($r, 'code'), $start->format('Y-m-d'), $nextWeek, $queued, $topics));
                $out[$sf] = 'reminder sent';
            } catch (Throwable $e) {
                Log::warning('blog: the weekly reminder failed', ['storefront' => $sf, 'error' => $e->getMessage()]);
                $out[$sf] = 'reminder failed';
            }
        }

        return $out;
    }
}
