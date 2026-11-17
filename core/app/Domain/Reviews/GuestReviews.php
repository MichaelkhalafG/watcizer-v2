<?php

declare(strict_types=1);

namespace App\Domain\Reviews;

use App\Storefront\ProductCards;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * REVIEWS FROM EVERYONE (Michael's decisions, 2026-11-17). WRITES product_reviews — one row per review, and nothing
 * else: never the shared `users` table, never anyone's login.
 *
 * A review written on a product page, without logging in: a name, an e-mail, stars and words.
 *  - LIVE AT ONCE (status approved), protected in the background: Cloudflare Turnstile (invisible), limits per e-mail
 *    (a day), per device and per address (an hour), and a review whose words or name carry a LINK or a PHONE NUMBER is
 *    HELD for the team (status pending). Held by those signals only — never by its stars.
 *  - THE E-MAIL is kept only as its SHA-256. When it is a customer account's, the review is attached to that account
 *    silently (user_id); the answer is the same either way, so the form never tells anyone whether an e-mail has an
 *    account, and nobody is ever logged in.
 *  - VERIFIED BUYER when that e-mail has a DELIVERED order (delivered or completed) containing the product.
 *  - One review per e-mail per product; a second one answers `already`.
 * A failure to reach Cloudflare does not lose the review: it is HELD (held_reason unverified) for the team.
 */
final class GuestReviews
{
    /** What a review is held for (held_reason). */
    public const HELD_LINK = 'link';

    public const HELD_PHONE = 'phone';

    public const HELD_UNVERIFIED = 'unverified';

    /**
     * Save one review. The answer: live | held | already | limited | verification | not_on_shop.
     */
    public static function submit(
        int $storefrontId,
        int $productId,
        string $name,
        string $email,
        int $rating,
        string $body,
        string $locale,
        string $turnstileToken,
        string $deviceId,
        string $ip,
    ): string {
        $email = mb_strtolower(trim($email));
        $hash = hash('sha256', $email);
        $visible = ProductCards::visibleOn(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'), $storefrontId)
            ->where('p.id', $productId)->exists();
        if (! $visible) {
            return 'not_on_shop';
        }
        // the limits, before anything else is asked of anyone
        $limits = config()->array('reviews.guest_limits', []);
        $keys = [
            'review-email:'.$hash => [Coerce::int($limits['email_per_day'] ?? 3), 86400],
            'review-ip:'.hash('sha256', $ip) => [Coerce::int($limits['ip_per_hour'] ?? 10), 3600],
        ];
        $device = preg_replace('/[^A-Za-z0-9-]/', '', $deviceId) ?? '';
        if ($device !== '') {
            $keys['review-device:'.hash('sha256', $device)] = [Coerce::int($limits['device_per_hour'] ?? 5), 3600];
        }
        foreach ($keys as $key => [$max]) {
            if (RateLimiter::tooManyAttempts($key, max(1, $max))) {
                return 'limited';
            }
        }
        if (DB::table('product_reviews')->where('storefront_id', $storefrontId)->where('product_id', $productId)->where('email_hash', $hash)->exists()) {
            return 'already';
        }
        $check = self::turnstile($turnstileToken, $ip);
        if ($check === 'failed') {
            return 'verification';
        }
        foreach ($keys as $key => [, $decay]) {
            RateLimiter::hit($key, $decay);
        }

        $held = self::holdReason($body.' '.$name) ?? ($check === 'unreachable' ? self::HELD_UNVERIFIED : null);
        $userId = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->orderBy('id')->value('id');
        try {
            DB::table('product_reviews')->insert([
                'storefront_id' => $storefrontId, 'product_id' => $productId, 'order_id' => null, 'request_id' => null,
                'source' => 'guest', 'user_id' => is_numeric($userId) ? (int) $userId : null, 'email_hash' => $hash,
                'verified' => self::boughtAndReceived($email, $productId),
                'author_name' => mb_substr(trim($name), 0, 60), 'rating' => max(1, min(5, $rating)), 'body' => trim($body),
                'photos' => null, 'locale' => $locale === 'en' ? 'en' : 'ar',
                'status' => $held === null ? 'approved' : 'pending', 'held_reason' => $held,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return 'already';    // two submissions at once: the unique key answers
        }
        if ($held === null) {
            // the product page shows it at its next refresh
            app(StorefrontCache::class)->flush($storefrontId);
        }

        return $held === null ? 'live' : 'held';
    }

    /**
     * Why a text is held for the team (a link or a phone number in it), or null. Arabic-Indic and Persian digits count as
     * digits; a price or a model number is not a phone number (eight digits or more, allowing spaces, dots and dashes).
     */
    public static function holdReason(string $text): ?string
    {
        $t = strtr($text, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', // i18n-exempt: DATA — Arabic-Indic digits typed in a review
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', // i18n-exempt: DATA — Persian digits
        ]);
        if (preg_match('~(https?://|www\.|\b[a-z0-9-]{2,}\.(com|net|org|eg|me|io|co|info|biz|xyz|link|ly|shop|store|online|site|app)\b|wa\.me|t\.me|bit\.ly)~iu', $t) === 1) {
            return self::HELD_LINK;
        }
        if (preg_match('/(?:\+?\d[\s.\-]?){8,}/u', $t) === 1) {
            return self::HELD_PHONE;
        }

        return null;
    }

    /** Whether this e-mail has a delivered order containing the product — on any of the shops (accounts are shared). */
    public static function boughtAndReceived(string $email, int $productId): bool
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        return DB::table('orders as o')->join('order_items as oi', 'oi.order_id', '=', 'o.id')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->whereIn('o.status', ['delivered', 'completed'])
            ->where('oi.product_id', $productId)
            ->where(fn (Builder $q) => $q->whereRaw('LOWER(o.guest_email) = ?', [$email])->orWhereRaw('LOWER(u.email) = ?', [$email]))
            ->exists();
    }

    /**
     * Cloudflare Turnstile: passed | failed | unreachable | off (no keys set: skipped, logged once a day).
     */
    public static function turnstile(string $token, string $ip): string
    {
        $secret = trim(Coerce::str(config('reviews.turnstile.secret_key')));
        if ($secret === '' || trim(Coerce::str(config('reviews.turnstile.site_key'))) === '') {
            if (RateLimiter::attempt('turnstile-off-log', 1, fn () => true, 86400)) {
                Log::notice('reviews: Turnstile keys are not set (TURNSTILE_SITE_KEY, TURNSTILE_SECRET_KEY) - the check is skipped');
            }

            return 'off';
        }
        if (trim($token) === '') {
            return 'failed';
        }
        try {
            $r = Http::asForm()->timeout(Coerce::int(config('reviews.turnstile.timeout', 5)))
                ->post(Coerce::str(config('reviews.turnstile.verify_url')), ['secret' => $secret, 'response' => $token, 'remoteip' => $ip]);
            if (! $r->successful()) {
                return 'unreachable';
            }

            return $r->json('success') === true ? 'passed' : 'failed';
        } catch (Throwable $e) {
            Log::warning('reviews: Turnstile could not be reached', ['error' => $e->getMessage()]);

            return 'unreachable';
        }
    }
}
