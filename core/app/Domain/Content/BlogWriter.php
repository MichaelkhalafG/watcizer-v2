<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Compat\CatalogWarmer;
use App\Compat\CompatLocaleSitemap;
use App\Domain\Seo\IndexNow;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The one door for writing an article (item 14, developer 2026-09-18).
 *
 * ── Scope, in the developer's own words ──────────────────────────────────────────────────────
 *
 * *"list, create, edit, publish/unpublish, with Arabic and English fields and the SEO fields. NO
 * image gallery for now — a single cover image is enough."* Everything below is that and nothing
 * more: no author, no categories, no tags, no scheduling.
 *
 * ── The rules, and why each is a refusal rather than a caveat ────────────────────────────────
 *
 *  1. **An Arabic title is required.** Arabic is the storefront's default locale and translation
 *     fallback is OFF (`AGENTS` §2.6 — a missing Arabic string shows up missing on the site, it
 *     does not fall back to English). An article with no Arabic title would publish with a blank
 *     heading, which is the same rule the product form enforces for the same reason.
 *
 *  2. **A slug is generated when none is typed**, from the English title and then the Arabic, using
 *     `LegacySlug` — the same slugifier every other public URL in this application goes through, so
 *     an article's address is built by the same rules as a product's.
 *
 *  3. **Publishing requires a body.** A published article with no text is a live page that says
 *     nothing, and it is the one state nobody discovers from inside the dashboard. Saving a DRAFT
 *     with an empty body is fine — that is what a draft is for.
 *
 *  4. **Every save is Markdown** (M2d, 2026-10-02, the blog editor — developer's option A). The
 *     editor writes Markdown and shows its preview before the save, rendered by the same rules the
 *     shop uses, so `body_format` becomes 'markdown' on any save. An article nobody has saved since
 *     stays 'text' and keeps looking exactly as it did (the storefront's ArticleBody).
 */
final class BlogWriter
{
    /** The locales an article is written in — the dashboard's own two. */
    public const LOCALES = ['ar', 'en'];

    /**
     * What a create or an edit may name.
     *
     * @return array<string, mixed>
     */
    public static function rules(?int $ignoreId = null): array
    {
        return [
            'slug' => [
                'nullable', 'string', 'max:191',
                Rule::unique('core_blogs', 'slug')->ignore($ignoreId),
            ],
            // the Arabic address (SEO Phase 3, 2026-11-14) — optional: made from the Arabic title until first published
            'slug_ar' => ['nullable', 'string', 'max:191'],
            'cover_path' => ['nullable', 'string', 'max:255'],
            'is_published' => ['required', 'boolean'],
            // Which storefront shows it (M2b, 2026-10-01): articles are per storefront.
            'storefront_id' => ['required', 'integer', 'exists:storefronts,id'],

            'title' => ['required', 'array'],
            'title.ar' => ['required', 'string', 'max:255'],
            'title.en' => ['nullable', 'string', 'max:255'],

            'body' => ['nullable', 'array'],
            'body.ar' => ['nullable', 'string'],
            'body.en' => ['nullable', 'string'],

            'meta_title' => ['nullable', 'array'],
            'meta_title.ar' => ['nullable', 'string', 'max:255'],
            'meta_title.en' => ['nullable', 'string', 'max:255'],

            'meta_description' => ['nullable', 'array'],
            'meta_description.ar' => ['nullable', 'string', 'max:500'],
            'meta_description.en' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * Create or update one article. Returns its id.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function save(?int $id, array $data): int
    {
        $titles = self::pair($data, 'title');
        $bodies = self::pair($data, 'body');
        $published = (bool) ($data['is_published'] ?? false);

        /*
         * Publishing with nothing to read is refused BEFORE anything is written, so a failed save
         * leaves no half-made article behind. The draft path stays open: an article with a title
         * and no text is exactly what somebody has after five minutes.
         */
        if ($published && trim($bodies['ar']) === '' && trim($bodies['en']) === '') {
            throw ValidationException::withMessages([
                'is_published' => ManageText::t(
                    'blogs.publish_needs_body',
                    'لا يمكن نشر مقال بلا نص. اكتب المحتوى أولاً، أو احفظه كمسودة وانشره لاحقًا.'
                ),
            ]);
        }

        $slug = self::slugFor($data, $titles, $id);

        $savedId = DB::transaction(function () use ($id, $data, $titles, $bodies, $published, $slug): int {
            $row = [
                'slug' => $slug,
                'storefront_id' => Coerce::int($data['storefront_id'] ?? null, 1),
                'cover_path' => Coerce::nstr($data['cover_path'] ?? null),
                'body_format' => 'markdown',
                'updated_at' => now(),
            ];

            /*
             * SEO Phase 3 (2026-11-14): the status follows the switch (a scheduled or held article saved as a draft keeps
             * its status — the queue is the publisher's); the Arabic slug is typed, or made from the Arabic title while the
             * article has never been published, and then never changes (BUSINESS FACTS 9: stable once published).
             */
            $sf = Coerce::int($data['storefront_id'] ?? null, 1);
            $typedAr = trim(Coerce::str($data['slug_ar'] ?? null));
            $before = $id === null ? null : DB::table('core_blogs')->where('id', $id)->first(['status', 'slug_ar', 'published_at']);
            $everPublished = $before !== null && Row::nstr(Row::cast($before), 'published_at') !== null;
            $storedAr = $before === null ? null : Row::nstr(Row::cast($before), 'slug_ar');
            if ($typedAr !== '') {
                $row['slug_ar'] = ArabicSlug::unique($sf, $typedAr, $id);
            } elseif (($storedAr === null || $storedAr === '') && ! $everPublished && $titles['ar'] !== '') {
                $row['slug_ar'] = ArabicSlug::unique($sf, $titles['ar'], $id) ?: null;
            }
            $was = $before === null ? 'draft' : Row::str(Row::cast($before), 'status');
            $row['status'] = $published ? 'published' : (in_array($was, ['scheduled', 'held', 'checked'], true) ? $was : 'draft');
            // a REAL change of a published article's words is its dateModified (a re-save of the same text is not)
            if ($id !== null && $everPublished && $published && self::wordsChanged($id, $titles, $bodies)) {
                $row['modified_at'] = now();
            }

            if ($id === null) {
                $row['created_at'] = now();
                // First publish stamps the date; a draft leaves it null.
                $row['published_at'] = $published ? now() : null;
                $id = (int) DB::table('core_blogs')->insertGetId($row);
            } else {
                /*
                 * The stamp is only set ONCE and only cleared by unpublishing.
                 *
                 * Re-stamping on every save of an already-published article would make
                 * `published_at` mean "last edited", and the screen that says "published on the
                 * 3rd" would start saying "published today" after a typo fix.
                 */
                $current = DB::table('core_blogs')->where('id', $id)->value('published_at');
                $row['published_at'] = match (true) {
                    ! $published => null,
                    $current === null => now(),
                    default => $current,
                };
                DB::table('core_blogs')->where('id', $id)->update($row);
            }

            foreach (self::LOCALES as $locale) {
                DB::table('core_blog_translations')->updateOrInsert(
                    ['blog_id' => $id, 'locale' => $locale],
                    [
                        'title' => $titles[$locale],
                        'body' => $bodies[$locale],
                        'meta_title' => Coerce::str(Coerce::arr($data['meta_title'] ?? null)[$locale] ?? null),
                        'meta_description' => Coerce::str(Coerce::arr($data['meta_description'] ?? null)[$locale] ?? null),
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }

            return $id;
        });
        self::sitemapsChanged($savedId);

        return $savedId;
    }

    /** Delete an article and its translations. The cascade is the foreign key's. */
    public function delete(int $id): void
    {
        $storefrontId = DB::table('core_blogs')->where('id', $id)->value('storefront_id');
        $slug = DB::table('core_blogs')->where('id', $id)->value('slug');
        DB::table('core_blogs')->where('id', $id)->delete();
        if (is_numeric($storefrontId)) {
            self::rebuildSitemaps((int) $storefrontId, is_string($slug) ? $slug : null);
        }
    }

    /**
     * Publish or unpublish, without touching anything else.
     *
     * Separate from {@see self::save()} because the list offers it as a one-click action, and
     * making the list send a whole article back to flip one flag is how a list ends up overwriting
     * a field somebody edited in another tab.
     *
     * @throws ValidationException
     */
    public function setPublished(int $id, bool $published): void
    {
        if ($published) {
            $hasBody = DB::table('core_blog_translations')
                ->where('blog_id', $id)
                ->whereNotNull('body')
                ->where('body', '<>', '')
                ->exists();

            if (! $hasBody) {
                throw ValidationException::withMessages([
                    'is_published' => ManageText::t(
                        'blogs.publish_needs_body',
                        'لا يمكن نشر مقال بلا نص. اكتب المحتوى أولاً، أو احفظه كمسودة وانشره لاحقًا.'
                    ),
                ]);
            }
        }

        $current = DB::table('core_blogs')->where('id', $id)->value('published_at');

        DB::table('core_blogs')->where('id', $id)->update([
            'published_at' => match (true) {
                ! $published => null,
                $current === null => now(),
                default => $current,
            },
            // the blog engine's status follows the switch (2026-11-14, SEO Phase 3)
            'status' => $published ? 'published' : 'draft',
            'updated_at' => now(),
        ]);
        self::sitemapsChanged($id);
    }

    /** An article went live or changed outside a dashboard save (the publishing job, the nightly refresh): sitemaps, IndexNow. */
    public static function articleChanged(int $id): void
    {
        self::sitemapsChanged($id);
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array{ar: string, en: string}  $titles
     * @param  array{ar: string, en: string}  $bodies
     */
    private static function wordsChanged(int $id, array $titles, array $bodies): bool
    {
        foreach (DB::table('core_blog_translations')->where('blog_id', $id)->get(['locale', 'title', 'body']) as $raw) {
            $r = Row::cast($raw);
            $l = Row::str($r, 'locale') === 'ar' ? 'ar' : 'en';
            if (trim(Row::nstr($r, 'title') ?? '') !== $titles[$l] || trim(Row::nstr($r, 'body') ?? '') !== trim($bodies[$l])) {
                return true;
            }
        }

        return false;
    }

    /** The article's shop, read before a change that may remove the row. */
    private static function sitemapsChanged(int $id): void
    {
        $storefrontId = DB::table('core_blogs')->where('id', $id)->value('storefront_id');
        $slug = DB::table('core_blogs')->where('id', $id)->value('slug');
        $slugAr = DB::table('core_blogs')->where('id', $id)->value('slug_ar');
        if (is_numeric($storefrontId)) {
            self::rebuildSitemaps((int) $storefrontId, is_string($slug) ? $slug : null, is_string($slugAr) && $slugAr !== '' ? $slugAr : null);
        }
    }

    /**
     * The shop lists its articles in its sitemaps, which are cached since 2026-11-14 and never rebuilt by a public request
     * while a last good copy exists. So the WRITE rebuilds them, here — an admin's save or the publishing job, never a
     * shopper's or a crawler's request. A failure is logged and never fails the article: catalog:warm builds them within
     * five minutes anyway.
     */
    private static function rebuildSitemaps(int $storefrontId, ?string $slug = null, ?string $slugAr = null): void
    {
        // IndexNow (2026-11-14): the article and the blog index, both languages — queued, sent after go-live; the Arabic
        // page at its Arabic slug, percent-encoded (SEO Phase 3)
        app(IndexNow::class)->queue($storefrontId, $slug === null ? ['/blogs'] : ['/blogs', '/blog/'.$slug], 'article changed',
            $slug !== null && $slugAr !== null ? ['/blog/'.$slug => '/blog/'.rawurlencode($slugAr)] : []);
        CompatLocaleSitemap::forget($storefrontId);
        try {
            app(CatalogWarmer::class)->warmSitemaps([$storefrontId]);
        } catch (\Throwable $e) {
            Log::warning('sitemap rebuild after an article change failed', ['storefront' => $storefrontId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Both locales of one translated field, as strings.
     *
     * @param  array<string, mixed>  $data
     * @return array{ar: string, en: string}
     */
    private static function pair(array $data, string $field): array
    {
        $values = Coerce::arr($data[$field] ?? null);

        return [
            'ar' => trim(Coerce::str($values['ar'] ?? null)),
            'en' => trim(Coerce::str($values['en'] ?? null)),
        ];
    }

    /**
     * The typed slug, or one generated from the titles.
     *
     * English first, because an article's URL should be Latin for the same reason a product's is:
     * `LegacySlug` yields '' for Arabic-only input, and a page whose address is empty is not a page.
     * When both titles are Arabic the article gets a dated fallback rather than a refusal — an
     * article is written for reading, and refusing to save one because its URL cannot be guessed
     * would lose the writing.
     *
     * @param  array<string, mixed>  $data
     * @param  array{ar: string, en: string}  $titles
     *
     * @throws ValidationException
     */
    private static function slugFor(array $data, array $titles, ?int $id): string
    {
        $typed = trim(Coerce::str($data['slug'] ?? null));
        if ($typed !== '') {
            $clean = LegacySlug::make($typed);
            if ($clean === '') {
                throw ValidationException::withMessages([
                    'slug' => ManageText::t(
                        'blogs.slug_unusable',
                        'الرابط المكتوب لا ينتج عنوانًا صالحًا. الروابط تُكتب بحروف لاتينية وأرقام وشرطات.'
                    ),
                ]);
            }

            return self::unique($clean, $id);
        }

        $generated = LegacySlug::make($titles['en']);
        if ($generated === '') {
            $generated = LegacySlug::make($titles['ar']);
        }
        if ($generated === '') {
            $generated = 'article-'.now()->format('Y-m-d-His');
        }

        return self::unique($generated, $id);
    }

    /** `article`, `article-2`, `article-3` — the slug column is unique and a save must not 1062. */
    private static function unique(string $base, ?int $id): string
    {
        $slug = $base;
        $suffix = 2;

        while (
            DB::table('core_blogs')->where('slug', $slug)
                ->when($id !== null, fn ($query) => $query->where('id', '!=', $id))
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;

            if ($suffix > 50) {
                throw ValidationException::withMessages([
                    'slug' => ManageText::t(
                        'blogs.slug_not_unique',
                        'تعذّر توليد رابط فريد من «:base». اكتب رابطًا مختلفًا.',
                        ['base' => $base],
                    ),
                ]);
            }
        }

        return $slug;
    }
}
