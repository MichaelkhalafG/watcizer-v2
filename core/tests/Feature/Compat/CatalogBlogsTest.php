<?php

use App\Domain\Content\BlogWriter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

use function Pest\Laravel\withHeaders;

/*
 * The storefront's articles (2026-10-01): `catalog/blogs` lists the PUBLISHED articles of the
 * storefront asking, newest first; `catalog/blog?slug=` returns one. A draft, or another
 * storefront's article, never leaves core.
 */

const BLOGS_KEY = 'catalog-blogs-test-key';

beforeEach(function () {
    config(['compat.api_key' => BLOGS_KEY]);
    DB::table('core_blog_translations')->delete();
    DB::table('core_blogs')->delete();
});

function article(int $storefront, string $slug, bool $published, string $body = 'نص المقال', ?string $publishedAt = null): int
{
    $id = app(BlogWriter::class)->save(null, [
        'storefront_id' => $storefront, 'slug' => $slug, 'is_published' => $published,
        'title' => ['ar' => "عنوان {$slug}", 'en' => "Title {$slug}"],
        'body' => ['ar' => $body, 'en' => "## Heading\nFirst paragraph of {$slug}.\n\n- a point\n- another point"],
        'meta_title' => ['ar' => '', 'en' => "Meta {$slug}"], 'meta_description' => ['ar' => '', 'en' => ''],
    ]);
    if ($publishedAt !== null) {
        DB::table('core_blogs')->where('id', $id)->update(['published_at' => $publishedAt]);
    }

    return $id;
}

it('lists only this storefront\'s published articles, newest first', function () {
    article(1, 'older', true, publishedAt: '2026-09-01 10:00:00');
    article(1, 'newer', true, publishedAt: '2026-09-20 10:00:00');
    article(1, 'a-draft', false);
    article(2, 'brand-fashion-one', true);

    $list = T::arr(withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blogs')->assertOk()->json('blogs'));
    expect(array_column($list, 'slug'))->toBe(['newer', 'older'])
        ->and(T::arr($list[0])['title'])->toBe(['en' => 'Title newer', 'ar' => 'عنوان newer'])
        ->and(T::arr(T::arr($list[0])['excerpt'])['en'])->toBe('Heading First paragraph of newer. a point another point');
});

it('returns one published article by slug, and 404s a draft or another storefront\'s', function () {
    article(1, 'shown', true);
    article(1, 'hidden-draft', false);
    article(2, 'other-shop', true);

    $blog = T::arr(withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blog?slug=shown')->assertOk()->json('blog'));
    // Changed deliberately (2026-10-02, the Markdown editor): `format` says how to read `body`.
    expect(array_keys($blog))->toBe(['slug', 'cover', 'published_at', 'format', 'title', 'body', 'meta_title', 'meta_description'])
        ->and($blog['format'])->toBe('markdown')
        ->and(T::arr($blog['meta_title'])['en'])->toBe('Meta shown');

    withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blog?slug=hidden-draft')->assertNotFound();
    withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blog?slug=other-shop')->assertNotFound();
    withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blog')->assertStatus(422);
});

it('cuts a long excerpt at a word, with an ellipsis', function () {
    article(1, 'long', true, str_repeat('كلمة طويلة ', 60));
    $list = T::arr(withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blogs')->json('blogs'));
    $excerpt = T::str(T::arr(T::arr($list[0])['excerpt'])['ar']);
    expect(mb_strlen($excerpt))->toBeLessThanOrEqual(181)
        ->and($excerpt)->toEndWith('…')
        ->and(mb_substr($excerpt, -2, 1))->not->toBe(' ');
});

it('loads the prepared articles as DRAFTS — invisible to shoppers until the team publishes — and only once', function () {
    Artisan::call('blogs:seed-drafts', ['--storefront' => 'watchizer', '--apply' => true]);
    $slugs = DB::table('core_blogs')->where('storefront_id', 1)->pluck('slug')->all();
    expect(count($slugs))->toBe(4)
        ->and(DB::table('core_blogs')->whereNotNull('published_at')->count())->toBe(0)
        ->and(DB::table('core_blog_translations')->where('locale', 'ar')->where('body', '!=', '')->count())->toBe(4);
    withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blogs')->assertOk()->assertJson(['blogs' => []]);

    Artisan::call('blogs:seed-drafts', ['--storefront' => 'watchizer', '--apply' => true]);
    expect(DB::table('core_blogs')->count())->toBe(4);
});

it('keeps an article written before the Markdown editor exactly as it was: format text, same excerpt', function () {
    // A row as it exists today, before M2d: plain text with "## " and "- ", and characters that mean
    // something in Markdown. Nobody has saved it in the new editor, so nothing about it may change.
    $id = article(1, 'legacy-text', true);
    DB::table('core_blogs')->where('id', $id)->update(['body_format' => 'text']);
    DB::table('core_blog_translations')->where('blog_id', $id)->where('locale', 'en')
        ->update(['body' => '## Care_guide
Keep it *dry* and use [no] spray.

- strap_one']);

    $blog = T::arr(withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blog?slug=legacy-text')->assertOk()->json('blog'));
    $list = T::arr(withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blogs')->json('blogs'));
    expect($blog['format'])->toBe('text')
        ->and(T::arr(T::arr($list[0])['excerpt'])['en'])->toBe('Care_guide Keep it *dry* and use [no] spray. strap_one');
});

it('saves every article from the editor as Markdown, and its excerpt keeps only the words', function () {
    article(1, 'md', true);
    DB::table('core_blog_translations')->where('locale', 'en')
        ->update(['body' => '## A **bold** start

See [our listing](/listing) and ![a watch](/Uploads_Images/Banner/a.webp).

1. first
> quoted']);

    expect(DB::table('core_blogs')->where('slug', 'md')->value('body_format'))->toBe('markdown');
    $list = T::arr(withHeaders(['Api-Code' => BLOGS_KEY])->getJson('/api/catalog/blogs')->json('blogs'));
    expect(T::arr(T::arr($list[0])['excerpt'])['en'])->toBe('A bold start See our listing and . first quoted');
});
