<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\SeoFacts;
use App\Http\Controllers\Manage\SeoTextController;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recount OUR catalogue's numbers for the brand and category page texts (the design pass, 2026-11-05).
 *
 *   php artisan seo:count-facts            compare a fresh count with the recorded numbers; writes nothing
 *   php artisan seo:count-facts --write    record the fresh count in database/seeders/content/seo/facts.json
 *
 * The page texts print FIXED numbers ({@see SeoFacts}): the number of models the shop carries on each brand and
 * category page — every real catalogue product of that page on that storefront, in stock or not; a category's
 * total is the sum of its brands' counts — and the price range, colours, movement types and materials. They live in
 * `facts.json` with the date and the way each was counted, and `seo:seed-texts` reads that file, never the database.
 * This command is how the file is refreshed: it counts every page the two content files have a text for, on every
 * storefront that has that page, and says what changed since the last count.
 *
 * It only READS the database. `--write` writes one file on disk — the content file, which belongs in the repository:
 * after a recount on the server, copy the file back and commit it, then `seo:seed-texts --overwrite --apply` puts the
 * new numbers on the pages.
 *
 * The BRANDS' OWN figures (collections, movements, case sizes, materials) are not counted here: they come from each
 * brand's official sources and are refreshed by hand in `brand_sources.json`, which lists every source.
 */
final class SeoCountFactsCommand extends Command
{
    protected $signature = 'seo:count-facts
        {--write : record the fresh count in the content file; without it nothing is written}';

    protected $description = 'Count the models, price ranges, colours and movement types our catalogue has on every brand and category page text; compare with (or write) facts.json';

    public function handle(): int
    {
        $path = SeoFacts::oursPath();
        $recorded = SeoFacts::read($path);
        $before = Coerce::arr($recorded['storefronts'] ?? []);

        $fresh = [];
        $pagesCounted = 0;
        $changed = 0;
        foreach (array_keys(SeoSeedTextsCommand::SHOP_NAMES) as $code) {
            $storefront = Coerce::nint(DB::table('storefronts')->where('code', $code)->value('id'));
            if ($storefront === null) {
                $this->line("skip   storefront {$code} (not in this database)");

                continue;
            }
            foreach (['brand' => 'brands', 'category' => 'categories'] as $type => $file) {
                $pages = [];
                foreach ($type === 'brand' ? SeoTextController::brands($storefront) : SeoTextController::categories($storefront) as $page) {
                    $pages[$page['key']] = true;
                }
                foreach (array_keys(SeoFacts::read(database_path('seeders/content/seo/'.$file.'.json'))) as $key) {
                    if ($key === '_about' || ! isset($pages[$key])) {
                        continue;                       // not a page of this storefront: nothing to count
                    }
                    $now = SeoFacts::count($code, $type, $key);
                    $fresh[$code][$type][$key] = $now;
                    $pagesCounted++;

                    $was = Coerce::arr(Coerce::arr(Coerce::arr($before[$code] ?? [])[$type] ?? [])[$key] ?? []);
                    if (json_encode($was) !== json_encode($now)) {
                        $changed++;
                        $this->line(sprintf('%-9s %s %s %s: models %s → %d', $was === [] ? 'new' : 'changed', $code, $type, $key, $was === [] ? '—' : (string) Coerce::int($was['models'] ?? 0), Coerce::int($now['models'] ?? 0)));
                    }
                }
            }
        }

        $newest = DB::table('catalog_products')->whereNull('deleted_at')->max('created_at');
        $document = [
            '_about' => 'OUR catalogue\'s numbers for the brand and category page texts — FIXED numbers, printed by `php artisan seo:seed-texts` and changed only by a recount. Written by `php artisan seo:count-facts --write`; do not edit by hand. The brands\' OWN figures are in brand_sources.json.',
            'counted_on' => now()->toDateString(),
            'counted_from' => 'database `'.DB::connection()->getDatabaseName().'`'.(is_string($newest) ? '; its newest catalogue product was added on '.substr($newest, 0, 10) : ''),
            'method' => [
                'models' => 'Every real catalogue product the page lists on that storefront, IN STOCK OR NOT: the page\'s own listing (the same query its storefront request runs) without the in-stock filter. This is the largest real number of models the shop carries there; what is in stock today is a smaller number and is not recorded.',
                'category_total' => 'A category\'s `models` is the SUM of its brands\' counts (`brands[].count`). `listed` is the listing\'s own total; the two differ only if a product of that category has no brand.',
                'types_brands' => 'A brand page: the kinds of product the brand has on that storefront (the category tree below its top level), with the number of models in each. A category page: its brands, with the number of models of each.',
                'movements_colors_materials' => 'The listing\'s own facets over the same products: each value with the number of models that carry it, most models first.',
                'price' => 'The lowest and the highest price among the same products, in EGP, as the listing reports them (a sale price counts when the product is on sale).',
                'refresh' => 'php artisan seo:count-facts (dry run: shows what changed) then php artisan seo:count-facts --write; then php artisan seo:seed-texts --storefront=<code> --overwrite --apply for each storefront.',
            ],
            'storefronts' => $fresh,
        ];

        $recordedOn = Coerce::str($recorded['counted_on'] ?? '');
        $this->info(sprintf('%d page(s) counted; %d differ from the recorded numbers%s.', $pagesCounted, $changed, $recordedOn === '' ? ' (nothing was recorded yet)' : ' of '.$recordedOn));

        if (! (bool) $this->option('write')) {
            $this->line('Dry run: add --write to record this count in '.$path);

            return self::SUCCESS;
        }
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (! is_string($json) || file_put_contents($path, $json."\n") === false) {
            $this->error('The file could not be written: '.$path);

            return self::FAILURE;
        }
        $this->info('Written: '.$path);

        return self::SUCCESS;
    }
}
