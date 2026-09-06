<?php

namespace App\Transform;

use App\Support\LegacySlug;

/**
 * The ONE place that decides storefront_product.slug for every legacy product (study
 * §0.2-9, §2.9.2 step 18, audit A-17): slugify(EN title) — or the id when that is '' —
 * assigned in id order, so the lowest id keeps the plain slug and every later twin gets
 * "-{id}". Step 18, the reconciliation and the audit all call this so they can never
 * disagree.
 */
final class ProductSlugs
{
    /**
     * @param  array<int, string>  $slugs  product id => assigned slug
     * @param  array<string, int>  $keepers  un-suffixed slug => the id that keeps it (collision groups only)
     * @param  array<string, list<int>>  $suffixed  un-suffixed slug => the later ids that got "-{id}"
     */
    private function __construct(public readonly array $slugs, public readonly array $keepers, public readonly array $suffixed) {}

    public static function plan(LegacySource $legacy): self
    {
        $titles = [];
        foreach ($legacy->table('product_translations')->select(['product_id', 'product_title'])->where('locale', 'en')->orderBy('id')->cursor() as $t) {
            $titles[Row::int($t, 'product_id')] = trim(Row::str($t, 'product_title'));
        }

        $owners = [];
        $slugs = [];
        $keepers = [];
        $suffixed = [];
        foreach ($legacy->table('products')->select(['id'])->orderBy('id')->cursor() as $p) {
            $id = Row::int($p, 'id');
            $plain = LegacySlug::orId($titles[$id] ?? '', $id);
            $slug = $plain;
            if (isset($owners[$plain])) {
                $keepers[$plain] = $owners[$plain];
                $suffixed[$plain][] = $id;
                $slug = "$plain-$id";
            }
            $owners[$slug] = $id;
            $slugs[$id] = $slug;
        }

        return new self($slugs, $keepers, $suffixed);
    }

    public function slug(int $productId): string
    {
        return $this->slugs[$productId] ?? (string) $productId;
    }

    /**
     * Collision groups whose kept product no longer answers the plain URL natively (needs a redirect row).
     *
     * @return array<string, int>
     */
    public function nonIdentityTwins(): array
    {
        $out = [];
        foreach ($this->keepers as $plain => $keeperId) {
            if ($this->slug($keeperId) !== $plain) {
                $out[$plain] = $keeperId;
            }
        }

        return $out;
    }
}
