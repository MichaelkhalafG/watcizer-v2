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
    expect(array_keys($blog))->toBe(['slug', 'cover', 'published_at', 'title', 'body', 'meta_title', 'meta_description'])
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
