<?php

declare(strict_types=1);

namespace App\Compat;

use App\Storefront\ImageUrl;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The storefront's articles (2026-10-01): the PUBLISHED articles of the storefront asking, newest
 * first, written on the dashboard (`core_blogs`, per storefront since M2b). A draft never leaves
 * core. `catalog/blogs` is the list, `catalog/blog?slug=` one article.
 */
final class CompatBlogs
{
    public const EXCERPT = 180;

    public function __construct(private readonly int $storefrontId) {}

    /**
     * @return list<array{slug: string, cover: ?string, published_at: string, title: array{en: string, ar: string}, excerpt: array{en: string, ar: string}}>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->published()->orderByDesc('b.published_at')->orderByDesc('b.id')->get(['b.id', 'b.slug', 'b.cover_path', 'b.body_format', 'b.published_at']) as $raw) {
            $row = Row::cast($raw);
            $tr = self::translations(Row::int($row, 'id'));
            $markdown = Row::nstr($row, 'body_format') === 'markdown';
            $out[] = [
                'slug' => Row::str($row, 'slug'),
                'cover' => self::cover(Row::nstr($row, 'cover_path')),
                'published_at' => Row::str($row, 'published_at'),
                'title' => ['en' => $tr['en']['title'], 'ar' => $tr['ar']['title']],
                'excerpt' => ['en' => self::excerpt($tr['en']['body'], $markdown), 'ar' => self::excerpt($tr['ar']['body'], $markdown)],
            ];
        }

        return $out;
    }

    /**
     * @return array{slug: string, cover: ?string, published_at: string, format: string, title: array{en: string, ar: string}, body: array{en: string, ar: string}, meta_title: array{en: string, ar: string}, meta_description: array{en: string, ar: string}}|null
     */
    public function one(string $slug): ?array
    {
        $raw = $this->published()->where('b.slug', $slug)->first(['b.id', 'b.slug', 'b.cover_path', 'b.body_format', 'b.published_at']);
        if (! is_object($raw)) {
            return null;
        }
        $row = Row::cast($raw);
        $tr = self::translations(Row::int($row, 'id'));
        $pair = fn (string $key): array => ['en' => $tr['en'][$key], 'ar' => $tr['ar'][$key]];

        return [
            'slug' => Row::str($row, 'slug'),
            'cover' => self::cover(Row::nstr($row, 'cover_path')),
            'published_at' => Row::str($row, 'published_at'),
            // How to read `body` (M2d): 'text' (plain paragraphs, "## ", "- " — written before the
            // Markdown editor) or 'markdown'. The storefront renders each its own safe way.
            'format' => Row::nstr($row, 'body_format') === 'markdown' ? 'markdown' : 'text',
            'title' => $pair('title'),
            'body' => $pair('body'),
            'meta_title' => $pair('meta_title'),
            'meta_description' => $pair('meta_description'),
        ];
    }

    private function published(): Builder
    {
        return DB::table('core_blogs as b')->where('b.storefront_id', $this->storefrontId)->whereNotNull('b.published_at');
    }

    /** @return array{en: array{title: string, body: string, meta_title: string, meta_description: string}, ar: array{title: string, body: string, meta_title: string, meta_description: string}} */
    private static function translations(int $blogId): array
    {
        $empty = ['title' => '', 'body' => '', 'meta_title' => '', 'meta_description' => ''];
        $out = ['en' => $empty, 'ar' => $empty];
        foreach (DB::table('core_blog_translations')->where('blog_id', $blogId)->whereIn('locale', ['en', 'ar'])->get() as $raw) {
            $row = Row::cast($raw);
            $locale = Row::str($row, 'locale') === 'ar' ? 'ar' : 'en';
            $out[$locale] = [
                'title' => Row::nstr($row, 'title') ?? '',
                'body' => Row::nstr($row, 'body') ?? '',
                'meta_title' => Row::nstr($row, 'meta_title') ?? '',
                'meta_description' => Row::nstr($row, 'meta_description') ?? '',
            ];
        }

        return $out;
    }

    /**
     * A Markdown body as plain words: headings, list and quote markers, emphasis, link addresses (the
     * words stay) and images dropped. The storefront's `markdownToPlain()` does the same for a meta
     * description.
     */
    public static function plain(string $body): string
    {
        $text = (string) preg_replace('/!\[[^\]]*\]\([^)]*\)/u', ' ', $body);                 // images
        $text = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/u', '$1', $text);              // links → their words
        $text = (string) preg_replace('/^\s{0,3}(?:#{1,6}\s+|>\s?|[-*+]\s+|\d+[.)]\s+)/mu', '', $text);
        $text = (string) preg_replace('/(\*\*|__|\*|_|`)/u', '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * The first ~180 characters, cut at a word. A 'text' article keeps exactly the excerpt it had
     * before the Markdown editor (only "## " and "- " dropped); a Markdown one loses all its marks.
     */
    private static function excerpt(string $body, bool $markdown = false): string
    {
        $text = $markdown
            ? self::plain($body)
            : trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/^(?:##\s+|-\s+)/mu', '', $body)));
        if (mb_strlen($text) <= self::EXCERPT) {
            return $text;
        }
        $cut = mb_substr($text, 0, self::EXCERPT);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > 80 ? mb_substr($cut, 0, $space) : $cut, ' ,.،').'…'; // i18n-exempt: punctuation data, never rendered on its own — the Arabic comma is one of the characters trimmed off a cut excerpt (proven by CatalogBlogsTest "cuts a long excerpt at a word")
    }

    private static function cover(?string $path): ?string
    {
        return $path === null || $path === '' ? null : ImageUrl::src($path);
    }
}
