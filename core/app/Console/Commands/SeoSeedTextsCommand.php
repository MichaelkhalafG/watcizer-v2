<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\SeoFacts;
use App\Domain\Content\SeoTexts;
use App\Domain\Content\SeoTextStructure;
use App\Http\Controllers\Manage\SeoTextController;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load the written brand and category page texts (M1y, 2026-10-30, SEO item 5) from
 * `database/seeders/content/seo/{brands,categories}.json` into `core_seo_texts`, through the same `SeoTexts::save` the
 * dashboard's "Page texts" screen uses (so every write is logged).
 *
 * `{shop}` in a text becomes the storefront's name in that language. A key the storefront has no page for is
 * reported and skipped — the list of pages is the dashboard screen's own ({@see SeoTextController::brands()} and
 * `categories()`), so the command cannot write a text no page shows. A page that already has a text is LEFT ALONE
 * unless `--overwrite`: what someone typed in the dashboard wins over the file. A meta description longer than 160
 * characters after the name is filled in is reported (Google cuts it) and still written.
 *
 * `{facts}` in a text (the twelve updates, Part 6.2, 2026-11-05) becomes the page's "in numbers" section
 * ({@see SeoFacts}) — FIXED numbers of two kinds, and the text says which is which: the brand's own catalogue
 * (from its official sources, each cited with the date it was read: `brand_sources.json`) and OUR catalogue on
 * this storefront (every model the page lists, in stock or not: `facts.json`, written by `seo:count-facts`).
 * This command reads those two files, never the database: a page whose numbers are not in them gets no section,
 * and is reported ("no numbers") so the recount is not forgotten.
 *
 * ── What `--overwrite` will NOT replace ───────────────────────────────────────────────────────────────────
 *
 * A text the DASHBOARD wrote last. `SeoTexts::save` records who wrote a row (`updated_by`): this command writes
 * as nobody (NULL), the "Page texts" screen writes as the signed-in user. So a page with `updated_by` set in
 * either language was edited by a person after the seed, and `--overwrite` reports it ("edited") and leaves it
 * alone — the reason the rewrite of 2026-11-05 can be applied without first proving by hand that nobody touched
 * a page. Only `--overwrite-edited`, named on purpose, replaces those too.
 *
 * Without `--apply` nothing is written.
 */
final class SeoSeedTextsCommand extends Command
{
    /** @var array<string, array<string, string>> storefront code => locale => the name used in its texts */
    public const SHOP_NAMES = [
        'watchizer' => ['en' => 'Watchizer', 'ar' => 'Watchizer'],
        'brandfashion' => ['en' => 'Brand Fashion', 'ar' => 'براند فاشون'], // i18n-exempt: the storefront's own Arabic name, written into its page texts
    ];

    public const META_LIMIT = 160;

    protected $signature = 'seo:seed-texts
        {--storefront=watchizer : the storefront code (watchizer | brandfashion)}
        {--overwrite : replace a text that is already there — but never one edited in the dashboard}
        {--overwrite-edited : with --overwrite, ALSO replace the texts somebody edited in the dashboard}
        {--apply : write them; without it nothing is written}';

    protected $description = 'Load the prepared AR+EN brand and category page texts for a storefront; keeps existing texts unless --overwrite';

    public function handle(StorefrontCache $cache): int
    {
        $code = Coerce::str($this->option('storefront'));
        $storefront = Coerce::nint(DB::table('storefronts')->where('code', $code)->value('id'));
        if ($storefront === null || ! isset(self::SHOP_NAMES[$code])) {
            $this->error('No storefront with that code.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $overwrite = (bool) $this->option('overwrite');
        $overwriteEdited = (bool) $this->option('overwrite-edited');
        if ($overwriteEdited && ! $overwrite) {
            $this->error('--overwrite-edited means nothing without --overwrite.');

            return self::FAILURE;
        }
        $existing = SeoTexts::all($storefront);
        // the pages a PERSON wrote last: type => key => "user #7, 2026-11-02 14:10"
        $edited = [];
        foreach (DB::table(SeoTexts::TABLE)->where('storefront_id', $storefront)->whereNotNull('updated_by')->orderBy('updated_at')->get(['subject_type', 'subject_key', 'updated_by', 'updated_at']) as $raw) {
            $row = (array) $raw;
            $edited[Coerce::str($row['subject_type'] ?? '')][Coerce::str($row['subject_key'] ?? '')] = 'user #'.Coerce::str($row['updated_by'] ?? '').', '.Coerce::str($row['updated_at'] ?? '');
        }
        $counts = ['add' => 0, 'keep' => 0, 'missing' => 0, 'long' => 0, 'edited' => 0, 'unnumbered' => 0];

        foreach (['brand' => 'brands', 'category' => 'categories'] as $type => $file) {
            $pages = [];
            $names = [];
            foreach ($type === 'brand' ? SeoTextController::brands($storefront) : SeoTextController::categories($storefront) as $page) {
                $pages[$page['key']] = $page['name'];
                $names[$page['key']] = $page['names'];
            }
            foreach (self::load($file) as $key => $byLocale) {
                if (! isset($pages[$key])) {
                    $this->line("skip   {$type} {$key} (no such page on {$code})");
                    $counts['missing']++;

                    continue;
                }
                $has = false;
                foreach (SeoTexts::LOCALES as $locale) {
                    $row = $existing[$type][$key][$locale] ?? null;
                    $has = $has || ($row !== null && (($row['description'] ?? null) !== null || ($row['meta_description'] ?? null) !== null));
                }
                if ($has && ! $overwrite) {
                    $this->line("keep   {$type} {$key} (already written)");
                    $counts['keep']++;

                    continue;
                }
                if ($has && isset($edited[$type][$key]) && ! $overwriteEdited) {
                    $this->warn("edited {$type} {$key} (changed in the dashboard by {$edited[$type][$key]}) — left alone");
                    $counts['edited']++;

                    continue;
                }
                $facts = SeoFacts::section($code, $type, $key, self::SHOP_NAMES[$code], $names[$key]);
                if ($facts['en'] === '') {
                    $this->warn("no numbers for {$type} {$key} on {$code}: its text is written without the section — run seo:count-facts --write, then seed again");
                    $counts['unnumbered']++;
                }
                $filled = [];
                foreach (SeoTexts::LOCALES as $locale) {
                    $name = self::SHOP_NAMES[$code][$locale];
                    $fields = Coerce::arr($byLocale[$locale] ?? []);
                    $description = SeoFacts::fill(str_replace('{shop}', $name, Coerce::str($fields['description'] ?? '')), $facts[$locale]);
                    $meta = str_replace('{shop}', $name, Coerce::str($fields['meta_description'] ?? ''));
                    if (mb_strlen($meta) > self::META_LIMIT) {
                        $this->warn("long   {$type} {$key} {$locale} meta is ".mb_strlen($meta).' characters');
                        $counts['long']++;
                    }
                    // THE PARTS (item 43 B): the edit page shows a text as fields when it is in the layout's shape —
                    // which is the shape these files are written in. A prepared text that is not is still written,
                    // and said so: it would be edited as Markdown.
                    if (SeoTextStructure::parse($description) === null) {
                        $this->warn("shape  {$type} {$key} {$locale} is not in the fields' shape (intro, sections with points, questions last): the edit page will show it as Markdown");
                    }
                    $filled[$locale] = ['description' => $description, 'meta_description' => $meta];
                }
                $this->line(($apply ? 'add    ' : 'would add ')."{$type} {$key}");
                $counts['add']++;
                if ($apply) {
                    SeoTexts::save($storefront, $type, $key, $filled, null, $pages[$key].' ('.$key.') — seo:seed-texts');
                }
            }
        }
        if ($apply && $counts['add'] > 0) {
            $cache->flush($storefront);
        }

        $this->info(sprintf('%s %d, kept %d, edited in the dashboard and left alone %d, no page %d, long meta %d, without numbers %d.%s', $apply ? 'Written' : 'Would write', $counts['add'], $counts['keep'], $counts['edited'], $counts['missing'], $counts['long'], $counts['unnumbered'], $apply ? '' : ' Dry run: add --apply to write.'));
        $numbers = SeoFacts::read(SeoFacts::oursPath());
        $this->line('Our numbers: counted on '.Coerce::str($numbers['counted_on'] ?? 'never').' ('.Coerce::str($numbers['counted_from'] ?? 'no count recorded').').');

        return self::SUCCESS;
    }

    /** @return array<string, array<string, mixed>> key => locale => fields; the `_about` note is not a page */
    private static function load(string $file): array
    {
        $raw = file_get_contents(database_path('seeders/content/seo/'.$file.'.json'));
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $out = [];
        foreach (is_array($decoded) ? $decoded : [] as $key => $value) {
            if (is_string($key) && $key !== '_about' && is_array($value)) {
                $out[$key] = Coerce::arr($value);
            }
        }

        return $out;
    }
}
