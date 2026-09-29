<?php

declare(strict_types=1);

namespace App\Compat;

use App\Domain\Content\HomeRails;
use App\Support\Coerce;
use App\Support\Val;
use Illuminate\Support\Facades\DB;

/**
 * The home page's rails with their cards (C-1 stage 4, slice C) — `GET catalog/home`.
 *
 * The rails and their order come from `storefront_home_rails` (the dashboard's Home rails screen).
 * WHICH cards each rail shows is the rule the browser used when it derived the home page from the
 * whole catalogue, unchanged, so moving it to the server changes nothing a shopper sees:
 *
 *  - `offers`   — products with a discount (`pct > 0`), in catalogue order;
 *  - `grade`, `brand`, `category_type` — that target's products, in catalogue order;
 *  - `featured` — the products marked featured on this storefront; while none are (none are
 *                 today), the browser's pool: on sale with a picture, or any product with a
 *                 picture when fewer than 3 are on sale. `card_count` of them, picked at random;
 *  - `newest`   — new kind, no browser precedent: in stock, newest first.
 *
 * Out-of-stock products are NOT filtered from the parity kinds — the browser did not filter them
 * either, and changing what the home page shows is a decision, not part of the move.
 *
 * A rail with no cards is left out (the browser rendered nothing for an empty grade). Titles are
 * the rail's own when set; null means "the target's name", which the storefront already has in its
 * tables — so a renamed grade renames its rail without anybody editing the rail.
 *
 * @phpstan-import-type Entry from CompatListing
 * @phpstan-import-type Cards from CompatListing
 */
final class CompatHome
{
    public function __construct(
        private readonly CompatListing $listing,
        private readonly CompatCategories $categories,
        private readonly int $storefrontId,
    ) {}

    /**
     * @return array{rails: list<array{id: int, kind: string, target: ?int, title: array{en: ?string, ar: ?string}, products: list<int>}>, products: list<array<array-key, mixed>>, ratings: list<array<array-key, mixed>>, images: list<array<array-key, mixed>>}
     */
    public function build(): array
    {
        $entries = $this->listing->entries();
        $rails = [];
        $all = [];
        foreach (HomeRails::active($this->storefrontId) as $rail) {
            $ids = $this->cardsFor($rail['kind'], $rail['target_id'], $rail['card_count'], $entries);
            if ($ids === []) {
                continue;
            }
            $rails[] = [
                'id' => $rail['id'],
                'kind' => $rail['kind'],
                // What the storefront names and links the rail by: category types in their LEGACY id,
                // the id every other storefront read uses for them.
                'target' => $rail['kind'] === HomeRails::CATEGORY_TYPE ? $this->legacyCategoryId($rail['target_id']) : $rail['target_id'],
                'title' => ['en' => $rail['title_en'], 'ar' => $rail['title_ar']],
                'products' => $ids,
            ];
            foreach ($ids as $id) {
                $all[$id] = true;
            }
        }

        return ['rails' => $rails, ...$this->listing->cards(array_keys($all))];
    }

    /**
     * @param  list<Entry>  $entries
     * @return list<int>
     */
    private function cardsFor(string $kind, ?int $target, int $count, array $entries): array
    {
        if ($kind === HomeRails::FEATURED) {
            $visible = array_map(fn (array $e): int => $e['id'], $entries);
            $marked = Coerce::intList(DB::table('storefront_product')->where('storefront_id', $this->storefrontId)
                ->where('is_featured', true)->pluck('product_id')->all());
            $pictured = Coerce::intList(DB::table('catalog_product_images')
                ->whereIn('product_id', $visible)->distinct()->pluck('product_id')->all());
            $pool = self::featuredPool($entries, $marked, $pictured);
            shuffle($pool);

            return array_slice($pool, 0, $count);
        }

        return self::pick($kind, $kind === HomeRails::CATEGORY_TYPE ? $this->legacyCategoryId($target) : $target, $count, $entries);
    }

    /**
     * A rail's cards for every kind but `featured`, best first. `$target` is the grade or brand id,
     * or the category type's LEGACY id — the id the index entries carry.
     *
     * @param  list<Entry>  $entries  in catalogue order
     * @return list<int>
     */
    public static function pick(string $kind, ?int $target, int $count, array $entries): array
    {
        if ($kind === HomeRails::NEWEST) {
            $fresh = array_values(array_filter($entries, fn (array $e): bool => $e['inStock']));
            usort($fresh, fn (array $a, array $b): int => $b['created'] <=> $a['created']);

            return array_slice(array_map(fn (array $e): int => $e['id'], $fresh), 0, $count);
        }
        $out = [];
        foreach ($entries as $e) {
            $match = match ($kind) {
                HomeRails::OFFERS => $e['pct'] > 0,
                HomeRails::GRADE => $target !== null && $e['grades'] === $target,
                HomeRails::BRAND => $target !== null && $e['brands'] === $target,
                HomeRails::CATEGORY_TYPE => $target !== null && $e['categories'] === $target,
                default => false,
            };
            if ($match) {
                $out[] = $e['id'];
                if (count($out) >= $count) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Who the `featured` rail may show, before the random pick: the products marked featured that
     * are visible; while none are, the browser's pool (FeaturedBanner) — on sale (0 < sale < selling)
     * with a picture, or any product with a picture when fewer than 3 are on sale.
     *
     * @param  list<Entry>  $entries
     * @param  list<int>  $marked  `storefront_product.is_featured`
     * @param  list<int>  $pictured  products with at least one image
     * @return list<int>
     */
    public static function featuredPool(array $entries, array $marked, array $pictured): array
    {
        $visible = array_flip(array_map(fn (array $e): int => $e['id'], $entries));
        $featured = array_values(array_filter($marked, fn (int $id): bool => isset($visible[$id])));
        if ($featured !== []) {
            return $featured;
        }
        $hasPicture = array_flip($pictured);
        $onSale = [];
        $any = [];
        foreach ($entries as $e) {
            if (! isset($hasPicture[$e['id']])) {
                continue;
            }
            $any[] = $e['id'];
            $sale = (float) ($e['saleRaw'] ?? 0);
            if ($sale > 0 && $sale < (float) ($e['sellingRaw'] ?? 0)) {
                $onSale[] = $e['id'];
            }
        }

        return count($onSale) >= 3 ? $onSale : $any;
    }

    private function legacyCategoryId(?int $storefrontCategoryId): ?int
    {
        foreach ($this->categories->categoryTypes() as $node) {
            if (Val::int($node, 'id') === $storefrontCategoryId) {
                return CompatCategories::legacyIdOf($node);
            }
        }

        return null;
    }
}
