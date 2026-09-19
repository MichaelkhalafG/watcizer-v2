<?php

use App\Transform\Row;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Electronics on Watchizer, the Joyroom products on both shops, and the Joyroom brand.
 *
 * ── What was lost, and how ──────────────────────────────────────────────────────────────────
 *
 * The Electronics tree was created on BOTH storefronts on 14 Sep. The catalogue rebuild on 17 Sep
 * kept only what comes from the legacy system, so a tree created in the dashboard was not among
 * the things it knew how to keep — and the Joyroom import that followed only built storefront 2.
 * The result is the state this restores: Brand Fashion has seven Electronics nodes and 72 Joyroom
 * products; Watchizer has neither.
 *
 * The restoration was approved on 2026-09-18 and never done. Rebuilds are finished, so this is
 * permanent.
 *
 * ── Measured before anything was written ────────────────────────────────────────────────────
 *
 *     Electronics nodes on storefront 2        7   (root + 6 children)
 *     Electronics nodes on storefront 1        0
 *     products under that tree               169
 *     of which Joyroom                        72   ← this migration's set
 *     Joyroom products already on Watchizer    0
 *     Joyroom brand row                     none   ← all 72 sit on `generic`
 *
 * The 72 are identified by `Joyroom` in the ENGLISH title, and that is the whole population:
 * no product carries a `JR-` sku, none sits outside the Electronics tree, and none is on
 * Watchizer. The other 97 products under that tree are NOT Joyroom — 58 of them are watch straps
 * filed under `accessories-2` — so "everything under Electronics" would have been the wrong set.
 *
 * ── One thing this deliberately does NOT fix ────────────────────────────────────────────────
 *
 * All 72 are missing both short descriptions, the Arabic long description, and a gender. They are
 * nevertheless VISIBLE on Brand Fashion today, because the visibility gate (2026-09-18) is
 * enforced when something is WRITTEN and was never applied retroactively.
 *
 * So they are placed on Watchizer visible, matching the state they already have on the shop that
 * already sells them: placing them hidden on one shop and visible on the other, from identical
 * data, would be an inconsistency with nothing behind it.
 *
 * The consequence is a trap worth knowing about, and it is listed in
 * `docs/wave4d/joyroom-incomplete.tsv`: **the first time one of these 72 is saved through the
 * product form, the gate will demote it and it will disappear from both shops.** Filling in the
 * four missing fields is the fix, and it is a data job, not a code one.
 */
return new class extends Migration
{
    /** The Electronics tree on Brand Fashion, whose shape Watchizer is given. */
    private const SOURCE_STOREFRONT = 2;

    private const TARGET_STOREFRONT = 1;

    public function up(): void
    {
        $brandId = $this->joyroomBrand();
        $rebranded = $this->rebrand($brandId);
        $nodes = $this->mirrorTree();
        [$placed, $filed] = $this->placeOnWatchizer($nodes);

        echo "  joyroom brand id {$brandId}; re-branded {$rebranded} products; "
            .'mirrored '.count($nodes).' electronics nodes onto Watchizer; '
            ."placed {$placed} products and filed {$filed} category rows\n";
    }

    /**
     * Not reversible by a `down()`, and a stub that pretended otherwise would be worse than none.
     *
     * Undoing this means deciding whether to delete the brand (which other products may have been
     * given by then), the tree (which may have gained nodes) and the placements (which may have
     * been edited). Every one of those is a judgement about data, and a migration cannot make it.
     */
    public function down(): void
    {
        // Intentionally empty. See the note above.
    }

    /**
     * The Joyroom brand and both its names.
     *
     * ── Idempotent PER ROW, not per brand — and that distinction cost a bug ─────────────────
     *
     * The first version returned early when the brand row already existed. The first RUN of this
     * migration died on a `created_at` that `catalog_brand_translations` does not have — after the
     * brand row was inserted, because nothing here was in a transaction. The re-run then found the
     * brand, took the early return, and skipped the names: a brand with no name in either
     * language, which is exactly the gap the lookups screen refuses to let anybody create by hand.
     *
     * "Already exists" is not the same as "already done". Each row is now checked for itself, so a
     * re-run after a partial failure REPAIRS rather than confirming the damage.
     */
    private function joyroomBrand(): int
    {
        $existing = DB::table('catalog_brands')->where('slug', 'joyroom')->value('id');

        $id = is_numeric($existing)
            ? (int) $existing
            : (int) DB::table('catalog_brands')->insertGetId([
                'slug' => 'joyroom',
                'is_active' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

        // Both languages, because a missing English name is a gap the lookups screen refuses and
        // the storefront cannot fall back from (AGENTS §2.17).
        //
        // No timestamps: the translation tables carry none — checked, not assumed. That check is
        // what the first run got wrong.
        foreach (['ar' => 'جوي روم', 'en' => 'Joyroom'] as $locale => $name) {
            $has = DB::table('catalog_brand_translations')
                ->where('brand_id', $id)->where('locale', $locale)->exists();

            if (! $has) {
                DB::table('catalog_brand_translations')->insert([
                    'brand_id' => $id,
                    'locale' => $locale,
                    'name' => $name,
                ]);
            }
        }

        return $id;
    }

    /** Move the 72 off `generic`. Only those still on generic, so a re-run is a no-op. */
    private function rebrand(int $brandId): int
    {
        $generic = DB::table('catalog_brands')->where('slug', 'generic')->value('id');
        if (! is_numeric($generic)) {
            return 0;
        }

        return DB::table('catalog_products')
            ->whereIn('id', $this->joyroomIds())
            ->where('brand_id', (int) $generic)
            ->update(['brand_id' => $brandId, 'updated_at' => now()]);
    }

    /**
     * Copy Brand Fashion's Electronics tree onto Watchizer.
     *
     * Keyed by SLUG rather than by id, because the two shops' trees are independent and a node's
     * id means nothing across them. Re-running finds the slugs already there and returns the map
     * unchanged.
     *
     * @return array<int, int> source node id => the matching Watchizer node id
     */
    private function mirrorTree(): array
    {
        $root = DB::table('storefront_categories')
            ->where('storefront_id', self::SOURCE_STOREFRONT)->where('slug', 'electronics')->whereNull('parent_id')
            ->first(['id', 'path']);

        if ($root === null) {
            return [];
        }

        $source = DB::table('storefront_categories')
            ->where('storefront_id', self::SOURCE_STOREFRONT)
            ->where('path', 'like', Row::str(Row::cast($root), 'path').'%')
            ->orderBy('depth')->orderBy('sort_order')->orderBy('id')
            ->get();

        $map = [];
        foreach ($source as $raw) {
            $node = Row::cast($raw);
            $parentSource = Row::nint($node, 'parent_id');
            $parentId = $parentSource === null ? null : ($map[$parentSource] ?? null);

            $existing = DB::table('storefront_categories')
                ->where('storefront_id', self::TARGET_STOREFRONT)
                ->where('slug', Row::str($node, 'slug'))
                ->value('id');

            if (is_numeric($existing)) {
                $map[Row::int($node, 'id')] = (int) $existing;

                continue;
            }

            $id = (int) DB::table('storefront_categories')->insertGetId([
                'storefront_id' => self::TARGET_STOREFRONT,
                'parent_id' => $parentId,
                'depth' => Row::int($node, 'depth'),
                // Filled below: the path is built from the NEW id, which does not exist yet.
                'path' => '',
                'slug' => Row::str($node, 'slug'),
                'icon' => Row::nstr($node, 'icon'),
                'image_path' => Row::nstr($node, 'image_path'),
                'is_active' => Row::int($node, 'is_active'),
                'show_in_menu' => Row::int($node, 'show_in_menu'),
                'sort_order' => Row::int($node, 'sort_order'),
                // Created in the dashboard, not taken from the legacy system — which is precisely
                // why the rebuild did not keep it, and why this must stay honest about it.
                'legacy_source' => null,
                'legacy_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $parentRow = $parentId === null
                ? null
                : DB::table('storefront_categories')->where('id', $parentId)->first(['path']);
            $parentPath = $parentRow === null ? '/' : Row::str(Row::cast($parentRow), 'path');

            DB::table('storefront_categories')->where('id', $id)->update(['path' => $parentPath.$id.'/']);

            $names = DB::table('storefront_category_translations')
                ->where('storefront_category_id', Row::int($node, 'id'))->get(['locale', 'name']);
            foreach ($names as $rawName) {
                $name = Row::cast($rawName);
                DB::table('storefront_category_translations')->insert([
                    'storefront_category_id' => $id,
                    'locale' => Row::str($name, 'locale'),
                    'name' => Row::str($name, 'name'),
                ]);
            }

            $map[Row::int($node, 'id')] = $id;
        }

        return $map;
    }

    /**
     * Put the 72 on Watchizer, in the mirrored categories, with the placement they already have.
     *
     * @param  array<int, int>  $nodes
     * @return array{0: int, 1: int}
     */
    private function placeOnWatchizer(array $nodes): array
    {
        $placed = 0;
        $filed = 0;

        foreach ($this->joyroomIds() as $productId) {
            $source = DB::table('storefront_product')
                ->where('storefront_id', self::SOURCE_STOREFRONT)->where('product_id', $productId)
                ->first();

            if ($source === null) {
                continue;
            }

            $already = DB::table('storefront_product')
                ->where('storefront_id', self::TARGET_STOREFRONT)->where('product_id', $productId)
                ->exists();

            if (! $already) {
                DB::table('storefront_product')->insert([
                    'storefront_id' => self::TARGET_STOREFRONT,
                    'product_id' => $productId,
                    'is_visible' => $source->is_visible,
                    'is_featured' => 0,
                    'sort_order' => $source->sort_order,
                    // The slug is unique PER STOREFRONT, so the same one is correct here and keeps
                    // the two shops' URLs matching.
                    'slug' => $source->slug,
                    'price_override' => $source->price_override,
                    'sale_price_override' => $source->sale_price_override,
                    'effective_price' => $source->effective_price,
                    'effective_sale_price' => $source->effective_sale_price,
                    'published_at' => $source->is_visible ? now() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $placed++;
            }

            $categories = DB::table('storefront_category_product')
                ->where('storefront_id', self::SOURCE_STOREFRONT)->where('product_id', $productId)
                ->get(['storefront_category_id', 'sort_order', 'is_primary']);

            foreach ($categories as $rawRow) {
                $row = Row::cast($rawRow);
                $target = $nodes[Row::int($row, 'storefront_category_id')] ?? null;
                if ($target === null) {
                    continue;
                }

                $exists = DB::table('storefront_category_product')
                    ->where('storefront_id', self::TARGET_STOREFRONT)
                    ->where('product_id', $productId)
                    ->where('storefront_category_id', $target)
                    ->exists();

                if ($exists) {
                    continue;
                }

                DB::table('storefront_category_product')->insert([
                    'storefront_id' => self::TARGET_STOREFRONT,
                    'storefront_category_id' => $target,
                    'product_id' => $productId,
                    'sort_order' => $row->sort_order,
                    'is_primary' => $row->is_primary,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $filed++;
            }
        }

        return [$placed, $filed];
    }

    /**
     * The 72. `Joyroom` in the English title — verified to be the whole population before use.
     *
     * @return list<int>
     */
    private function joyroomIds(): array
    {
        $out = [];
        foreach (
            DB::table('catalog_products as p')
                ->join('catalog_product_translations as t', function (JoinClause $join): void {
                    $join->on('t.product_id', '=', 'p.id')->where('t.locale', '=', 'en');
                })
                ->whereNull('p.deleted_at')
                ->where('t.title', 'like', '%oyroom%')
                ->distinct()
                ->orderBy('p.id')
                ->pluck('p.id') as $id
        ) {
            $out[] = is_numeric($id) ? (int) $id : 0;
        }

        return $out;
    }
};
