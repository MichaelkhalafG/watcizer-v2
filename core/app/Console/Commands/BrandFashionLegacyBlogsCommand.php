<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Import\BrandFashion\LegacyBlogs;
use App\Support\Coerce;
use App\Support\WriteTarget;
use Illuminate\Console\Command;

/**
 * `php artisan bf:legacy-blogs <extract dir> --images=<uploads>` — the old site's articles on Brand
 * Fashion, cleaned (2026-11-11, D-13). Details: {@see LegacyBlogs}. Run AFTER `bf:legacy-redirects`
 * (its links are rewritten through that map).
 *
 * WRITES only with `--apply`: `core_blogs` + translations through `BlogWriter`, images through
 * `MediaStore` (Banner_home), one `storefront_redirects` row per article (source `bf_legacy`), one
 * activity row each. Undo: delete the articles on the dashboard's Blogs screen, then
 * `DELETE FROM storefront_redirects WHERE storefront_id = 2 AND source = 'bf_legacy' AND to_path LIKE '/blog/%'`.
 */
final class BrandFashionLegacyBlogsCommand extends Command
{
    protected $signature = 'bf:legacy-blogs
        {extract : the bf:legacy-extract folder (site.json)}
        {--images= : the folder holding the extracted uploads}
        {--arabic= : the translation file (JSON keyed by the OLD slug: title, meta_title, meta_description, body_html) — O-1}
        {--apply : write (default is a dry run)}
        {--allow-remote : required to write anywhere but a local copy}';

    protected $description = 'Brand Fashion: migrate the old site\'s blog posts, cleaned (dry run by default)';

    public function handle(LegacyBlogs $blogs): int
    {
        $dir = rtrim(str_replace('\\', '/', Coerce::str($this->argument('extract'))), '/');
        if (! is_file($dir.'/site.json')) {
            $this->error("{$dir}/site.json not found (bf:legacy-extract).");

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        if ($apply && ! (bool) $this->option(WriteTarget::ALLOW_REMOTE)) {
            $remote = WriteTarget::remote();
            if ($remote !== []) {
                WriteTarget::refuse($this, 'bf:legacy-blogs', $remote, ['articles on Brand Fashion, their images, one redirect each'], ['run it on a local copy', 'or pass --allow-remote']);

                return self::FAILURE;
            }
        }
        $images = Coerce::str($this->option('images'));
        $root = $images === '' ? null : rtrim(str_replace('\\', '/', $images), '/');

        $site = Coerce::arr(json_decode((string) file_get_contents($dir.'/site.json'), true, 512, JSON_THROW_ON_ERROR));
        $arabicFile = Coerce::str($this->option('arabic'));
        $arabic = [];
        if ($arabicFile !== '') {
            if (! is_file($arabicFile)) {
                $this->error("No such file: {$arabicFile}");

                return self::FAILURE;
            }
            $arabic = Coerce::arr(json_decode((string) file_get_contents($arabicFile), true, 512, JSON_THROW_ON_ERROR));
            $slugs = array_map(fn (mixed $p): string => Coerce::str(Coerce::arr($p)['slug'] ?? null), Coerce::arr($site['posts'] ?? null));
            foreach ($slugs as $slug) {
                $entry = Coerce::arr($arabic[$slug] ?? null);
                foreach (['title', 'meta_title', 'meta_description', 'body_html'] as $field) {
                    if (trim(Coerce::str($entry[$field] ?? null)) === '') {
                        $this->error("{$arabicFile}: /{$slug} has no {$field} — refusing to publish half an article.");

                        return self::FAILURE;
                    }
                }
            }
            foreach (array_diff(array_keys($arabic), $slugs) as $extra) {
                $this->warn("  {$arabicFile}: /{$extra} is not one of the old site's posts — ignored");
            }
        }
        $this->info('bf:legacy-blogs — '.($apply ? 'APPLY' : 'DRY RUN (nothing is written)').($arabic === [] ? '' : ' — with the Arabic of '.$arabicFile));
        foreach (Coerce::arr($site['posts'] ?? null) as $p) {
            $post = Coerce::arr($p);
            $r = $blogs->migrate($post, $root, $apply, 'Brand Fashion', Coerce::arr($arabic[Coerce::str($post['slug'] ?? null)] ?? null));
            $this->line(sprintf('  %-8s /%s → /blog/%s — %d links rewritten, %d images, %d missing',
                $r['action'], Coerce::str(Coerce::arr($p)['slug'] ?? null), $r['slug'], $r['links'], $r['images'], $r['images_missing']));
        }
        foreach ($blogs->notes() as $note) {
            $this->warn('  '.$note);
        }

        return self::SUCCESS;
    }
}
