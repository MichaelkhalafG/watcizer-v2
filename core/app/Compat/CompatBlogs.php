<?php

declare(strict_types=1);

namespace App\Compat;

use App\Domain\Content\DataBlocks;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
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
     * @return list<array{slug: string, cover: ?string, published_at: string, slug_ar: ?string, modified_at: string, topic: ?string, brand: ?array{slug: string, name: array{en: string, ar: string}}, family: ?string, tags: list<string>, title: array{en: string, ar: string}, excerpt: array{en: string, ar: string}}>
     */
    public function list(): array
    {
        $out = [];
        foreach ($this->published()->orderByDesc('b.published_at')->orderByDesc('b.id')->get(self::COLUMNS) as $raw) {
            $row = Row::cast($raw);
            $tr = self::translations(Row::int($row, 'id'));
            $markdown = Row::nstr($row, 'body_format') === 'markdown';
            $out[] = [
                'slug' => Row::str($row, 'slug'),
                'cover' => $this->cover(Row::nstr($row, 'cover_path')),
                'published_at' => self::iso(Row::str($row, 'published_at')),
                ...self::about($row),
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
        // the English slug or (2026-11-14, SEO Phase 3) the Arabic one — the storefront sends a visitor to the right one
        $raw = $this->published()->where(fn (Builder $q) => $q->where('b.slug', $slug)->orWhere('b.slug_ar', $slug))->first(self::COLUMNS);
        if (! is_object($raw)) {
            return null;
        }
        $row = Row::cast($raw);
        $tr = self::translations(Row::int($row, 'id'));
        $pair = fn (string $key): array => ['en' => $tr['en'][$key], 'ar' => $tr['ar'][$key]];

        return [
            'slug' => Row::str($row, 'slug'),
            'cover' => $this->cover(Row::nstr($row, 'cover_path')),
            'published_at' => self::iso(Row::str($row, 'published_at')),
            ...self::about($row),
            // How to read `body` (M2d): 'text' (plain paragraphs, "## ", "- " — written before the
            // Markdown editor) or 'markdown'. The storefront renders each its own safe way.
            'format' => Row::nstr($row, 'body_format') === 'markdown' ? 'markdown' : 'text',
            'title' => $pair('title'),
            'body' => $pair('body'),
            'meta_title' => $pair('meta_title'),
            'meta_description' => $pair('meta_description'),
            // the live data blocks of both languages, resolved (SEO Phase 3): token => block; cached, see DataBlocks
            'blocks' => (object) app(DataBlocks::class)->forBodies($this->storefrontId, $tr['en']['body'], $tr['ar']['body']),
            'related' => $this->related(Row::int($row, 'id'), Row::nint($row, 'brand_id'), Row::nstr($row, 'topic')),
        ];
    }

    private const COLUMNS = ['b.id', 'b.slug', 'b.slug_ar', 'b.cover_path', 'b.body_format', 'b.published_at', 'b.modified_at', 'b.topic', 'b.brand_id', 'b.family', 'b.tags'];

    /**
     * What an article is about and when it last really changed (SEO Phase 3): the Arabic slug, `modified_at` (the
     * Article's dateModified — the publication date until the content really changes), the topic, the brand, the
     * family and the tags.
     *
     * @return array{slug_ar: ?string, modified_at: string, topic: ?string, brand: ?array{slug: string, name: array{en: string, ar: string}}, family: ?string, tags: list<string>}
     */
    private static function about(\stdClass $row): array
    {
        $brandId = Row::nint($row, 'brand_id');
        $brand = null;
        if ($brandId !== null) {
            $slug = DB::table('catalog_brands')->where('id', $brandId)->value('slug');
            $names = DB::table('catalog_brand_translations')->where('brand_id', $brandId)->pluck('name', 'locale')->all();
            $brand = is_string($slug) ? ['slug' => $slug, 'name' => ['en' => Coerce::str($names['en'] ?? ''), 'ar' => Coerce::str($names['ar'] ?? '')]] : null;
        }
        $tags = json_decode(Row::nstr($row, 'tags') ?? '[]', true);
        $published = Row::str($row, 'published_at');
        $modified = Row::nstr($row, 'modified_at');

        return [
            'slug_ar' => Row::nstr($row, 'slug_ar'),
            'modified_at' => self::iso($modified !== null && strtotime($modified) > strtotime($published) ? $modified : $published),
            'topic' => Row::nstr($row, 'topic'),
            'brand' => $brand,
            'family' => Row::nstr($row, 'family'),
            'tags' => is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [],
        ];
    }

    /**
     * A stored time (the app's time zone: UTC on production) as ISO 8601 with its offset (SEO Phase 3, 2026-11-14) — the
     * storefront shows it in Cairo time and the structured data carries it exactly. Was the bare database string.
     */
    public static function iso(string $db): string
    {
        return $db === '' ? '' : Carbon::parse($db, config()->string('app.timezone'))->toIso8601String();
    }

    /**
     * Up to three other published articles — the same brand first, then the same topic, then the newest — for the
     * "related articles" under an article.
     *
     * @return list<array{slug: string, slug_ar: ?string, cover: ?string, title: array{en: string, ar: string}}>
     */
    private function related(int $id, ?int $brandId, ?string $topic): array
    {
        $q = $this->published()->where('b.id', '<>', $id);
        if ($brandId !== null) {
            $q->orderByRaw('CASE WHEN b.brand_id = ? THEN 0 ELSE 1 END', [$brandId]);
        }
        if ($topic !== null) {
            $q->orderByRaw('CASE WHEN b.topic = ? THEN 0 ELSE 1 END', [$topic]);
        }
        $out = [];
        foreach ($q->orderByDesc('b.published_at')->orderByDesc('b.id')->limit(3)->get(['b.id', 'b.slug', 'b.slug_ar', 'b.cover_path']) as $raw) {
            $row = Row::cast($raw);
            $tr = self::translations(Row::int($row, 'id'));
            $out[] = ['slug' => Row::str($row, 'slug'), 'slug_ar' => Row::nstr($row, 'slug_ar'), 'cover' => $this->cover(Row::nstr($row, 'cover_path')), 'title' => ['en' => $tr['en']['title'], 'ar' => $tr['ar']['title']]];
        }

        return $out;
    }

    private function published(): Builder
    {
        // published = a publication date that has come (a scheduled article has `publish_at` and no `published_at` yet)
        return DB::table('core_blogs as b')->where('b.storefront_id', $this->storefrontId)->whereNotNull('b.published_at')->where('b.published_at', '<=', now());
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

    /** The cover on THIS storefront's image host (COMPAT-ASSET-HOST, 2026-10-14); the default shop's expression is unchanged. */
    private function cover(?string $path): ?string
    {
        return $path === null || $path === '' ? null : ImageUrl::srcFor($this->storefrontId, $path);
    }
}
