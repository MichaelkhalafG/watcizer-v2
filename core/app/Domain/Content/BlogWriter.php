<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Support\ManageText;
use Illuminate\Support\Facades\DB;
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
            'cover_path' => ['nullable', 'string', 'max:255'],
            'is_published' => ['required', 'boolean'],

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

        return DB::transaction(function () use ($id, $data, $titles, $bodies, $published, $slug): int {
            $row = [
                'slug' => $slug,
                'cover_path' => Coerce::nstr($data['cover_path'] ?? null),
                'updated_at' => now(),
            ];

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
    }

    /** Delete an article and its translations. The cascade is the foreign key's. */
    public function delete(int $id): void
    {
        DB::table('core_blogs')->where('id', $id)->delete();
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
            'updated_at' => now(),
        ]);
    }

    // ── internals ────────────────────────────────────────────────────────────────────────────

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
