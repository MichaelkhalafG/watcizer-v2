<?php

declare(strict_types=1);

namespace App\Compat;

use App\Domain\Content\HomeBanners;
use App\Domain\Content\HomeRails;
use App\Storefront\CutMasks;
use App\Storefront\HomeHero;
use App\Storefront\StorefrontHome;
use App\Storefront\StorefrontUrls;
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
     * @return array{hero: string, managed_sections: list<string>, banners: list<array<string, mixed>>, side_banners: array{0: array<string, mixed>|null, 1: array<string, mixed>|null}, rails: list<array{id: int, kind: string, background: ?string, blend: bool, masks: array<int, string>, colors: list<string>, description: array{en: ?string, ar: ?string}, target: ?int, title: array{en: ?string, ar: ?string}, products: list<int>}>, products: list<array<array-key, mixed>>, ratings: list<array<array-key, mixed>>, images: list<array<array-key, mixed>>}
     */
    public function build(): array
    {
        // read ONCE per build and handed to the rails, which ask for it since the Banners block row; never kept on
        // this object, which can outlive a request (the hero is the dashboard's to change at any moment)
        $hero = $this->hero();
        $rails = $this->rails($hero);
        $all = [];
        foreach ($rails as $rail) {
            foreach ($rail['products'] as $id) {
                $all[$id] = true;
            }
        }

        return [
            'hero' => $hero,
            'banners' => HomeBanners::list($this->storefrontId, StorefrontUrls::compatAssetBase($this->storefrontId)),
            // The two slots beside the large hero (Part 3.1, 2026-11-05): `[top, bottom]`, each a banner or null.
            'side_banners' => HomeBanners::side($this->storefrontId, StorefrontUrls::compatAssetBase($this->storefrontId)),
            'rails' => $rails,
            // the SECTIONS the dashboard has a row for (on or off): the storefront draws those only where their row
            // is; a section with no row keeps its old fixed place, so nothing moves until a row is added
            'managed_sections' => HomeRails::managedSections($this->storefrontId),
            ...$this->listing->cards(array_keys($all)),
        ];
    }

    /**
     * The rails with their picks, without the cards — what {@see StorefrontHome} asks for
     * (it builds v2 cards of its own). Kept separate from {@see self::build} so the v2 home does not run
     * compat's banners query and settings read on every cold build and throw both away.
     *
     * `$hero` is the storefront's hero mode when the caller already has it (both home builders do); left out,
     * it is read here — and only if a Banners block row is met, the one rail that depends on it.
     *
     * @return list<array{id: int, kind: string, background: ?string, blend: bool, masks: array<int, string>, colors: list<string>, description: array{en: ?string, ar: ?string}, target: ?int, title: array{en: ?string, ar: ?string}, products: list<int>}>
     */
    public function rails(?string $hero = null): array
    {
        $entries = $this->listing->entries();
        $rails = [];
        foreach (HomeRails::active($this->storefrontId) as $rail) {
            if (in_array($rail['kind'], HomeRails::SECTIONS, true)) {
                /*
                 * THE BANNERS BLOCK AS A SECTION (the design pass, 2026-11-05): where, among the sliders, the
                 * large banner and its two side slots are drawn while a hero (the 3D watch or the campaign)
                 * leads the page. It carries no products — the storefront draws `banners` / `side_banners`
                 * from this same payload at this place. When the hero setting is `banner` the block already
                 * IS the top of the page, so the row is passed over rather than drawn twice.
                 *
                 * An older storefront build drops it by its own rule (a rail needs products to be drawn).
                 */
                // …and every other SECTION (the Hot Deals and Flash Sale sliders, the category tiles, the brand strip —
                // the review of 2026-11-05) goes out the same way: its place in the order, no products.
                if ($rail['kind'] === HomeRails::BANNERS) {
                    $hero ??= $this->hero();
                }
                if ($rail['kind'] !== HomeRails::BANNERS || $hero !== HomeHero::BANNER) {
                    $rails[] = [
                        'id' => $rail['id'], 'kind' => $rail['kind'], 'background' => null, 'blend' => false, 'masks' => [], 'colors' => [],
                        'description' => ['en' => null, 'ar' => null], 'target' => null, 'title' => ['en' => null, 'ar' => null], 'products' => [],
                    ];
                }

                continue;
            }
            $ids = in_array($rail['kind'], HomeRails::PICKED, true)
                ? self::picked($rail['product_ids'], $entries)
                // an under-a-price rail's "target" is its price cap
                : $this->cardsFor($rail['kind'], $rail['kind'] === HomeRails::PRICE ? $rail['max_price'] : $rail['target_id'], $rail['card_count'], $entries);
            if ($ids === []) {
                continue;
            }
            $rails[] = [
                'id' => $rail['id'],
                'kind' => $rail['kind'],
                /*
                 * How this slider LOOKS (the twelve updates, Part 3.2, 2026-11-05): its section's colour and
                 * whether the cards' photos blend into it. `null` / `false` — every rail the dashboard has not
                 * touched — draws exactly as before. A brand banner (3.3) also carries its three colours and
                 * its sentence; its `target` is the brand, its `products` the three picks that are showable.
                 */
                'background' => $rail['background'],
                'blend' => $rail['blend'],
                /*
                 * THE BLEND NEVER TINTS THE PRODUCT (the review of 2026-11-05): with the blend on, each product's
                 * cover photo comes with the MASK of its white studio background (product id => a `data:` URL of
                 * ~1.7 KB). The storefront draws the photo as it is and lays the mask over it, so the slider's
                 * colour shows where the white was and the product's own pixels are untouched. A product that is
                 * not in this map (no studio background, no mask yet) is drawn as it is, on white. Empty without
                 * the blend — a slider that does not blend carries no weight for it.
                 */
                'masks' => $rail['blend'] && $rail['background'] !== null ? CutMasks::forProducts($ids) : [],
                'colors' => $rail['colors'],
                'description' => ['en' => $rail['description_en'], 'ar' => $rail['description_ar']],
                // What the storefront names and links the rail by: category types in their LEGACY id,
                // the id every other storefront read uses for them.
                'target' => match ($rail['kind']) {
                    HomeRails::CATEGORY_TYPE => $this->legacyCategoryId($rail['target_id']),
                    HomeRails::PRICE => $rail['max_price'],                // the cap: the storefront titles and links the rail by it
                    default => $rail['target_id'],
                },
                'title' => ['en' => $rail['title_en'], 'ar' => $rail['title_ar']],
                'products' => $ids,
            ];
        }

        return $rails;
    }

    /*
     * THE HERO (the developer's addendum, 2026-10-20) — AN ADDED KEY, nothing changed.
     *
     * Watchizer reads this endpoint, not v2, and it is LIVE and taking real orders. Its home page draws the
     * 3D watch and has never drawn a banner, so `hero` and `banners` are new keys here and every key that was
     * here is untouched: `rails`, `products`, `ratings` and `images` are byte-for-byte what they were.
     *
     * `hero` is `three_d` or `banner`, from this storefront's `settings.home.hero` — `three_d` for Watchizer,
     * written explicitly by the release's migration, so the page renders exactly what it renders today until
     * somebody flips the switch on the dashboard. `banners` is the same list, in the same shape, that v2 serves
     * ({@see HomeBanners}), so ONE hero component can read both endpoints; Watchizer has no banners today, so
     * the list is empty, and the 3D hero would not read it anyway.
     *
     * The storefront row is read here rather than injected because compat resolves its storefront by HOST and
     * has no {@see \App\Storefront\StorefrontContext} — that is bound on v2 routes only.
     */
    private function hero(): string
    {
        $settings = DB::table('storefronts')->where('id', $this->storefrontId)->value('settings');
        $decoded = is_string($settings) ? json_decode($settings, true) : null;

        return HomeHero::of(is_array($decoded) ? $decoded : null);
    }

    /**
     * A custom rail's cards (M2e): the team's picks, in the team's order, keeping only products this
     * storefront shows (the listing index holds exactly those). A pick that was hidden or deleted
     * since is skipped — never an error, and never a gap.
     *
     * @param  list<int>  $picks
     * @param  list<Entry>  $entries
     * @return list<int>
     */
    public static function picked(array $picks, array $entries): array
    {
        $shown = [];
        foreach ($entries as $e) {
            $shown[$e['id']] = true;
        }

        return array_values(array_filter($picks, fn (int $id): bool => isset($shown[$id])));
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
        if ($kind === HomeRails::BEST_VALUE) {
            // in stock, with a REAL discount — the biggest first, the newest breaking a tie
            $deals = array_values(array_filter($entries, fn (array $e): bool => $e['inStock'] && $e['pct'] > 0));
            usort($deals, fn (array $a, array $b): int => [$b['pct'], $b['created']] <=> [$a['pct'], $a['created']]);

            return array_slice(array_map(fn (array $e): int => $e['id'], $deals), 0, $count);
        }
        if ($kind === HomeRails::PRICE) {
            // in stock, at `$target` (the cap) or less — what the shopper PAYS — newest first
            $cheap = array_values(array_filter($entries, fn (array $e): bool => $e['inStock'] && $target !== null && $e['price'] > 0 && $e['price'] <= $target));
            usort($cheap, fn (array $a, array $b): int => $b['created'] <=> $a['created']);

            return array_slice(array_map(fn (array $e): int => $e['id'], $cheap), 0, $count);
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
