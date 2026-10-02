<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Compat\CompatCart;
use App\Domain\Catalog\PriceWatch;
use App\Domain\Customers\CustomerSeen;
use App\Storefront\ImageUrl;
use App\Support\LegacySlug;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The weekly re-engagement e-mail, rebuilt on core (2026-10-01; decisions 2026-09-28 and 2026-09-30).
 *
 * ── The rules ─────────────────────────────────────────────────────────────────────────────────
 *  - WEEKLY, per storefront. `plan()` (Monday 10:00) picks who and what, records it, and mails the
 *    TEAM a preview; `send()` queues the real e-mails 24 hours later unless the storefront is paused.
 *  - Who: customers with an address, absent 30+ days by the "last seen" core owns (or the legacy
 *    last login, for someone not back since the flip), not unsubscribed, and NOT in last week's
 *    run — never the same customer two weeks running. While the storefront's audience is `team`
 *    (the default), the run goes to the team's addresses only.
 *  - What: up to 6 products that are in stock, visible on that storefront, and whose price has not
 *    changed for 14 days (`PriceWatch`; no price hold) — and never a product that address was sent
 *    before. Ranked by the customer's own past orders (same brand, same gender), then newest. Fewer
 *    than 3 → no e-mail for that person.
 *  - At SEND time (`mailData()`) every product is checked again — still in stock, still visible,
 *    same price — and the e-mail shows today's price. Fewer than 3 left → not sent.
 *  - Every e-mail carries a working unsubscribe (and the List-Unsubscribe header); an unsubscribed
 *    address is skipped by every later run.
 *  - Bulk mail: it goes through `BulkMailer`, so it only uses the day's bulk budget and never an
 *    order confirmation's place.
 */
final class ReEngagement
{
    public const KIND = 'reengagement';

    public const PER_EMAIL = 6;

    public const MIN_PRODUCTS = 3;

    public const ABSENT_DAYS = 30;

    public const STEADY_DAYS = 14;

    public function __construct(private readonly BulkMailer $mailer) {}

    /** @return array{paused: bool, audience: string, team_emails: list<string>, manual_product_ids: list<int>} */
    public static function settings(int $storefrontId): array
    {
        $raw = DB::table('core_reengagement_settings')->where('storefront_id', $storefrontId)->first();
        $row = is_object($raw) ? Row::cast($raw) : null;
        $typed = $row === null ? '' : (Row::nstr($row, 'team_emails') ?? '');
        // ONLY the addresses typed on this storefront's screen (R7, developer 2026-09-30). There is no
        // fallback: it used to be the order-notification addresses, which are shared by both shops — so
        // Brand Fashion's team would have received Watchizer's campaign preview. Empty = not planned.
        $team = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $typed) ?: []), fn (string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false));

        return [
            'paused' => $row !== null && Row::bool($row, 'paused'),
            'audience' => $row !== null && Row::str($row, 'audience') === 'customers' ? 'customers' : 'team',
            'team_emails' => $team,
            'manual_product_ids' => self::idList($row === null ? null : Row::nstr($row, 'manual_product_ids')),
        ];
    }

    /**
     * A stored JSON list of product ids, in order, positive ints only.
     *
     * @return list<int>
     */
    public static function idList(?string $json): array
    {
        $decoded = json_decode($json ?? '[]', true);

        return array_values(array_filter(is_array($decoded) ? $decoded : [], fn (mixed $id): bool => is_int($id) && $id > 0));
    }

    /**
     * Plan this week's run for a storefront and mail the team its preview. Once per ISO week.
     *
     * @return array{status: string, run_id: ?int, recipients: int}
     */
    public function plan(int $storefrontId): array
    {
        $week = now()->format('o-\WW');
        // This week's run, if one exists. SENT is final. Anything else (waiting its 24 hours, or paused)
        // is RE-PLANNED IN PLACE from the current settings (developer, 2026-10-01): "already planned" used
        // to do nothing, so a changed setting silently never took effect until someone deleted the run.
        $existing = DB::table('core_reengagement_runs')->where('storefront_id', $storefrontId)->where('week', $week)->first(['id', 'status', 'sent_at']);
        if (is_object($existing) && Row::str(Row::cast($existing), 'status') === 'sent') {
            $sentAt = Row::nstr(Row::cast($existing), 'sent_at') ?? '';

            return ['status' => "already sent this week (on {$sentAt}) — the next one is planned on Monday", 'run_id' => Row::int(Row::cast($existing), 'id'), 'recipients' => 0];
        }
        $replanning = is_object($existing) ? Row::int(Row::cast($existing), 'id') : null;
        $settings = self::settings($storefrontId);
        // No preview recipients → no run at all (R7). The preview is the 24-hour safety net; a week
        // nobody would see before it goes out is not planned, whichever the audience.
        if ($settings['team_emails'] === []) {
            return ['status' => 'not planned: no preview recipients set on the dashboard', 'run_id' => null, 'recipients' => 0];
        }
        PriceWatch::refresh();
        $eligible = $this->eligibleProducts($storefrontId);
        // The team's picks for this week (R4), in their order — only those that pass the same rules as
        // the algorithm's (in stock, visible, price steady). None picked = the algorithm, unchanged.
        $manual = $settings['manual_product_ids'];
        $pool = $manual === [] ? $eligible : array_values(array_intersect($manual, $eligible));

        $recipients = $settings['audience'] === 'customers'
            ? $this->absentCustomers($storefrontId)
            : array_map(fn (string $e): array => ['user_id' => null, 'email' => mb_strtolower($e)], $settings['team_emails']);

        return DB::transaction(function () use ($storefrontId, $week, $settings, $pool, $recipients, $manual, $replanning): array {
            $fields = [
                'audience' => $settings['audience'],
                'manual_product_ids' => $manual === [] || $settings['paused'] ? null : (string) json_encode($manual),
                'status' => $settings['paused'] ? 'paused' : 'previewed',
                'recipients' => 0,
                // A re-plan restarts the 24 hours: the team must see the NEW preview before it goes out.
                'previewed_at' => now(), 'send_after' => now()->addHours(24), 'updated_at' => now(),
            ];
            if ($replanning !== null) {
                // Only reached for an unsent run (see above), so nothing in `sends` has been mailed yet:
                // they are queued at send time.
                DB::table('core_reengagement_sends')->where('run_id', $replanning)->delete();
                DB::table('core_reengagement_runs')->where('id', $replanning)->where('status', '!=', 'sent')->update($fields);
                $runId = $replanning;
            } else {
                $runId = (int) DB::table('core_reengagement_runs')->insertGetId(['storefront_id' => $storefrontId, 'week' => $week, 'created_at' => now()] + $fields);
            }
            $verb = $replanning !== null ? 're-planned from the current settings' : 'planned';
            if ($settings['paused']) {
                return ['status' => "{$verb} — PAUSED: nothing goes out this week unless it is resumed and planned again", 'run_id' => $runId, 'recipients' => 0];
            }
            $count = 0;
            foreach ($recipients as $r) {
                $picked = $this->pick($pool, $r['user_id'], $r['email'], $manual === []);
                if (count($picked) < self::MIN_PRODUCTS) {
                    continue;
                }
                DB::table('core_reengagement_sends')->insertOrIgnore([
                    'run_id' => $runId, 'user_id' => $r['user_id'], 'email' => $r['email'],
                    'product_ids' => (string) json_encode($picked), 'created_at' => now(),
                ]);
                $count++;
            }
            DB::table('core_reengagement_runs')->where('id', $runId)->update(['recipients' => $count, 'updated_at' => now()]);

            // The preview: one e-mail to each team address with the run's numbers and a sample. Keyed by
            // the planning time too, so a RE-plan's new preview is not dropped as a duplicate of the first.
            $stamp = now()->format('YmdHis');
            foreach ($settings['team_emails'] as $email) {
                $this->mailer->enqueue(self::KIND.'_preview', mb_strtolower($email), ['run_id' => $runId], "reengagement-preview:{$runId}:{$stamp}:".mb_strtolower($email));
            }
            $sendsAt = now()->addHours(24)->format('D j M H:i');

            return ['status' => "{$verb} — preview sent to the team; goes out after {$sendsAt} unless paused", 'run_id' => $runId, 'recipients' => $count];
        });
    }

    /**
     * Queue the e-mails of every run whose 24 hours have passed — unless its storefront is paused
     * now, in which case the run is marked paused and nothing goes out this week.
     *
     * @return list<array{run_id: int, status: string, queued: int}>
     */
    public function send(): array
    {
        $out = [];
        foreach (DB::table('core_reengagement_runs')->where('status', 'previewed')->where('send_after', '<=', now())->get() as $raw) {
            $run = Row::cast($raw);
            $runId = Row::int($run, 'id');
            if (self::settings(Row::int($run, 'storefront_id'))['paused']) {
                DB::table('core_reengagement_runs')->where('id', $runId)->update(['status' => 'paused', 'updated_at' => now()]);
                $out[] = ['run_id' => $runId, 'status' => 'paused', 'queued' => 0];

                continue;
            }
            $queued = 0;
            foreach (DB::table('core_reengagement_sends')->where('run_id', $runId)->get(['id', 'email']) as $s) {
                $send = Row::cast($s);
                if ($this->mailer->enqueue(self::KIND, Row::str($send, 'email'), ['send_id' => Row::int($send, 'id')], 'reengagement:'.Row::int($send, 'id')) !== null) {
                    $queued++;
                }
            }
            DB::table('core_reengagement_runs')->where('id', $runId)->update(['status' => 'sent', 'sent_at' => now(), 'updated_at' => now()]);
            // The team's picks were for THIS week's e-mail: now that it is sent, they are cleared, and next
            // week is automatic unless someone picks again (standing picks would reach nobody after one
            // week: never the same products twice). Cleared at SEND, not at plan, so a re-plan keeps them.
            if (Row::nstr($run, 'manual_product_ids') !== null) {
                DB::table('core_reengagement_settings')->where('storefront_id', Row::int($run, 'storefront_id'))->update(['manual_product_ids' => null, 'updated_at' => now()]);
            }
            $out[] = ['run_id' => $runId, 'status' => 'sent', 'queued' => $queued];
        }

        return $out;
    }

    /**
     * What one e-mail shows, built at SEND time from live data. Null = do not send (unsubscribed
     * since, or fewer than 3 products still in stock at the same price).
     *
     * @return array{email: string, storefront_id: int, products: list<array{name: string, price: float, image: ?string, url: string}>}|null
     */
    public function mailData(int $sendId): ?array
    {
        $raw = DB::table('core_reengagement_sends as s')->join('core_reengagement_runs as r', 'r.id', '=', 's.run_id')
            ->where('s.id', $sendId)->first(['s.email', 's.product_ids', 'r.storefront_id']);
        if (! is_object($raw)) {
            return null;
        }
        $send = Row::cast($raw);
        $storefrontId = Row::int($send, 'storefront_id');
        $email = Row::str($send, 'email');
        if (self::optedOut($storefrontId, $email)) {
            return null;
        }
        $decoded = json_decode(Row::str($send, 'product_ids'), true);
        $ids = array_values(array_filter(is_array($decoded) ? $decoded : [], 'is_int'));
        $live = array_values(array_intersect($ids, $this->eligibleProducts($storefrontId)));
        if (count($live) < self::MIN_PRODUCTS) {
            return null;
        }

        return ['email' => $email, 'storefront_id' => $storefrontId, 'products' => self::cards($storefrontId, $live)];
    }

    /**
     * The preview e-mail's data: the run's numbers and one sample (the first send).
     *
     * @return array{week: string, audience: string, recipients: int, send_after: string, sample: array<string, mixed>|null}|null
     */
    public function previewData(int $runId): ?array
    {
        $run = DB::table('core_reengagement_runs')->where('id', $runId)->first();
        if (! is_object($run)) {
            return null;
        }
        $r = Row::cast($run);
        $first = DB::table('core_reengagement_sends')->where('run_id', $runId)->orderBy('id')->value('id');

        return [
            'week' => Row::str($r, 'week'),
            'audience' => Row::str($r, 'audience'),
            'recipients' => Row::int($r, 'recipients'),
            'send_after' => Row::str($r, 'send_after'),
            'sample' => $first === null ? null : $this->mailData((int) (is_numeric($first) ? $first : 0)),
        ];
    }

    public static function optedOut(int $storefrontId, string $email): bool
    {
        return DB::table('core_marketing_optouts')->where('storefront_id', $storefrontId)->where('email', mb_strtolower($email))->exists();
    }

    public static function unsubscribe(int $storefrontId, string $email): void
    {
        DB::table('core_marketing_optouts')->insertOrIgnore(['storefront_id' => $storefrontId, 'email' => mb_strtolower($email), 'created_at' => now()]);
    }

    /**
     * Products that may be featured today on a storefront: visible, active, in stock, price steady.
     *
     * @return list<int> newest first
     */
    public function eligibleProducts(int $storefrontId): array
    {
        $ids = array_values(array_map(fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0), DB::table('catalog_products as p')
            ->join('storefront_product as sp', function (JoinClause $j) use ($storefrontId): void {
                $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId)->where('sp.is_visible', '=', 1);
            })
            ->whereNull('p.deleted_at')->where('p.is_active', 1)
            ->whereRaw('p.stock_express + p.stock_market > 0')
            ->orderByDesc('p.created_at')->orderByDesc('p.id')
            ->pluck('p.id')->all()));
        $steady = array_flip(PriceWatch::steady($ids, self::STEADY_DAYS));

        return array_values(array_filter($ids, fn (int $id): bool => isset($steady[$id])));
    }

    /**
     * How many customers a run planned NOW would reach — `absentCustomers()` in one query, for the
     * screen's status line (it runs three queries per customer, too slow for a page load). Same rules:
     * an address, last seen (core's stamp or the legacy login, the later; else sign-up) 30+ days ago,
     * not unsubscribed, not in last week's run. An upper bound: someone with fewer than 3 products
     * left to show is still counted here and skipped at planning.
     */
    public static function audienceEstimate(int $storefrontId): int
    {
        $lastWeek = DB::table('core_reengagement_runs')->where('storefront_id', $storefrontId)
            ->where('week', now()->subWeek()->format('o-\WW'))->value('id');
        $count = DB::table('users as u')
            ->leftJoin('core_customer_seen as s', function (JoinClause $j) use ($storefrontId): void {
                $j->on('s.user_id', '=', 'u.id')->where('s.storefront_id', '=', $storefrontId);
            })
            ->where('u.type', 'User')->whereNotNull('u.email')->where('u.email', '!=', '')
            ->whereRaw('COALESCE(GREATEST(COALESCE(s.last_seen_at, u.last_login_at), COALESCE(u.last_login_at, s.last_seen_at)), u.created_at) <= ?',
                [now()->subDays(self::ABSENT_DAYS)->toDateTimeString()])
            ->whereNotExists(fn (Builder $q) => $q->from('core_marketing_optouts as o')->where('o.storefront_id', $storefrontId)->whereRaw('o.email = LOWER(u.email)'))
            ->when($lastWeek !== null, fn (Builder $q) => $q->whereNotExists(fn (Builder $s) => $s->from('core_reengagement_sends as rs')->where('rs.run_id', $lastWeek)->whereRaw('rs.email = LOWER(u.email)')))
            ->count();

        return $count;
    }

    /**
     * When the next run is planned: Mondays at 10:00 (routes/console.php), in the app's time zone.
     * Monday is named: Carbon's startOfWeek() follows the locale, and under `ar` it is SATURDAY.
     */
    public static function nextPlanAt(): Carbon
    {
        $monday = now()->startOfWeek(Carbon::MONDAY)->setTime(10, 0);

        return now()->lt($monday) ? $monday : $monday->addWeek();
    }

    /** @return list<array{user_id: int, email: string}> */
    private function absentCustomers(int $storefrontId): array
    {
        $lastWeek = DB::table('core_reengagement_runs')->where('storefront_id', $storefrontId)
            ->where('week', now()->subWeek()->format('o-\WW'))->value('id');
        $skip = $lastWeek === null ? [] : array_flip(array_map(fn (mixed $e): string => is_string($e) ? $e : '', DB::table('core_reengagement_sends')->where('run_id', $lastWeek)->pluck('email')->all()));
        $cutoff = now()->subDays(self::ABSENT_DAYS)->toDateTimeString();
        $out = [];
        foreach (DB::table('users')->where('type', 'User')->whereNotNull('email')->where('email', '!=', '')->orderBy('id')->get(['id', 'email', 'created_at']) as $raw) {
            $u = Row::cast($raw);
            $email = mb_strtolower(Row::str($u, 'email'));
            $id = Row::int($u, 'id');
            if (isset($skip[$email]) || self::optedOut($storefrontId, $email)) {
                continue;
            }
            $seen = CustomerSeen::lastSeen($id, $storefrontId) ?? Row::nstr($u, 'created_at');
            if ($seen !== null && $seen <= $cutoff) {
                $out[] = ['user_id' => $id, 'email' => $email];
            }
        }

        return $out;
    }

    /**
     * Up to PER_EMAIL products for one address: never one it was sent before; ranked by the
     * customer's own past orders (same brand +2, same gender +1), then the pool's order (newest).
     * `$ranked = false` (the team's picks, R4): the pool's order as given, no ranking.
     *
     * @param  list<int>  $pool
     * @return list<int>
     */
    private function pick(array $pool, ?int $userId, string $email, bool $ranked = true): array
    {
        $sent = [];
        foreach (DB::table('core_reengagement_sends')->where('email', $email)->pluck('product_ids') as $json) {
            $decoded = json_decode(is_string($json) ? $json : '[]', true);
            foreach (is_array($decoded) ? $decoded : [] as $id) {
                if (is_int($id)) {
                    $sent[$id] = true;
                }
            }
        }
        $candidates = array_values(array_filter($pool, fn (int $id): bool => ! isset($sent[$id])));
        if (! $ranked || $userId === null || $candidates === []) {
            return array_slice($candidates, 0, self::PER_EMAIL);
        }
        $bought = array_map(fn (mixed $id): int => (int) (is_numeric($id) ? $id : 0), DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.user_id', $userId)->pluck('i.product_id')->all());
        if ($bought === []) {
            return array_slice($candidates, 0, self::PER_EMAIL);
        }
        $int = fn (mixed $v): int => (int) (is_numeric($v) ? $v : 0);
        $brands = array_flip(array_map($int, DB::table('catalog_products')->whereIn('id', $bought)->pluck('brand_id')->all()));
        $genders = array_flip(array_map($int, DB::table('catalog_product_gender')->whereIn('product_id', $bought)->pluck('gender_id')->all()));
        $brandOf = DB::table('catalog_products')->whereIn('id', $candidates)->pluck('brand_id', 'id');
        $genderOf = [];
        foreach (DB::table('catalog_product_gender')->whereIn('product_id', $candidates)->get(['product_id', 'gender_id']) as $g) {
            $row = Row::cast($g);
            $genderOf[Row::int($row, 'product_id')][Row::int($row, 'gender_id')] = true;
        }
        $rank = array_flip($candidates);
        usort($candidates, function (int $a, int $b) use ($brands, $genders, $brandOf, $genderOf, $rank): int {
            $score = fn (int $id): int => (isset($brands[(int) (is_numeric($brandOf[$id] ?? null) ? $brandOf[$id] : 0)]) ? 2 : 0)
                + (array_intersect_key($genderOf[$id] ?? [], $genders) !== [] ? 1 : 0);

            return [$score($b), $rank[$a]] <=> [$score($a), $rank[$b]];
        });

        return array_slice($candidates, 0, self::PER_EMAIL);
    }

    /**
     * @param  list<int>  $ids
     * @return list<array{name: string, price: float, image: ?string, url: string}>
     */
    private static function cards(int $storefrontId, array $ids): array
    {
        $domain = DB::table('storefronts')->where('id', $storefrontId)->value('domain');
        $host = 'https://'.(is_string($domain) ? $domain : 'watchizereg.com');
        $rows = DB::table('catalog_products')->whereIn('id', $ids)->get(['id', 'selling_price', 'sale_price'])->keyBy('id');
        $titles = [];
        foreach (DB::table('catalog_product_translations')->whereIn('product_id', $ids)->get(['product_id', 'locale', 'title']) as $t) {
            $row = Row::cast($t);
            $titles[Row::int($row, 'product_id')][Row::str($row, 'locale')] = Row::nstr($row, 'title');
        }
        $covers = [];
        foreach (DB::table('catalog_product_images')->whereIn('product_id', $ids)->orderByDesc('is_cover')->orderBy('sort')->get(['product_id', 'path']) as $img) {
            $row = Row::cast($img);
            $covers[Row::int($row, 'product_id')] ??= Row::str($row, 'path');
        }
        $out = [];
        foreach ($ids as $id) {
            $p = $rows->get($id);
            if (! is_object($p)) {
                continue;
            }
            $p = Row::cast($p);
            $en = $titles[$id]['en'] ?? '';
            $slug = LegacySlug::make((string) $en);
            // English only (developer, 2026-10-01): the English title wherever one exists, the Arabic
            // only for a product that has none; the link is the English product page.
            $out[] = [
                'name' => (string) ($en ?: ($titles[$id]['ar'] ?? '')),
                'price' => CompatCart::catalogPrice(Row::str($p, 'selling_price'), Row::nstr($p, 'sale_price')),
                'image' => isset($covers[$id]) ? ImageUrl::src($covers[$id]) : null,
                'url' => $host.'/product/'.($slug !== '' ? $slug : (string) $id),
            ];
        }

        return $out;
    }
}
