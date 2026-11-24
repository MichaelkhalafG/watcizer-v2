<?php

use App\Domain\Content\BlogPublisher;
use App\Domain\Content\BlogSafety;
use App\Domain\Content\WeeklyBatch;
use App\Domain\Seo\SearchData;
use App\Domain\Seo\SeoReport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\T;

/*
 * THE SAFETY NET AND THE WEEKLY REPORT (SEO Phase 5, 2026-11-14): too much of a batch held pauses a shop's publishing
 * and alerts the team; a short queue alerts without pausing; the report is written for every active shop; Search
 * Console and Bing say "not connected" until their credentials are set.
 */
beforeEach(function () {
    config(['mail.default' => 'array', 'seo.gsc_service_account' => '', 'seo.bing_api_key' => '']);
    foreach ([1, 2] as $sf) {
        $settings = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', $sf)->value('settings') ?? '{}'), true) ?: []);
        unset($settings['blog']);
        DB::table('storefronts')->where('id', $sf)->update(['settings' => json_encode($settings)]);
    }
    DB::table('core_blog_translations')->delete();
    DB::table('core_blogs')->delete();
});

it('pauses a shop and alerts once when too much of a batch is held, and leaves a good batch alone', function () {
    expect(BlogSafety::afterImport(1, 9, 1))->toBeFalse()
        ->and(BlogPublisher::paused(1))->toBeFalse();

    expect(BlogSafety::afterImport(1, 6, 4))->toBeTrue()
        ->and(BlogPublisher::paused(1))->toBeTrue();
    BlogSafety::afterImport(1, 6, 4);                                  // the same reason the same day: one alert
    $alerts = BlogSafety::alerts(1);
    expect($alerts)->toHaveCount(1)
        ->and($alerts[0]['reason'])->toBe('held_share')
        ->and($alerts[0]['paused'])->toBeTrue()
        ->and(BlogPublisher::paused(2))->toBeFalse();                  // the other shop is untouched
});

it('alerts on a short queue without pausing, once the shop runs on the queue', function () {
    DB::table('core_blogs')->insert(['storefront_id' => 2, 'slug' => 'queued-one', 'body_format' => 'markdown', 'status' => 'scheduled',
        'publish_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);   // under the 2-day threshold
    Artisan::call('blog:safety');
    $alerts = BlogSafety::alerts(2);
    expect($alerts)->not->toBe([])
        ->and($alerts[0]['reason'])->toBe('queue')
        ->and(BlogPublisher::paused(2))->toBeFalse()
        ->and(BlogSafety::alerts(1))->toBe([]);                        // no queue yet: nothing to say
});

it('writes a weekly report for every active shop, saying plainly what is not connected', function () {
    expect(SearchData::gscConnected())->toBeFalse()->and(SearchData::bingConnected())->toBeFalse();
    $dir = sys_get_temp_dir().'/seo-report-'.bin2hex(random_bytes(3));
    config(['seo.report_dir' => $dir]);
    $md = SeoReport::build(1);
    expect($md)->toContain('## Articles')
        ->and($md)->toContain('## Pages not found')
        ->and($md)->toContain('## Sitemaps and IndexNow')
        ->and($md)->toContain('Search Console: not connected')
        ->and($md)->toContain('Bing Webmaster Tools: not connected');
    $files = SeoReport::writeAll();
    expect(count($files))->toBeGreaterThanOrEqual(1);
    array_map('unlink', $files);
    @rmdir($dir);
});

it("picks next week's topics from the map, never one a shop's articles already use", function () {
    $wz = array_column(WeeklyBatch::nextTopics('watchizer'), 'id');
    $bf = array_column(WeeklyBatch::nextTopics('brandfashion'), 'id');
    expect($wz)->toHaveCount(7)->and($wz)->not->toContain('T0001')
        ->and($bf)->toHaveCount(7)->and($bf)->not->toContain('T0158')
        ->and(WeeklyBatch::usedTopics('watchizer'))->toContain('T0001');
});

it('reminds on day 6 of a publishing week when next week is not written, and not when it is', function () {
    Mail::fake();
    $settings = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 1)->value('settings') ?? '{}'), true) ?: []);
    DB::table('storefronts')->where('id', 1)->update(['settings' => json_encode(['seo' => ['report_email' => 'owner@example.test']] + $settings)]);
    $first = now('Africa/Cairo')->subDays(5)->setTime(10, 0);          // today is day 6 of the first week
    $row = fn (string $slug, Carbon\CarbonInterface $at) => ['storefront_id' => 1, 'slug' => $slug, 'body_format' => 'markdown', 'status' => 'scheduled',
        'publish_at' => $at->copy()->setTimezone(config()->string('app.timezone'))->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now()];
    DB::table('core_blogs')->insert($row('week-one', $first));
    expect(WeeklyBatch::remind()[1])->toBe('reminder sent');
    for ($d = 0; $d < 7; $d++) {
        DB::table('core_blogs')->insert($row('next-'.$d, $first->copy()->addDays(7 + $d)));
    }
    expect(WeeklyBatch::remind()[1])->toBe('next week queued');
    expect(WeeklyBatch::remind(now()->addDay())[1])->toBe('not day 6');
});

it('e-mails the weekly report to the shop address when one is set', function () {
    Mail::fake();
    $settings = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 1)->value('settings') ?? '{}'), true) ?: []);
    DB::table('storefronts')->where('id', 1)->update(['settings' => json_encode(['seo' => ['report_email' => 'owner@example.test']] + $settings)]);
    expect(SeoReport::reportEmail(1))->toBe('owner@example.test')->and(SeoReport::reportEmail(2))->toBeNull();
    $dir = sys_get_temp_dir().'/seo-report-'.bin2hex(random_bytes(3));
    config(['seo.report_dir' => $dir]);
    array_map('unlink', SeoReport::writeAll());
    @rmdir($dir);
    Mail::assertSent(App\Mail\SeoReportMail::class);
});

it('pauses publishing only on a real fall of the blog in search, and only alerts on a small blog (2026-11-24)', function () {
    $w = fn (int $impressions, int $pages): array => ['impressions' => $impressions, 'pages' => $pages];
    // the case that paused Watchizer twice: 12 → 7 pages on a small blog. A watch alert, never a pause
    $small = BlogSafety::judge($w(300, 12), $w(300, 12), $w(150, 7));
    expect($small['pause'])->toBeFalse()->and(T::str($small['message']))->toContain('publishing continues')->toContain('too little to judge');
    // a big blog where only the pages fell: watch, not pause
    $one = BlogSafety::judge($w(5000, 60), $w(5000, 60), $w(4800, 30));
    expect($one['pause'])->toBeFalse()->and(T::str($one['message']))->toContain('only one measure fell');
    // a big blog where BOTH fell by more than 40 % this week: pause
    $both = BlogSafety::judge($w(5000, 60), $w(5000, 60), $w(2500, 30));
    expect($both['pause'])->toBeTrue()->and(T::str($both['message']))->toContain('both fell')->toContain('publishing paused');
    // impressions down 30 % two weeks in a row on a big blog: pause
    $twice = BlogSafety::judge($w(5000, 60), $w(3400, 55), $w(2300, 50));
    expect($twice['pause'])->toBeTrue()->and(T::str($twice['message']))->toContain('two weeks in a row');
    // down 30 % once, flat before: nothing to say (under the 40 % watch line)
    expect(BlogSafety::judge($w(3400, 55), $w(3400, 55), $w(2300, 50)))->toBe(['pause' => false, 'message' => null]);
    // the volume floor is read from config
    config(['seo.safety.min_impressions' => 100, 'seo.safety.min_pages' => 5]);
    expect(BlogSafety::judge($w(300, 12), $w(300, 12), $w(150, 5))['pause'])->toBeTrue();
});

it('records a watch alert without pausing, and a pause alert with the switch', function () {
    BlogSafety::alert(1, 'search_watch', 'a fall to watch', false);
    $blog = T::arr(T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 1)->value('settings')), true))['blog'] ?? []);
    expect($blog['paused'] ?? false)->toBeFalse()->and(T::rows($blog['alerts'] ?? [])[0]['reason'])->toBe('search_watch');
    BlogSafety::alert(1, 'search_drop', 'a real fall', true);
    $blog = T::arr(T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 1)->value('settings')), true))['blog'] ?? []);
    expect($blog['paused'])->toBeTrue();
});
