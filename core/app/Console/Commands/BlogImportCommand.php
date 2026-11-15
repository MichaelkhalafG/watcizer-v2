<?php

namespace App\Console\Commands;

use App\Domain\Content\ArabicSlug;
use App\Domain\Content\BlogSafety;
use App\Domain\Content\QualityGate;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * IMPORT A BATCH OF ARTICLES (SEO Phase 3 item 5, 2026-11-14): `blog:import <shop> <folder> --start=YYYY-MM-DD`.
 *
 * The folder holds EN + AR pairs, `<key>.en.md` and `<key>.ar.md`, each a Markdown body under a front matter:
 *
 *   ---
 *   slug: tommy-hilfiger-mens-watches-guide       (English file: the English address; Arabic file: the Arabic one)
 *   title: …
 *   meta_title: …
 *   meta_description: …
 *   cover: /Uploads_Images/Product_image/….webp   (one of our own product photos)
 *   topic: buying-guide                           (English file only, like everything below)
 *   cluster: tommy-hilfiger
 *   brand: tommy-hilfiger
 *   family: watch
 *   gender: men
 *   tags: tommy hilfiger, men's watches
 *   priority: 1                                   (lower first)
 *   ---
 *
 * Every pair goes through the quality gate (QualityGate::checkArticle). A pair that PASSES in both languages is
 * SCHEDULED: one article per shop per day at --time (Cairo time, default 10:00), from --start or the day after the
 * shop's last scheduled article, whichever is later, never more than two of one cluster in a row. Stored in the app's
 * time zone (UTC on production), shown in Cairo time. A pair that fails is saved HELD with its reasons (the dashboard
 * lists them). Nothing is published here: blog:publish-due does that when the time comes.
 *
 * Safe to re-run: an article already in core by its slug is updated and re-checked while it is not published yet, and
 * left alone once published. DRY RUN by default: `--apply` writes.
 */
final class BlogImportCommand extends Command
{
    protected $signature = 'blog:import {shop : the storefront code} {folder : the batch folder} {--start= : the first publication day, Cairo date YYYY-MM-DD (the go-live date)} {--time=10:00 : the publication time, Cairo} {--apply}';

    protected $description = 'Load a batch of EN + AR articles, run the quality gate, and schedule what passes (one per day)';

    public function handle(QualityGate $gate): int
    {
        $sf = Coerce::int(DB::table('storefronts')->where('code', Coerce::str($this->argument('shop')))->value('id'));
        $folder = Coerce::str($this->argument('folder'));
        $folder = is_dir($folder) ? $folder : database_path('seeders/content/articles/'.$folder);
        if ($sf === 0 || ! is_dir($folder)) {
            $this->error('No such shop, or no such folder: '.$folder);

            return self::FAILURE;
        }
        $start = Coerce::str($this->option('start'));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) !== 1) {
            $this->error('--start=YYYY-MM-DD is required: the first publication day (Cairo), the go-live date.');

            return self::FAILURE;
        }
        $time = Coerce::str($this->option('time')) ?: '10:00';
        $apply = (bool) $this->option('apply');

        $pairs = self::pairs($folder);
        $passed = [];
        $held = [];
        foreach ($pairs as $key => $pair) {
            $en = $pair['en'] ?? null;
            $ar = $pair['ar'] ?? null;
            if ($en === null || $ar === null) {
                $this->warn("{$key}: a language is missing — skipped");

                continue;
            }
            $meta = $en['meta'];
            $brandSlug = $meta['brand'] ?? '';
            $brandId = $brandSlug === '' ? null : (Coerce::int(DB::table('catalog_brands')->where('slug', $brandSlug)->value('id')) ?: null);
            $gender = strtolower($meta['gender'] ?? '');
            $genderId = $gender === '' ? null
                : (Coerce::int(DB::table('catalog_gender_translations')->where('locale', 'en')->whereRaw('LOWER(TRIM(name)) = ?', [$gender])->value('gender_id')) ?: null);
            $family = ($meta['family'] ?? '') === '' ? null : $meta['family'];
            $slug = ($meta['slug'] ?? '') ?: $key;
            $report = $gate->checkArticle($sf, $slug, self::text($en), self::text($ar), ['brand_id' => $brandId, 'family' => $family, 'gender_id' => $genderId]);
            $row = ['key' => $key, 'slug' => $slug, 'brand_id' => $brandId, 'family' => $family, 'report' => (string) json_encode($report, JSON_UNESCAPED_UNICODE),
                'priority' => Coerce::int($meta['priority'] ?? 5), 'cluster' => ($meta['cluster'] ?? '') ?: ($brandSlug ?: $key)];
            if ($report['en']['passed'] && $report['ar']['passed']) {
                $passed[] = $row;
                $this->line(sprintf('PASS %s (EN %d words, AR %d words, similarity %d%%/%d%%)', $slug, $report['en']['stats']['words'] ?? 0, $report['ar']['stats']['words'] ?? 0,
                    $report['en']['stats']['similarity_pct'] ?? 0, $report['ar']['stats']['similarity_pct'] ?? 0));
            } else {
                $held[] = $row;
                $this->warn('HELD '.$slug);
                foreach (['en', 'ar'] as $l) {
                    foreach ($report[$l]['reasons'] as $r) {
                        $this->line("   {$l}: {$r}");
                    }
                }
            }
        }

        // the order: priority, then the folder's order — then no more than two of one cluster in a row
        usort($passed, fn (array $a, array $b): int => [$a['priority'], $a['key']] <=> [$b['priority'], $b['key']]);
        $passed = self::spread($passed);
        $first = Carbon::parse($start.' '.$time, 'Africa/Cairo');
        $last = DB::table('core_blogs')->where('storefront_id', $sf)->where('status', 'scheduled')->max('publish_at');
        if (is_string($last)) {
            $after = Carbon::parse($last, config()->string('app.timezone'))->setTimezone('Africa/Cairo')->addDay()->setTimeFromTimeString($time);
            $first = $after->greaterThan($first) ? $after : $first;
        }
        $day = $first->copy();
        $schedule = [];
        foreach ($passed as $row) {
            $schedule[] = ['row' => $row, 'status' => 'scheduled', 'at' => $day->copy()->setTimezone(config()->string('app.timezone'))->format('Y-m-d H:i:s')];
            $this->line(sprintf('  %s Cairo  %s', $day->format('Y-m-d H:i'), $row['slug']));
            $day->addDay();
        }
        foreach ($held as $row) {
            $schedule[] = ['row' => $row, 'status' => 'held', 'at' => null];
        }
        $this->info(sprintf('%d passed and scheduled (%s → %s, Cairo), %d held.', count($passed),
            $passed === [] ? '-' : $first->format('Y-m-d'), $passed === [] ? '-' : $day->copy()->subDay()->format('Y-m-d'), count($held)));
        if (! $apply) {
            $this->comment('DRY RUN — nothing written. Re-run with --apply.');

            return self::SUCCESS;
        }

        foreach ($schedule as $item) {
            $row = $item['row'];
            $en = $pairs[$row['key']]['en'] ?? null;
            $ar = $pairs[$row['key']]['ar'] ?? null;
            if ($en === null || $ar === null) {
                continue;
            }
            $found = DB::table('core_blogs')->where('storefront_id', $sf)->where('slug', $row['slug'])->first(['id', 'published_at']);
            $existing = $found === null ? null : Row::cast($found);
            $id = $existing === null ? null : Row::int($existing, 'id');
            if ($existing !== null && Row::nstr($existing, 'published_at') !== null) {
                $this->line("  {$row['slug']}: already published — left alone");

                continue;
            }
            // written directly, not through BlogWriter::save: an unpublished article is in no sitemap, so the sixty
            // sitemap rebuilds a batch would cost through the writer are skipped (the publisher rebuilds on publication)
            $id = DB::transaction(function () use ($id, $sf, $row, $en, $ar): int {
                $fields = ['storefront_id' => $sf, 'slug' => $row['slug'], 'cover_path' => ($en['meta']['cover'] ?? '') ?: null, 'body_format' => 'markdown', 'updated_at' => now(),
                    'slug_ar' => ArabicSlug::unique($sf, ($ar['meta']['slug'] ?? '') ?: ($ar['meta']['title'] ?? ''), $id) ?: null];
                if ($id === null) {
                    $id = (int) DB::table('core_blogs')->insertGetId($fields + ['created_at' => now(), 'published_at' => null]);
                } else {
                    DB::table('core_blogs')->where('id', $id)->update($fields);
                }
                foreach (['en' => $en, 'ar' => $ar] as $locale => $f) {
                    DB::table('core_blog_translations')->updateOrInsert(['blog_id' => $id, 'locale' => $locale], self::text($f) + ['updated_at' => now(), 'created_at' => now()]);
                }

                return $id;
            });
            DB::table('core_blogs')->where('id', $id)->update([
                'status' => $item['status'],
                'publish_at' => $item['at'],
                'topic' => mb_substr($en['meta']['topic'] ?? '', 0, 64) ?: null,
                'brand_id' => $row['brand_id'],
                'family' => $row['family'],
                'tags' => json_encode(array_values(array_filter(array_map('trim', explode(',', $en['meta']['tags'] ?? ''))))),
                'gate_report' => $row['report'],
                'gate_checked_at' => now(),
                'publish_attempts' => 0,
                'publish_error' => null,
            ]);
        }
        // the safety net (SEO Phase 5): too large a share held → this shop's publishing pauses, and the team is told
        if (BlogSafety::afterImport($sf, count($passed), count($held))) {
            $this->warn('More than the allowed share of this batch was held: publishing is PAUSED for this shop (BlogSafety).');
        }
        $this->info('written.');

        return self::SUCCESS;
    }

    /**
     * `<key>.<en|ar>.md` files of a folder, by key, in file-name order.
     *
     * @return array<string, array<string, array{meta: array<string, string>, body: string}>>
     */
    private static function pairs(string $folder): array
    {
        $out = [];
        $files = glob(rtrim($folder, '/\\').'/*.md') ?: [];
        sort($files);
        foreach ($files as $path) {
            if (preg_match('/^(.+)\.(en|ar)\.md$/', basename($path), $m) === 1) {
                $out[$m[1]][$m[2]] = self::read($path);
            }
        }

        return $out;
    }

    /**
     * @param  array{meta: array<string, string>, body: string}  $f
     * @return array{title: string, meta_title: string, meta_description: string, body: string}
     */
    private static function text(array $f): array
    {
        return ['title' => $f['meta']['title'] ?? '', 'meta_title' => $f['meta']['meta_title'] ?? '', 'meta_description' => $f['meta']['meta_description'] ?? '', 'body' => $f['body']];
    }

    /** @return array{meta: array<string, string>, body: string} */
    public static function read(string $path): array
    {
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($path));
        $meta = [];
        if (preg_match('/\A---\n(.*?)\n---\n/s', $raw, $m) === 1) {
            foreach (explode("\n", $m[1]) as $line) {
                if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $kv) === 1) {
                    $meta[$kv[1]] = trim($kv[2], " \t\"'");
                }
            }
            $raw = substr($raw, strlen($m[0]));
        }

        return ['meta' => $meta, 'body' => trim($raw)."\n"];
    }

    /**
     * Reorder so that no more than two articles of one cluster follow each other, keeping the order otherwise.
     *
     * @template T of array{cluster: string}
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    public static function spread(array $rows): array
    {
        $out = [];
        while ($rows !== []) {
            $n = count($out);
            $pick = 0;
            if ($n >= 2 && $out[$n - 1]['cluster'] === $out[$n - 2]['cluster']) {
                foreach ($rows as $i => $r) {
                    if ($r['cluster'] !== $out[$n - 1]['cluster']) {
                        $pick = $i;

                        break;
                    }
                }
            }
            $out[] = $rows[$pick];
            array_splice($rows, $pick, 1);
        }

        return $out;
    }
}
