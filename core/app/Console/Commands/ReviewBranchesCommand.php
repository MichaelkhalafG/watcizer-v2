<?php

namespace App\Console\Commands;

use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * THE BRANCHES' GOOGLE REVIEW LINKS (Michael, 2026-11-17): `reviews:branches [--apply] [--undo]`. WRITES
 * storefront_pickup_stores.google_review_url, review_key and whatsapp_review_template — with --apply only; a DRY RUN
 * otherwise.
 *
 * Each branch is found by a STABLE KEY — its shop's code and its English name (exactly one row must match) — never by
 * an id, so the step runs the same on any copy. A field the team has already filled is never overwritten. The Google
 * links are Google's own "write a review" page of each branch, by its place id, read from the map links Michael gave
 * (2026-11-17, read-only: each place id resolved back to the same place on Google Maps):
 *   Watchizer — Arkadia Mall           ChIJ3xVn1-BBWBQRVj0FxAW57Ts
 *   Brand Fashion — Arkadia Mall       ChIJ31-3VjZBWBQRwLR_XL9Stv0
 *   Brand Fashion — Heliopolis         ChIJAwftNAA_WBQRfmu-Av2YUwo
 * Watchizer's Heliopolis row gets nothing: no Google listing of its own was given (Michael: one button for Watchizer).
 *
 * BACKUP first (--apply): the three columns of each matched row, into bk_rel_20261118_branches, once. --undo restores.
 */
final class ReviewBranchesCommand extends Command
{
    public const BACKUP = 'bk_rel_20261118_branches';

    /** @var list<array{shop: string, name_en: string, key: string, place: string, template: string}> */
    public const BRANCHES = [
        [
            'shop' => 'watchizer', 'name_en' => 'Arkadia Mall', 'key' => 'arkadia', 'place' => 'ChIJ3xVn1-BBWBQRVj0FxAW57Ts',
            'template' => 'أهلًا، شكرًا لزيارتك واتشايزر في أركاديا مول. لو حابب تشاركنا رأيك في تجربتك معانا، تقدر تكتب مراجعة على جوجل من الرابط ده: {link} — رأيك يهمنا ويساعد غيرك يختار.', // i18n-exempt: storefront content - the staff's WhatsApp message to an in-store customer, Arabic (the team edits it on the dashboard)
        ],
        [
            'shop' => 'brandfashion', 'name_en' => 'Arkadia Mall', 'key' => 'arkadia', 'place' => 'ChIJ31-3VjZBWBQRwLR_XL9Stv0',
            'template' => 'أهلًا، شكرًا لزيارتك براند فاشون في أركاديا مول. لو حابب تشاركنا رأيك في تجربتك معانا، تقدر تكتب مراجعة على جوجل من الرابط ده: {link} — رأيك يهمنا ويساعد غيرك يختار.', // i18n-exempt: storefront content - as above
        ],
        [
            'shop' => 'brandfashion', 'name_en' => 'Heliopolis', 'key' => 'heliopolis', 'place' => 'ChIJAwftNAA_WBQRfmu-Av2YUwo',
            'template' => 'أهلًا، شكرًا لزيارتك براند فاشون في مصر الجديدة. لو حابب تشاركنا رأيك في تجربتك معانا، تقدر تكتب مراجعة على جوجل من الرابط ده: {link} — رأيك يهمنا ويساعد غيرك يختار.', // i18n-exempt: storefront content - as above
        ],
    ];

    protected $signature = 'reviews:branches {--apply : write (default: a dry run)} {--undo : restore the backed-up rows}';

    protected $description = "Set the branches' Google review links, in-store addresses and WhatsApp messages (dry run unless --apply)";

    public static function googleUrl(string $place): string
    {
        return 'https://search.google.com/local/writereview?placeid='.$place;
    }

    public function handle(): int
    {
        if (! DB::getSchemaBuilder()->hasColumn('storefront_pickup_stores', 'review_key')) {
            $this->error('The guest_reviews migration has not run here.');

            return self::FAILURE;
        }
        if ((bool) $this->option('undo')) {
            return $this->undo();
        }
        $apply = (bool) $this->option('apply');
        if ($apply && ! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            DB::statement('CREATE TABLE '.self::BACKUP.' (store_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, google_review_url VARCHAR(500) NULL, review_key VARCHAR(32) NULL, whatsapp_review_template TEXT NULL, created_at TIMESTAMP NULL)');
        }
        $set = 0;
        $problems = 0;
        $shops = [];
        foreach (self::BRANCHES as $b) {
            $sf = Coerce::int(DB::table('storefronts')->where('code', $b['shop'])->value('id'));
            $rows = $sf === 0 ? collect() : DB::table('storefront_pickup_stores')->where('storefront_id', $sf)->where('name_en', $b['name_en'])->get();
            if ($rows->count() !== 1) {
                $problems++;
                $this->line(sprintf('  STOP-ROW %s / %s: %d rows match (exactly one must) - nothing written for it', $b['shop'], $b['name_en'], $rows->count()));

                continue;
            }
            $r = Row::cast($rows->first());
            $id = Row::int($r, 'id');
            $want = ['google_review_url' => self::googleUrl($b['place']), 'review_key' => $b['key'], 'whatsapp_review_template' => $b['template']];
            $changes = [];
            foreach ($want as $col => $value) {
                $now = Row::nstr($r, $col);
                if ($now === null || $now === '') {
                    $changes[$col] = $value;
                } elseif ($now !== $value) {
                    $this->line(sprintf('  KEEP %s / %s %s: the team set "%s"', $b['shop'], $b['name_en'], $col, mb_substr($now, 0, 60)));
                }
            }
            if ($changes === []) {
                $this->line(sprintf('  OK   %s / %s (row %d): already set', $b['shop'], $b['name_en'], $id));

                continue;
            }
            $this->line(sprintf('  %s %s / %s (row %d): %s', $apply ? 'SET ' : 'WOULD', $b['shop'], $b['name_en'], $id, implode(', ', array_keys($changes))));
            if ($apply) {
                DB::statement('INSERT IGNORE INTO '.self::BACKUP.' (store_id, google_review_url, review_key, whatsapp_review_template, created_at)
                    SELECT id, google_review_url, review_key, whatsapp_review_template, NOW() FROM storefront_pickup_stores WHERE id = ?', [$id]);
                DB::table('storefront_pickup_stores')->where('id', $id)->update($changes + ['updated_at' => now()]);
                $shops[$sf] = true;
            }
            $set++;
        }
        foreach (array_keys($shops) as $sf) {
            app(StorefrontCache::class)->flush($sf);
        }
        $this->info(sprintf('%s: %d branches %s, %d rows not found as expected.', $apply ? 'APPLIED' : 'DRY RUN', $set, $apply ? 'set' : 'would be set', $problems));

        return $problems > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function undo(): int
    {
        if (! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            $this->error('No backup table '.self::BACKUP.' - nothing to undo.');

            return self::FAILURE;
        }
        $n = 0;
        foreach (DB::table(self::BACKUP)->get() as $raw) {
            $r = Row::cast($raw);
            DB::table('storefront_pickup_stores')->where('id', Row::int($r, 'store_id'))->update([
                'google_review_url' => Row::nstr($r, 'google_review_url'), 'review_key' => Row::nstr($r, 'review_key'),
                'whatsapp_review_template' => Row::nstr($r, 'whatsapp_review_template'), 'updated_at' => now(),
            ]);
            $n++;
        }
        foreach (DB::table('storefronts')->pluck('id') as $sf) {
            app(StorefrontCache::class)->flush(Coerce::int($sf));
        }
        $this->info("UNDONE: {$n} branches put back as they were.");

        return self::SUCCESS;
    }
}
