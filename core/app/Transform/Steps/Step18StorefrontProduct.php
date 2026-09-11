<?php

namespace App\Transform\Steps;

use App\Models\Storefront\StorefrontRedirect;
use App\Transform\ProductSlugs;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Step 18 — one storefront_product row per legacy product **per ACTIVE storefront**, key
 * (storefront_id, product_id). slug from ProductSlugs (slugify(EN title), id fallback, "-{id}"
 * on collision — A-17), effective_price = selling, effective_sale_price = the valid sale,
 * published_at NULL.
 *
 * ── VISIBLE BY DEFAULT, ON EVERY STOREFRONT (developer decision 2026-09-11) ──────────────────
 *
 * `is_visible = 1` on first insert, for every active storefront. The reasoning is the team's
 * workflow: the shared catalogue is one catalogue, and it is far faster to HIDE the few hundred
 * products that do not belong on a given site than to hand-place seven thousand that do. So the
 * transform opens everything and the dashboard closes what does not fit.
 *
 * ── AND THAT IS WHY INSERT-ONLY IS ABSOLUTE HERE ─────────────────────────────────────────────
 *
 * A visible-by-default rule that ran on every transform would be a NIGHTLY RE-ENABLE: the team
 * hides a product on Brand Fashion, the next rehearsal un-hides it, and nobody can tell whether
 * they are looking at a decision or at a default. Dashboard-owned columns — `is_visible`,
 * `is_featured`, `sort_order`, both price overrides, `published_at` — are therefore written on
 * INSERT only and never appear in the upsert's update list. `CatalogSafetyTest` and
 * `MultiStorefrontTest` both prove it by hiding a product and re-running.
 *
 * **`slug` is insert-only too**, and it was not always: the upsert used to refresh it from the
 * legacy EN title on every run. That is a URL, the dashboard can edit it, and editing it writes a
 * 301 redirect — so refreshing it would revert a deliberate change AND leave a redirect pointing
 * at a slug that no longer exists. Switch night is a fresh rebuild, where every row is an INSERT,
 * so the `storefront_product[slug plan]` reconciliation still holds exactly; on an ADDITIVE
 * rehearsal a legacy rename now shows up as a reported divergence instead of silently moving a
 * live URL, which is the more useful of the two behaviours.
 *
 * What a re-run DOES refresh is `effective_price` / `effective_sale_price`: those are a projection
 * of the legacy price rather than a decision, and a stale price on a rehearsal database would be a
 * worse lie than a moved one.
 */
final class Step18StorefrontProduct implements Step
{
    /** @var list<string> */
    private const COLUMNS = ['storefront_id', 'product_id', 'is_visible', 'is_featured', 'sort_order', 'slug', 'price_override', 'sale_price_override', 'effective_price', 'effective_sale_price', 'published_at', 'created_at', 'updated_at'];

    /** @var list<string> */
    private const REDIRECT_COLUMNS = ['storefront_id', 'from_hash', 'from_path', 'to_path', 'status', 'source', 'hits', 'last_hit_at', 'created_at', 'updated_at'];

    public function number(): int
    {
        return 18;
    }

    public function name(): string
    {
        return 'storefront_product';
    }

    public function target(): string
    {
        return 'storefront_product, storefront_redirects (A-17 twins)';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        $plan = ProductSlugs::plan($ctx->legacy);
        foreach ($plan->suffixed as $ids) {
            $result->count('slug_collision_suffixed', count($ids));           // A-17
        }
        foreach ($plan->slugs as $id => $slug) {
            if (mb_strlen($slug) > 191) {
                throw new RuntimeException("products.id=$id: slug [$slug] exceeds storefront_product.slug(191) — the live URL cannot be preserved; decide before running (X-05).");
            }
        }

        $ctx->eachStorefront(function (int $storefrontId, bool $primary) use ($ctx, $result, $plan): void {
            $before = $ctx->db->table('storefront_product')->where('storefront_id', $storefrontId)->count();
            $this->syncStorefront($ctx, $result, $plan, $primary);
            $after = $ctx->db->table('storefront_product')->where('storefront_id', $storefrontId)->count();
            $result->count('storefront_'.$storefrontId.'_rows', $after - $before);
            $result->note(sprintf('storefront %d: %d storefront_product rows (%+d this run)', $storefrontId, $after, $after - $before));
        });
    }

    /** One storefront's rows. `$ctx->storefrontId` already points at it. */
    private function syncStorefront(TransformContext $ctx, StepResult $result, ProductSlugs $plan, bool $primary): void
    {
        $ctx->chunkLegacy('products', ['id', 'selling_price', 'sale_price_after_discount', 'active', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $plan, $primary): void {
            $out = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                if ($primary) {
                    $result->read++;
                    if (! Row::bool($row, 'active')) {
                        $result->count('inactive_but_visible');                 // A-16
                    }
                }
                $selling = Row::money($row, 'selling_price');
                $sale = Row::nmoney($row, 'sale_price_after_discount');
                if ($sale !== null && ! ((float) $sale > 0 && (float) $sale < (float) $selling)) {
                    $sale = null;
                }
                $out[] = [
                    'storefront_id' => $ctx->storefrontId,
                    'product_id' => $id,
                    'is_visible' => 1,
                    'is_featured' => 0,
                    'sort_order' => 0,
                    'slug' => $plan->slug($id),
                    'price_override' => null,
                    'sale_price_override' => null,
                    'effective_price' => $selling,
                    'effective_sale_price' => $sale,
                    'published_at' => null,
                    'created_at' => Row::nstr($row, 'created_at'),
                    'updated_at' => Row::nstr($row, 'updated_at'),
                ];
            }
            $result->writes->add($ctx->writer->upsert('storefront_product', self::COLUMNS, ['storefront_id', 'product_id'], self::REFRESHED, $out));
        });

        $this->seedTwinRedirects($ctx, $result, $plan, $primary);
    }

    /**
     * The columns a RE-RUN is allowed to refresh on an existing row.
     *
     * Never `is_visible`, `is_featured`, `sort_order`, `slug`, the price overrides or
     * `published_at` — every one of those is the team's from the moment the row exists, and
     * refreshing them would turn the visible-by-default rule into a nightly re-enable and a typed
     * URL into a moving target.
     *
     * @var list<string>
     */
    private const REFRESHED = ['effective_price', 'effective_sale_price', 'updated_at'];

    /**
     * One storefront_redirects row, or NULL when source and target are the same path after
     * normalisation (lower-case, trimmed — the same rule the redirect layer hashes with).
     * Every twin redirect passes through here; there is no other way to write one.
     *
     * @return array<string, mixed>|null
     */
    public static function redirectRow(int $storefrontId, string $from, string $to, ?string $createdAt, ?string $updatedAt): ?array
    {
        if (StorefrontRedirect::hashPath($from) === StorefrontRedirect::hashPath($to)) {
            return null;
        }

        return [
            'storefront_id' => $storefrontId,
            'from_hash' => StorefrontRedirect::hashPath($from),
            'from_path' => $from,
            'to_path' => $to,
            'status' => 301,
            'source' => 'legacy_twin',
            'hits' => 0,
            'last_hit_at' => null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * A-17 twins (decided 2026-09-06, revised after review 2026-09-07): for every collision
     * group, a redirect from the un-suffixed URL all twins answered pre-switch to the
     * CANONICAL URL of the kept product (its assigned slug). A row whose target equals its
     * source after normalisation is never written — the kept product answers that URL
     * natively — so on today's data no row is seeded; the rows appear only if a kept
     * product's canonical slug ever differs from the plain one. hits/last_hit_at untouched.
     */
    private function seedTwinRedirects(TransformContext $ctx, StepResult $result, ProductSlugs $plan, bool $primary): void
    {
        $rows = [];
        foreach ($plan->keepers as $plain => $keeperId) {
            $from = '/product/'.$plain;
            $to = '/product/'.$plan->slug($keeperId);
            $stamp = $ctx->legacy->table('products')->select(['created_at', 'updated_at'])->where('id', $keeperId)->first();
            $row = self::redirectRow($ctx->storefrontId, $from, $to, $stamp === null ? null : Row::nstr($stamp, 'created_at'), $stamp === null ? null : Row::nstr($stamp, 'updated_at'));
            if ($row === null) {
                if ($primary) {
                    $result->count('twin_redirect_identity_skipped');
                    $ctx->diff('A-17', 'products', $keeperId, "twins share $from", 'kept product answers it natively — no redirect row (identity guard)');
                }

                continue;
            }
            $rows[] = $row;
            $result->count('twin_redirects');
        }
        if ($rows !== []) {
            $result->writes->add($ctx->writer->upsert('storefront_redirects', self::REDIRECT_COLUMNS, ['storefront_id', 'from_hash'], ['from_path', 'to_path', 'status', 'source', 'updated_at'], $rows));
        }
    }
}
