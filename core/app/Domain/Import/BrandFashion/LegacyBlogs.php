<?php

declare(strict_types=1);

namespace App\Domain\Import\BrandFashion;

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\BlogWriter;
use App\Domain\Media\MediaStore;
use App\Models\Storefront\Storefront;
use App\Models\Storefront\StorefrontRedirect;
use App\Support\Coerce;
use App\Support\LegacySlug;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The old site's 11 blog posts → Brand Fashion's articles, cleaned (2026-11-11, D-13).
 *
 *   • both languages: the English body and title; the Arabic ones from a translation file
 *     (`--arabic`, O-1: the old site's Arabic fields are EMPTY on all 11 posts — the translation is
 *     ours, written for the reader, the same facts), else the old site's ACF fields;
 *   • the Arabic body is HTML with the OLD links and image addresses, so it goes through the very
 *     same cleaning, link re-pointing and image processing as the English;
 *   • the body as Markdown ({@see LegacyMarkdown}): HTML stripped to what the shop renders;
 *   • every link to the old shop rewritten to its NEW address — through `storefront_redirects`
 *     (so it is run AFTER `bf:legacy-redirects`), a kept product slug as it is, the old path
 *     otherwise; outside links kept;
 *   • every image re-processed from its ORIGINAL through `MediaStore` (type `banner`, the folder
 *     article covers already use) — a missing file drops the image and is reported, never a broken
 *     link to the old site;
 *   • the Rank Math title and description kept (variables expanded); the Arabic ones from the file;
 *   • the old address is `bf:legacy-redirects`'s: `/blog/<slug>` once the article is PUBLISHED,
 *     `/blogs` while it is a draft (the old posts have no Arabic, so they arrive as drafts);
 *   • the slug is the old one, except WordPress's numeric fallback (`18216-2`), which is rebuilt
 *     from the title;
 *   • the original publication date is kept, so the list does not present 2023's posts as new.
 *
 * Re-runnable: a post whose slug already exists on Brand Fashion is skipped.
 */
final class LegacyBlogs
{
    private int $storefront = Storefront::BRAND_FASHION_ID;

    /** @var array<string, string> source file → our /Uploads_Images path */
    private array $stored = [];

    /** @var list<string> */
    private array $notes = [];

    public function __construct(
        private readonly BlogWriter $blogs,
        private readonly MediaStore $media,
    ) {}

    /** @return list<string> */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @param  array<string, mixed>  $post  one `posts` entry of site.json
     * @param  array<string, mixed>  $arabic  this post's entry of the translation file: title, meta_title, meta_description, body_html
     * @return array{action: string, slug: string, id: int|null, links: int, images: int, images_missing: int}
     */
    public function migrate(array $post, ?string $imagesRoot, bool $apply, string $sitename = 'Brand Fashion', array $arabic = []): array
    {
        $oldSlug = Coerce::str($post['slug'] ?? null);
        $titleEn = LegacyText::title(Coerce::str($post['title'] ?? null));
        $titleAr = trim(Coerce::str($arabic['title'] ?? null)) ?: Coerce::str($post['title_ar'] ?? null);
        $slug = self::newSlug($oldSlug, $titleEn);

        if (DB::table('core_blogs')->where('slug', $slug)->exists()) {
            return ['action' => 'already', 'slug' => $slug, 'id' => null, 'links' => 0, 'images' => 0, 'images_missing' => 0];
        }

        $links = 0;
        $images = 0;
        $missing = 0;
        $linkMap = function (string $href) use (&$links): string {
            $to = $this->newHref($href);
            if ($to !== $href) {
                $links++;
            }

            return $to;
        };
        $imageMap = function (string $src, string $alt) use ($imagesRoot, $apply, &$images, &$missing): string {
            $file = LegacyMarkdown::uploadFiles($src)[0] ?? null;
            if ($file === null) {
                return '';                                   // not one of the old uploads: dropped
            }
            $ours = $this->store($file, $imagesRoot, $apply);
            $ours === '' ? $missing++ : $images++;

            return $ours;
        };

        $bodyEn = LegacyMarkdown::fromHtml(Coerce::str($post['body_en_html'] ?? null), $linkMap, $imageMap);
        $bodyAr = LegacyMarkdown::fromHtml(Coerce::str($arabic['body_html'] ?? null) ?: Coerce::str($post['body_ar_html'] ?? null), $linkMap, $imageMap);
        $cover = Coerce::nstr($post['cover_file'] ?? null);
        $coverPath = $cover === null ? '' : $this->store($cover, $imagesRoot, $apply);

        $seo = Coerce::arr($post['seo'] ?? null);
        $metaTitle = str_replace(['%title%', '%sep%', '%sitename%'], [$titleEn, '-', $sitename], Coerce::str($seo['title'] ?? null));
        $metaDescription = Coerce::str($seo['description'] ?? null);
        if (preg_match('/%[a-z_]+%/i', $metaTitle.$metaDescription) === 1) {
            $this->notes[] = "{$oldSlug}: a Rank Math variable is left unexpanded";
        }
        if ($titleAr === '') {
            $this->notes[] = "{$oldSlug}: no Arabic on the old site — saved as a DRAFT until the team writes its Arabic title";
        }

        if (! $apply) {
            return ['action' => 'create', 'slug' => $slug, 'id' => null, 'links' => $links, 'images' => $images, 'images_missing' => $missing];
        }

        $id = $this->blogs->save(null, [
            'storefront_id' => $this->storefront,
            'slug' => $slug,
            'cover_path' => $coverPath === '' ? null : ltrim(substr($coverPath, strlen('/Uploads_Images/')), '/'),
            'is_published' => $titleAr !== '',
            'title' => ['ar' => $titleAr, 'en' => $titleEn],
            'body' => ['ar' => $bodyAr, 'en' => $bodyEn],
            'meta_title' => ['ar' => trim(Coerce::str($arabic['meta_title'] ?? null)), 'en' => $metaTitle],
            'meta_description' => ['ar' => trim(Coerce::str($arabic['meta_description'] ?? null)), 'en' => $metaDescription],
        ]);
        $published = Coerce::str($post['published_at'] ?? null);
        if ($titleAr !== '' && $published !== '') {
            // The old date, so 2023's articles are not presented as new (BlogWriter stamps "now").
            DB::table('core_blogs')->where('id', $id)->update(['published_at' => $published]);
        }
        ActivityLog::record('core_blogs', $id, ActivityLog::CREATED, [], ['from' => '/'.$oldSlug, 'reason' => 'D-13 the old site\'s article'], label: $titleEn, storefrontId: $this->storefront);

        return ['action' => 'created', 'slug' => $slug, 'id' => $id, 'links' => $links, 'images' => $images, 'images_missing' => $missing];
    }

    /**
     * The article's slug on Brand Fashion: the old one, except WordPress's numeric fallback
     * (`18216-2`), rebuilt from the title. `bf:legacy-redirects` computes the same, so the old URL
     * points at the article the moment it is published (a draft has no page: its URL goes to /blogs).
     */
    public static function newSlug(string $oldSlug, string $titleEn): string
    {
        return preg_match('/^\d+(-\d+)?$/', $oldSlug) === 1 ? LegacySlug::make(LegacyText::title($titleEn)) : $oldSlug;
    }

    /** An old href → the address the article should carry now. */
    private function newHref(string $href): string
    {
        $parts = parse_url($href);
        if ($parts === false) {
            return '';
        }
        $host = strtolower($parts['host'] ?? '');
        $isOld = $host === '' || preg_match('/(^|\.)brandfashionegy\.com$/', $host) === 1;
        if (! $isOld) {
            return preg_match('/^(https?:|mailto:|tel:)/i', $href) === 1 ? $href : '';
        }
        $path = $parts['path'] ?? '/';
        $clean = rtrim(rawurldecode($path), '/');
        $clean = $clean === '' ? '/' : $clean;
        $row = DB::table('storefront_redirects')->where('storefront_id', $this->storefront)
            ->where('from_hash', StorefrontRedirect::hashPath($clean))->value('to_path');
        if (is_string($row) && $row !== '') {
            return $row;
        }

        return $clean;
    }

    /** An upload original → `/Uploads_Images/<folder>/<file>` (stored once per run), or '' when it is missing. */
    private function store(string $file, ?string $imagesRoot, bool $apply): string
    {
        if (isset($this->stored[$file])) {
            return $this->stored[$file];
        }
        $source = $imagesRoot === null ? null : $imagesRoot.'/'.$file;
        if ($source === null || ! is_file($source)) {
            $this->notes[] = "image not on disk: {$file}";

            return $this->stored[$file] = '';
        }
        if (! $apply) {
            return $this->stored[$file] = '/Uploads_Images/Banner_home/(would be stored)';
        }
        try {
            $s = $this->media->storePath($source, 'banner');
        } catch (Throwable $e) {
            $this->notes[] = "image unreadable: {$file}";

            return $this->stored[$file] = '';
        }

        return $this->stored[$file] = '/Uploads_Images/'.$s['folder'].'/'.$s['file'];
    }
}
