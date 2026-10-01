<?php

use App\Domain\Access\UserWriteGuard;
use App\Domain\Catalog\PriceWatch;
use App\Domain\Customers\CustomerSeen;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\MailBudget;
use App\Domain\Notifications\ReEngagement;
use App\Mail\ReEngagementMail;
use App\Mail\ReEngagementPreviewMail;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ViewErrorBag;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Shopper;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;
use function Pest\Laravel\withHeaders;

/*
 * The weekly re-engagement e-mail (2026-10-01; decisions 2026-09-28 / 2026-09-30): weekly, live
 * prices and stock, never out of stock, a "last seen" core owns, per storefront, working
 * unsubscribe, never the same customer two weeks running, never the same products twice, only
 * products whose price has not changed for 14 days, a preview 24 hours ahead, a pause switch, and
 * the first runs to the team only.
 */

beforeEach(function () {
    // The order-notification addresses are shared by every shop, so they must never receive a
    // storefront's campaign preview (R7, developer 2026-09-30): set to OTHER addresses here, which
    // no assertion below expects. The preview list is the storefront's own, typed on its screen.
    config(['notifications.admin_emails' => ['shared.orders@example.test'], 'notifications.bulk.daily_cap' => 1000]);
    foreach (['core_reengagement_sends', 'core_reengagement_runs', 'core_reengagement_settings', 'core_marketing_optouts', 'core_customer_seen', 'core_mail_daily'] as $t) {
        DB::table($t)->delete();
    }
    DB::table('core_reengagement_settings')->insert(['storefront_id' => 1, 'paused' => false, 'audience' => 'team',
        'team_emails' => "team.one@example.test\nteam.two@example.test", 'created_at' => now(), 'updated_at' => now()]);
});

/**
 * Exactly these products are eligible on storefront 1 (in stock, visible, price steady 15 days);
 * every other visible product is made ineligible by a fresh price clock.
 *
 * @return list<int>
 */
function steadyProducts(int $n): array
{
    PriceWatch::refresh();
    DB::table('core_price_watch')->update(['since' => now()]);
    $ids = array_values(array_map(fn (mixed $id): int => T::int($id), DB::table('catalog_products as p')
        ->join('storefront_product as sp', function (JoinClause $j): void {
            $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1);
        })->whereNull('p.deleted_at')->where('p.is_active', 1)->orderBy('p.id')->limit($n)->pluck('p.id')->all()));
    foreach ($ids as $id) {
        app(InventoryService::class)->set(StockTarget::product($id), 'express', 5, 'adjustment');
        app(InventoryService::class)->set(StockTarget::product($id), 'market', 0, 'adjustment');
    }
    DB::table('core_price_watch')->whereIn('product_id', $ids)->update(['since' => now()->subDays(15)]);

    return $ids;
}

/**
 * Keep exactly these products steady (15 days) and every other one fresh — so a run's pool does not
 * grow when a test moves the clock forward.
 *
 * @param  list<int>  $ids
 */
function pinSteady(array $ids): void
{
    DB::table('core_price_watch')->update(['since' => now()]);
    DB::table('core_price_watch')->whereIn('product_id', $ids)->update(['since' => now()->subDays(15)]);
}

/**
 * A customer last seen `$daysAgo` days ago on storefront 1.
 *
 * @return array{id: int, email: string}
 */
function customerAway(int $daysAgo): array
{
    $user = Shopper::register();
    $id = T::int($user->getAttribute('id'));
    UserWriteGuard::fixture(fn () => DB::table('users')->where('id', $id)->update(['last_login_at' => null, 'created_at' => now()->subDays(400)]));
    DB::table('core_customer_seen')->insert(['user_id' => $id, 'storefront_id' => 1, 'last_seen_at' => now()->subDays($daysAgo)]);

    return ['id' => $id, 'email' => mb_strtolower(T::str($user->getAttribute('email')))];
}

it('stamps the last seen core owns on a signed-in storefront call, at most once an hour', function () {
    [$user, $token] = Shopper::withToken();
    $id = T::int($user->getAttribute('id'));
    config(['compat.api_key' => 'reeng-key']);

    withHeaders(['Api-Code' => 'reeng-key', 'Authorization' => 'Bearer '.$token])->getJson('/api/me/orders')->assertOk();
    $first = T::str(DB::table('core_customer_seen')->where('user_id', $id)->value('last_seen_at'));
    travel(10)->minutes();
    CustomerSeen::touch($id, 1);
    expect(T::str(DB::table('core_customer_seen')->where('user_id', $id)->value('last_seen_at')))->toBe($first)
        ->and(CustomerSeen::lastSeen($id, 1))->toBe($first);
});

it('knows since when a price has held, and restarts the clock when it changes', function () {
    $id = steadyProducts(1)[0];
    expect(PriceWatch::steady([$id], 14))->toBe([$id]);

    DB::table('catalog_products')->where('id', $id)->update(['selling_price' => DB::raw('selling_price + 100'), 'sale_price' => null]);
    expect(PriceWatch::refresh()['changed'])->toBe(1)
        ->and(PriceWatch::steady([$id], 14))->toBe([]);
});

it('plans a team-only run by default, previews it, and sends it 24 hours later', function () {
    $products = steadyProducts(6);
    $engine = app(ReEngagement::class);

    $plan = $engine->plan(1);
    // Once per week — a second plan in the same week RE-PLANS the same run in place (developer,
    // 2026-10-01: "already planned" used to do nothing, so changed settings silently never took effect).
    expect($plan['status'])->toStartWith('planned — preview sent')->and($plan['recipients'])->toBe(2)
        ->and($engine->plan(1)['status'])->toStartWith('re-planned from the current settings')
        ->and(DB::table('core_reengagement_runs')->count())->toBe(1);
    $run = T::row(DB::table('core_reengagement_runs')->where('id', $plan['run_id'])->first());
    expect(T::str($run->audience))->toBe('team')
        ->and(DB::table('core_reengagement_sends')->where('run_id', $plan['run_id'])->pluck('email')->sort()->values()->all())
        ->toBe(['team.one@example.test', 'team.two@example.test'])
        ->and(DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->where('event', 'reengagement_preview')->count())->toBe(2);

    expect($engine->send())->toBe([]);                                      // not yet: the 24 hours
    travel(25)->hours();
    $sent = $engine->send();
    expect($sent[0]['status'])->toBe('sent')->and($sent[0]['queued'])->toBe(2);

    $picked = array_map(fn (mixed $v): int => T::int($v), T::arr(json_decode(T::str(DB::table('core_reengagement_sends')->where('run_id', $plan['run_id'])->value('product_ids')), true)));
    expect(array_values(array_diff($picked, $products)))->toBe([]);
});

it('mails customers away 30+ days — not recent ones, not the unsubscribed, not last week\'s — and never the same products twice', function () {
    $pool = steadyProducts(20);
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['paused' => false, 'audience' => 'customers']);
    $away = customerAway(40);
    $recent = customerAway(5);
    $gone = customerAway(60);
    ReEngagement::unsubscribe(1, $gone['email']);
    $engine = app(ReEngagement::class);

    $first = $engine->plan(1);
    $emails = array_map(fn (mixed $e): string => T::str($e), DB::table('core_reengagement_sends')->where('run_id', $first['run_id'])->pluck('email')->all());
    expect(in_array($away['email'], $emails, true))->toBeTrue()
        ->and(in_array($recent['email'], $emails, true))->toBeFalse()
        ->and(in_array($gone['email'], $emails, true))->toBeFalse();
    $firstPicks = array_map(fn (mixed $v): int => T::int($v), T::arr(json_decode(T::str(DB::table('core_reengagement_sends')->where('run_id', $first['run_id'])->where('email', $away['email'])->value('product_ids')), true)));
    expect(count($firstPicks))->toBe(6);

    travel(1)->weeks();
    pinSteady($pool);                                                        // 14 unsent products left: plenty
    $second = $engine->plan(1);
    expect(DB::table('core_reengagement_sends')->where('run_id', $second['run_id'])->pluck('email')->all())
        ->not->toContain($away['email']);                                    // not two weeks running — the ONLY reason

    travel(1)->weeks();
    pinSteady($pool);
    $third = $engine->plan(1);
    // Back in the third week — from the same pool of 20, with NONE of the six products from the first.
    $thirdPicks = array_map(fn (mixed $v): int => T::int($v), T::arr(json_decode(T::str(DB::table('core_reengagement_sends')->where('run_id', $third['run_id'])->where('email', $away['email'])->value('product_ids')), true)));
    expect($thirdPicks)->not->toBeEmpty()
        ->and(array_values(array_intersect($thirdPicks, $firstPicks)))->toBe([]);
});

it('skips the week when paused — at planning or at sending', function () {
    steadyProducts(6);
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['paused' => true, 'audience' => 'team']);
    $engine = app(ReEngagement::class);
    expect($engine->plan(1)['status'])->toContain('PAUSED');

    travel(1)->weeks();
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['paused' => false]);
    $plan = $engine->plan(1);
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['paused' => true]);
    travel(25)->hours();
    expect($engine->send()[0])->toBe(['run_id' => $plan['run_id'], 'status' => 'paused', 'queued' => 0])
        ->and(DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->where('event', 'reengagement')->count())->toBe(0);
});

it('builds each e-mail from LIVE stock at send time and drops it below three products', function () {
    $products = steadyProducts(4);
    $engine = app(ReEngagement::class);
    $plan = $engine->plan(1);
    $sendId = T::int(DB::table('core_reengagement_sends')->where('run_id', $plan['run_id'])->value('id'));
    expect(count(T::arr($engine->mailData($sendId)['products'] ?? null)))->toBe(4);

    app(InventoryService::class)->set(StockTarget::product($products[0]), 'express', 0, 'adjustment');
    expect(count(T::arr($engine->mailData($sendId)['products'] ?? null)))->toBe(3);   // the sold-out one is gone
    app(InventoryService::class)->set(StockTarget::product($products[1]), 'express', 0, 'adjustment');
    expect($engine->mailData($sendId))->toBeNull();                          // two left: not sent
});

it('carries a working unsubscribe — a button, the one-click header — honoured by every later run', function () {
    steadyProducts(6);
    $engine = app(ReEngagement::class);
    $plan = $engine->plan(1);
    $send = T::row(DB::table('core_reengagement_sends')->where('run_id', $plan['run_id'])->orderBy('id')->first());
    $url = BulkMailer::unsubscribeUrl(T::int($send->id));
    $path = (string) parse_url($url, PHP_URL_PATH);

    $data = $engine->mailData(T::int($send->id));
    expect($data)->not->toBeNull();
    $mail = new ReEngagementMail($data ?? ['email' => '', 'storefront_id' => 1, 'products' => []], $url);
    expect($mail->render())->toContain($url);

    get($path)->assertOk()->assertSee('<form method="POST"', false);
    expect(ReEngagement::optedOut(1, T::str($send->email)))->toBeFalse();
    get(preg_replace('#/[a-f0-9]{40}$#', '/'.str_repeat('0', 40), $path) ?? '')->assertNotFound();

    post($path)->assertOk()->assertSee("You're unsubscribed", false);            // English only since 2026-10-01
    expect(ReEngagement::optedOut(1, T::str($send->email)))->toBeTrue()
        ->and($engine->mailData(T::int($send->id)))->toBeNull();             // a queued e-mail is not sent after it
});

it('sends through the bulk budget, counted as bulk', function () {
    steadyProducts(6);
    $engine = app(ReEngagement::class);
    $engine->plan(1);
    travel(25)->hours();
    $engine->send();
    $result = app(BulkMailer::class)->drain(50);
    expect($result['sent'])->toBe(4)                                         // 2 previews + 2 run e-mails
        ->and(MailBudget::sentToday(MailBudget::BULK))->toBe(4);
});

it('plans nothing, and mails no one, when the storefront has no preview addresses — never the shared order-notification list', function () {
    steadyProducts(6);
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['team_emails' => '']);

    $plan = app(ReEngagement::class)->plan(1);

    expect($plan['run_id'])->toBeNull()
        ->and($plan['status'])->toStartWith('not planned')
        ->and(DB::table('core_reengagement_runs')->count())->toBe(0)
        ->and(DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->where('payload', 'like', '%shared.orders@example.test%')->exists())->toBeFalse();
});

/* ── R4 (developer, 2026-09-30): the team may pick the next e-mail's products by hand ────────────── */

it('uses the team\'s picks, in their order, for the next e-mail only — skipping any the rules exclude', function () {
    $ids = steadyProducts(8);
    $gone = $ids[0];
    app(InventoryService::class)->set(StockTarget::product($gone), 'express', 0, 'adjustment');   // sold out since it was picked
    $picks = [$ids[5], $gone, $ids[2], $ids[7]];
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['manual_product_ids' => json_encode($picks)]);

    $plan = app(ReEngagement::class)->plan(1);

    $sent = DB::table('core_reengagement_sends')->where('run_id', $plan['run_id'])->pluck('product_ids')->all();
    expect($sent)->toHaveCount(2)                                           // both team addresses
        ->and(array_map(fn (mixed $j): mixed => json_decode(T::str($j), true), $sent))->each->toBe([$ids[5], $ids[2], $ids[7]])
        ->and(json_decode(T::str(DB::table('core_reengagement_runs')->where('id', $plan['run_id'])->value('manual_product_ids')), true))->toBe($picks)
        // Kept until the e-mail is SENT (developer, 2026-10-01 — cleared at send, not at plan, so a
        // re-plan keeps them); this test used to demand they were cleared at plan.
        ->and(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('manual_product_ids'))->not->toBeNull();
    travel(25)->hours();
    app(ReEngagement::class)->send();
    expect(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('manual_product_ids'))->toBeNull();   // sent → cleared
});

it('re-plans an unsent week in place from the changed settings, with a fresh preview, and never re-plans a sent one', function () {
    $ids = steadyProducts(8);
    $engine = app(ReEngagement::class);
    $first = $engine->plan(1);
    $before = DB::table('core_reengagement_sends')->where('run_id', $first['run_id'])->pluck('email')->sort()->values()->all();

    // The team changes its preview list and picks three products, then plans again the same week.
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update([
        'team_emails' => 'only.one@example.test', 'manual_product_ids' => json_encode([$ids[4], $ids[1], $ids[6]]),
    ]);
    $again = $engine->plan(1);

    expect($again['run_id'])->toBe($first['run_id'])                              // the SAME run, rebuilt
        ->and($again['status'])->toStartWith('re-planned')
        ->and($before)->toBe(['team.one@example.test', 'team.two@example.test'])
        ->and(DB::table('core_reengagement_sends')->where('run_id', $first['run_id'])->pluck('email')->all())->toBe(['only.one@example.test'])
        ->and(json_decode(T::str(DB::table('core_reengagement_sends')->where('run_id', $first['run_id'])->value('product_ids')), true))->toBe([$ids[4], $ids[1], $ids[6]])
        ->and(DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->where('event', ReEngagement::KIND.'_preview')->count())->toBe(3);   // 2 first + 1 new

    travel(25)->hours();
    $engine->send();
    expect($engine->plan(1)['status'])->toStartWith('already sent this week');
});

it('sends nothing on picks that leave fewer than 3 eligible, and keeps the picks while the week is paused', function () {
    $ids = steadyProducts(4);
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['manual_product_ids' => json_encode([$ids[0], $ids[1]])]);
    expect(app(ReEngagement::class)->plan(1)['recipients'])->toBe(0);

    DB::table('core_reengagement_runs')->delete();
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['paused' => true, 'manual_product_ids' => json_encode([$ids[0], $ids[1], $ids[2]])]);
    app(ReEngagement::class)->plan(1);
    expect(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('manual_product_ids'))->not->toBeNull();
});

it('saves 3–6 picks placed on the storefront from the dashboard, and refuses 1–2 or another shop\'s product', function () {
    $ids = steadyProducts(4);
    $foreign = T::int(DB::table('catalog_products as p')->whereNotExists(fn (Builder $q) => $q->from('storefront_product as sp')->whereColumn('sp.product_id', 'p.id')->where('sp.storefront_id', 1))->min('p.id'));
    // Changed deliberately (rework 2026-10-01): the form no longer carries `paused`, and the team's
    // addresses arrive as a list (one per chip), not a typed block.
    $put = fn (array $picks) => actingAs(Staff::admin())->put('/manage/storefronts/1/reengagement',
        ['audience' => 'team', 'team_emails' => ['team.one@example.test'], 'manual_product_ids' => $picks]);

    $put([$ids[0], $ids[1]])->assertSessionHasErrors('manual_product_ids');
    $put([$ids[0], $ids[1], $foreign])->assertSessionHasErrors('manual_product_ids.2');
    $put([$ids[2], $ids[0], $ids[1]])->assertSessionHasNoErrors();
    expect(json_decode(T::str(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('manual_product_ids')), true))->toBe([$ids[2], $ids[0], $ids[1]]);
    $put([])->assertSessionHasNoErrors();
    expect(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('manual_product_ids'))->toBeNull();
});

it('serves the picker: chosen ids in their order, only this storefront\'s products, and only to who may use the screen', function () {
    $ids = steadyProducts(3);
    $rows = actingAs(Staff::admin())->getJson('/manage/storefronts/1/reengagement/products?ids='.implode(',', [$ids[2], $ids[0], $ids[1]]))->assertOk()->json('data');
    expect(array_column(T::arr($rows), 'id'))->toBe([$ids[2], $ids[0], $ids[1]]);

    // Each row carries the price THIS storefront charges, by checkout's rule: the sale price only when
    // 0 < sale < selling, with the selling price as `was`.
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $ids[2])->update(['effective_price' => 1000, 'effective_sale_price' => 800]);
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $ids[0])->update(['effective_price' => 1000, 'effective_sale_price' => 1200]);
    $priced = actingAs(Staff::admin())->getJson('/manage/storefronts/1/reengagement/products?ids='.$ids[2].','.$ids[0])->assertOk()->json('data');
    expect([T::arr(T::arr($priced)[0])['price'], T::arr(T::arr($priced)[0])['was']])->toEqual([800, 1000])
        ->and([T::arr(T::arr($priced)[1])['price'], T::arr(T::arr($priced)[1])['was']])->toEqual([1000, null]);

    $code = T::str(DB::table('catalog_products')->where('id', $ids[0])->value('wa_code'));
    $found = actingAs(Staff::admin())->getJson('/manage/storefronts/1/reengagement/products?q='.urlencode($code))->assertOk()->json('data');
    expect(array_column(T::arr($found), 'id'))->toContain($ids[0]);

    actingAs(Staff::dataEntry())->getJson('/manage/storefronts/1/reengagement/products?q=ab')->assertForbidden();
    actingAs(Staff::dataEntry())->getJson('/manage/storefronts/1/home-rails/products?q=ab')->assertOk();
});

it('writes the campaign e-mail, its subject, the team preview and the unsubscribe page in English only', function () {
    $ids = steadyProducts(6);
    foreach ($ids as $id) {                                  // the rule: the English title wherever one exists
        DB::table('catalog_product_translations')->where('product_id', $id)->where('locale', 'en')->update(['title' => "English title {$id}"]);
    }
    $engine = app(ReEngagement::class);
    $plan = $engine->plan(1);
    $sendId = T::int(DB::table('core_reengagement_sends')->where('run_id', $plan['run_id'])->value('id'));
    $data = $engine->mailData($sendId);
    expect($data)->not->toBeNull();
    $mail = new ReEngagementMail($data ?? ['email' => '', 'storefront_id' => 1, 'products' => []], BulkMailer::unsubscribeUrl($sendId));
    $html = $mail->render();
    $previewData = $engine->previewData(T::int($plan['run_id']));
    expect($previewData)->not->toBeNull();
    $preview = new ReEngagementPreviewMail($previewData ?? ['week' => '', 'audience' => 'team', 'recipients' => 0, 'send_after' => '', 'sample' => null], 'https://example.test/manage');
    $previewHtml = $preview->render();
    $page = get((string) parse_url(BulkMailer::unsubscribeUrl($sendId), PHP_URL_PATH))->assertOk()->getContent();

    $arabic = '/\p{Arabic}/u';
    expect(preg_match($arabic, $html))->toBe(0, 'the e-mail has Arabic in it')
        ->and(preg_match($arabic, (string) $mail->subject))->toBe(0, 'the subject has Arabic in it')
        ->and(preg_match($arabic, $previewHtml))->toBe(0, 'the preview has Arabic in it')
        ->and(preg_match($arabic, (string) $preview->subject))->toBe(0, 'the preview subject has Arabic in it')
        ->and(preg_match($arabic, (string) $page))->toBe(0, 'the unsubscribe page has Arabic in it')
        ->and(str_contains($html, 'English title '.$ids[0]))->toBeTrue()
        ->and(str_contains($html, '/ar/product/'))->toBeFalse();
});

/* ── The screen's rework (developer-approved 2026-10-01) ─────────────────────────────────────────── */

it('pauses and resumes alone — the rest of the settings are never touched by it', function () {
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['audience' => 'customers', 'manual_product_ids' => '[1,2,3]']);
    $before = (array) DB::table('core_reengagement_settings')->where('storefront_id', 1)->first(['audience', 'team_emails', 'manual_product_ids']);

    // Even a request that carries other fields changes only the pause.
    actingAs(Staff::admin())->post('/manage/storefronts/1/reengagement/pause', ['paused' => true, 'audience' => 'team', 'team_emails' => []])
        ->assertSessionHasNoErrors()->assertSessionHas('status');
    expect(T::int(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('paused')))->toBe(1)
        ->and((array) DB::table('core_reengagement_settings')->where('storefront_id', 1)->first(['audience', 'team_emails', 'manual_product_ids']))->toBe($before);

    actingAs(Staff::admin())->post('/manage/storefronts/1/reengagement/pause', ['paused' => false])->assertSessionHasNoErrors();
    expect(T::int(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('paused')))->toBe(0);

    // And saving the settings never changes the pause.
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['paused' => true]);
    actingAs(Staff::admin())->put('/manage/storefronts/1/reengagement', ['audience' => 'team', 'team_emails' => ['a@example.test'], 'manual_product_ids' => []])
        ->assertSessionHasNoErrors();
    expect(T::int(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('paused')))->toBe(1);

    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/reengagement/pause', ['paused' => false])->assertForbidden();
});

it('takes the team\'s addresses as a list: each one checked, named when refused, one of each kept', function () {
    $put = fn (array $emails) => actingAs(Staff::admin())->put('/manage/storefronts/1/reengagement', ['audience' => 'team', 'team_emails' => $emails, 'manual_product_ids' => []]);

    $put(['ok@example.test', 'not-an-address'])->assertSessionHasErrors('team_emails.1');
    $bag = session('errors');
    expect($bag)->toBeInstanceOf(ViewErrorBag::class);
    if ($bag instanceof ViewErrorBag) {
        expect($bag->first('team_emails.1'))->toContain('not-an-address');   // names the address it refused
    }
    expect(T::str(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('team_emails')))->toBe("team.one@example.test\nteam.two@example.test");

    $put(['B@Example.test', 'b@example.test', 'c@example.test'])->assertSessionHasNoErrors();
    expect(T::str(DB::table('core_reengagement_settings')->where('storefront_id', 1)->value('team_emails')))->toBe("b@example.test\nc@example.test")
        ->and(ReEngagement::settings(1)['team_emails'])->toBe(['b@example.test', 'c@example.test']);

    $put(array_map(fn (int $i): string => "t{$i}@example.test", range(1, 21)))->assertSessionHasErrors('team_emails');
});

it('re-plans this week from the screen with a fresh preview — and refuses a sent week or one with no team', function () {
    steadyProducts(6);
    $engine = app(ReEngagement::class);
    $first = $engine->plan(1);
    travel(1)->minutes();                                                    // a re-plan's preview is keyed by its planning time

    actingAs(Staff::admin())->post('/manage/storefronts/1/reengagement/replan')->assertSessionHasNoErrors()->assertSessionHas('status');
    expect(DB::table('core_reengagement_runs')->where('storefront_id', 1)->count())->toBe(1)
        ->and(T::int(DB::table('core_reengagement_runs')->where('storefront_id', 1)->value('id')))->toBe($first['run_id'])
        ->and(DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->where('event', ReEngagement::KIND.'_preview')->count())->toBe(4);   // 2 + 2

    DB::table('core_reengagement_runs')->where('id', $first['run_id'])->update(['status' => 'sent', 'sent_at' => now()]);
    actingAs(Staff::admin())->post('/manage/storefronts/1/reengagement/replan')->assertSessionHas('error');
    expect(DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->where('event', ReEngagement::KIND.'_preview')->count())->toBe(4);

    DB::table('core_reengagement_runs')->delete();
    DB::table('core_reengagement_settings')->where('storefront_id', 1)->update(['team_emails' => null]);
    actingAs(Staff::admin())->post('/manage/storefronts/1/reengagement/replan')->assertSessionHas('error');
    expect(DB::table('core_reengagement_runs')->count())->toBe(0);

    actingAs(Staff::dataEntry())->post('/manage/storefronts/1/reengagement/replan')->assertForbidden();
});

it('estimates the audience in one query, counting exactly whom planning would reach', function () {
    $away = customerAway(40);
    customerAway(5);                                                          // recent: not counted
    $gone = customerAway(60);
    ReEngagement::unsubscribe(1, $gone['email']);                            // unsubscribed: not counted
    $legacy = Shopper::register();                                           // never seen by core; legacy login 90 days ago
    $legacyId = T::int($legacy->getAttribute('id'));
    UserWriteGuard::fixture(fn () => DB::table('users')->where('id', $legacyId)->update(['last_login_at' => now()->subDays(90), 'created_at' => now()->subDays(400)]));
    $lastWeek = customerAway(50);                                            // in last week's run: not counted
    $runId = DB::table('core_reengagement_runs')->insertGetId(['storefront_id' => 1, 'week' => now()->subWeek()->format('o-\\WW'), 'audience' => 'customers',
        'status' => 'sent', 'recipients' => 1, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('core_reengagement_sends')->insert(['run_id' => $runId, 'email' => $lastWeek['email'], 'product_ids' => '[]', 'created_at' => now()]);

    $engine = app(ReEngagement::class);
    $planned = array_column(T::arr((fn () => $this->absentCustomers(1))->call($engine)), 'email');

    expect($planned)->toContain($away['email'])
        ->and($planned)->toContain(mb_strtolower(T::str($legacy->getAttribute('email'))))
        ->and($planned)->not->toContain($gone['email'])
        ->and($planned)->not->toContain($lastWeek['email'])
        ->and(ReEngagement::audienceEstimate(1))->toBe(count($planned));
});

it('gives the screen its state: this week, the next planning time, the audience estimate, and the addresses as a list', function () {
    actingAs(Staff::admin())->get('/manage/storefronts/1/reengagement')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Manage/ReEngagement/Index')
            ->where('state.week', now()->format('o-\\WW'))
            ->where('state.next_plan_at', ReEngagement::nextPlanAt()->toDateTimeString())
            ->has('state.customers_estimate')
            ->where('settings.team_emails', ['team.one@example.test', 'team.two@example.test']));

    $monday = now()->startOfWeek(Carbon::MONDAY)->setTime(10, 0);
    expect(ReEngagement::nextPlanAt()->isMonday())->toBeTrue()
        ->and(ReEngagement::nextPlanAt()->greaterThan(now()))->toBeTrue()
        ->and(ReEngagement::nextPlanAt()->equalTo(now()->lt($monday) ? $monday : $monday->copy()->addWeek()))->toBeTrue();
});
