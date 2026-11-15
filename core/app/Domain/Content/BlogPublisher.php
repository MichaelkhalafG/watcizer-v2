<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AUTO-PUBLISHING (SEO Phase 3, 2026-11-14). Run by the scheduler (`blog:publish-due`, every ten minutes): every article
 * that is `scheduled` and whose `publish_at` (UTC) has come is published — unless its shop's publishing is PAUSED
 * (`settings.blog.paused`, a switch on the dashboard's storefront screen). Publishing = `published_at` set to the
 * scheduled moment, status `published`; then the shop's sitemaps are rebuilt, IndexNow is told and the shop's caches are
 * flushed, so the article, the blog index and the related pages show it.
 *
 * Idempotent and safe to re-run: an article is published once (the update is conditional on its status); a failure is
 * logged on the article (`publish_error`), retried by the next run, and after MAX_ATTEMPTS the article is held with the
 * reason, for the dashboard.
 */
final class BlogPublisher
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly StorefrontCache $cache) {}

    /** Is publishing paused for this shop? */
    public static function paused(int $storefrontId): bool
    {
        $settings = json_decode(Coerce::str(DB::table('storefronts')->where('id', $storefrontId)->value('settings')), true);

        return is_array($settings) && (Coerce::arr($settings['blog'] ?? null)['paused'] ?? false) === true;
    }

    /**
     * Publish what is due. Returns per shop: published, failed, paused (articles waiting because the shop is paused).
     *
     * @return array<int, array{published: int, failed: int, paused: int}>
     */
    public function publishDue(): array
    {
        $out = [];
        $due = DB::table('core_blogs')->where('status', 'scheduled')->whereNotNull('publish_at')->where('publish_at', '<=', now())
            ->orderBy('publish_at')->orderBy('id')->get(['id', 'storefront_id', 'publish_at', 'publish_attempts']);
        foreach ($due as $raw) {
            $r = Row::cast($raw);
            $id = Row::int($r, 'id');
            $sf = Row::int($r, 'storefront_id');
            $out[$sf] ??= ['published' => 0, 'failed' => 0, 'paused' => 0];
            if (self::paused($sf)) {
                $out[$sf]['paused']++;

                continue;
            }
            try {
                $done = DB::table('core_blogs')->where('id', $id)->where('status', 'scheduled')->update([
                    'status' => 'published',
                    'published_at' => Row::str($r, 'publish_at'),
                    'publish_error' => null,
                    'updated_at' => now(),
                ]);
                if ($done > 0) {
                    BlogWriter::articleChanged($id);
                    $this->cache->flush($sf);
                    $out[$sf]['published']++;
                }
            } catch (Throwable $e) {
                $attempts = Row::int($r, 'publish_attempts') + 1;
                DB::table('core_blogs')->where('id', $id)->update([
                    'publish_attempts' => $attempts,
                    'publish_error' => mb_substr($e->getMessage(), 0, 500),
                ] + ($attempts >= self::MAX_ATTEMPTS ? ['status' => 'held'] : []));
                Log::warning('blog: could not publish', ['article' => $id, 'attempt' => $attempts, 'error' => $e->getMessage()]);
                $out[$sf]['failed']++;
            }
        }

        return $out;
    }

    /**
     * How many days of scheduled articles each shop has left (from now), for the dashboard and the queue alert.
     *
     * @return array<int, float>
     */
    public static function queueDays(): array
    {
        $out = [];
        foreach (DB::table('core_blogs')->where('status', 'scheduled')->where('publish_at', '>', now())
            ->groupBy('storefront_id')->selectRaw('storefront_id, MAX(publish_at) AS last')->get() as $raw) {
            $r = Row::cast($raw);
            $last = Row::nstr($r, 'last');
            $out[Row::int($r, 'storefront_id')] = $last === null ? 0.0 : max(0.0, round((strtotime($last) - time()) / 86400, 1));
        }

        return $out;
    }
}
