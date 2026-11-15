<?php

namespace App\Console\Commands;

use App\Domain\Content\QualityGate;
use App\Domain\Content\SeoFacts;
use App\Domain\Content\SeoTexts;
use App\Domain\Content\SitePaths;
use App\Http\Controllers\Manage\SeoTextController;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * LOAD THE PHASE 2 COPY (2026-11-14): brand, category and landing page texts written as Markdown files, one per page and
 * language — `database/seeders/content/seo/phase2/<shop code>-<type>-<key>.<locale>.md`, a landing key's slashes written
 * as `+` (`brandfashion-landing-guess+bags+women.en.md`). Each file: front matter (`intro:`, `meta:`) and the guide.
 *
 * `{facts}` becomes the page's "in numbers" section, as seo:seed-texts does; `{shop}` the shop's name. Saved through
 * SeoTexts::save (logged; a changed text loses its gate verdict), then the quality gate runs on every page loaded and
 * records its verdict. DRY RUN by default: `--apply` writes. `--only=<shop>-<type>-<key>` loads one page.
 */
final class SeoLoadCopyCommand extends Command
{
    protected $signature = 'seo:load-copy {--apply} {--only= : one page, as its file name without the language}';

    protected $description = 'Load the written page copy (brand, category, landing) and run the quality gate on it';

    public function handle(QualityGate $gate, StorefrontCache $cache): int
    {
        $dir = database_path('seeders/content/seo/phase2');
        $apply = (bool) $this->option('apply');
        $only = Coerce::str($this->option('only'));
        $pages = [];
        foreach (glob($dir.'/*.md') ?: [] as $path) {
            if (preg_match('/^([a-z]+)-(brand|category|landing)-(.+)\.(en|ar)\.md$/', basename($path), $m) !== 1) {
                $this->warn('skip '.basename($path).' (not <shop>-<type>-<key>.<locale>.md)');

                continue;
            }
            $id = $m[1].'-'.$m[2].'-'.$m[3];
            if ($only !== '' && $only !== $id) {
                continue;
            }
            $pages[$id]['shop'] = $m[1];
            $pages[$id]['type'] = $m[2];
            $pages[$id]['key'] = str_replace('+', '/', $m[3]);
            $pages[$id][$m[4]] = self::read($path);
        }
        $touched = [];
        foreach ($pages as $id => $page) {
            $sf = Coerce::int(DB::table('storefronts')->where('code', $page['shop'])->value('id'));
            if ($sf === 0 || ! isset($page['en'], $page['ar'])) {
                $this->warn("skip {$id} (no such shop, or a language is missing)");

                continue;
            }
            $type = $page['type'];
            $key = $page['key'];
            $names = self::names($sf, $type, $key);
            if ($names === null) {
                $this->warn("skip {$id} (the shop has no such page now)");

                continue;
            }
            $shop = SeoSeedTextsCommand::SHOP_NAMES[$page['shop']] ?? ['en' => $page['shop'], 'ar' => $page['shop']];
            $facts = $type === 'landing' ? ['en' => '', 'ar' => ''] : SeoFacts::section($page['shop'], $type, $key, $shop, $names);
            $byLocale = [];
            foreach (['en', 'ar'] as $locale) {
                $text = $page[$locale];
                $byLocale[$locale] = [
                    'intro' => str_replace('{shop}', $shop[$locale], $text['intro']),
                    'description' => SeoFacts::fill(str_replace('{shop}', $shop[$locale], $text['body']), $facts[$locale]),
                    'meta_description' => str_replace('{shop}', $shop[$locale], $text['meta']),
                ];
            }
            $this->line(($apply ? 'load   ' : 'would load ')."{$id}");
            if ($apply) {
                SeoTexts::save($sf, $type, $key, $byLocale, null, "{$type} {$key} — seo:load-copy");
                $touched[$sf] = true;
            }
            SitePaths::forget();
            $result = $gate->checkPage($sf, $type, $key, $byLocale['en'], $byLocale['ar'], QualityGate::scopeOf($sf, $type, $key));
            foreach (['en', 'ar'] as $locale) {
                $r = $result[$locale];
                if ($apply) {
                    SeoTexts::recordGate($sf, $type, $key, $locale, $r['passed'] ? SeoTexts::PASSED : SeoTexts::HELD, $r['reasons']);
                }
                $stats = implode(', ', array_map(fn (string $k, int $v): string => "{$k} {$v}", array_keys($r['stats']), $r['stats']));
                $this->line(sprintf('       %s %s (%s)%s', $locale, $r['passed'] ? 'PASSED' : 'HELD', $stats, $r['passed'] ? '' : ' — '.implode('; ', $r['reasons'])));
            }
        }
        foreach (array_keys($touched) as $sf) {
            $cache->flush($sf);
        }

        return self::SUCCESS;
    }

    /** @return array{intro: string, meta: string, body: string} */
    private static function read(string $path): array
    {
        $raw = str_replace("\r", '', (string) file_get_contents($path));
        $front = [];
        if (preg_match('/^---\n(.*?)\n---\n(.*)$/s', $raw, $m) === 1) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^(intro|meta):\s*(.*)$/', $line, $f) === 1) {
                    $front[$f[1]] = trim($f[2]);
                }
            }
            $raw = $m[2];
        }

        return ['intro' => $front['intro'] ?? '', 'meta' => $front['meta'] ?? '', 'body' => trim($raw)];
    }

    /** @return array{en: string, ar: string}|null the page's names, when the shop has the page */
    private static function names(int $sf, string $type, string $key): ?array
    {
        $list = match ($type) {
            'brand' => SeoTextController::brands($sf),
            'landing' => SeoTextController::landings($sf),
            default => SeoTextController::categories($sf),
        };
        foreach ($list as $page) {
            if ($page['key'] === $key) {
                return $page['names'];
            }
        }

        return null;
    }
}
