<?php

declare(strict_types=1);

namespace App\Domain\Campaigns;

use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\EmailProducts;
use App\Domain\Notifications\MailBudget;
use App\Domain\Notifications\MarketingLinks;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Notifications\ReEngagement;
use App\Domain\Promotions\PersonalCodes;
use App\Domain\Promotions\PromotionRules;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * THE E-MAIL CAMPAIGNS (Michael, 2026-11-24; plan item 5, decisions D2, D14-D16).
 *
 * ── The audience ────────────────────────────────────────────────────────────────────────────
 * Every e-mail address we hold (D2, no consent step): signed-up accounts, guests who ordered, guests who typed their
 * address at the checkout - or an uploaded list. Ready filters, combinable (AND): no order in the last 30 / 60 / 90 days,
 * N orders or more, spent EGP X or more, bought a brand or a category, signed up but never ordered, and "customers of this
 * shop" (ordered here). Orders count when they are real (processing, shipped, delivered, completed). An unsubscribed
 * address is ALWAYS left out, and the count shown before sending is the count that will be sent to.
 *
 * ── The message and the code ────────────────────────────────────────────────────────────────
 * A subject and text in Arabic and English, optional products (today's price at send time), and an optional code: one
 * single-use code per recipient (PersonalCodes), bound to that address, with the one-offer rule of every code.
 *
 * ── Sending (D16) ───────────────────────────────────────────────────────────────────────────
 * The shops' own Hostinger mailboxes, no new provider. A campaign's recipients are fixed when it starts; then
 * `campaigns:dispatch` (every five minutes) hands the bulk outbox ONLY what the shop's bulk allowance can send in that
 * pass (at most `notifications.bulk.per_run`, never more than MailBudget::bulkRemaining). So a campaign never queues in
 * front of a restock alert for days, never touches the order e-mails' channel or their daily reserve, and a large one
 * simply continues the next day. Every e-mail is checked again at send time: an address unsubscribed since is skipped.
 */
final class EmailCampaigns
{
    public const KIND = 'campaign';

    /** Orders that count as orders for the filters. */
    public const REAL = ['processing', 'shipped', 'delivered', 'completed'];

    public const NO_ORDER_DAYS = [30, 60, 90];

    /**
     * The audience as stored, every key present and typed.
     *
     * @param  array<string, mixed>  $in
     * @return array{accounts: bool, guests: bool, no_order_days: int|null, min_orders: int|null, min_spent: float|null, brand_ids: list<int>, category_ids: list<int>, never_ordered: bool, this_shop: bool, list_id: int|null}
     */
    public static function audience(array $in): array
    {
        $days = Coerce::nint($in['no_order_days'] ?? null);
        $ids = fn (mixed $v): array => array_values(array_unique(array_filter(array_map(fn ($x): int => Coerce::int($x), Coerce::arr($v)), fn (int $x): bool => $x > 0)));

        return [
            'accounts' => (bool) ($in['accounts'] ?? true),
            'guests' => (bool) ($in['guests'] ?? true),
            'no_order_days' => in_array($days, self::NO_ORDER_DAYS, true) ? $days : null,
            'min_orders' => ($n = Coerce::nint($in['min_orders'] ?? null)) !== null && $n > 0 ? $n : null,
            'min_spent' => ($m = Coerce::nfloat($in['min_spent'] ?? null)) !== null && $m > 0 ? $m : null,
            'brand_ids' => $ids($in['brand_ids'] ?? []),
            'category_ids' => $ids($in['category_ids'] ?? []),
            'never_ordered' => (bool) ($in['never_ordered'] ?? false),
            'this_shop' => (bool) ($in['this_shop'] ?? false),
            'list_id' => ($l = Coerce::nint($in['list_id'] ?? null)) !== null && $l > 0 ? $l : null,
        ];
    }

    /**
     * The addresses an audience reaches on a shop, unsubscribed ones left out.
     *
     * @param  array{accounts: bool, guests: bool, no_order_days: int|null, min_orders: int|null, min_spent: float|null, brand_ids: list<int>, category_ids: list<int>, never_ordered: bool, this_shop: bool, list_id: int|null}  $a
     * @return array{recipients: list<array{email: string, user_id: int|null, name: string|null}>, held: int, unsubscribed: int}
     */
    public static function resolve(int $storefrontId, array $a): array
    {
        /** @var array<string, array{email: string, user_id: int|null, name: string|null}> $pool */
        $pool = [];
        $valid = fn (?string $e): ?string => $e !== null && filter_var(trim($e), FILTER_VALIDATE_EMAIL) !== false ? mb_strtolower(trim($e)) : null;
        if ($a['list_id'] !== null) {
            foreach (DB::table('core_email_list_rows as r')->join('core_email_lists as l', 'l.id', '=', 'r.list_id')
                ->where('l.id', $a['list_id'])->where('l.storefront_id', $storefrontId)->get(['r.email', 'r.name']) as $raw) {
                $r = Row::cast($raw);
                if (($e = $valid(Row::nstr($r, 'email'))) !== null) {
                    $pool[$e] ??= ['email' => $e, 'user_id' => null, 'name' => Row::nstr($r, 'name')];
                }
            }
        } else {
            if ($a['accounts']) {
                // the shops' customers only (users.type User), never a dashboard account (a core role)
                foreach (DB::table('users')->whereNotNull('email')->where('type', 'User')
                    ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('core_user_roles as cr')->whereColumn('cr.user_id', 'users.id'))->orderBy('id')->get(['id', 'email', 'first_name', 'last_name']) as $raw) {
                    $r = Row::cast($raw);
                    if (($e = $valid(Row::nstr($r, 'email'))) !== null) {
                        $name = trim(Coerce::str(Row::nstr($r, 'first_name')).' '.Coerce::str(Row::nstr($r, 'last_name')));
                        $pool[$e] ??= ['email' => $e, 'user_id' => Row::int($r, 'id'), 'name' => $name === '' ? null : $name];
                    }
                }
            }
            if ($a['guests']) {
                foreach (DB::table('orders')->whereNull('user_id')->whereNotNull('guest_email')->orderBy('id')->get(['guest_email', 'guest_name']) as $raw) {
                    $r = Row::cast($raw);
                    if (($e = $valid(Row::nstr($r, 'guest_email'))) !== null) {
                        $pool[$e] ??= ['email' => $e, 'user_id' => null, 'name' => Row::nstr($r, 'guest_name')];
                    }
                }
                foreach (DB::table('core_checkout_contacts')->where('storefront_id', $storefrontId)->pluck('email') as $email) {
                    if (($e = $valid(Coerce::nstr($email))) !== null) {
                        $pool[$e] ??= ['email' => $e, 'user_id' => null, 'name' => null];
                    }
                }
            }
            $pool = self::filtered($storefrontId, $pool, $a);
        }
        $held = count($pool);
        $out = DB::table('core_marketing_optouts')->where('storefront_id', $storefrontId)->pluck('email')->map(fn ($e): string => mb_strtolower(Coerce::str($e)))->flip()->all();
        $recipients = array_values(array_filter($pool, fn (array $p): bool => ! isset($out[$p['email']])));

        return ['recipients' => $recipients, 'held' => $held, 'unsubscribed' => $held - count($recipients)];
    }

    /**
     * @param  array<string, array{email: string, user_id: int|null, name: string|null}>  $pool
     * @param  array{accounts: bool, guests: bool, no_order_days: int|null, min_orders: int|null, min_spent: float|null, brand_ids: list<int>, category_ids: list<int>, never_ordered: bool, this_shop: bool, list_id: int|null}  $a
     * @return array<string, array{email: string, user_id: int|null, name: string|null}>
     */
    private static function filtered(int $storefrontId, array $pool, array $a): array
    {
        $needsOrders = $a['no_order_days'] !== null || $a['min_orders'] !== null || $a['min_spent'] !== null || $a['brand_ids'] !== [] || $a['category_ids'] !== [] || $a['never_ordered'] || $a['this_shop'];
        if (! $needsOrders) {
            return $pool;
        }
        // every real order, keyed to its address: the account's e-mail for an account's order, the typed one for a guest's
        $accountEmail = [];
        foreach ($pool as $p) {
            if ($p['user_id'] !== null) {
                $accountEmail[$p['user_id']] = $p['email'];
            }
        }
        $stats = [];
        $orders = DB::table('orders')->whereIn('status', self::REAL)
            ->when($a['this_shop'] || $a['brand_ids'] !== [] || $a['category_ids'] !== [], fn (Builder $q) => $q->where('storefront_id', $storefrontId))
            ->get(['id', 'user_id', 'guest_email', 'total_price_for_order', 'created_at']);
        $orderEmail = [];
        foreach ($orders as $raw) {
            $o = Row::cast($raw);
            $uid = Row::nint($o, 'user_id');
            $email = $uid !== null ? ($accountEmail[$uid] ?? null) : mb_strtolower(trim((string) Row::nstr($o, 'guest_email')));
            if ($email === null || $email === '' || ! isset($pool[$email])) {
                continue;
            }
            $orderEmail[Row::int($o, 'id')] = $email;
            $s = $stats[$email] ?? ['n' => 0, 'spent' => 0.0, 'last' => ''];
            $s['n']++;
            $s['spent'] += (float) Row::str($o, 'total_price_for_order');
            $s['last'] = max($s['last'], Row::str($o, 'created_at'));
            $stats[$email] = $s;
        }
        $bought = null;
        if ($a['brand_ids'] !== [] || $a['category_ids'] !== []) {
            $bought = [];
            $items = DB::table('order_items as i')->join('catalog_products as p', 'p.id', '=', 'i.product_id')
                ->whereIn('i.order_id', array_keys($orderEmail))
                ->where(function (Builder $q) use ($a, $storefrontId): void {
                    if ($a['brand_ids'] !== []) {
                        $q->orWhereIn('p.brand_id', $a['brand_ids']);
                    }
                    if ($a['category_ids'] !== []) {
                        $q->orWhereExists(fn (Builder $s) => $s->selectRaw('1')->from('storefront_category_product as scp')
                            ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
                            ->join('storefront_categories as top', 'top.storefront_id', '=', 'c.storefront_id')
                            ->whereColumn('scp.product_id', 'i.product_id')->where('scp.storefront_id', $storefrontId)
                            ->whereIn('top.id', $a['category_ids'])->whereRaw("c.path LIKE CONCAT(top.path, '%')"));
                    }
                })->pluck('i.order_id');
            foreach ($items as $orderId) {
                $bought[$orderEmail[Coerce::int($orderId)] ?? ''] = true;
            }
        }
        $since = $a['no_order_days'] === null ? null : now()->subDays($a['no_order_days'])->toDateTimeString();

        return array_filter($pool, function (array $p) use ($stats, $a, $since, $bought): bool {
            $s = $stats[$p['email']] ?? ['n' => 0, 'spent' => 0.0, 'last' => ''];
            if ($a['never_ordered'] && ($p['user_id'] === null || $s['n'] > 0)) {
                return false;                                    // signed up (an account) and never ordered
            }
            if ($a['this_shop'] && $s['n'] === 0) {
                return false;
            }
            if ($since !== null && ($s['n'] === 0 || $s['last'] >= $since)) {
                return false;                                    // a customer who ordered before, but not in the last N days
            }
            if ($a['min_orders'] !== null && $s['n'] < $a['min_orders']) {
                return false;
            }
            if ($a['min_spent'] !== null && $s['spent'] < $a['min_spent']) {
                return false;
            }

            return $bought === null || isset($bought[$p['email']]);
        });
    }

    /** Fix the recipients and start sending (now, or at the scheduled time when `campaigns:dispatch` reaches it). */
    public static function start(int $campaignId): int
    {
        $c = self::row($campaignId);
        if ($c === null || ! in_array(Row::str($c, 'status'), ['draft', 'scheduled'], true)) {
            return 0;
        }
        $sf = Row::int($c, 'storefront_id');
        $audience = self::audience(Coerce::arr(json_decode(Row::str($c, 'audience'), true)));
        $recipients = self::resolve($sf, $audience)['recipients'];
        DB::transaction(function () use ($campaignId, $recipients): void {
            foreach (array_chunk($recipients, 500) as $chunk) {
                DB::table('core_email_campaign_recipients')->insertOrIgnore(array_map(fn (array $r): array => [
                    'campaign_id' => $campaignId, 'email' => $r['email'], 'user_id' => $r['user_id'], 'name' => $r['name'] === null ? null : mb_substr($r['name'], 0, 120),
                    'status' => 'pending', 'created_at' => now(),
                ], $chunk));
            }
            DB::table('core_email_campaigns')->where('id', $campaignId)->update([
                'status' => 'sending', 'started_at' => now(), 'recipients' => count($recipients), 'updated_at' => now(),
            ]);
        });

        return count($recipients);
    }

    /**
     * Every five minutes: start the campaigns whose time has come, then hand the bulk outbox what each shop can send in
     * this pass, and close the campaigns that have nothing left.
     *
     * @return array{started: int, queued: int, finished: int}
     */
    public static function dispatch(BulkMailer $mailer): array
    {
        $out = ['started' => 0, 'queued' => 0, 'finished' => 0];
        foreach (DB::table('core_email_campaigns')->where('status', 'scheduled')->where('scheduled_at', '<=', now())->pluck('id') as $id) {
            self::start(Coerce::int($id));
            $out['started']++;
        }
        $perRun = config()->integer('notifications.bulk.per_run');
        foreach (DB::table('core_email_campaigns')->where('status', 'sending')->orderBy('id')->get(['id', 'storefront_id']) as $raw) {
            $c = Row::cast($raw);
            $id = Row::int($c, 'id');
            $sf = Row::int($c, 'storefront_id');
            // what this shop's campaigns already have waiting in the outbox (the payload ends with its storefront id)
            $waiting = DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->where('status', OrderMailer::STATUS_PENDING)
                ->where('event', self::KIND)->where('payload', 'like', '%"storefront_id":'.$sf.'}')->count();
            $room = min($perRun, MailBudget::bulkRemaining($sf)) - $waiting;
            if ($room > 0) {
                foreach (DB::table('core_email_campaign_recipients')->where('campaign_id', $id)->where('status', 'pending')->orderBy('id')->limit($room)->get(['id', 'email']) as $r) {
                    $rec = Row::cast($r);
                    $mailer->enqueue(self::KIND, Row::str($rec, 'email'), ['recipient_id' => Row::int($rec, 'id'), 'storefront_id' => $sf], 'campaign:'.Row::int($rec, 'id'));
                    DB::table('core_email_campaign_recipients')->where('id', Row::int($rec, 'id'))->update(['status' => 'queued']);
                    $out['queued']++;
                }
            }
            if (DB::table('core_email_campaign_recipients')->where('campaign_id', $id)->whereIn('status', ['pending', 'queued'])->doesntExist()) {
                DB::table('core_email_campaigns')->where('id', $id)->update(['status' => 'sent', 'finished_at' => now(), 'updated_at' => now()]);
                $out['finished']++;
            }
        }

        return $out;
    }

    /**
     * One recipient's e-mail, built at SEND time; null (the recipient marked skipped) when it must not go.
     *
     * @return array<string, mixed>|null
     */
    public static function mailData(int $recipientId): ?array
    {
        $raw = DB::table('core_email_campaign_recipients')->where('id', $recipientId)->first();
        if ($raw === null) {
            return null;
        }
        $r = Row::cast($raw);
        $c = self::row(Row::int($r, 'campaign_id'));
        if ($c === null || Row::str($c, 'status') === 'cancelled') {
            DB::table('core_email_campaign_recipients')->where('id', $recipientId)->update(['status' => 'skipped', 'skip_reason' => 'cancelled']);

            return null;
        }
        $sf = Row::int($c, 'storefront_id');
        $email = Row::str($r, 'email');
        if (ReEngagement::optedOut($sf, $email)) {
            DB::table('core_email_campaign_recipients')->where('id', $recipientId)->update(['status' => 'skipped', 'skip_reason' => 'unsubscribed']);

            return null;
        }
        $code = null;
        $percent = Row::nfloat($c, 'code_percent');
        if ($percent !== null && $percent > 0 && PromotionRules::moneyRewardsEnabled($sf)) {
            // the recipient's own code: issued once, at its first send attempt, and reused by a retry
            $codeId = Row::nint($r, 'code_id');
            if ($codeId === null) {
                $codeId = PersonalCodes::issue($sf, $email, 'campaign', $recipientId, $percent, Row::nfloat($c, 'code_cap'), max(1, Row::nint($c, 'code_days') ?? 7))['id'];
                DB::table('core_email_campaign_recipients')->where('id', $recipientId)->update(['code_id' => $codeId]);
            }
            $codeRow = Row::cast(DB::table('core_personal_codes')->where('id', $codeId)->first() ?? new \stdClass);
            $code = ['code' => Row::str($codeRow, 'code'), 'percent' => $percent, 'cap' => Row::nfloat($c, 'code_cap'), 'expires_at' => Row::str($codeRow, 'expires_at')];
        }

        return self::message($c, $email, $code, MarketingLinks::unsubscribeUrl($sf, $email, self::KIND));
    }

    /**
     * The e-mail of a campaign row for an address (also the preview and the test send, with a sample code).
     *
     * @param  array{code: string, percent: float, cap: float|null, expires_at: string}|null  $code
     * @return array<string, mixed>
     */
    public static function message(\stdClass $c, string $email, ?array $code, string $unsubscribeUrl): array
    {
        $sf = Row::int($c, 'storefront_id');
        $ids = array_values(array_map(fn ($v): int => Coerce::int($v), Coerce::arr(json_decode((string) Row::nstr($c, 'product_ids'), true))));

        return [
            'storefront_id' => $sf, 'email' => $email,
            'subject_ar' => Row::str($c, 'subject_ar'), 'subject_en' => Row::str($c, 'subject_en'),
            'body_ar' => Row::str($c, 'body_ar'), 'body_en' => Row::str($c, 'body_en'),
            'products' => EmailProducts::cards($sf, $ids), 'code' => $code,
            'shop_url' => StorefrontUrls::frontend($sf), 'unsubscribe_url' => $unsubscribeUrl,
        ];
    }

    /** The recipient's e-mail went out. */
    public static function markSent(int $recipientId): void
    {
        DB::table('core_email_campaign_recipients')->where('id', $recipientId)->update(['status' => 'sent', 'sent_at' => now()]);
    }

    public static function row(int $campaignId): ?\stdClass
    {
        $raw = DB::table('core_email_campaigns')->where('id', $campaignId)->first();

        return $raw === null ? null : Row::cast($raw);
    }

    /** @return array{pending: int, queued: int, sent: int, skipped: int, failed: int} */
    public static function progress(int $campaignId): array
    {
        $n = [];
        foreach (DB::table('core_email_campaign_recipients')->where('campaign_id', $campaignId)->groupBy('status')->selectRaw('status, COUNT(*) AS n')->get() as $raw) {
            $r = Row::cast($raw);
            $n[Row::str($r, 'status')] = Row::int($r, 'n');
        }

        return ['pending' => $n['pending'] ?? 0, 'queued' => $n['queued'] ?? 0, 'sent' => $n['sent'] ?? 0, 'skipped' => $n['skipped'] ?? 0, 'failed' => $n['failed'] ?? 0];
    }

    /** Days the throttle needs for this many e-mails on a shop: the bulk allowance a day (cap - reserve), at least 1. */
    public static function daysToSend(int $storefrontId, int $count): int
    {
        $mailbox = DB::table('storefront_mailboxes')->where('storefront_id', $storefrontId)->first(['daily_cap', 'transactional_reserve']);
        $perDay = $mailbox === null ? 0 : max(0, Row::int(Row::cast($mailbox), 'daily_cap') - Row::int(Row::cast($mailbox), 'transactional_reserve'));

        return $perDay <= 0 ? 0 : max(1, (int) ceil($count / $perDay));
    }
}
