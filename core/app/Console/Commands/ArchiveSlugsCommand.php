<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\PlacementWriter;
use App\Models\Storefront\Storefront;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Restore each imported Brand Fashion product's ORIGINAL public URL (item 7, 2026-09-18).
 *
 * ── The problem ──────────────────────────────────────────────────────────────────────────────
 *
 * brandfashionegy.com has been live for years and Google has indexed it. The importer brought 7,087
 * of its products into this catalogue and gave each one a slug DERIVED from the English title,
 * because that is what the transform does when it has nothing better. Those derived slugs are not
 * the URLs the world already knows: `yves-saint-laurent-perfume` where the live site served
 * `/ysl-perfume-original/`. Launching on the derived slugs would 404 every indexed product page at
 * once.
 *
 * ── The join, and why it is the post id and nothing else ─────────────────────────────────────
 *
 * `new branding/bf-archive` is a crawl of the live site: `url_inventory.csv` (one row per URL, with
 * the hash of the captured HTML) and `html/<hash>.html`. Each captured page carries WordPress's own
 * marker for which post it is — `postid-<n>` on the `<body>` tag, and the same number in the
 * shortlink. The importer stored the same number as `catalog_products.import_ref` = `woo:<n>`.
 *
 * Nothing else in the two sources can be trusted to line up. The titles were machine-translated on
 * the way in, and the slugs are exactly what is being corrected — matching on either would be
 * matching on the thing that is wrong.
 *
 * ── What it deliberately does NOT do ─────────────────────────────────────────────────────────
 *
 * **It writes no redirects.** A 301 would point FROM the derived slug, which has never been served
 * to anybody: the new Brand Fashion storefront is not live yet. A redirect from a URL that has
 * never existed is a row that can only ever be wrong later.
 *
 * **It does not touch Watchizer.** Only `storefront_product` rows on the Brand Fashion storefront,
 * and only for products the importer made. The 626 Watchizer products also placed on Brand Fashion
 * keep the slugs they already have.
 *
 * **It will not create a duplicate.** Two archived URLs can resolve to one original slug — the live
 * site had its own collisions — and a slug is unique per storefront. A collision is REPORTED and
 * skipped, never suffixed: a product silently given `/ysl-perfume-2/` is a product whose indexed
 * URL still 404s, with a row that says it was fixed.
 */
final class ArchiveSlugsCommand extends Command
{
    protected $signature = 'core:archive-slugs
        {map : a JSON file of {"<woo id>": "<archived url>"} — see docs/wave4d/ITEM7_SLUGS.md}
        {--storefront=brandfashion : the storefront code whose slugs are restored}
        {--apply : write the slugs; without it nothing is written and the counts are the same}
        {--report= : write a CSV of every product and what happened to it}';

    protected $description = 'Set each imported product\'s slug to its original live URL, from the site archive.';

    public function handle(): int
    {
        $mapPath = Coerce::str($this->argument('map'));
        if (! is_file($mapPath)) {
            $this->error("No such file: {$mapPath}");

            return self::FAILURE;
        }

        $code = Coerce::str($this->option('storefront'), 'brandfashion');
        $storefront = Storefront::query()->where('code', $code)->first();
        if ($storefront === null) {
            $this->error("No storefront with code [{$code}].");

            return self::FAILURE;
        }

        /** @var array<string, string> $map */
        $map = Coerce::arr(json_decode(Coerce::str(file_get_contents($mapPath)), true));
        $this->info('archive map: '.count($map).' woo ids with a captured URL');

        $apply = (bool) $this->option('apply');
        if (! $apply) {
            $this->warn('DRY RUN — nothing is written. Add --apply to write the slugs.');
        }

        // ── every imported product on this storefront ───────────────────────────────────────
        $rows = DB::table('storefront_product as sp')
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->where('sp.storefront_id', $storefront->id)
            ->whereNotNull('p.import_ref')
            ->orderBy('sp.product_id')
            ->get(['sp.product_id', 'sp.slug', 'p.import_ref', 'p.wa_code']);

        /*
         * Every slug already on this storefront, so a collision is detected against the WHOLE
         * storefront and not only against the rows this command is changing. A product that keeps
         * its derived slug still occupies it.
         */
        $taken = [];
        foreach (
            DB::table('storefront_product')->where('storefront_id', $storefront->id)
                ->whereNotNull('slug')->get(['product_id', 'slug']) as $raw
        ) {
            $row = Row::cast($raw);
            $taken[Row::str($row, 'slug')] = Row::int($row, 'product_id');
        }

        $counts = ['matched' => 0, 'already' => 0, 'no_url' => 0, 'collision' => 0, 'unslugged' => 0, 'too_long' => 0];
        $report = [];
        $writes = [];

        /*
         * ── Pass one: what each product WANTS, before anything is claimed ───────────────────
         *
         * Deciding and assigning in a single loop looked correct and was not. `$taken` starts as
         * every slug currently on the storefront, so a product asking for `generic-men-sunglasses-
         * sn124` was refused because product 5738 held it — and 5738 was, later in the very same
         * run, about to move to its own archived slug and give it up.
         *
         * That produced 14 "collisions", of which 7 were nothing but the order of the loop. A
         * report that calls an ordering artefact a data conflict sends somebody to investigate the
         * catalogue for a bug in this file.
         */
        $wants = [];
        $meta = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $productId = Row::int($row, 'product_id');
            $current = Row::nstr($row, 'slug') ?? '';
            $ref = Row::str($row, 'import_ref');
            $wooId = str_starts_with($ref, 'woo:') ? substr($ref, 4) : '';
            $meta[$productId] = ['code' => Row::str($row, 'wa_code'), 'ref' => $ref, 'current' => $current];

            $url = Coerce::nstr($map[$wooId] ?? null);
            if ($url === null) {
                $counts['no_url']++;
                $report[] = [$productId, $meta[$productId]['code'], $ref, $current, '', 'no archived url'];

                continue;
            }

            $original = self::slugFromUrl($url);
            if ($original === null) {
                // A URL whose last segment is not a slug this command can vouch for — an
                // underscore, an encoded Arabic title. Counted apart so it cannot hide inside
                // "no archived url", which is a different and much larger fact.
                $counts['unslugged']++;
                $report[] = [$productId, $meta[$productId]['code'], $ref, $current, $url, 'url has no slug'];

                continue;
            }

            /*
             * The column is varchar(191) and `PlacementWriter::SLUG_MAX` says so too. Ten of the
             * archived URLs are longer than that — the live site ran on a schema that allowed it.
             *
             * REFUSED, not truncated. A truncated slug is not the URL Google indexed, so cutting it
             * to fit buys nothing and costs the report its honesty: the row would say "restored"
             * while the old link still 404s. These ten keep their derived slug and are listed.
             */
            if (mb_strlen($original) > PlacementWriter::SLUG_MAX) {
                $counts['too_long']++;
                $report[] = [
                    $productId, $meta[$productId]['code'], $ref, $current, $original,
                    'archived slug is '.mb_strlen($original).' characters, the column holds '.PlacementWriter::SLUG_MAX,
                ];

                continue;
            }

            if ($original === $current) {
                $counts['already']++;
                $report[] = [$productId, $meta[$productId]['code'], $ref, $current, $original, 'already correct'];

                continue;
            }

            $wants[$productId] = $original;
        }

        /*
         * ── Pass two: everyone vacates first, then everyone claims ──────────────────────────
         *
         * Every product in `$wants` is leaving its current slug, so those slugs are free before a
         * single assignment is made. What remains in `$taken` is held by somebody who is NOT
         * moving, and a clash with one of those is a real clash.
         */
        foreach (array_keys($wants) as $productId) {
            unset($taken[$meta[$productId]['current']]);
        }

        foreach ($wants as $productId => $original) {
            $owner = $taken[$original] ?? null;
            if ($owner !== null && $owner !== $productId) {
                /*
                 * A REAL collision: two products want one URL, or a product that is staying put
                 * already has it. Skipped, never suffixed — a product quietly given
                 * `/ysl-perfume-2/` is a product whose indexed URL still 404s, with a row in the
                 * report saying it was fixed.
                 */
                $counts['collision']++;
                $report[] = [
                    $productId, $meta[$productId]['code'], $meta[$productId]['ref'],
                    $meta[$productId]['current'], $original, "collides with product {$owner}",
                ];

                continue;
            }

            $counts['matched']++;
            $report[] = [
                $productId, $meta[$productId]['code'], $meta[$productId]['ref'],
                $meta[$productId]['current'], $original, 'restored',
            ];
            $writes[$productId] = $original;
            $taken[$original] = $productId;
        }

        if ($apply && $writes !== []) {
            self::write($storefront->id, $writes);
        }

        $this->table(
            ['outcome', 'products'],
            [
                ['restored to the original URL', $counts['matched']],
                ['already correct', $counts['already']],
                ['no archived URL for this product', $counts['no_url']],
                ['archived URL had no usable slug', $counts['unslugged']],
                ['archived slug too long for the column', $counts['too_long']],
                ['original slug taken by another product', $counts['collision']],
                ['— imported products on this storefront', $rows->count()],
            ],
        );

        $reportPath = Coerce::nstr($this->option('report'));
        if ($reportPath !== null) {
            $fh = fopen($reportPath, 'w');
            if ($fh !== false) {
                // A BOM, so Excel opens the Arabic-adjacent columns correctly — the same rule the
                // client-facing CSV follows.
                fwrite($fh, "\xEF\xBB\xBF");
                fputcsv($fh, ['product_id', 'wa_code', 'import_ref', 'slug_before', 'archived_slug', 'outcome']);
                foreach ($report as $line) {
                    fputcsv($fh, $line);
                }
                fclose($fh);
                $this->info("report: {$reportPath}");
            }
        }

        if (! $apply) {
            $this->warn('Nothing was written. Re-run with --apply.');
        }

        return self::SUCCESS;
    }

    /**
     * Write the new slugs — in TWO phases, because one is not enough.
     *
     * ── The 1062 this exists to avoid ────────────────────────────────────────────
     *
     * `sp_storefront_slug_unique` is checked per STATEMENT, not at commit, so being inside a
     * transaction does not help. The assignment above guarantees the FINAL state is conflict-free;
     * it says nothing about the states in between. Product A moving to a slug that product B has
     * not yet vacated is a duplicate-key error halfway through, and on this catalogue it was not
     * hypothetical — `2-calvin-klein-cross-bag` hit it on the first run.
     *
     * Ordering the writes cannot fix it in general: A wants B's slug and B wants A's is a cycle,
     * and this data contains them.
     *
     * So every affected row is first parked on a slug nothing can collide with — the product id is
     * unique per storefront by definition — and only then given its real one. Both phases are in
     * one transaction, so a failure in either leaves the storefront exactly as it was. (The first
     * run's failure is what proved that: the rollback held and not one slug had moved.)
     *
     * @param  array<int, string>  $writes  product id => the slug it should end up with
     */
    private static function write(int $storefrontId, array $writes): void
    {
        DB::transaction(function () use ($storefrontId, $writes): void {
            foreach (array_keys($writes) as $productId) {
                DB::table('storefront_product')
                    ->where('storefront_id', $storefrontId)
                    ->where('product_id', $productId)
                    ->update(['slug' => 'item7-parking-'.$productId]);
            }

            foreach ($writes as $productId => $slug) {
                DB::table('storefront_product')
                    ->where('storefront_id', $storefrontId)
                    ->where('product_id', $productId)
                    ->update(['slug' => $slug, 'updated_at' => now()]);
            }
        });
    }

    /**
     * The last path segment of an archived URL — the product's original slug.
     *
     * WordPress serves these as `https://host/<slug>/` with a trailing slash, so the last NON-EMPTY
     * segment is the slug. Returns null when there is nothing usable, rather than an empty string
     * that would later be written over a perfectly good derived slug.
     */
    public static function slugFromUrl(string $url): ?string
    {
        $path = Coerce::nstr(parse_url($url, PHP_URL_PATH));
        if ($path === null) {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        if ($segments === []) {
            return null;
        }

        $slug = rawurldecode((string) end($segments));

        // The live site's product URLs are ASCII slugs. Anything else — an encoded Arabic title, a
        // query fragment that survived — is reported rather than written, because a slug this
        // command cannot vouch for is worse than the derived one already in place.
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) === 1 ? $slug : null;
    }
}
