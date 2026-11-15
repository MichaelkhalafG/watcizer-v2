<?php

namespace App\Console\Commands;

use App\Domain\Content\ArabicSlug;
use App\Domain\Content\BlogWriter;
use App\Domain\Content\QualityGate;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * UPDATE THE SHOPS' EXISTING, PUBLISHED ARTICLES (SEO Phase 4 item 6, 2026-11-15):
 * `blog:update-published <shop> <folder> [--apply | --undo]`.
 *
 * The brief: "Watchizer's 6 get Arabic versions and data blocks; Brand Fashion's 11 imported ones get updated, linked
 * and enriched to the same standard, through the same gate." blog:import leaves a published article alone, so the
 * rewritten versions come through here instead. The folder holds `<key>.en.md` / `<key>.ar.md` pairs in blog:import's
 * format; the English file's `slug:` names the PUBLISHED article it replaces, and that address never changes.
 *
 * For each pair: the article must be published on that shop (anything else is skipped and reported); the pair goes
 * through the same QualityGate::checkArticle as a new article; only a pair that PASSES in both languages is written.
 * A held pair leaves the live article exactly as it is. Writing goes through BlogWriter::save — the dashboard's own
 * path — so the publication date stays, dateModified is set by a real change of words, and the sitemaps and the
 * IndexNow queue follow. The Arabic address is kept when the article has one; otherwise it is made from the Arabic
 * file's `slug:` (or title), once.
 *
 * BACKUP first (--apply): every replaced article's row and both translations, into bk_seo_20261115_blog_update, once
 * per article — a second run never overwrites it. --undo puts every backed-up article back byte for byte and rebuilds
 * the sitemaps. DRY RUN by default.
 */
final class BlogUpdatePublishedCommand extends Command
{
    private const BACKUP = 'bk_seo_20261115_blog_update';

    /** The article columns the backup keeps and --undo restores. */
    private const BLOG_COLUMNS = ['slug_ar', 'cover_path', 'body_format', 'modified_at', 'updated_at', 'topic', 'brand_id', 'family', 'tags', 'gate_report', 'gate_checked_at'];

    protected $signature = 'blog:update-published {shop : the storefront code} {folder : the folder of rewritten articles} {--apply} {--undo}';

    protected $description = 'Replace published articles with rewritten EN + AR versions that pass the quality gate (backup first; --undo restores)';

    public function handle(QualityGate $gate, BlogWriter $writer): int
    {
        $sf = Coerce::int(DB::table('storefronts')->where('code', Coerce::str($this->argument('shop')))->value('id'));
        if ($sf === 0) {
            $this->error('No such shop.');

            return self::FAILURE;
        }
        if ((bool) $this->option('undo')) {
            return $this->undo($sf);
        }
        $folder = Coerce::str($this->argument('folder'));
        $folder = is_dir($folder) ? $folder : database_path('seeders/content/articles/'.$folder);
        if (! is_dir($folder)) {
            $this->error('No such folder: '.$folder);

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        $pairs = [];
        $files = glob(rtrim($folder, '/\\').'/*.md') ?: [];
        sort($files);
        foreach ($files as $path) {
            if (preg_match('/^(.+)\.(en|ar)\.md$/', basename($path), $m) === 1) {
                $pairs[$m[1]][$m[2]] = BlogImportCommand::read($path);
            }
        }

        $passed = [];
        $held = 0;
        $skipped = 0;
        foreach ($pairs as $key => $pair) {
            $en = $pair['en'] ?? null;
            $ar = $pair['ar'] ?? null;
            if ($en === null || $ar === null) {
                $this->warn("{$key}: a language is missing — skipped");
                $skipped++;

                continue;
            }
            $slug = Coerce::str($en['meta']['slug'] ?? '');
            $found = $slug === '' ? null : DB::table('core_blogs')->where('storefront_id', $sf)->where('slug', $slug)->first(['id', 'published_at']);
            $row = $found === null ? null : Row::cast($found);
            if ($row === null || Row::nstr($row, 'published_at') === null) {
                $this->warn("SKIP {$key}: no published article with the slug [{$slug}] on this shop — new articles go through blog:import");
                $skipped++;

                continue;
            }
            $meta = $en['meta'];
            $brandSlug = $meta['brand'] ?? '';
            $brandId = $brandSlug === '' ? null : (Coerce::int(DB::table('catalog_brands')->where('slug', $brandSlug)->value('id')) ?: null);
            $gender = strtolower($meta['gender'] ?? '');
            $genderId = $gender === '' ? null
                : (Coerce::int(DB::table('catalog_gender_translations')->where('locale', 'en')->whereRaw('LOWER(TRIM(name)) = ?', [$gender])->value('gender_id')) ?: null);
            $family = ($meta['family'] ?? '') === '' ? null : $meta['family'];
            $report = $gate->checkArticle($sf, $slug, self::text($en), self::text($ar), ['brand_id' => $brandId, 'family' => $family, 'gender_id' => $genderId]);
            if ($report['en']['passed'] && $report['ar']['passed']) {
                $this->line(sprintf('PASS %s (EN %d words, AR %d words, similarity %d%%/%d%%)', $slug, $report['en']['stats']['words'] ?? 0, $report['ar']['stats']['words'] ?? 0,
                    $report['en']['stats']['similarity_pct'] ?? 0, $report['ar']['stats']['similarity_pct'] ?? 0));
                $passed[] = ['id' => Row::int($row, 'id'), 'slug' => $slug, 'en' => $en, 'ar' => $ar, 'brand_id' => $brandId, 'family' => $family, 'report' => (string) json_encode($report, JSON_UNESCAPED_UNICODE)];
            } else {
                $held++;
                $this->warn('HELD '.$slug.' — the live article stays as it is');
                foreach (['en', 'ar'] as $l) {
                    foreach ($report[$l]['reasons'] as $r) {
                        $this->line("   {$l}: {$r}");
                    }
                }
            }
        }
        $this->info(sprintf('%d passed, %d held, %d skipped.', count($passed), $held, $skipped));
        if (! $apply) {
            $this->comment('DRY RUN — nothing written. Re-run with --apply.');

            return self::SUCCESS;
        }
        if ($passed === []) {
            $this->info('nothing to write.');

            return self::SUCCESS;
        }

        $this->backup(array_column($passed, 'id'));
        foreach ($passed as $p) {
            $id = $p['id'];
            $storedAr = Coerce::str(DB::table('core_blogs')->where('id', $id)->value('slug_ar'));
            $typedAr = $storedAr !== '' ? $storedAr : (ArabicSlug::unique($sf, ($p['ar']['meta']['slug'] ?? '') ?: ($p['ar']['meta']['title'] ?? ''), $id) ?: '');
            $cover = Coerce::str($p['en']['meta']['cover'] ?? '') ?: Coerce::str(DB::table('core_blogs')->where('id', $id)->value('cover_path'));
            $en = self::text($p['en']);
            $ar = self::text($p['ar']);
            $writer->save($id, [
                'slug' => $p['slug'],
                'slug_ar' => $typedAr,
                'cover_path' => $cover === '' ? null : $cover,
                'is_published' => true,
                'storefront_id' => $sf,
                'title' => ['en' => $en['title'], 'ar' => $ar['title']],
                'body' => ['en' => $en['body'], 'ar' => $ar['body']],
                'meta_title' => ['en' => $en['meta_title'], 'ar' => $ar['meta_title']],
                'meta_description' => ['en' => $en['meta_description'], 'ar' => $ar['meta_description']],
            ]);
            DB::table('core_blogs')->where('id', $id)->update([
                'topic' => mb_substr($p['en']['meta']['topic'] ?? '', 0, 64) ?: null,
                'brand_id' => $p['brand_id'],
                'family' => $p['family'],
                'tags' => json_encode(array_values(array_filter(array_map('trim', explode(',', $p['en']['meta']['tags'] ?? ''))))),
                'gate_report' => $p['report'],
                'gate_checked_at' => now(),
            ]);
            $this->line("  updated {$p['slug']} (#{$id})");
        }
        $this->info('written. Backup: '.self::BACKUP.' — undo: php -d memory_limit=3072M artisan blog:update-published '.Coerce::str($this->argument('shop')).' - --undo');

        return self::SUCCESS;
    }

    /** @param list<int> $ids */
    private function backup(array $ids): void
    {
        DB::statement('CREATE TABLE IF NOT EXISTS '.self::BACKUP.' (blog_id BIGINT UNSIGNED NOT NULL, locale VARCHAR(8) NOT NULL, title TEXT NULL, meta_title TEXT NULL,'
            .' meta_description TEXT NULL, body LONGTEXT NULL, tr_created_at TIMESTAMP NULL, tr_updated_at TIMESTAMP NULL, blog_row LONGTEXT NULL,'
            .' backed_up_at TIMESTAMP NULL, PRIMARY KEY (blog_id, locale))');
        foreach ($ids as $id) {
            $blog = DB::table('core_blogs')->where('id', $id)->first(self::BLOG_COLUMNS);
            $rowJson = (string) json_encode($blog === null ? [] : (array) $blog, JSON_UNESCAPED_UNICODE);
            // the translation's own timestamps too: BlogWriter::save stamps created_at AND updated_at, so --undo puts both back
            foreach (DB::table('core_blog_translations')->where('blog_id', $id)
                ->get(['locale', 'title', 'meta_title', 'meta_description', 'body', 'created_at', 'updated_at']) as $raw) {
                $t = Row::cast($raw);
                DB::table(self::BACKUP)->insertOrIgnore([
                    'blog_id' => $id, 'locale' => Row::str($t, 'locale'), 'title' => Row::nstr($t, 'title'), 'meta_title' => Row::nstr($t, 'meta_title'),
                    'meta_description' => Row::nstr($t, 'meta_description'), 'body' => Row::nstr($t, 'body'),
                    'tr_created_at' => Row::nstr($t, 'created_at'), 'tr_updated_at' => Row::nstr($t, 'updated_at'), 'blog_row' => $rowJson, 'backed_up_at' => now(),
                ]);
            }
            // an article with no Arabic row yet: a marker row, so --undo knows to remove the Arabic this run adds
            if (! DB::table('core_blog_translations')->where('blog_id', $id)->where('locale', 'ar')->exists()) {
                DB::table(self::BACKUP)->insertOrIgnore(['blog_id' => $id, 'locale' => 'ar:none', 'blog_row' => $rowJson, 'backed_up_at' => now()]);
            }
        }
        $this->line('backup: '.DB::table(self::BACKUP)->count().' rows in '.self::BACKUP);
    }

    private function undo(int $sf): int
    {
        if (! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            $this->error('No backup table '.self::BACKUP.' — nothing was updated here.');

            return self::FAILURE;
        }
        $ids = DB::table(self::BACKUP.' as k')->join('core_blogs as b', 'b.id', '=', 'k.blog_id')->where('b.storefront_id', $sf)
            ->distinct()->pluck('k.blog_id')->map(fn ($v): int => Coerce::int($v))->all();
        foreach ($ids as $id) {
            DB::transaction(function () use ($id): void {
                foreach (DB::table(self::BACKUP)->where('blog_id', $id)->get() as $raw) {
                    $k = Row::cast($raw);
                    $locale = Row::str($k, 'locale');
                    if ($locale === 'ar:none') {
                        DB::table('core_blog_translations')->where('blog_id', $id)->where('locale', 'ar')->delete();
                    } else {
                        DB::table('core_blog_translations')->where('blog_id', $id)->where('locale', $locale)->update([
                            'title' => Row::nstr($k, 'title'), 'meta_title' => Row::nstr($k, 'meta_title'),
                            'meta_description' => Row::nstr($k, 'meta_description'), 'body' => Row::nstr($k, 'body'),
                            'created_at' => Row::nstr($k, 'tr_created_at'), 'updated_at' => Row::nstr($k, 'tr_updated_at'),
                        ]);
                    }
                    $blog = json_decode(Row::str($k, 'blog_row'), true);
                    if (is_array($blog) && $blog !== []) {
                        DB::table('core_blogs')->where('id', $id)->update(array_intersect_key($blog, array_flip(self::BLOG_COLUMNS)));
                    }
                }
            });
            BlogWriter::articleChanged($id);
            $this->line("  restored #{$id}");
        }
        $this->info(count($ids).' article(s) restored from '.self::BACKUP.'; the table stays until the deploy is closed.');

        return self::SUCCESS;
    }

    /**
     * @param  array{meta: array<string, string>, body: string}  $f
     * @return array{title: string, meta_title: string, meta_description: string, body: string}
     */
    private static function text(array $f): array
    {
        return ['title' => $f['meta']['title'] ?? '', 'meta_title' => $f['meta']['meta_title'] ?? '', 'meta_description' => $f['meta']['meta_description'] ?? '', 'body' => $f['body']];
    }
}
