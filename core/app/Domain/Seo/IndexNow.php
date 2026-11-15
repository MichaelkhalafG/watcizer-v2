<?php

namespace App\Domain\Seo;

use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * INDEXNOW (SEO Phase 1, 2026-11-14; config/indexnow.php). A change queues the addresses it touched — both languages
 * of each — and `indexnow:flush` tells the engines in batches. Queueing never fails a save: an error is logged and the
 * save goes on. Sending is off until go-live (`indexnow.enabled`).
 */
final class IndexNow
{
    /** A storefront's key: 32 hex characters derived from the app key — stable, and served at /indexnow-key.txt. */
    public static function key(int $storefrontId): string
    {
        return substr(hash_hmac('sha256', 'indexnow:'.$storefrontId, config()->string('app.key')), 0, 32);
    }

    /** The storefront's site address, as its sitemaps name it (Watchizer's from compat.sitemap_domain). */
    public static function site(int $storefrontId): string
    {
        if ($storefrontId === config()->integer('compat.storefront_id')) {
            return rtrim(config()->string('compat.sitemap_domain'), '/');
        }

        return rtrim(StorefrontUrls::frontend($storefrontId), '/');
    }

    /**
     * Queue site paths (each in both languages) for a storefront. An address already waiting is not queued twice.
     *
     * @param  list<string>  $paths  site paths, '/product/x'
     * @param  array<string, string>  $arabic  a path's own Arabic path (without /ar), percent-encoded — an article's Arabic slug
     */
    public function queue(int $storefrontId, array $paths, string $reason, array $arabic = []): void
    {
        try {
            $site = self::site($storefrontId);
            foreach (array_unique($paths) as $path) {
                $bare = '/'.ltrim($path, '/');
                $arBare = isset($arabic[$path]) ? '/'.ltrim($arabic[$path], '/') : $bare;
                foreach ([$bare, '/ar'.($arBare === '/' ? '' : $arBare)] as $p) {
                    $url = $site.$p;
                    $waiting = DB::table('indexnow_queue')->where('storefront_id', $storefrontId)->whereNull('sent_at')->where('url', $url)->exists();
                    if (! $waiting) {
                        DB::table('indexnow_queue')->insert(['storefront_id' => $storefrontId, 'url' => mb_substr($url, 0, 700), 'reason' => mb_substr($reason, 0, 64), 'queued_at' => now()]);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('indexnow: could not queue', ['storefront' => $storefrontId, 'reason' => $reason, 'error' => $e->getMessage()]);
        }
    }

    /** A product's page on every storefront that places it (shown or not: a hidden one is re-read and dropped). */
    public function queueProduct(int $productId, string $reason = 'product saved'): void
    {
        try {
            $compat = config()->integer('compat.storefront_id');
            $title = DB::table('catalog_product_translations')->where('product_id', $productId)->where('locale', 'en')->value('title');
            foreach (DB::table('storefront_product')->where('product_id', $productId)->get(['storefront_id', 'slug']) as $row) {
                $sf = Row::int($row, 'storefront_id');
                // Watchizer's product page answers the English title's slug (compat); every other shop its own slug
                $slug = $sf === $compat ? LegacySlug::make(is_string($title) ? $title : '') : rawurlencode(Row::str($row, 'slug'));
                if ($slug !== '') {
                    $this->queue($sf, ['/product/'.$slug], $reason);
                }
            }
        } catch (Throwable $e) {
            Log::warning('indexnow: could not queue a product', ['product' => $productId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Send what is waiting, per storefront, in batches. Nothing is sent while IndexNow is off.
     *
     * @return array{enabled: bool, sent: int, waiting: int, failed: int}
     */
    public function flush(): array
    {
        $waiting = DB::table('indexnow_queue')->whereNull('sent_at')->count();
        if (! config()->boolean('indexnow.enabled', false)) {
            return ['enabled' => false, 'sent' => 0, 'waiting' => $waiting, 'failed' => 0];
        }
        $sent = 0;
        $failed = 0;
        $batch = max(1, config()->integer('indexnow.batch', 1000));
        foreach (DB::table('indexnow_queue')->whereNull('sent_at')->distinct()->pluck('storefront_id') as $sfRaw) {
            $sf = Coerce::int($sfRaw);
            $rows = DB::table('indexnow_queue')->where('storefront_id', $sf)->whereNull('sent_at')->orderBy('id')->limit($batch)->get(['id', 'url']);
            if ($rows->isEmpty()) {
                continue;
            }
            $site = self::site($sf);
            $host = (string) parse_url($site, PHP_URL_HOST);
            $urls = $rows->map(fn (object $r): string => Row::str($r, 'url'))->values()->all();
            try {
                $response = Http::timeout(config()->integer('indexnow.timeout', 20))->acceptJson()->post(config()->string('indexnow.endpoint'), [
                    'host' => $host,
                    'key' => self::key($sf),
                    'keyLocation' => $site.'/indexnow-key.txt',
                    'urlList' => $urls,
                ]);
                $status = $response->status();
            } catch (Throwable $e) {
                Log::warning('indexnow: the request failed', ['storefront' => $sf, 'error' => $e->getMessage()]);
                $status = 0;
            }
            // 200/202 accepted; anything else is kept waiting for the next run, with its status recorded
            $ok = $status === 200 || $status === 202;
            DB::table('indexnow_queue')->whereIn('id', $rows->pluck('id')->all())->update($ok ? ['sent_at' => now(), 'status' => $status] : ['status' => $status]);
            $ok ? $sent += count($urls) : $failed += count($urls);
        }

        return ['enabled' => true, 'sent' => $sent, 'waiting' => DB::table('indexnow_queue')->whereNull('sent_at')->count(), 'failed' => $failed];
    }
}
