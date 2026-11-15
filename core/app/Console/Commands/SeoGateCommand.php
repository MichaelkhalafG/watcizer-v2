<?php

namespace App\Console\Commands;

use App\Domain\Content\QualityGate;
use App\Domain\Content\SeoTexts;
use App\Domain\Content\SitePaths;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * THE QUALITY GATE over page texts (SEO Phase 2, 2026-11-14): brand, category and landing texts, both languages of each
 * checked together, the verdict recorded per language (passed / held, with the reasons) — a landing page is indexable
 * only when both languages passed. `--dry-run` checks and prints without recording. Flushes the storefront once at the
 * end so the pages and the sitemaps follow.
 */
final class SeoGateCommand extends Command
{
    protected $signature = 'seo:gate {--storefront=* : storefront ids (default: all)} {--type=* : brand, category, landing (default: all)} {--key= : one page} {--dry-run}';

    protected $description = 'Run the automated quality gate over brand, category and landing page texts';

    public function handle(QualityGate $gate, StorefrontCache $cache): int
    {
        SitePaths::forget();
        $storefronts = array_values(array_map(fn (mixed $v): int => Coerce::int($v), Coerce::arr($this->option('storefront'))));
        $asked = array_values(array_map(fn (mixed $v): string => Coerce::str($v), Coerce::arr($this->option('type'))));
        $types = array_values(array_intersect($asked === [] ? SeoTexts::TYPES : $asked, SeoTexts::TYPES));
        $q = DB::table(SeoTexts::TABLE)->whereIn('subject_type', $types)->select(['storefront_id', 'subject_type', 'subject_key'])->distinct();
        if ($storefronts !== []) {
            $q->whereIn('storefront_id', $storefronts);
        }
        if (is_string($this->option('key')) && $this->option('key') !== '') {
            $q->where('subject_key', $this->option('key'));
        }
        $dry = (bool) $this->option('dry-run');
        $passed = 0;
        $held = 0;
        $touched = [];
        foreach ($q->orderBy('storefront_id')->orderBy('subject_type')->orderBy('subject_key')->get() as $page) {
            $sf = Row::int($page, 'storefront_id');
            $type = Row::str($page, 'subject_type');
            $key = Row::str($page, 'subject_key');
            $texts = SeoTexts::bilingual($sf, $type, $key);
            $result = $gate->checkPage($sf, $type, $key, $texts['en'] ?? null, $texts['ar'] ?? null, QualityGate::scopeOf($sf, $type, $key));
            foreach (['en', 'ar'] as $locale) {
                $r = $result[$locale];
                $verdict = $r['passed'] ? SeoTexts::PASSED : SeoTexts::HELD;
                $r['passed'] ? $passed++ : $held++;
                if (! $dry && ($texts[$locale] ?? null) !== null) {
                    SeoTexts::recordGate($sf, $type, $key, $locale, $verdict, $r['reasons']);
                }
                $this->line(sprintf('storefront %d %s %s %s: %s%s', $sf, $type, $key, $locale, strtoupper($verdict),
                    $r['passed'] ? '' : ' — '.implode('; ', $r['reasons'])));
            }
            $touched[$sf] = true;
        }
        if (! $dry) {
            foreach (array_keys($touched) as $sf) {
                $cache->flush($sf);
            }
        }
        $this->line(sprintf('%d passed, %d held%s', $passed, $held, $dry ? ' (dry run: nothing recorded)' : ''));

        return self::SUCCESS;
    }
}
