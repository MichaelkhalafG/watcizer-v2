<?php

declare(strict_types=1);

namespace App\Domain\Promotions;

use App\Domain\Activity\ActivityLog;
use App\Domain\Campaigns\CampaignWriter;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copy one storefront's LIVE offers to another as the target's OWN, independent rows
 * (2026-11-11, Brand Fashion step 3). Written for Watchizer → Brand Fashion; nothing in it names
 * either shop (D13: a storefront difference is data, never a code path).
 *
 * ── What is copied, and through which door ───────────────────────────────────────────────────
 *
 *   • PROMOTION RULES attached to the source storefront, not issued by the wheel (the wheel is
 *     Watchizer's alone), live now or starting later → `PromotionWriter::save()` with the target as
 *     their ONLY storefront: their own row, their own usage counters, their own switch.
 *   • CAMPAIGNS of the source live now or starting later → `CampaignWriter::create()` on the
 *     target, with only the products that are VISIBLE on the target, each with its campaign price.
 *
 * ── Codes ───────────────────────────────────────────────────────────────────────────────────
 *
 * `promotion_rules.code` is UNIQUE across the whole table (`promo_code_unique`), so an independent
 * copy of a coded rule CANNOT carry the same code. Every coded rule therefore needs an explicit
 * `old=new` pair from the operator; a coded rule without one is refused by name, before anything
 * is written. (Sharing ONE rule between the shops — the only way the same code works — is not a
 * copy, and is not what this does.)
 *
 * Not copied, because there is nothing to copy: free shipping (`settings.shipping.free_over`) and
 * the money-rewards switch (`settings.promotions.money_rewards`) are storefront settings — they are
 * REPORTED, side by side, so a difference is visible; changing them is the settings screen's job.
 * The one-offer-per-order rule is the engine's (a rule's `stackable`), so it travels with each rule.
 */
final class OfferCopy
{
    public function __construct(private readonly PromotionWriter $promotions) {}

    /**
     * What a copy would create. Nothing is written.
     *
     * @param  array<string, string>  $codeMap  source code => target code
     * @return array{rules: list<array<string, mixed>>, campaigns: list<array<string, mixed>>, settings: array<int, array<string, mixed>>, refusals: list<string>}
     */
    public function plan(int $from, int $to, array $codeMap): array
    {
        $now = now()->toDateTimeString();
        $refusals = [];
        $rules = [];

        $ids = DB::table('promotion_rules as r')
            ->join('promotion_rule_storefront as s', 's.promotion_rule_id', '=', 'r.id')
            ->where('s.storefront_id', $from)
            ->where('r.is_active', 1)
            ->where(fn (Builder $q) => $q->whereNull('r.issuer')->orWhere('r.issuer', '!=', 'wheel'))
            ->where(fn (Builder $q) => $q->whereNull('r.ends_at')->orWhere('r.ends_at', '>', $now))
            ->orderBy('r.id')->pluck('r.id');

        foreach ($ids as $rid) {
            $id = Coerce::int($rid);
            $rule = Row::cast(DB::table('promotion_rules')->where('id', $id)->first() ?? throw new RuntimeException("rule {$id} vanished"));
            $code = Row::nstr($rule, 'code');
            $newCode = null;
            if ($code !== null) {
                $newCode = $codeMap[$code] ?? null;
                if ($newCode === null) {
                    $refusals[] = "rule {$id} has the code {$code}: pass --code={$code}=<new code> (codes are unique across both shops)";
                } elseif (DB::table('promotion_rules')->where('code', $newCode)->exists()) {
                    $refusals[] = "the code {$newCode} already exists — choose another for {$code}";
                }
            }
            // A category or sub-type id is a node of ONE storefront's tree (each tree is an independent copy, §2.25):
            // copied as-is it would point at the source shop's node. Refused, never translated by guess.
            if (DB::table('promotion_rule_conditions')->where('promotion_rule_id', $id)->whereNotNull('storefront_category_id')->exists()
                || DB::table('promotion_rule_scopes')->where('promotion_rule_id', $id)->whereIn('dimension', ['category', 'sub_type'])->exists()) {
                $refusals[] = "rule {$id} ({$code}) names a category of the source shop's tree: recreate it on the target by hand";
            }
            $already = $newCode !== null && DB::table('promotion_rules as r')
                ->join('promotion_rule_storefront as s', 's.promotion_rule_id', '=', 'r.id')
                ->where('s.storefront_id', $to)->where('r.code', $newCode)->exists();

            $rules[] = [
                'source_id' => $id,
                'name' => Row::str($rule, 'name'),
                'code' => $code,
                'new_code' => $newCode,
                'already' => $already,
                'payload' => $this->rulePayload($rule, $to, $newCode),
            ];
        }

        $campaigns = [];
        foreach (DB::table('storefront_campaigns')->where('storefront_id', $from)->where('is_active', 1)
            ->where('ends_at', '>', $now)->orderBy('id')->get() as $c) {
            $camp = Row::cast($c);
            $cid = Row::int($camp, 'id');
            $visibleOnTarget = [];
            foreach (DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
                ->where('sp.storefront_id', $to)->where('sp.is_visible', 1)->where('p.is_active', 1)->whereNull('p.deleted_at')
                ->whereIn('sp.product_id', DB::table('storefront_campaign_products')->where('campaign_id', $cid)->select('product_id'))
                ->pluck('sp.product_id') as $pid) {
                $visibleOnTarget[Coerce::int($pid)] = true;
            }
            $productIds = [];
            $prices = [];
            $left = [];
            foreach (DB::table('storefront_campaign_products')->where('campaign_id', $cid)->orderBy('position')
                ->get(['product_id', 'price_mode', 'price_value']) as $cp) {
                $r = Row::cast($cp);
                $pid = Row::int($r, 'product_id');
                if (! isset($visibleOnTarget[$pid])) {
                    $left[] = $pid;

                    continue;
                }
                $productIds[] = $pid;
                $mode = Row::nstr($r, 'price_mode');
                if ($mode !== null) {
                    $prices[$pid] = ['mode' => $mode, 'value' => Row::nfloat($r, 'price_value') ?? 0.0];
                }
            }
            $overlap = CampaignWriter::overlapping($to, null, Row::str($camp, 'presentation'), Row::str($camp, 'starts_at'), Row::str($camp, 'ends_at'));
            $campaigns[] = [
                'source_id' => $cid,
                'name' => Row::str($camp, 'name'),
                'presentation' => Row::str($camp, 'presentation'),
                'starts_at' => Row::str($camp, 'starts_at'),
                'ends_at' => Row::str($camp, 'ends_at'),
                'products' => count($productIds),
                'priced' => count($prices),
                'left_out' => $left,
                'overlap' => $overlap,
                'payload' => [
                    'name' => Row::str($camp, 'name'),
                    'presentation' => Row::str($camp, 'presentation'),
                    'starts_at' => Row::str($camp, 'starts_at'),
                    'ends_at' => Row::str($camp, 'ends_at'),
                    'is_active' => true,
                    'product_ids' => $productIds,
                    'prices' => $prices,
                    // The prices were confirmed on the source when they were set; the same
                    // numbers on the same products are not a new decision.
                    'confirm_prices' => true,
                ],
            ];
        }

        $settings = [];
        foreach ([$from, $to] as $sf) {
            $raw = DB::table('storefronts')->where('id', $sf)->value('settings');
            $s = Coerce::arr(is_string($raw) ? json_decode($raw, true) : null);
            $settings[$sf] = [
                'free_over' => Coerce::arr($s['shipping'] ?? null)['free_over'] ?? null,
                'money_rewards' => Coerce::arr($s['promotions'] ?? null)['money_rewards'] ?? null,
            ];
        }

        return ['rules' => $rules, 'campaigns' => $campaigns, 'settings' => $settings, 'refusals' => $refusals];
    }

    /**
     * Create what the plan lists. Refuses the whole run if the plan carries a refusal.
     *
     * @param  array{rules: list<array<string, mixed>>, campaigns: list<array<string, mixed>>, settings: array<int, array<string, mixed>>, refusals: list<string>}  $plan
     * @return array{rules: list<int>, campaigns: list<int>}
     */
    public function apply(array $plan, int $to): array
    {
        if ($plan['refusals'] !== []) {
            throw new RuntimeException('Refused: '.implode(' | ', $plan['refusals']));
        }
        $created = ['rules' => [], 'campaigns' => []];
        foreach ($plan['rules'] as $rule) {
            if (Coerce::bool($rule['already'] ?? false)) {
                continue;
            }
            $payload = Coerce::arr($rule['payload'] ?? null);
            $id = $this->promotions->save($payload, null, null);
            ActivityLog::record('promotion_rules', $id, ActivityLog::CREATED, [], [
                'copied_from' => Coerce::int($rule['source_id'] ?? null),
                'code' => $payload['code'] ?? null,
            ], label: Coerce::nstr($payload['name'] ?? null), storefrontId: $to);
            $created['rules'][] = $id;
        }
        foreach ($plan['campaigns'] as $campaign) {
            $payload = Coerce::arr($campaign['payload'] ?? null);
            if (Coerce::intList($payload['product_ids'] ?? []) === [] || Coerce::nstr($campaign['overlap'] ?? null) !== null) {
                continue;
            }
            $prices = [];
            foreach (Coerce::arr($payload['prices'] ?? null) as $pid => $price) {
                $p = Coerce::arr($price);
                $prices[(int) $pid] = ['mode' => Coerce::str($p['mode'] ?? null), 'value' => Coerce::float($p['value'] ?? null)];
            }
            $id = CampaignWriter::create($to, [
                'name' => Coerce::str($payload['name'] ?? null),
                'presentation' => Coerce::str($payload['presentation'] ?? null),
                'starts_at' => Coerce::str($payload['starts_at'] ?? null),
                'ends_at' => Coerce::str($payload['ends_at'] ?? null),
                'is_active' => true,
                'product_ids' => Coerce::intList($payload['product_ids'] ?? []),
                'prices' => $prices,
                'confirm_prices' => true,
            ], null);
            ActivityLog::record('storefront_campaigns', $id, ActivityLog::CREATED, [], [
                'copied_from' => Coerce::int($campaign['source_id'] ?? null),
                'products' => count(Coerce::intList($payload['product_ids'] ?? [])),
            ], label: Coerce::nstr($payload['name'] ?? null), storefrontId: $to);
            $created['campaigns'][] = $id;
        }

        return $created;
    }

    /** @return array<string, mixed> the screen payload that recreates this rule on the target */
    private function rulePayload(\stdClass $rule, int $to, ?string $newCode): array
    {
        $id = Row::int($rule, 'id');
        $conditions = [];
        foreach (DB::table('promotion_rule_conditions')->where('promotion_rule_id', $id)->orderBy('id')->get() as $c) {
            $conditions[] = (array) $c;
        }
        $rewards = [];
        foreach (DB::table('promotion_rule_rewards')->where('promotion_rule_id', $id)->orderBy('id')->get() as $w) {
            $rewards[] = (array) $w;
        }
        $scopes = [];
        foreach (DB::table('promotion_rule_scopes')->where('promotion_rule_id', $id)->orderBy('id')->get(['role', 'dimension', 'value_id', 'value']) as $s) {
            $scopes[] = (array) $s;
        }

        return [
            'name' => Row::str($rule, 'name'),
            'code' => $newCode,
            'label_en' => Row::nstr($rule, 'label_en'),
            'label_ar' => Row::nstr($rule, 'label_ar'),
            'apply_per' => Row::str($rule, 'apply_per'),
            'max_discount' => Row::nstr($rule, 'max_discount'),
            'min_order_amount' => Row::nstr($rule, 'min_order_amount'),
            'usage_limit' => Row::nint($rule, 'usage_limit'),
            'usage_limit_per_customer' => Row::nint($rule, 'usage_limit_per_customer'),
            'include_sale_items' => Row::bool($rule, 'include_sale_items'),
            'stackable' => Row::bool($rule, 'stackable'),
            'unit_floor' => Row::str($rule, 'unit_floor'),
            'qualify_min_quantity' => Row::nint($rule, 'qualify_min_quantity'),
            'qualify_min_amount' => Row::nstr($rule, 'qualify_min_amount'),
            'priority' => Row::int($rule, 'priority'),
            'is_active' => true,
            'starts_at' => Row::nstr($rule, 'starts_at'),
            'ends_at' => Row::nstr($rule, 'ends_at'),
            'storefronts' => [$to],
            'conditions' => $conditions,
            'rewards' => $rewards,
            'scopes' => $scopes,
        ];
    }
}
