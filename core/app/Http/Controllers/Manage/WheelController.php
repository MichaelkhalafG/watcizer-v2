<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Wheel\Wheel;
use App\Domain\Wheel\WheelPrizes;
use App\Domain\Wheel\WheelPrizeWriter;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Support\Val;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The wheel's dashboard page (2026-11-07; the prize editor since 2026-11-08): the team controls the SEGMENTS — label
 * in both languages, type, value, odds, colour, the cap of a percentage prize — the code's validity in hours and an
 * optional minimum order, and the ON/OFF switch; and reads who spun (phones MASKED), what they won, which ad brought
 * them, whether the code was used and on which order, with the totals (spins, codes used, discount given, sales with
 * wheel codes) in all and by source.
 *
 * A saved change applies to the NEXT spins only: every code already won keeps the terms it was won with (they are
 * on the spin's own row and its own prize rule). Every save and every switch is activity-logged.
 *
 * Phones are personal data: the list shows them MASKED (`010•••••678`); there is no export and no search by number.
 * "Discount given" and "sales" count orders that are not cancelled, exactly as the engine's own usage counting does.
 */
final class WheelController
{
    public const LIMIT = 300;

    public function __construct(private readonly StorefrontCache $cache) {}

    public function index(Storefront $storefront): Response
    {
        $sf = (int) $storefront->id;
        $now = now();
        $settings = Wheel::settings($sf);
        $prizes = WheelPrizes::forStorefront($sf);

        // the redemption of each code on an order that stands (one per code: single use)
        $usage = [];
        foreach (
            DB::table('promotion_redemptions as pr')
                ->join('orders as o', 'o.id', '=', 'pr.order_id')
                ->join(Wheel::TABLE.' as s', 's.code', '=', 'pr.code')
                ->where('s.storefront_id', $sf)
                ->where('o.status', '!=', 'cancelled')
                ->get(['s.id as spin_id', 'o.id as order_id', 'o.order_number', 'o.total_price_for_order', 'pr.amount']) as $raw
        ) {
            $row = Row::cast($raw);
            $usage[Row::int($row, 'spin_id')] = [
                'order_id' => Row::int($row, 'order_id'),
                'order_number' => Row::nstr($row, 'order_number') ?? ('#'.Row::int($row, 'order_id')),
                'order_total' => Row::money($row, 'total_price_for_order'),
                'discount' => Row::money($row, 'amount'),
            ];
        }

        $rows = [];
        $totals = ['spins' => 0, 'used' => 0, 'discount' => 0.0, 'sales' => 0.0, 'active' => 0, 'expired' => 0];
        $bySource = [];
        $byPrize = [];
        $byRound = [];
        $stamp = $now->format('Y-m-d H:i:s');
        foreach (DB::table(Wheel::TABLE)->where('storefront_id', $sf)->orderByDesc('id')->get() as $raw) {
            $spin = Row::cast($raw);
            $id = Row::int($spin, 'id');
            $use = $usage[$id] ?? null;
            $state = match (true) {
                Row::str($spin, 'prize_type') === 'none' => Wheel::STATE_NONE,
                $use !== null => Wheel::STATE_USED,
                Row::str($spin, 'expires_at') <= $stamp => Wheel::STATE_EXPIRED,
                default => Wheel::STATE_ACTIVE,
            };
            $source = Row::nstr($spin, 'utm_source') ?? '';
            $labelEn = Row::str($spin, 'prize_label_en');
            $labelAr = Row::str($spin, 'prize_label_ar');

            $campaign = Row::str($spin, 'campaign');
            $totals['spins']++;
            if ($state !== Wheel::STATE_NONE) {
                $totals[$state === Wheel::STATE_USED ? 'used' : ($state === Wheel::STATE_EXPIRED ? 'expired' : 'active')]++;
            }
            $bySource[$source] ??= ['source' => $source, 'spins' => 0, 'used' => 0, 'discount' => 0.0, 'sales' => 0.0];
            $bySource[$source]['spins']++;
            $byRound[$campaign] ??= ['campaign' => $campaign, 'round' => Wheel::roundOf($campaign), 'spins' => 0, 'used' => 0, 'discount' => 0.0, 'sales' => 0.0];
            $byRound[$campaign]['spins']++;
            $byPrize[$labelEn] ??= ['label' => ['en' => $labelEn, 'ar' => $labelAr], 'spins' => 0, 'used' => 0];
            $byPrize[$labelEn]['spins']++;
            if ($use !== null) {
                $totals['discount'] += (float) $use['discount'];
                $totals['sales'] += (float) $use['order_total'];
                $bySource[$source]['used']++;
                $bySource[$source]['discount'] += (float) $use['discount'];
                $bySource[$source]['sales'] += (float) $use['order_total'];
                $byPrize[$labelEn]['used']++;
                $byRound[$campaign]['used']++;
                $byRound[$campaign]['discount'] += (float) $use['discount'];
                $byRound[$campaign]['sales'] += (float) $use['order_total'];
            }

            if (count($rows) < self::LIMIT) {
                $rows[] = [
                    'id' => $id,
                    'created_at' => Carbon::parse(Row::str($spin, 'created_at'))->format('Y-m-d H:i'),
                    'phone_hint' => Wheel::mask(Row::str($spin, 'phone')),
                    'prize_label' => ['en' => $labelEn, 'ar' => $labelAr],
                    'color' => Row::str($spin, 'color'),
                    'code' => Row::nstr($spin, 'code'),
                    'prize_type' => Row::str($spin, 'prize_type'),
                    'invite_code' => Row::nstr($spin, 'invite_code'),
                    'campaign' => Row::str($spin, 'campaign'),
                    'utm_source' => Row::nstr($spin, 'utm_source'),
                    'utm_medium' => Row::nstr($spin, 'utm_medium'),
                    'utm_campaign' => Row::nstr($spin, 'utm_campaign'),
                    'locale' => Row::str($spin, 'locale'),
                    'expires_at' => Carbon::parse(Row::str($spin, 'expires_at'))->format('Y-m-d H:i'),
                    'state' => $state,
                    'order_id' => $use['order_id'] ?? null,
                    'order_number' => $use['order_number'] ?? null,
                    'order_total' => $use['order_total'] ?? null,
                    'discount' => $use['discount'] ?? null,
                ];
            }
        }
        usort($bySource, fn (array $a, array $b): int => $b['spins'] <=> $a['spins']);
        // the current round is listed even before its first spin
        $byRound[$settings['campaign']] ??= ['campaign' => $settings['campaign'], 'round' => $settings['round'], 'spins' => 0, 'used' => 0, 'discount' => 0.0, 'sales' => 0.0];
        krsort($byRound, SORT_NATURAL);

        return Inertia::render('Manage/Wheel/Index', [
            'storefront' => ['id' => $sf, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'enabled' => $settings['enabled'],
            'ttl_hours' => $settings['ttl_hours'],
            'min_order' => $settings['min_order'],
            'campaign' => $settings['campaign'],
            'round' => $settings['round'],
            'round_started_at' => $settings['round_started_at'] === null ? null : Carbon::parse($settings['round_started_at'])->format('Y-m-d H:i'),
            'by_round' => array_values(array_map(fn (array $r): array => $r + ['discount_text' => number_format($r['discount'], 2, '.', ''), 'sales_text' => number_format($r['sales'], 2, '.', '')], $byRound)),
            'prizes' => $prizes,
            'types' => WheelPrizes::TYPES,
            'modes' => WheelPrizes::MODES,
            'categories' => self::categoryOptions($sf),
            'gift_products' => self::giftBriefs($sf, $prizes),
            'referrals' => self::referralTotals($sf),
            'limits' => ['min' => WheelPrizes::MIN_SEGMENTS, 'max' => WheelPrizes::MAX_SEGMENTS, 'max_hours' => WheelPrizeWriter::MAX_HOURS],
            'fresh' => WheelPrizes::DEFAULTS[0],
            'totals' => ['spins' => $totals['spins'], 'used' => $totals['used'], 'active' => $totals['active'], 'expired' => $totals['expired'],
                'discount' => number_format($totals['discount'], 2, '.', ''), 'sales' => number_format($totals['sales'], 2, '.', '')],
            'by_source' => array_map(fn (array $s): array => $s + ['discount_text' => number_format($s['discount'], 2, '.', ''), 'sales_text' => number_format($s['sales'], 2, '.', '')], $bySource),
            'by_prize' => array_values($byPrize),
            'spins' => $rows,
            'limit' => self::LIMIT,
            'spin_url' => rtrim('https://'.(string) $storefront->domain, '/').'/spin',
            'now' => $now->format('Y-m-d H:i'),
        ]);
    }

    /** The segments and the terms — one save, all or nothing; the refusals land on their fields. */
    public function prizes(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'segments' => ['required', 'array'],
            'segments.*.label_en' => ['nullable', 'string', 'max:80'],
            'segments.*.label_ar' => ['nullable', 'string', 'max:80'],
            'segments.*.type' => ['nullable', 'string', 'max:16'],
            'segments.*.value' => ['nullable', 'numeric'],
            'segments.*.odds' => ['nullable', 'integer'],
            'segments.*.color' => ['nullable', 'string', 'max:7'],
            'segments.*.cap' => ['nullable', 'numeric'],
            'segments.*.scope_category_id' => ['nullable', 'integer'],
            'segments.*.min_quantity' => ['nullable', 'integer'],
            'segments.*.apply_mode' => ['nullable', 'string', 'max:16'],
            'segments.*.gift_product_ids' => ['nullable'],
            'ttl_hours' => ['nullable', 'integer'],
            'min_order' => ['nullable', 'numeric'],
        ]));
        $segments = [];
        foreach (Val::arr($data, 'segments') as $row) {
            $segments[] = Coerce::arr($row);
        }
        WheelPrizeWriter::save(
            (int) $storefront->id,
            $segments,
            ['ttl_hours' => $data['ttl_hours'] ?? null, 'min_order' => $data['min_order'] ?? null],
            Coerce::nint($request->user()?->getAuthIdentifier()),
        );
        // the storefront reads the segments and the terms from core on every open of /spin (held a minute)
        $this->cache->forgetStorefront((int) $storefront->id, (string) $storefront->code);
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', ManageText::t('wheel.prizes_saved', 'تم حفظ الجوائز. التغيير يسري على الإدارات الجديدة فقط؛ الأكواد المربوحة من قبل تحتفظ بشروطها.'));
    }

    /** A new round: every phone and device may spin once more; the old round's codes keep their terms and expiry. */
    public function round(Storefront $storefront): RedirectResponse
    {
        $next = Wheel::startNewRound((int) $storefront->id, (string) $storefront->name);
        // the storefront's button reads the round's key from the campaigns answer, the page from GET wheel
        $this->cache->forgetStorefront((int) $storefront->id, (string) $storefront->code);
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', ManageText::t('wheel.round_started', 'بدأت الجولة :round. يمكن للجميع إدارة العجلة مرة أخرى؛ أكواد الجولات السابقة تعمل حتى انتهاء صلاحيتها.', ['round' => $next]));
    }

    public function toggle(Request $request, Storefront $storefront): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = $request->boolean('enabled');
        $before = Wheel::enabled((int) $storefront->id);
        Wheel::setEnabled((int) $storefront->id, $enabled);
        ActivityLog::record('storefronts', (int) $storefront->id, ActivityLog::UPDATED, ['wheel_enabled' => $before], ['wheel_enabled' => $enabled],
            label: (string) $storefront->name, storefrontId: (int) $storefront->id);
        // the resolved storefront row (its settings) is cached ten minutes: the storefront's floating button and /spin read the switch from it
        $this->cache->forgetStorefront((int) $storefront->id, (string) $storefront->code);
        $this->cache->flush((int) $storefront->id);

        return back()->with('status', $enabled
            ? ManageText::t('wheel.switched_on', 'تم تشغيل العجلة.')
            : ManageText::t('wheel.switched_off', 'تم إيقاف العجلة. الأكواد الموجودة تعمل حتى انتهاء صلاحيتها.'));
    }

    /**
     * This storefront's categories, as the editor's category select lists them (the node and its children are one scope).
     *
     * @return list<array{id: int, label: string, depth: int}>
     */
    private static function categoryOptions(int $storefrontId): array
    {
        $out = [];
        foreach (DB::table('storefront_categories as c')
            ->leftJoin('storefront_category_translations as ar', function (JoinClause $j): void {
                $j->on('ar.storefront_category_id', '=', 'c.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('storefront_category_translations as en', function (JoinClause $j): void {
                $j->on('en.storefront_category_id', '=', 'c.id')->where('en.locale', '=', 'en');
            })
            ->where('c.storefront_id', $storefrontId)->where('c.slug', '!=', 'legacy-tree')
            ->orderBy('c.path')->get(['c.id', 'c.depth', 'c.slug', 'ar.name as ar_name', 'en.name as en_name']) as $raw) {
            $row = Row::cast($raw);
            $depth = Row::int($row, 'depth');
            $name = Row::nstr($row, 'ar_name') ?? Row::nstr($row, 'en_name') ?? Row::str($row, 'slug');
            $en = Row::nstr($row, 'en_name');
            $out[] = ['id' => Row::int($row, 'id'), 'label' => str_repeat('— ', max(0, $depth - 1)).$name.($en !== null && $en !== $name ? ' ('.$en.')' : ''), 'depth' => $depth];
        }

        return $out;
    }

    /**
     * The products named by the gift lists, by id: the title the editor shows beside each id, and whether it is in stock.
     *
     * @param  list<array<string, mixed>>  $prizes
     * @return array<int, array{title: string, in_stock: bool}>
     */
    private static function giftBriefs(int $storefrontId, array $prizes): array
    {
        $ids = [];
        foreach ($prizes as $prize) {
            foreach (Coerce::arr($prize['gift_product_ids'] ?? []) as $id) {
                $int = Coerce::int($id);
                if ($int > 0 && ! in_array($int, $ids, true)) {
                    $ids[] = $int;
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        $inStock = WheelPrizes::inStock($storefrontId, $ids);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['title' => '#'.$id, 'in_stock' => in_array($id, $inStock, true)];
        }
        foreach (DB::table('catalog_product_translations')->whereIn('product_id', $ids)->whereIn('locale', ['ar', 'en'])->orderByRaw("FIELD(locale, 'en', 'ar')")->get(['product_id', 'title']) as $raw) {
            $row = Row::cast($raw);
            $pid = Row::int($row, 'product_id');
            if (isset($out[$pid])) {
                $out[$pid]['title'] = Row::nstr($row, 'title') ?? $out[$pid]['title'];
            }
        }

        return $out;
    }

    /**
     * The invite-a-friend numbers: friends who ordered with an invite, deliveries, cancellations.
     *
     * @return array{placed: int, delivered: int, cancelled: int}
     */
    private static function referralTotals(int $storefrontId): array
    {
        $out = ['placed' => 0, 'delivered' => 0, 'cancelled' => 0];
        foreach (DB::table('storefront_wheel_referrals')->where('storefront_id', $storefrontId)->select('status', DB::raw('COUNT(*) as n'))->groupBy('status')->get() as $raw) {
            $row = Row::cast($raw);
            $status = Row::str($row, 'status');
            if (array_key_exists($status, $out)) {
                $out[$status] = Row::int($row, 'n');
            }
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $out = [];
        foreach (DB::table('storefronts')->orderBy('id')->get(['id', 'name']) as $raw) {
            $row = Row::cast($raw);
            $out[] = ['value' => (string) Row::int($row, 'id'), 'label' => Row::str($row, 'name')];
        }

        return $out;
    }
}
