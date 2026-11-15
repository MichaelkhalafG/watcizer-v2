<?php

declare(strict_types=1);

namespace App\Domain\Reviews;

use App\Domain\Media\MediaStore;
use App\Domain\Notifications\StorefrontMailer;
use App\Mail\ReviewRequestMail;
use App\Storefront\ImageUrl;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontIdentity;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * VERIFIED REVIEWS (SEO Phase 5 item 1, 2026-11-14).
 *
 *  1. ASK — `reviews:request` (daily): every order of a shop marked delivered (or completed) `after_days` ago, and no
 *     longer ago than `within_days`, that was not asked yet, gets a request: a random token (only its SHA-256 is
 *     stored), and an e-mail to the customer with the link /review/<token> — only while REVIEW_REQUESTS_ENABLED is on.
 *     The dashboard's Reviews screen also offers the team a WhatsApp link with the same page, for customers without
 *     an e-mail.
 *  2. WRITE — the link opens the shop's review page: one form per product of that order (stars, words, up to three
 *     photos, the name to show). Each review is VERIFIED by construction (a delivered order) and saved PENDING.
 *  3. APPROVE — the team approves or rejects on the dashboard (logged). Only APPROVED reviews are shown on the product
 *     page and feed its aggregateRating and Review markup; the shop's summary (count, average) is the approved ones.
 */
final class Reviews
{
    public const STATUSES = ['pending', 'approved', 'rejected'];

    /**
     * Create the requests that are due and send their e-mails (when switched on).
     *
     * @return array{created: int, sent: int, skipped: int}
     */
    public static function requestDue(bool $send = true): array
    {
        $out = ['created' => 0, 'sent' => 0, 'skipped' => 0];
        // only orders placed on or after go-live (REVIEW_REQUESTS_FROM, UTC); none at all until it is set
        $from = trim(Coerce::str(config('reviews.requests_from')));
        if ($from === '' || strtotime($from) === false) {
            return $out;
        }
        $fromApp = \Illuminate\Support\Carbon::parse($from, 'UTC')->setTimezone(config()->string('app.timezone'));
        $after = now()->subDays(Coerce::int(config('reviews.after_days', 3)));
        $within = now()->subDays(Coerce::int(config('reviews.within_days', 30)));
        // 1. a request for every delivered order that is due and has none (the WhatsApp link needs one even with e-mail off)
        $rows = DB::table('orders as o')->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->whereIn('o.status', ['delivered', 'completed'])->where('o.created_at', '>=', $fromApp)
            ->where('o.updated_at', '<=', $after)->where('o.updated_at', '>=', $within)
            ->whereNotExists(fn (Builder $q) => $q->select(DB::raw(1))->from('review_requests as r')->whereColumn('r.order_id', 'o.id'))
            ->orderBy('o.id')->limit(500)
            ->get(['o.id', 'o.storefront_id', 'o.guest_email', 'o.guest_phone', 'u.email', 'u.phone_number']);
        foreach ($rows as $raw) {
            $r = Row::cast($raw);
            $email = trim((Row::nstr($r, 'email') ?? '') ?: (Row::nstr($r, 'guest_email') ?? ''));
            $phone = trim((Row::nstr($r, 'phone_number') ?? '') ?: (Row::nstr($r, 'guest_phone') ?? ''));
            DB::table('review_requests')->insert([
                'storefront_id' => Row::int($r, 'storefront_id'), 'order_id' => Row::int($r, 'id'), 'token_hash' => hash('sha256', Str::random(40)),
                'email' => $email === '' ? null : mb_substr($email, 0, 191), 'phone' => $phone === '' ? null : mb_substr($phone, 0, 32),
                'expires_at' => now()->addDays(Coerce::int(config('reviews.expires_days', 60))), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $out['created']++;
        }
        if (! $send || ! config('reviews.requests_enabled')) {
            return $out;
        }
        // 2. the e-mails: requests not sent yet, of the last `within_days`, never re-issued for WhatsApp (a new token here
        //    would void the link the team sent) — each gets its token now, as only its hash is ever stored
        $unsent = DB::table('review_requests')->whereNull('sent_at')->whereNotNull('email')->whereColumn('updated_at', 'created_at')
            ->where('created_at', '>=', $within)->whereIn('order_id', fn (Builder $q) => $q->select('id')->from('orders')->where('created_at', '>=', $fromApp))->orderBy('id')->limit(Coerce::int(config('reviews.max_per_run', 50)))->get(['id', 'storefront_id', 'order_id', 'email']);
        foreach ($unsent as $raw) {
            $r = Row::cast($raw);
            $email = Row::str($r, 'email');
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $out['skipped']++;

                continue;
            }
            $sf = Row::int($r, 'storefront_id');
            $token = Str::random(40);
            try {
                StorefrontMailer::send($sf, $email, new ReviewRequestMail(self::pageUrl($sf, $token), self::productNames($sf, Row::int($r, 'order_id')), StorefrontIdentity::for($sf)));
                DB::table('review_requests')->where('id', Row::int($r, 'id'))->update(['token_hash' => hash('sha256', $token), 'sent_at' => now()]);
                $out['sent']++;
            } catch (Throwable $e) {
                Log::warning('reviews: could not send a request', ['request' => Row::int($r, 'id'), 'error' => $e->getMessage()]);
                $out['skipped']++;
            }
        }

        return $out;
    }

    /** The shop's review page for a token. */
    public static function pageUrl(int $storefrontId, string $token): string
    {
        return rtrim(StorefrontUrls::frontend($storefrontId), '/').'/review/'.$token;
    }

    /**
     * A NEW token for a request (the dashboard's WhatsApp link: a token is only ever stored hashed, so the team gets a
     * fresh one, which replaces the old). Null for a request that is not this shop's.
     */
    public static function reissue(int $storefrontId, int $requestId): ?string
    {
        $token = Str::random(40);
        $done = DB::table('review_requests')->where('id', $requestId)->where('storefront_id', $storefrontId)
            ->update(['token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(Coerce::int(config('reviews.expires_days', 60))), 'updated_at' => now()]);

        return $done > 0 ? $token : null;
    }

    /**
     * The request a token opens on this shop, with the order's products and which of them already have a review — or
     * null (unknown, another shop's, or expired).
     *
     * @return array{request_id: int, order_id: int, products: list<array{id: int, slug: string, title: array{en: string, ar: string}, image: ?string, reviewed: bool}>}|null
     */
    public static function forToken(int $storefrontId, string $token): ?array
    {
        $req = DB::table('review_requests')->where('token_hash', hash('sha256', $token))->where('storefront_id', $storefrontId)->first();
        if ($req === null) {
            return null;
        }
        $r = Row::cast($req);
        $expires = Row::nstr($r, 'expires_at');
        if ($expires !== null && strtotime($expires) < time()) {
            return null;
        }
        $orderId = Row::int($r, 'order_id');
        $reviewed = DB::table('product_reviews')->where('order_id', $orderId)->pluck('product_id')->map(fn ($v): int => Coerce::int($v))->all();
        $products = [];
        foreach (self::orderProducts($storefrontId, $orderId) as $p) {
            $products[] = $p + ['reviewed' => in_array($p['id'], $reviewed, true)];
        }

        return ['request_id' => Row::int($r, 'id'), 'order_id' => $orderId, 'products' => $products];
    }

    /**
     * Save one review from a request's page: PENDING until the team approves it. Returns 'saved', 'already' (that
     * product of that order has a review), 'not_in_order' or 'expired'.
     *
     * @param  list<UploadedFile>  $photos
     */
    public static function submit(int $storefrontId, string $token, int $productId, int $rating, string $body, string $name, string $locale, array $photos = []): string
    {
        $request = self::forToken($storefrontId, $token);
        if ($request === null) {
            return 'expired';
        }
        $product = array_values(array_filter($request['products'], fn (array $p): bool => $p['id'] === $productId))[0] ?? null;
        if ($product === null) {
            return 'not_in_order';
        }
        if ($product['reviewed']) {
            return 'already';
        }
        $stored = [];
        foreach (array_slice($photos, 0, Coerce::int(config('reviews.max_photos', 3))) as $file) {
            $saved = app(MediaStore::class)->store($file, 'review', 'review-'.$request['order_id'].'-'.$productId);
            $stored[] = trim($saved['folder'], '/').'/'.$saved['file'];                 // a path like the catalogue's (ImageUrl::srcFor)
        }
        DB::table('product_reviews')->insert([
            'storefront_id' => $storefrontId, 'product_id' => $productId, 'order_id' => $request['order_id'], 'request_id' => $request['request_id'],
            'author_name' => mb_substr(trim($name), 0, 60), 'rating' => max(1, min(5, $rating)), 'body' => trim($body),
            'photos' => $stored === [] ? null : json_encode($stored), 'locale' => $locale === 'en' ? 'en' : 'ar', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return 'saved';
    }

    /** Approve or reject (the dashboard). Returns false for a review that is not there. */
    public static function moderate(int $reviewId, string $status, int $staffId, ?string $reason = null): bool
    {
        $row = DB::table('product_reviews')->where('id', $reviewId)->first(['storefront_id']);
        if ($row === null || ! in_array($status, self::STATUSES, true)) {
            return false;
        }
        DB::table('product_reviews')->where('id', $reviewId)->update([
            'status' => $status, 'moderated_by' => $staffId, 'moderated_at' => now(), 'updated_at' => now(),
            'rejection_reason' => $status === 'rejected' ? ($reason === null ? null : mb_substr($reason, 0, 255)) : null,
        ]);
        app(StorefrontCache::class)->flush(Row::int(Row::cast($row), 'storefront_id'));

        return true;
    }

    /**
     * The APPROVED reviews of a product on a shop, newest first, and their count and average — for the product page and
     * its structured data.
     *
     * @return array{count: int, average: ?float, items: list<array{id: int, name: string, rating: int, body: string, photos: list<string>, locale: string, date: string}>}
     */
    public static function approvedFor(int $storefrontId, int $productId, int $limit = 10): array
    {
        $q = DB::table('product_reviews')->where('storefront_id', $storefrontId)->where('product_id', $productId)->where('status', 'approved');
        $count = (clone $q)->count();
        $avg = $count === 0 ? null : round(Coerce::float((clone $q)->avg('rating')), 1);
        $items = [];
        foreach ($q->orderByDesc('created_at')->orderByDesc('id')->limit($limit)->get() as $raw) {
            $r = Row::cast($raw);
            $photos = json_decode(Row::nstr($r, 'photos') ?? '[]', true);
            $items[] = [
                'id' => Row::int($r, 'id'), 'name' => Row::str($r, 'author_name'), 'rating' => Row::int($r, 'rating'), 'body' => Row::str($r, 'body'),
                'photos' => is_array($photos) ? array_values(array_map(fn (string $p): string => ImageUrl::srcFor($storefrontId, $p), array_filter($photos, 'is_string'))) : [],
                'locale' => Row::str($r, 'locale'), 'date' => substr(Row::str($r, 'created_at'), 0, 10),
            ];
        }

        return ['count' => $count, 'average' => $avg, 'items' => $items];
    }

    /**
     * The shop's summary of its approved reviews (the About page and the home's trust line). Visible text only — a
     * shop's own reviews of itself are not marked up as an aggregateRating (Google ignores self-serving ones).
     *
     * @return array{count: int, average: ?float}
     */
    public static function summary(int $storefrontId): array
    {
        $q = DB::table('product_reviews')->where('storefront_id', $storefrontId)->where('status', 'approved');
        $count = (clone $q)->count();

        return ['count' => $count, 'average' => $count === 0 ? null : round(Coerce::float((clone $q)->avg('rating')), 1)];
    }

    /** @return list<array{id: int, slug: string, title: array{en: string, ar: string}, image: ?string}> */
    private static function orderProducts(int $storefrontId, int $orderId): array
    {
        $out = [];
        $rows = DB::table('order_items as oi')->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'oi.product_id')->where('sp.storefront_id', '=', $storefrontId))
            ->where('oi.order_id', $orderId)->where('oi.is_reward', 0)->groupBy('oi.product_id', 'sp.slug')->orderBy('oi.product_id')->get(['oi.product_id', 'sp.slug']);
        foreach ($rows as $raw) {
            $r = Row::cast($raw);
            $id = Row::int($r, 'product_id');
            $tr = DB::table('catalog_product_translations')->where('product_id', $id)->pluck('title', 'locale')->all();
            $img = DB::table('catalog_product_images')->where('product_id', $id)->whereNull('variant_id')->orderByDesc('is_cover')->orderBy('sort')->value('path');
            $out[] = ['id' => $id, 'slug' => Row::str($r, 'slug'), 'title' => ['en' => Coerce::str($tr['en'] ?? ''), 'ar' => Coerce::str($tr['ar'] ?? '')],
                'image' => is_string($img) && $img !== '' ? ImageUrl::srcFor($storefrontId, $img) : null];
        }

        return $out;
    }

    /** @return list<string> the order's product titles, for the e-mail */
    private static function productNames(int $storefrontId, int $orderId): array
    {
        return array_map(fn (array $p): string => $p['title']['en'] !== '' ? $p['title']['en'] : $p['title']['ar'], self::orderProducts($storefrontId, $orderId));
    }
}
