<?php

namespace App\Transform\Steps;

use App\Models\Storefront\StorefrontRedirect;
use App\Support\LegacySlug;
use App\Transform\Row;
use App\Transform\StepResult;
use App\Transform\TransformContext;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Step 18 — one storefront_product row per legacy product for storefront 1, key
 * (storefront_id, product_id). is_visible = 1 (the legacy storefront shows everything —
 * A-16 lists active = 0), slug = slugify(EN title) — or the id when the title slugifies
 * to '' — with "-{id}" on collision (A-17; today those products share a URL);
 * effective_price = selling, effective_sale_price = the valid sale; published_at NULL.
 *
 * Dashboard-owned columns (is_visible, is_featured, sort_order, price overrides,
 * published_at) are written on INSERT only, never refreshed by a re-run.
 */
final class Step18StorefrontProduct implements Step
{
    /** @var list<string> */
    private const COLUMNS = ['storefront_id', 'product_id', 'is_visible', 'is_featured', 'sort_order', 'slug', 'price_override', 'sale_price_override', 'effective_price', 'effective_sale_price', 'published_at', 'created_at', 'updated_at'];

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
        return 'storefront_product';
    }

    public function run(TransformContext $ctx, StepResult $result): void
    {
        /** @var array<int, string> product id => EN title */
        $titles = [];
        foreach ($ctx->legacy->table('product_translations')->select(['product_id', 'product_title'])->where('locale', 'en')->orderBy('id')->cursor() as $t) {
            $titles[Row::int($t, 'product_id')] = trim(Row::str($t, 'product_title'));
        }

        // Slugs are assigned in id order so the lowest id keeps the plain slug on every run.
        /** @var array<string, int> slug => first product id */
        $owners = [];
        /** @var array<int, string> */
        $slugs = [];
        /** @var array<string, int> unsuffixed slug => earliest id, for collision groups (A-17) */
        $twins = [];
        foreach ($ctx->legacy->table('products')->select(['id'])->orderBy('id')->cursor() as $p) {
            $id = Row::int($p, 'id');
            $slug = LegacySlug::orId($titles[$id] ?? '', $id);
            if (isset($owners[$slug])) {
                $twins[$slug] = $owners[$slug];
                $slug .= "-$id";                                        // A-17
                $result->count('slug_collision_suffixed');
            }
            if (mb_strlen($slug) > 191) {
                throw new RuntimeException("products.id=$id: slug [$slug] exceeds storefront_product.slug(191) — the live URL cannot be preserved; decide before running (X-05).");
            }
            $owners[$slug] = $id;
            $slugs[$id] = $slug;
        }

        $this->seedTwinRedirects($ctx, $result, $twins);

        $ctx->chunkLegacy('products', ['id', 'selling_price', 'sale_price_after_discount', 'active', 'created_at', 'updated_at'], function (Collection $rows) use ($ctx, $result, $slugs): void {
            $out = [];
            foreach ($rows as $row) {
                $id = Row::int($row, 'id');
                $result->read++;
                if (! Row::bool($row, 'active')) {
                    $result->count('inactive_but_visible');             // A-16
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
                    'slug' => $slugs[$id] ?? (string) $id,
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
    }

    /**
     * A-17 (decided 2026-09-06): one storefront_redirects row per collision group, from the
     * un-suffixed URL every twin answered pre-switch to the earliest id's canonical URL, so the
     * ownership of that URL is explicit. Because the earliest id keeps the plain slug, the
     * target equals the source today: the redirect layer MUST treat such a row as an ownership
     * record (serve, do not redirect) — study §2.9.5 A-17. hits/last_hit_at are never touched.
     *
     * @param  array<string, int>  $twins  unsuffixed slug => earliest product id
     */
    private function seedTwinRedirects(TransformContext $ctx, StepResult $result, array $twins): void
    {
        if ($twins === []) {
            return;
        }
        /** @var array<int, array{created_at: string|null, updated_at: string|null}> */
        $stamps = [];
        foreach ($ctx->legacy->table('products')->select(['id', 'created_at', 'updated_at'])->whereIn('id', array_values($twins))->get() as $p) {
            $stamps[Row::int($p, 'id')] = ['created_at' => Row::nstr($p, 'created_at'), 'updated_at' => Row::nstr($p, 'updated_at')];
        }
        $rows = [];
        foreach ($twins as $slug => $earliestId) {
            $from = '/product/'.$slug;
            $rows[] = [
                'storefront_id' => $ctx->storefrontId,
                'from_hash' => StorefrontRedirect::hashPath($from),
                'from_path' => $from,
                'to_path' => $from,
                'status' => 301,
                'source' => 'legacy_twin',
                'hits' => 0,
                'last_hit_at' => null,
                'created_at' => $stamps[$earliestId]['created_at'] ?? null,
                'updated_at' => $stamps[$earliestId]['updated_at'] ?? null,
            ];
            $result->count('twin_redirects');
        }
        $result->writes->add($ctx->writer->upsert(
            'storefront_redirects',
            ['storefront_id', 'from_hash', 'from_path', 'to_path', 'status', 'source', 'hits', 'last_hit_at', 'created_at', 'updated_at'],
            ['storefront_id', 'from_hash'],
            ['from_path', 'to_path', 'status', 'source', 'updated_at'],
            $rows,
        ));
    }
}
