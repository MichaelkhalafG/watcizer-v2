<?php

use App\Domain\Catalog\ProductIndexer;
use App\Domain\Seo\IndexNow;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\CatalogFixture;
use Tests\Support\T;

/*
 * INDEXNOW (SEO Phase 1, 2026-11-14): a change queues its pages in both languages; `indexnow:flush` sends them in
 * batches — and sends NOTHING while it is off (until go-live).
 */

it('queues a saved product\'s page in both languages on every shop that places it, once while it waits', function () {
    $id = CatalogFixture::product('watch');
    CatalogFixture::onStorefront($id, true, 2);
    DB::table('indexnow_queue')->delete();

    app(ProductIndexer::class)->reindex($id);
    app(ProductIndexer::class)->reindex($id);                        // saved twice: still one row per address

    $slug = T::str(DB::table('storefront_product')->where('storefront_id', 2)->where('product_id', $id)->value('slug'));
    $urls = DB::table('indexnow_queue')->where('storefront_id', 2)->pluck('url')->all();
    $site = IndexNow::site(2);
    expect($urls)->toEqualCanonicalizing([$site.'/product/'.rawurlencode($slug), $site.'/ar/product/'.rawurlencode($slug)]);
});

it('sends nothing while it is off, and sends in a batch once it is on — keeping a refused batch for the next run', function () {
    DB::table('indexnow_queue')->delete();
    app(IndexNow::class)->queue(2, ['/faq'], 'test');
    // one sequence for the whole test: the first request is refused (500), the second accepted (202)
    Http::fakeSequence()->push('', 500)->push('', 202);

    config(['indexnow.enabled' => false]);
    expect(app(IndexNow::class)->flush())->toMatchArray(['enabled' => false, 'sent' => 0, 'waiting' => 2]);
    Http::assertNothingSent();

    config(['indexnow.enabled' => true]);
    expect(app(IndexNow::class)->flush())->toMatchArray(['sent' => 0, 'failed' => 2, 'waiting' => 2]);   // a 500: kept

    expect(app(IndexNow::class)->flush())->toMatchArray(['sent' => 2, 'waiting' => 0]);
    Http::assertSent(function (Request $r): bool {
        $body = $r->data();

        return ($body['key'] ?? null) === IndexNow::key(2)
            && str_ends_with(is_string($body['keyLocation'] ?? null) ? $body['keyLocation'] : '', '/indexnow-key.txt')
            && count((array) ($body['urlList'] ?? [])) === 2;
    });
    expect(DB::table('indexnow_queue')->whereNotNull('sent_at')->where('status', 202)->count())->toBe(2);
});

it('gives each shop its own stable 32-character key', function () {
    expect(IndexNow::key(1))->toMatch('/^[a-f0-9]{32}$/')
        ->and(IndexNow::key(1))->toBe(IndexNow::key(1))
        ->and(IndexNow::key(1))->not->toBe(IndexNow::key(2));
});
