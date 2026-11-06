<?php

declare(strict_types=1);

// i18n-exempt-file: every Arabic string here is STOREFRONT page text (the "in numbers" section of a brand or
// category page, written in the page's own language) — it is never shown to a dashboard operator, so the manage seam
// has nothing to translate.

namespace App\Domain\Content;

use App\Models\Storefront\Storefront;
use App\Storefront\CacheTags;
use App\Storefront\CategoryTree;
use App\Storefront\Lookups;
use App\Storefront\ProductListing;
use App\Storefront\StorefrontCache;
use App\Storefront\StorefrontContext;
use App\Storefront\StorefrontHost;
use App\Storefront\StorefrontUrls;
use App\Support\Coerce;
use RuntimeException;

/**
 * The "in numbers" section of a brand or category page text (the twelve updates, Part 6.2, 2026-11-05; rebuilt on
 * the developer's decision of the same day).
 *
 * THE NUMBERS ARE FIXED, AND OF TWO KINDS — and the text says which is which:
 *
 *  (a) THE BRAND'S OWN CATALOGUE — the whole brand, not what the shop stocks: its collections, movement types,
 *      case sizes, materials. Researched from the brand's OFFICIAL sources only and kept, figure by figure, with
 *      its source URL and the date it was read, in `brand_sources.json` ({@see self::BRAND_FILE}). A figure the
 *      brand does not publish, or that its sources disagree on, is not there — it is listed under `left_out`
 *      with the reason, never estimated, and a third party's estimate is never printed as the brand's number.
 *
 *  (b) OUR CATALOGUE — for each page on each storefront: the number of models the shop carries, counted as
 *      EVERY real catalogue product of that page on that storefront, in stock or not (the largest real number —
 *      a count of what is in stock today is smaller and moves hourly); a category's total is the SUM OF ITS
 *      BRANDS' COUNTS; plus the price range, the dial and strap colours, the movement types and the materials,
 *      all from the shop's own data. Counted by `php artisan seo:count-facts` ({@see self::count()}) and kept,
 *      with the date and how each number was counted, in `facts.json` ({@see self::OURS_FILE}).
 *
 * `seo:seed-texts` puts this section where a prepared text has {@see self::TOKEN}. It reads the two FILES and
 * nothing else: the numbers a page shows are the ones recorded in the repository, not whatever the database says
 * at the moment of seeding, and they change only when somebody recounts (ours) or re-reads the sources (the brand's).
 *
 * WHAT REACHES THE PAGE (the developer's review, 2026-11-05) is narrower than what the files hold: the brand's
 * range is presented as available at the shop — its family names and plain facts, from the entry's `page` block —
 * with no date, no outbound link, no source note and no comparison between the brand's catalogue and ours. The
 * sources, the dates and the brand's own counts stay in the files for whoever refreshes them ({@see self::section()}).
 *
 * HOW (b) IS COUNTED: the page's own listing — the same {@see ProductListing} its v2 request runs — WITHOUT the
 * in-stock filter, with its facets. The listing is CALLED, not requested over HTTP: the route is rate-limited and,
 * on production, a storefront answers only on its own host ({@see StorefrontHost}); either failure would have left
 * the numbers out without a word. The storefront is bound exactly as `ResolveStorefront` binds it for a request,
 * the listing runs, and what was bound before is put back; anything that goes wrong is an exception.
 */
final class SeoFacts
{
    public const TOKEN = '{facts}';

    /** Our catalogue's numbers, per storefront and page — written by `seo:count-facts --write`. */
    public const OURS_FILE = 'seeders/content/seo/facts.json';

    /** The brands' own figures, each with its official source and the date it was read — edited by hand. */
    public const BRAND_FILE = 'seeders/content/seo/brand_sources.json';

    /** How many names a bullet lists before it stops (the busiest first — the facets come sorted by count). */
    private const TOP = 6;

    /** A category names this many of its brands with their counts, then says how many more there are. */
    private const TOP_BRANDS = 8;

    public static function oursPath(): string
    {
        $override = config('storefront.seo_facts_file');

        return is_string($override) && $override !== '' ? $override : database_path(self::OURS_FILE);
    }

    public static function brandPath(): string
    {
        $override = config('storefront.seo_brand_sources_file');

        return is_string($override) && $override !== '' ? $override : database_path(self::BRAND_FILE);
    }

    /** @return array<string, mixed> the decoded file, or [] when it is not there */
    public static function read(string $path): array
    {
        $raw = is_file($path) ? file_get_contents($path) : false;
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * COUNT one page of one storefront, now: every real catalogue product it lists, in stock or not.
     *
     * @return array<string, mixed> `models` (a category: the sum of its brands' counts), `listed` (the listing's own
     *                              total, so a product without a brand shows as a difference), then `types` (a brand
     *                              page) or `brands` (a category page), `movements`, `dial_colors`, `band_colors`,
     *                              `materials` — each a list of `{en, ar, count}` — and `price` `{min, max}`
     */
    public static function count(string $storefrontCode, string $type, string $key): array
    {
        $payload = self::listing($storefrontCode, ['facets' => '1', 'per_page' => '1'] + ($type === 'brand' ? ['brand' => $key] : ['category' => $key]));
        $listed = Coerce::int(Coerce::arr($payload['meta'] ?? [])['total'] ?? 0);
        if ($listed <= 0) {
            return ['models' => 0, 'listed' => 0];
        }
        $facets = Coerce::arr($payload['facets'] ?? []);

        $out = ['models' => $listed, 'listed' => $listed];
        if ($type === 'brand') {
            // the kinds of product the brand has here: the tree below its top level, busiest first
            $kinds = array_values(array_filter(self::facetRows($facets['category'] ?? null), fn (array $r): bool => Coerce::int($r['depth'] ?? 0) >= 2));
            usort($kinds, fn (array $a, array $b): int => Coerce::int($b['count'] ?? 0) <=> Coerce::int($a['count'] ?? 0));
            $out['types'] = self::named($kinds);
        } else {
            $out['brands'] = self::named(self::facetRows($facets['brand'] ?? null));
            // "a category's total = the sum of its brands' counts" (the developer, 2026-11-05)
            $out['models'] = array_sum(array_map(fn (array $b): int => $b['count'], $out['brands']));
        }
        $out['movements'] = self::named(self::facetRows($facets['movement'] ?? null));
        $out['dial_colors'] = self::named(self::facetRows($facets['dial_color'] ?? null));
        $out['band_colors'] = self::named(self::facetRows($facets['band_color'] ?? null));
        $out['materials'] = self::named(self::facetRows($facets['material'] ?? null));

        $price = Coerce::arr($facets['price'] ?? []);
        if (is_numeric($price['min'] ?? null) && is_numeric($price['max'] ?? null) && (float) $price['min'] > 0) {
            $out['price'] = ['min' => (float) $price['min'], 'max' => (float) $price['max']];
        }

        return $out;
    }

    /**
     * The section, in both languages, from the two files — '' in both when the files have nothing for this page.
     *
     * WHAT IS ON THE PAGE, AND WHAT IS NOT (the developer's review and decision, 2026-11-05):
     *
     *  - the brand's range is presented as AVAILABLE AT THE SHOP: the shop carries the brand's families (named,
     *    when its official sources name them) and adds a model it does not list yet. There is NO comparison
     *    between the brand's catalogue and ours, and no count of the brand's own catalogue;
     *  - NO date, NO outbound link and NO source note: where a figure came from and when it was read stay in the
     *    content files (`sources`, `figures[].source`, `checked_on`, `counted_on`) and never reach a page;
     *  - every number that IS printed is ours and real — the models the page lists on that storefront, their
     *    types, colours, movement types, materials and price range. Nothing is said about the stock or the price
     *    of a model the shop does not list;
     *  - each language uses ITS OWN names: the English text never carries the Arabic name of a brand or a category.
     *
     * @param  array<string, string>  $shop  locale => the storefront's name as its texts write it
     * @param  array<string, string>  $names  locale => the page's own name (the brand, or the category) in that language
     * @return array{en: string, ar: string}
     */
    public static function section(string $storefrontCode, string $type, string $key, array $shop, array $names): array
    {
        $file = self::read(self::oursPath());
        $ours = Coerce::arr(Coerce::arr(Coerce::arr(Coerce::arr($file['storefronts'] ?? [])[$storefrontCode] ?? [])[$type] ?? [])[$key] ?? []);
        $models = Coerce::int($ours['models'] ?? 0);

        // What a brand page may say about the brand's range: `page` of its entry — family names and plain facts,
        // each of them backed by a sourced figure of the same entry (the guard test holds the file to that).
        $page = $type === 'brand' ? Coerce::arr(Coerce::arr(self::read(self::brandPath())[$key] ?? [])['page'] ?? []) : [];
        $families = array_values(array_filter(Coerce::arr($page['families'] ?? []), fn (mixed $f): bool => is_string($f) && trim($f) !== ''));
        $facts = array_values(array_filter(Coerce::arr($page['facts'] ?? []), 'is_array'));
        if ($models <= 0 && $families === [] && $facts === []) {
            return ['en' => '', 'ar' => ''];
        }

        $out = [];
        foreach (['en', 'ar'] as $locale) {
            $ar = $locale === 'ar';
            $sep = $ar ? '، ' : ', ';
            $shopName = $shop[$locale] ?? $shop['en'] ?? '';
            $name = trim($names[$locale] ?? '') ?: trim($names['en'] ?? '');
            $blocks = [];

            if ($type === 'brand') {
                // the brand's range, as available at the shop
                $lead = $families === []
                    ? ($ar ? "{$shopName} يوفّر {$name}." : "{$shopName} carries {$name}.")
                    : ($ar
                        ? "{$shopName} يوفّر {$name} بعائلاته: ".implode('، ', $families).'.'
                        : "{$shopName} carries {$name} across its families: ".implode(', ', $families).'.');
                $blocks[] = $lead.' '.($ar
                    ? 'وإن لم تجد الموديل الذي تريده معروضًا بعد، اطلبه ونضيفه لك.'
                    : 'If the model you want is not listed yet, ask for it and we add it.');
            }

            $lines = [];
            if ($models > 0) {
                $lines[] = $ar ? "**الموديلات المعروضة:** {$models}." : "**Models listed:** {$models}.";
                if ($type === 'brand') {
                    self::add($lines, $ar ? 'الأنواع' : 'Types', self::counted(Coerce::arr($ours['types'] ?? []), $locale, self::TOP), $sep);
                } else {
                    $brands = array_values(array_filter(Coerce::arr($ours['brands'] ?? []), 'is_array'));
                    $listed = self::counted($brands, $locale, self::TOP_BRANDS);
                    $rest = array_slice($brands, self::TOP_BRANDS);
                    if ($rest !== []) {
                        $restModels = array_sum(array_map(fn (array $b): int => Coerce::int($b['count'] ?? 0), $rest));
                        $listed[] = $ar ? 'و'.count($rest).' ماركة أخرى ('.$restModels.')' : 'and '.count($rest).' more brands ('.$restModels.')';
                    }
                    self::add($lines, $ar ? 'الماركات' : 'Brands', $listed, $sep);
                }
            }
            // plain facts about the brand's range (movements, sizes, materials), as the shop presents it
            foreach ($facts as $fact) {
                $label = self::text($fact['label'] ?? null, $locale);
                $value = self::text($fact['value'] ?? null, $locale);
                if ($label !== '' && $value !== '') {
                    $lines[] = "**{$label}:** ".rtrim($value, '.').'.';
                }
            }
            if ($models > 0) {
                self::add($lines, $ar ? 'نوع الحركة' : 'Movement', self::counted(Coerce::arr($ours['movements'] ?? []), $locale, self::TOP), $sep);
                self::add($lines, $ar ? 'ألوان الميناء' : 'Dial colours', self::names(Coerce::arr($ours['dial_colors'] ?? []), $locale), $sep);
                self::add($lines, $ar ? 'ألوان السوار' : 'Strap colours', self::names(Coerce::arr($ours['band_colors'] ?? []), $locale), $sep);
                self::add($lines, $ar ? 'الخامات' : 'Materials', self::names(Coerce::arr($ours['materials'] ?? []), $locale), $sep);

                $price = Coerce::arr($ours['price'] ?? []);
                $min = is_numeric($price['min'] ?? null) ? (float) $price['min'] : null;
                $max = is_numeric($price['max'] ?? null) ? (float) $price['max'] : null;
                if ($min !== null && $max !== null && $min > 0) {
                    $lines[] = $min === $max
                        ? ($ar ? '**السعر:** '.self::money($min).' ج.م.' : '**Price:** EGP '.self::money($min).'.')
                        : ($ar ? '**نطاق الأسعار:** من '.self::money($min).' إلى '.self::money($max).' ج.م.' : '**Price range:** EGP '.self::money($min).' to EGP '.self::money($max).'.');
                }
            }
            if ($lines !== []) {
                $blocks[] = implode("\n", array_map(fn (string $line): string => '- '.$line, $lines));
            }

            $heading = $ar ? "{$name} في {$shopName}" : "{$name} at {$shopName}";
            $out[$locale] = '## '.$heading."\n\n".implode("\n\n", $blocks);
        }

        return ['en' => $out['en'], 'ar' => $out['ar']];
    }

    /**
     * The listing of one page of one storefront, run with that storefront bound as a v2 request binds it.
     *
     * @param  array<string, string>  $input
     * @return array<string, mixed> the listing's answer (`data`, `meta`, `facets`), or [] for a page the tree does not have
     */
    private static function listing(string $storefrontCode, array $input): array
    {
        $storefront = Storefront::query()->where('code', $storefrontCode)->first();
        if (! $storefront instanceof Storefront) {
            throw new RuntimeException("No storefront with the code {$storefrontCode}.");
        }
        $id = (int) $storefront->id;

        $classes = [StorefrontContext::class, CacheTags::class, Lookups::class, CategoryTree::class];
        $before = [];
        foreach ($classes as $class) {
            $before[$class] = app()->bound($class) ? app($class) : null;
        }
        $assetBefore = config('storefront.request_asset_base');

        $tags = new CacheTags;
        $tags->storefront($id);
        $context = new StorefrontContext($storefront, 'en', $tags);
        $cache = app(StorefrontCache::class);
        app()->instance(StorefrontContext::class, $context);
        app()->instance(CacheTags::class, $tags);
        app()->instance(Lookups::class, new Lookups($cache, $context));
        app()->instance(CategoryTree::class, new CategoryTree($cache, $context));
        config(['storefront.request_asset_base' => StorefrontUrls::assetBase($id)]);

        try {
            return app()->make(ProductListing::class)->run($input, '/api/v2/'.$storefrontCode.'/products') ?? [];
        } finally {
            foreach ($classes as $class) {
                if ($before[$class] === null) {
                    app()->forgetInstance($class);
                } else {
                    app()->instance($class, $before[$class]);
                }
            }
            config(['storefront.request_asset_base' => $assetBefore]);
        }
    }

    /**
     * Put the section where the token is, or take the token (and the blank lines around it) away.
     */
    public static function fill(string $text, string $section): string
    {
        if (! str_contains($text, self::TOKEN)) {
            return $text;
        }
        if ($section === '') {
            return trim((string) preg_replace('/\n*'.preg_quote(self::TOKEN, '/').'\n*/u', "\n\n", $text));
        }

        return str_replace(self::TOKEN, $section, $text);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $values
     */
    private static function add(array &$lines, string $label, array $values, string $separator): void
    {
        if ($values !== []) {
            $lines[] = '**'.$label.':** '.implode($separator, $values).'.';
        }
    }

    /** @return list<array<mixed>> */
    private static function facetRows(mixed $facet): array
    {
        return array_values(array_filter(Coerce::arr($facet), 'is_array'));
    }

    /**
     * A facet's rows as the file keeps them: the name in both languages and the count, nothing else.
     *
     * @param  list<array<mixed>>  $rows
     * @return list<array{en: string, ar: string, count: int}>
     */
    private static function named(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $en = self::text($row['name'] ?? null, 'en');
            $count = Coerce::int($row['count'] ?? 0);
            if ($en !== '' && $count > 0) {
                $out[] = ['en' => $en, 'ar' => self::text($row['name'] ?? null, 'ar') ?: $en, 'count' => $count];
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<string> "Name (count)", the first `$top`
     */
    private static function counted(array $rows, string $locale, int $top): array
    {
        $out = [];
        foreach (array_slice(array_values(array_filter($rows, 'is_array')), 0, $top) as $row) {
            $name = self::text($row, $locale);
            $count = Coerce::int($row['count'] ?? 0);
            if ($name !== '' && $count > 0) {
                $out[] = $name.' ('.$count.')';
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $rows
     * @return list<string>
     */
    private static function names(array $rows, string $locale): array
    {
        $out = [];
        foreach (array_slice(array_values(array_filter($rows, 'is_array')), 0, self::TOP) as $row) {
            $name = self::text($row, $locale);
            if ($name !== '' && Coerce::int($row['count'] ?? 0) > 0) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** A string, or the `$locale` side of an `{en, ar}` pair (English when that language is missing). */
    private static function text(mixed $value, string $locale): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        $pair = Coerce::arr($value);
        $text = $pair[$locale] ?? $pair['en'] ?? '';

        return is_string($text) ? trim($text) : '';
    }

    /** 3,900 — Western digits and no decimals unless the amount has them, as the storefronts print a price. */
    private static function money(float $amount): string
    {
        return number_format($amount, floor($amount) === $amount ? 0 : 2, '.', ',');
    }
}
