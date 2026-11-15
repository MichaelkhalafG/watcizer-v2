<?php

use App\Domain\Seo\NotFoundLog;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Shops;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

/*
 * THE 404 MONITOR (SEO Phase 1, 2026-11-14): a shop's server reports the pages it answered 404 for; the dashboard lists
 * them per shop, and the team marks them fixed.
 */

const NF_KEY = 'test-storefront-server-key-0123456789abcdef';

beforeEach(function () {
    config(['compat.server_key' => NF_KEY]);
    DB::table('seo_not_found')->delete();
});

it('records a 404 once per path — query dropped, case-blind — counting people and search engines apart', function () {
    $bf = Shops::id('brandfashion');
    $send = fn (array $body) => postJson('/api/v2/brandfashion/not-found', $body, ['X-Storefront-Server-Key' => NF_KEY])->assertNoContent();

    $send(['path' => '/old-page/?utm_source=x', 'referer' => 'https://example.com/blog/post?id=7#top', 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0)']);
    $send(['path' => '/OLD-PAGE', 'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);

    expect(DB::table('seo_not_found')->where('storefront_id', $bf)->count())->toBe(1);
    $row = T::row(DB::table('seo_not_found')->where('storefront_id', $bf)->first());
    expect($row->path)->toBe('/old-page')
        ->and(T::int($row->hits))->toBe(2)
        ->and(T::int($row->bot_hits))->toBe(1)
        ->and($row->last_referer)->toBe('https://example.com/blog/post');     // no query, no fragment; kept when the next has none
});

it("records a server's report under the shop of its path", function () {
    postJson('/api/v2/watchizer/not-found', ['path' => '/gone-watch'], ['X-Storefront-Server-Key' => NF_KEY])->assertNoContent();
    postJson('/api/v2/brandfashion/not-found', ['path' => '/gone-bag'], ['X-Storefront-Server-Key' => NF_KEY])->assertNoContent();

    expect(DB::table('seo_not_found')->where('path', '/gone-watch')->value('storefront_id'))->toBe(Shops::id('watchizer'))
        ->and(DB::table('seo_not_found')->where('path', '/gone-bag')->value('storefront_id'))->toBe(Shops::id('brandfashion'));
});

it('refuses a report without the storefront server key, and records nothing', function () {
    postJson('/api/v2/brandfashion/not-found', ['path' => '/x'])->assertForbidden();
    postJson('/api/v2/brandfashion/not-found', ['path' => '/x'], ['X-Storefront-Server-Key' => 'wrong-key-wrong-key-wrong-key-wrong'])->assertForbidden();
    config(['compat.server_key' => '']);                                       // no key configured: nothing ever matches
    postJson('/api/v2/brandfashion/not-found', ['path' => '/x'], ['X-Storefront-Server-Key' => ''])->assertForbidden();
    expect(DB::table('seo_not_found')->count())->toBe(0);
});

it('stops taking NEW paths at the cap, keeps counting known ones, and reopens a fixed one asked for again', function () {
    $wz = Shops::id('watchizer');
    $log = app(NotFoundLog::class);
    expect($log->record($wz, 'not a path', null, false))->toBeFalse();

    $log->record($wz, '/known', null, false);
    foreach (array_chunk(range(1, NotFoundLog::MAX_OPEN - 1), 2000) as $chunk) {   // one INSERT would pass the placeholder limit
        DB::table('seo_not_found')->insert(array_map(fn (int $i): array => [
            'storefront_id' => $wz, 'path' => '/filler-'.$i, 'path_hash' => sha1('/filler-'.$i), 'hits' => 1, 'bot_hits' => 0,
        ], $chunk));
    }
    expect($log->record($wz, '/brand-new', null, false))->toBeFalse()          // at the cap: a new path is not taken
        ->and($log->record($wz, '/known', null, true))->toBeTrue();             // a known one still counts

    DB::table('seo_not_found')->where('path', '/known')->update(['resolved_at' => now()]);
    $log->record($wz, '/known', null, false);
    expect(DB::table('seo_not_found')->where('path', '/known')->value('resolved_at'))->toBeNull()
        ->and(T::int(DB::table('seo_not_found')->where('path', '/known')->value('hits')))->toBe(3);
});

it('lists a shop\'s open paths on the dashboard, most asked first, and marks one fixed (logged)', function () {
    $bf = Shops::id('brandfashion');
    $log = app(NotFoundLog::class);
    $log->record($bf, '/rare', null, false);
    foreach (range(1, 3) as $_) {
        $log->record($bf, '/often', null, true);
    }
    $log->record(Shops::id('watchizer'), '/other-shop', null, false);
    $admin = Staff::admin();

    actingAs($admin)->get("/manage/storefronts/{$bf}/not-found")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Manage/NotFound/Index')
        ->where('open', 2)
        ->where('table.data.0.path', '/often')
        ->where('table.data.0.bot_hits', 3)
        ->where('table.data.1.path', '/rare')
        ->has('table.data', 2));

    $id = T::int(DB::table('seo_not_found')->where('storefront_id', $bf)->where('path', '/often')->value('id'));
    actingAs($admin)->put("/manage/storefronts/{$bf}/not-found/{$id}", ['fixed' => true])->assertRedirect();
    expect(DB::table('seo_not_found')->where('id', $id)->value('resolved_at'))->not->toBeNull()
        ->and(DB::table('core_activity_log')->where('subject_type', 'seo_not_found')->where('subject_id', $id)->exists())->toBeTrue();

    actingAs($admin)->get("/manage/storefronts/{$bf}/not-found?filters[state]=fixed")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('state', 'fixed')->has('table.data', 1)->where('table.data.0.fixed', true));
});
