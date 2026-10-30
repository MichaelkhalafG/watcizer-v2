<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\BlogWriter;
use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Load the written articles (2026-10-30, SEO item 5) from `database/seeders/content/blog/{storefront}/{slug}/{en,ar}.md`
 * into `core_blogs`, through the dashboard's own `BlogWriter`.
 *
 * Each file opens with a front-matter block (`---` … `---`) of `key: value` lines — `title`, `meta_title`,
 * `meta_description`, `target_query`, and a `sources:` list kept for the record (never stored on the article). The
 * rest of the file is the Markdown body.
 *
 * Michael's rule: Watchizer's articles are PUBLISHED; Brand Fashion's are DRAFTS until brandfashionegy.com is live.
 * `--publish` / `--draft` override it per run. Without `--apply` nothing is written. An article whose slug already
 * exists is reported and left alone (edit it on the dashboard). Both languages are required.
 */
final class BlogsSeedContentCommand extends Command
{
    /** @var array<string, bool> storefront code => published by default */
    public const PUBLISH_BY_DEFAULT = ['watchizer' => true, 'brandfashion' => false];

    protected $signature = 'blogs:seed-content
        {--storefront=watchizer : the storefront code (watchizer | brandfashion)}
        {--publish : publish them, whatever the storefront\'s default}
        {--draft : keep them as drafts, whatever the storefront\'s default}
        {--apply : write them; without it nothing is written}';

    protected $description = 'Load the prepared AR+EN articles for a storefront (Watchizer published, Brand Fashion drafts); skips existing slugs';

    public function handle(BlogWriter $writer): int
    {
        $code = Coerce::str($this->option('storefront'));
        $storefront = Coerce::nint(DB::table('storefronts')->where('code', $code)->value('id'));
        if ($storefront === null) {
            $this->error('No storefront with that code.');

            return self::FAILURE;
        }
        $publish = $this->option('publish') ? true : ($this->option('draft') ? false : (self::PUBLISH_BY_DEFAULT[$code] ?? false));
        $dirs = glob(database_path('seeders/content/blog/'.$code.'/*'), GLOB_ONLYDIR) ?: [];
        sort($dirs);
        $apply = (bool) $this->option('apply');
        $added = 0;

        foreach ($dirs as $dir) {
            $slug = basename($dir);
            $en = self::read($dir.'/en.md');
            $ar = self::read($dir.'/ar.md');
            if ($en === null || $ar === null) {
                $this->warn("skip   {$slug} (needs both en.md and ar.md)");

                continue;
            }
            if (DB::table('core_blogs')->where('slug', $slug)->exists()) {
                $this->line("skip   {$slug} (already there)");

                continue;
            }
            $this->line(($apply ? 'add    ' : 'would add ').$slug.($publish ? ' (published)' : ' (draft)'));
            if (! $apply) {
                continue;
            }
            try {
                $id = $writer->save(null, [
                    'storefront_id' => $storefront,
                    'slug' => $slug,
                    'is_published' => $publish,
                    'title' => ['ar' => $ar['meta']['title'] ?? '', 'en' => $en['meta']['title'] ?? ''],
                    'body' => ['ar' => $ar['body'], 'en' => $en['body']],
                    'meta_title' => ['ar' => $ar['meta']['meta_title'] ?? '', 'en' => $en['meta']['meta_title'] ?? ''],
                    'meta_description' => ['ar' => $ar['meta']['meta_description'] ?? '', 'en' => $en['meta']['meta_description'] ?? ''],
                ]);
            } catch (ValidationException $e) {
                $this->error($slug.': '.implode(' ', array_map(fn (mixed $m): string => Coerce::str(is_array($m) ? ($m[0] ?? '') : $m), $e->errors())));

                continue;
            }
            ActivityLog::record('core_blogs', $id, ActivityLog::CREATED, [],
                ['slug' => $slug, 'storefront_id' => $storefront, 'published' => $publish], 'article loaded: '.$slug, $storefront);
            $added++;
        }
        $this->info($apply
            ? "Added {$added} article(s) on {$code} as ".($publish ? 'published' : 'drafts').'.'
            : 'Nothing written. Run again with --apply.');

        return self::SUCCESS;
    }

    /**
     * The front matter (simple `key: value` lines; a `sources:` list is skipped) and the Markdown body.
     *
     * @return array{meta: array<string, string>, body: string}|null
     */
    public static function read(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }
        $text = str_replace("\r\n", "\n", (string) file_get_contents($path));
        if (! str_starts_with($text, "---\n")) {
            return ['meta' => [], 'body' => trim($text)];
        }
        $end = strpos($text, "\n---\n", 4);
        if ($end === false) {
            return null;
        }
        $meta = [];
        foreach (explode("\n", substr($text, 4, $end - 4)) as $line) {
            if (preg_match('/^([a-z_]+):\s*(.*)$/', $line, $m) === 1 && $m[2] !== '') {
                $meta[$m[1]] = trim($m[2]);
            }
        }

        return ['meta' => $meta, 'body' => trim(substr($text, $end + 5))];
    }
}
