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
 * Step 18 — one storefront_product row per legacy product for storefront 1, key
 * (storefront_id, product_id). is_visible = 1 (the legacy storefront shows everything —
 * A-16 lists active = 0), slug from ProductSlugs (slugify(EN title), id fallback, "-{id}"
 * on collision — A-17), effective_price = selling, effective_sale_price = the valid sale,
 * published_at NULL.
 *
 * Dashboard-owned columns (is_visible, is_featured, sort_order, price overrides,
 * published_at) are written on INSERT only, never refreshed by a re-run.
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

        $ctx->chunkLegacy('products', ['id', 'selling_price', 'sale_price_after_discount', 'active', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $plan): void {
            $out = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $result->read++;
                if (! Row::bool($row, 'active')) {
                    $result->count('inactive_but_visible');                     // A-16
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
            $result->writes->add($ctx->writer->upsert('storefront_product', self::COLUMNS, ['storefront_id', 'product_id'], ['slug', 'effective_price', 'effective_sale_price', 'updated_at'], $out));
        });

        $this->seedTwinRedirects($ctx, $result, $plan);
    }

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
    private function seedTwinRedirects(TransformContext $ctx, StepResult $result, ProductSlugs $plan): void
    {
        $rows = [];
        foreach ($plan->keepers as $plain => $keeperId) {
            $from = '/product/'.$plain;
            $to = '/product/'.$plan->slug($keeperId);
            $stamp = $ctx->legacy->table('products')->select(['created_at', 'updated_at'])->where('id', $keeperId)->first();
            $row = self::redirectRow($ctx->storefrontId, $from, $to, $stamp === null ? null : Row::nstr($stamp, 'created_at'), $stamp === null ? null : Row::nstr($stamp, 'updated_at'));
            if ($row === null) {
                $result->count('twin_redirect_identity_skipped');
                $ctx->diff('A-17', 'products', $keeperId, "twins share $from", 'kept product answers it natively — no redirect row (identity guard)');

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
