<?php

namespace App\Console\Commands;

use App\Domain\Content\SeoFacts;
use App\Domain\Content\SeoTextStructure;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * seo:structure-check — every stored page text, and whether the edit page shows it as FIELDS (item 43 B, 2026-11-05).
 *
 * The page texts were not migrated to a new storage: a text is still one Markdown field per language, and the edit
 * page shows it as its parts (intro, "<name> at <shop>" with the catalogue's facts, the other sections with their
 * points, the questions) whenever the text is in that shape — which `SeoTextStructure::parse()` proves by compiling
 * the parts back into the SAME text. This command is that proof for every page, before anybody opens the form:
 *
 *   fields    the text is in the shape; saving it untouched from the form writes the same words
 *   markdown  the text is not in the shape (somebody wrote it freely): the form keeps the Markdown editor for it
 *
 * and, for a page shown as fields, how many of its fact lines differ from what the catalogue would write today
 * (`facts.json`) — those lines are refreshed the next time the page is saved from the form, and only then.
 *
 * It reads and prints. It writes nothing, so there is nothing to restore.
 *
 *   php artisan seo:structure-check
 *   php artisan seo:structure-check --storefront=brandfashion --all     (list every page, not only the exceptions)
 */
final class SeoStructureCheckCommand extends Command
{
    protected $signature = 'seo:structure-check {--storefront= : one storefront code; all of them when left out} {--all : list every page, not only the exceptions}';

    protected $description = 'Report, for every stored page text, whether the edit page shows it as fields (writes nothing).';

    public function handle(): int
    {
        $storefronts = DB::table('storefronts')->when($this->option('storefront'), fn ($q, $code) => $q->where('code', $code))->orderBy('id')->get(['id', 'code', 'name']);
        if ($storefronts->isEmpty()) {
            $this->error('No storefront with that code.');

            return self::FAILURE;
        }

        foreach ($storefronts as $storefront) {
            $code = Row::str($storefront, 'code');
            $shop = SeoSeedTextsCommand::SHOP_NAMES[$code] ?? ['en' => Row::str($storefront, 'name'), 'ar' => Row::str($storefront, 'name')];
            $fields = 0;
            $markdown = 0;
            $empty = 0;
            $stale = 0;
            $this->line("== {$code}");
            foreach (DB::table('core_seo_texts')->where('storefront_id', Row::int($storefront, 'id'))->orderBy('subject_type')->orderBy('subject_key')->orderBy('locale')->get() as $row) {
                $type = Row::str($row, 'subject_type');
                $key = Row::str($row, 'subject_key');
                $locale = Row::str($row, 'locale');
                $text = Row::nstr($row, 'description') ?? '';
                if (trim($text) === '') {
                    $empty++;

                    continue;
                }
                $parsed = SeoTextStructure::parse($text);
                if ($parsed === null) {
                    $markdown++;
                    $this->warn("  markdown  {$type} {$key} [{$locale}] — not in the fields' shape; edited as Markdown, unchanged");

                    continue;
                }
                $fields++;
                // the facts the catalogue would write for this page today, against the ones the text carries
                $generated = [];
                foreach (SeoTextStructure::generated(SeoFacts::section($code, $type, $key, $shop, ['en' => '', 'ar' => ''])[$locale === 'ar' ? 'ar' : 'en'])['facts'] as $fact) {
                    $generated[$fact['label']] = $fact['line'];
                }
                $differ = 0;
                foreach ($parsed['at']['facts'] ?? [] as $fact) {
                    $differ += isset($generated[$fact['label']]) && $generated[$fact['label']] !== $fact['line'] ? 1 : 0;
                }
                $stale += $differ > 0 ? 1 : 0;
                if ($this->option('all') || $differ > 0) {
                    $this->line(sprintf('  fields    %s %s [%s] — intro %s, facts %d%s, sections %d, questions %s',
                        $type, $key, $locale, $parsed['intro'] === '' ? 'no' : 'yes', count($parsed['at']['facts'] ?? []),
                        $differ > 0 ? " ({$differ} differ from the catalogue's numbers today)" : '', count($parsed['sections']), $parsed['faq'] === null ? 'no' : 'yes'));
                }
            }
            $this->info(sprintf('  %d texts shown as fields (%d of them carry a number the catalogue would write differently today), %d stay Markdown, %d empty. Nothing was written.', $fields, $stale, $markdown, $empty));
        }

        return self::SUCCESS;
    }
}
