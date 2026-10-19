<?php

namespace Database\Seeders;

use App\Domain\Content\HomeRails;
use App\Models\Storefront\Storefront;
use App\Storefront\StorefrontCache;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The Brand Fashion home's rails (backlog A8 / BF-HOME-RAILS; spec v2.11 §10.1 and §11.6), created the way
 * the dashboard's Home rails screen creates them — through {@see HomeRails::create()}, never raw SQL — so the
 * same set can be put on production with one command instead of being hand-built twice.
 *
 * What it creates on the storefront whose code is `brandfashion` (storefront 2), in this order, skipping any
 * rail that already exists (same kind and target, or the same English title for a custom rail), so a second
 * run adds nothing:
 *
 *   1. `offers`                              (the production rail of 2026-10-14 — kept when present)
 *   2. `newest`         "New this week"
 *   3. `category_type`  Watches
 *   4. `brand`          the biggest brand by visible products
 *   5. `custom`         "Bags for her"               — the 12 newest in-stock bags placed under Women
 *   6. `brand`          the second biggest brand
 *   7. `category_type`  Fashion
 *   8. `brand`          the third biggest brand
 *   9. `custom`         "Watches under EGP 5,000"    — the 12 newest in-stock watches priced under 5,000
 *  10–13. `brand`       the fourth to seventh biggest brands (only those with ≥ 20 in-stock products)
 *  14. `custom`         "Our picks"                  — the 6 newest in-stock products of six different brands
 *
 * Brands are chosen from THIS storefront's visible products (most first), so production picks production's
 * brands; the custom picks are queried at seed time for the same reason. A custom rail keeps its picks until
 * the team edits it in the dashboard (it does not drop sold-out products — spec §19-12's reason the deals band
 * is a threshold instead); that is the dashboard's business after this runs. Electronics gets no rail: every
 * electronics product is sold out (72 of 72, 2026-10-17) and a rail of twelve "Notify me" cards is not a home.
 *
 * The storefront's cache version is bumped at the end (what the dashboard does on every rail write), so the
 * v2 `home` payload and the compat `catalog/home` see the rails at once.
 *
 * Reversible: `php artisan db:seed --class=BrandFashionHomeRailsRemoveSeeder` deletes exactly the rails this
 * seeder would create (by the same keys), and leaves any other rail — the production `offers` rail included —
 * where it is. Print-only dry run: `php artisan db:seed --class=BrandFashionHomeRailsSeeder` with
 * `BF_RAILS_DRY_RUN=1` in the environment.
 */
final class BrandFashionHomeRailsSeeder extends Seeder
{
    public const CODE = 'brandfashion';

    public const CARDS = 12;

    public const MIN_BRAND_IN_STOCK = 20;

    /** @var array<string, array{en: string, ar: string}> the custom rails' titles — the remove seeder matches on `en` */
    public const CUSTOM = [
        'bags_for_her' => ['en' => 'Bags for her', 'ar' => 'حقائب لها'],
        'watches_under_5000' => ['en' => 'Watches under EGP 5,000', 'ar' => 'ساعات بأقل من 5,000 ج.م'],
        'our_picks' => ['en' => 'Our picks', 'ar' => 'اختياراتنا'],
    ];

    public function run(string $mode = 'seed'): void
    {
        $storefront = Storefront::query()->where('code', self::CODE)->first();
        if ($storefront === null) {
            throw new RuntimeException('No storefront with code '.self::CODE.' — run StorefrontSeeder first. Nothing was written.');
        }
        $sf = (int) $storefront->id;
        $dry = $mode === 'seed' && (string) getenv('BF_RAILS_DRY_RUN') === '1';

        if ($mode === 'remove') {
            $this->remove($sf);
        } else {
            $this->seed($sf, $dry);
        }
        if (! $dry) {
            app(StorefrontCache::class)->flush($sf);
            $this->say("storefront {$sf} cache version bumped");
        }
    }

    private function seed(int $sf, bool $dry): void
    {
        $existing = HomeRails::all($sf);
        $has = function (string $kind, ?int $target = null, ?string $titleEn = null) use ($existing): bool {
            foreach ($existing as $r) {
                if ($r['kind'] !== $kind) {
                    continue;
                }
                if ($kind === HomeRails::CUSTOM ? $r['title_en'] === $titleEn : $r['target_id'] === $target) {
                    return true;
                }
            }

            return false;
        };
        $created = 0;
        /**
         * @param  list<int>|null  $picks
         */
        $create = function (string $kind, ?int $target, ?array $picks, ?string $titleEn, ?string $titleAr, int $cards, string $label) use ($sf, $dry, &$created): void {
            if ($dry) {
                $this->say("would create {$label}");

                return;
            }
            $id = HomeRails::create($sf, [
                'kind' => $kind,
                'target_id' => $target,
                'product_ids' => $picks === null ? null : self::ints($picks),
                'title_en' => $titleEn,
                'title_ar' => $titleAr,
                'is_active' => true,
                'card_count' => $cards,
            ]);
            $created++;
            $this->say("created rail {$id}: {$label}");
        };

        $watches = $this->rootId($sf, 'watches');
        $fashion = $this->rootId($sf, 'fashion');
        $brands = $this->brandsByInStock($sf);   // [brand_id => in-stock count], most first
        $brandIds = array_keys($brands);

        /** @var list<array{kind: string, target_id: ?int, key: ?string, picks: ?list<int>, title_en: ?string, title_ar: ?string, label: string}> $plan */
        $plan = [];
        $row = fn (string $kind, ?int $target, ?string $key, ?array $picks, ?string $titleEn, ?string $titleAr, string $label): array => ['kind' => $kind, 'target_id' => $target, 'key' => $key, 'picks' => $picks, 'title_en' => $titleEn, 'title_ar' => $titleAr, 'label' => $label];
        $brandRow = fn (int $bid): array => $row(HomeRails::BRAND, $bid, null, null, null, null, "brand {$bid} ({$brands[$bid]} in stock)");
        $plan[] = $row(HomeRails::OFFERS, null, null, null, null, null, 'offers');
        $plan[] = $row(HomeRails::NEWEST, null, null, null, 'New this week', 'جديد هذا الأسبوع', 'newest "New this week"');
        if ($watches !== null) {
            $plan[] = $row(HomeRails::CATEGORY_TYPE, $watches, null, null, null, null, "category_type watches ({$watches})");
        }
        if (isset($brandIds[0])) {
            $plan[] = $brandRow($brandIds[0]);
        }
        $plan[] = $row(HomeRails::CUSTOM, null, 'bags_for_her', $this->bagsForHer($sf), null, null, 'custom "Bags for her"');
        if (isset($brandIds[1])) {
            $plan[] = $brandRow($brandIds[1]);
        }
        if ($fashion !== null) {
            $plan[] = $row(HomeRails::CATEGORY_TYPE, $fashion, null, null, null, null, "category_type fashion ({$fashion})");
        }
        if (isset($brandIds[2])) {
            $plan[] = $brandRow($brandIds[2]);
        }
        $plan[] = $row(HomeRails::CUSTOM, null, 'watches_under_5000', $this->watchesUnder($sf, 5000), null, null, 'custom "Watches under EGP 5,000"');
        foreach (array_slice($brandIds, 3, 4) as $bid) {
            $plan[] = $brandRow($bid);
        }
        $plan[] = $row(HomeRails::CUSTOM, null, 'our_picks', $this->ourPicks($sf), null, null, 'custom "Our picks"');

        foreach ($plan as $p) {
            $kind = $p['kind'];
            if ($kind === HomeRails::CUSTOM) {
                $title = self::CUSTOM[$p['key'] ?? ''] ?? null;
                $picks = $p['picks'] ?? [];
                if ($title === null) {
                    continue;
                }
                if ($has($kind, null, $title['en'])) {
                    $this->say("kept existing {$p['label']}");

                    continue;
                }
                if (count($picks) < 2) {
                    $this->say("skipped {$p['label']}: ".count($picks).' product(s) — a rail needs 2 (spec §9)');

                    continue;
                }
                $create($kind, null, $picks, $title['en'], $title['ar'], count($picks), $p['label'].' × '.count($picks));

                continue;
            }
            if ($has($kind, $p['target_id'])) {
                $this->say("kept existing {$p['label']}");

                continue;
            }
            $create($kind, $p['target_id'], null, $p['title_en'], $p['title_ar'], self::CARDS, $p['label']);
        }
        if (! $dry) {
            // The planned ORDER, through the dashboard's own reorder verb: the plan's rails first (in plan order), then
            // any rail the team added that this seeder does not know, in its current position.
            $all = HomeRails::all($sf);
            $ordered = [];
            foreach ($plan as $p) {
                foreach ($all as $r) {
                    if ($r['kind'] !== $p['kind'] || in_array($r['id'], $ordered, true)) {
                        continue;
                    }
                    $match = $p['kind'] === HomeRails::CUSTOM ? $r['title_en'] === (self::CUSTOM[$p['key'] ?? '']['en'] ?? null) : $r['target_id'] === $p['target_id'];
                    if ($match) {
                        $ordered[] = $r['id'];
                        break;
                    }
                }
            }
            foreach ($all as $r) {
                if (! in_array($r['id'], $ordered, true)) {
                    $ordered[] = $r['id'];
                }
            }
            HomeRails::reorder($sf, $ordered);
            $this->say('rails put in the planned order: '.implode(', ', $ordered));
        }
        $this->say(($dry ? 'dry run — ' : '')."{$created} rail(s) created on storefront {$sf}; ".count(HomeRails::all($sf)).' now in the table'.($dry ? ' (unchanged)' : ''));
    }

    private function remove(int $sf): void
    {
        $brands = array_keys($this->brandsByInStock($sf));
        $targets = array_filter([$this->rootId($sf, 'watches'), $this->rootId($sf, 'fashion')]);
        $customTitles = array_map(fn (array $t): string => $t['en'], self::CUSTOM);
        $removed = 0;
        foreach (HomeRails::all($sf) as $r) {
            $mine = match ($r['kind']) {
                HomeRails::NEWEST => $r['title_en'] === 'New this week',
                HomeRails::CATEGORY_TYPE => in_array($r['target_id'], $targets, true),
                HomeRails::BRAND => in_array($r['target_id'], array_slice($brands, 0, 7), true),
                HomeRails::CUSTOM => in_array($r['title_en'], $customTitles, true),
                default => false,   // the `offers` rail predates this seeder on production: never touched
            };
            if ($mine) {
                HomeRails::delete($sf, $r['id']);
                $removed++;
                $this->say("removed rail {$r['id']}: {$r['kind']} ".($r['title_en'] ?? $r['target_id'] ?? ''));
            }
        }
        $this->say("{$removed} rail(s) removed from storefront {$sf}; ".count(HomeRails::all($sf)).' remain');
    }

    /** The visible depth-1 node of a slug on this storefront, or null. */
    private function rootId(int $sf, string $slug): ?int
    {
        $id = DB::table('storefront_categories')->where('storefront_id', $sf)->where('depth', 1)->where('slug', $slug)->where('is_active', 1)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** Visible, active, placed products of this storefront. */
    private function visible(int $sf): Builder
    {
        return DB::table('storefront_product as sp')
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $sf)->where('sp.is_visible', 1)
            ->where('p.is_active', 1)->whereNull('p.deleted_at');
    }

    /** @return array<int, int> brand id => in-stock visible products, most first, at least MIN_BRAND_IN_STOCK */
    private function brandsByInStock(int $sf): array
    {
        $out = [];
        $rows = $this->visible($sf)->where('p.in_stock', 1)
            ->selectRaw('p.brand_id, COUNT(*) AS n')->groupBy('p.brand_id')
            ->havingRaw('COUNT(*) >= ?', [self::MIN_BRAND_IN_STOCK])
            ->orderByDesc('n')->orderBy('p.brand_id')->get();
        foreach ($rows as $r) {
            $out[Row::int($r, 'brand_id')] = Row::int($r, 'n');
        }

        return $out;
    }

    /** @return list<int> */
    private function bagsForHer(int $sf): array
    {
        $women = DB::table('catalog_genders as g')->join('catalog_gender_translations as t', 't.gender_id', '=', 'g.id')
            ->where('t.locale', 'en')->where('t.name', 'Women')->value('g.id');
        $q = $this->visible($sf)->where('p.in_stock', 1)->where('p.family', 'bag');
        if (is_numeric($women)) {
            $q->whereExists(fn (Builder $s) => $s->selectRaw('1')->from('catalog_product_gender as pg')->whereColumn('pg.product_id', 'p.id')->where('pg.gender_id', (int) $women));
        }

        return self::ints($q->orderByDesc('p.created_at')->orderByDesc('p.id')->limit(self::CARDS)->pluck('p.id')->all());
    }

    /** @return list<int> */
    private function watchesUnder(int $sf, int $ceiling): array
    {
        return self::ints($this->visible($sf)->where('p.in_stock', 1)->where('p.family', 'watch')
            ->whereRaw('COALESCE(sp.effective_sale_price, sp.effective_price) < ?', [$ceiling])
            ->orderByDesc('p.created_at')->orderByDesc('p.id')->limit(self::CARDS)->pluck('p.id')->all());
    }

    /**
     * @param  array<mixed>  $values
     * @return list<int>
     */
    private static function ints(array $values): array
    {
        $out = [];
        foreach ($values as $v) {
            if (is_numeric($v)) {
                $out[] = (int) $v;
            }
        }

        return $out;
    }

    /** @return list<int> the newest in-stock product of each of six brands */
    private function ourPicks(int $sf): array
    {
        $picks = [];
        foreach (array_keys($this->brandsByInStock($sf)) as $bid) {
            $id = $this->visible($sf)->where('p.in_stock', 1)->where('p.brand_id', $bid)->orderByDesc('p.created_at')->orderByDesc('p.id')->value('p.id');
            if (is_numeric($id)) {
                $picks[] = (int) $id;
            }
            if (count($picks) === 6) {
                break;
            }
        }

        return $picks;
    }

    private function say(string $line): void
    {
        $this->command->getOutput()->writeln($line);
    }
}
