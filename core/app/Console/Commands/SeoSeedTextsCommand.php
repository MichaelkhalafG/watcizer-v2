<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Content\SeoTexts;
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
        {--overwrite : replace a text that is already there}
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
        $existing = SeoTexts::all($storefront);
        $counts = ['add' => 0, 'keep' => 0, 'missing' => 0, 'long' => 0];

        foreach (['brand' => 'brands', 'category' => 'categories'] as $type => $file) {
            $pages = [];
            foreach ($type === 'brand' ? SeoTextController::brands($storefront) : SeoTextController::categories($storefront) as $page) {
                $pages[$page['key']] = $page['name'];
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
                $filled = [];
                foreach (SeoTexts::LOCALES as $locale) {
                    $name = self::SHOP_NAMES[$code][$locale];
                    $fields = Coerce::arr($byLocale[$locale] ?? []);
                    $description = str_replace('{shop}', $name, Coerce::str($fields['description'] ?? ''));
                    $meta = str_replace('{shop}', $name, Coerce::str($fields['meta_description'] ?? ''));
                    if (mb_strlen($meta) > self::META_LIMIT) {
                        $this->warn("long   {$type} {$key} {$locale} meta is ".mb_strlen($meta).' characters');
                        $counts['long']++;
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

        $this->info(sprintf('%s %d, kept %d, no page %d, long meta %d.%s', $apply ? 'Written' : 'Would write', $counts['add'], $counts['keep'], $counts['missing'], $counts['long'], $apply ? '' : ' Dry run: add --apply to write.'));

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
