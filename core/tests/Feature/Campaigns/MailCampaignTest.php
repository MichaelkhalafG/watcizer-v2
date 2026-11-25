<?php

use App\Domain\Campaigns\EmailCampaigns;
use App\Domain\Campaigns\ListUpload;
use App\Domain\Campaigns\Xlsx;
use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\MailBudget;
use App\Domain\Notifications\ReEngagement;
use App\Mail\CampaignMail;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Support\PromotionFixture;
use Tests\Support\Staff;
use Tests\Support\StorefrontMailboxFixture;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
 * THE E-MAIL CAMPAIGNS (Michael, 2026-11-24, plan item 5; D2, D14-D16). The audience filters and their counts, the
 * unsubscribed always left out, staff never in it, the list upload's checks, the throttle, the per-recipient code, the
 * send-time check, and the dashboard's test send to the staff address only. No real mail: Mail is faked.
 */

beforeEach(function () {
    Mail::fake();
    StorefrontMailboxFixture::ensure();
    StorefrontMailboxFixture::budget(1, 1000, 200);
    PromotionFixture::moneyRewards(true, 1);
    foreach (['core_email_campaign_recipients', 'core_email_campaigns', 'core_email_list_rows', 'core_email_lists', 'core_marketing_optouts', 'core_mail_daily'] as $t) {
        DB::table($t)->delete();
    }
    DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->delete();
});

/** A guest order on a shop, for an address. */
function mcOrder(string $email, int $daysAgo, float $total, string $status = 'delivered', int $sf = 1, ?int $productId = null): int
{
    $id = (int) DB::table('orders')->insertGetId(['storefront_id' => $sf, 'user_id' => null, 'guest_email' => $email, 'guest_name' => 'Guest '.$email, 'status' => $status,
        'total_price_for_order' => number_format($total, 2, '.', ''), 'payment_method' => 'cash', 'order_number' => 'MC'.random_int(1000000, 9999999),
        'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo)]);
    if ($productId !== null) {
        DB::table('order_items')->insert(['order_id' => $id, 'product_id' => $productId, 'quantity' => 1, 'piece_price' => $total, 'total_price' => $total, 'type_stock' => 'Express', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
}

function mcEmail(string $tag): string
{
    return 'mc-'.$tag.'-'.Str::lower(Str::random(6)).'@example.test';
}

/**
 * @param  array<string, mixed>  $filters
 * @return list<string>
 */
function mcReach(array $filters): array
{
    return array_column(EmailCampaigns::resolve(1, EmailCampaigns::audience($filters + ['accounts' => false]))['recipients'], 'email');
}

/** @param  array<string, mixed>  $filters */
function mcIn(array $filters, string $email): bool
{
    return in_array($email, mcReach($filters), true);
}

it('combines the ready filters over the addresses we hold, and counts only real orders', function () {
    $product = T::int(DB::table('storefront_product')->where('storefront_id', 1)->where('is_visible', 1)->orderBy('product_id')->value('product_id'));
    $brand = T::int(DB::table('catalog_products')->where('id', $product)->value('brand_id'));
    $loyal = mcEmail('loyal');                  // 2 orders, 100 days ago, EGP 5,000, a product of $brand
    mcOrder($loyal, 100, 3000.0, 'delivered', 1, $product);
    mcOrder($loyal, 110, 2000.0);
    $recent = mcEmail('recent');                // 1 order 10 days ago
    mcOrder($recent, 10, 800.0);
    $cancelled = mcEmail('cancelled');          // only a cancelled order: not a customer by the filters
    mcOrder($cancelled, 100, 9000.0, 'cancelled');
    $other = mcEmail('other');                  // ordered on the other shop only
    mcOrder($other, 100, 1000.0, 'delivered', 2);

    $in = 'mcIn';
    expect($in(['no_order_days' => 90], $loyal))->toBeTrue()->and($in(['no_order_days' => 90], $recent))->toBeFalse()->and($in(['no_order_days' => 90], $cancelled))->toBeFalse();
    expect($in(['min_orders' => 2], $loyal))->toBeTrue()->and($in(['min_orders' => 2], $recent))->toBeFalse();
    expect($in(['min_spent' => 4000], $loyal))->toBeTrue()->and($in(['min_spent' => 4000], $cancelled))->toBeFalse();
    expect($in(['brand_ids' => [$brand]], $loyal))->toBeTrue()->and($in(['brand_ids' => [$brand]], $recent))->toBeFalse();
    expect($in(['this_shop' => true], $loyal))->toBeTrue()->and($in(['this_shop' => true], $recent))->toBeTrue()
        ->and($in(['this_shop' => true], $other))->toBeFalse()->and($in(['this_shop' => true], $cancelled))->toBeFalse();
    // combined: AND
    expect(mcReach(['no_order_days' => 90, 'min_orders' => 2, 'min_spent' => 4000]))->toContain($loyal);
    expect($in(['no_order_days' => 90, 'min_orders' => 3], $loyal))->toBeFalse();
    // no filter: every address held, the cancelled one included (we hold it)
    expect(mcReach([]))->toContain($loyal, $recent, $cancelled, $other);
});

it('always leaves out the unsubscribed, and says how many', function () {
    $gone = mcEmail('gone');
    mcOrder($gone, 40, 500.0);
    $before = EmailCampaigns::resolve(1, EmailCampaigns::audience([]));
    ReEngagement::unsubscribe(1, $gone);
    $after = EmailCampaigns::resolve(1, EmailCampaigns::audience([]));
    expect(array_column($after['recipients'], 'email'))->not->toContain($gone)
        ->and($after['unsubscribed'])->toBe($before['unsubscribed'] + 1)
        ->and(count($after['recipients']))->toBe(count($before['recipients']) - 1);
});

it('never puts a dashboard account in an audience', function () {
    $admin = Staff::admin();
    $email = mb_strtolower(T::str(DB::table('users')->where('id', $admin->getAuthIdentifier())->value('email')));
    expect(array_column(EmailCampaigns::resolve(1, EmailCampaigns::audience(['guests' => false]))['recipients'], 'email'))->not->toContain($email);
});

it('checks an uploaded list before saving: bad e-mail and phone rows listed, duplicates and unsubscribed dropped', function () {
    ReEngagement::unsubscribe(1, 'unsub@example.test');
    $bytes = Xlsx::write([
        ['email', 'name', 'phone'],
        ['Good.One@Example.test', 'Mona', '01012345678'],
        ['not-an-email', 'X', ''],
        ['good.two@example.test', '', '1012345679'],      // a leading zero a spreadsheet dropped
        ['good.one@example.test', 'Mona again', ''],      // duplicate
        ['bad.phone@example.test', 'Y', '12345'],
        ['unsub@example.test', 'Z', ''],
    ]);
    $path = tempnam(sys_get_temp_dir(), 'mc').'.xlsx';
    file_put_contents($path, $bytes);
    $report = ListUpload::parse(1, $path, 'list.xlsx');
    expect(array_column($report['rows'], 'email'))->toBe(['good.one@example.test', 'good.two@example.test'])
        ->and($report['rows'][1]['phone'])->toBe('01012345679')
        ->and($report['errors'])->toBe([['row' => 3, 'reason' => 'bad_email'], ['row' => 6, 'reason' => 'bad_phone']])
        ->and($report['duplicates'])->toBe(1)->and($report['unsubscribed'])->toBe(1);
    // the same rules from a .csv
    $csv = tempnam(sys_get_temp_dir(), 'mc').'.csv';
    file_put_contents($csv, "\xEF\xBB\xBFemail,name,phone\nok@example.test,Ali,\nnope,,\n");
    $c = ListUpload::parse(1, $csv, 'list.csv');
    expect(array_column($c['rows'], 'email'))->toBe(['ok@example.test'])->and($c['errors'])->toBe([['row' => 3, 'reason' => 'bad_email']]);
    @unlink($path);
    @unlink($csv);
});

it('serves the template, reports an upload without saving, and saves only with the consent tick', function () {
    actingAs(Staff::admin());
    $tpl = get('/manage/storefronts/1/mail-campaigns/template')->assertOk();
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    file_put_contents($path, (string) $tpl->getContent());
    expect(Xlsx::read($path))->toBe([['email', 'name', 'phone']]);
    @unlink($path);

    $file = fn (): UploadedFile => UploadedFile::fake()->createWithContent('list.csv', "email,name\none@example.test,One\ntwo@example.test,Two\n");
    postJson('/manage/storefronts/1/mail-campaigns/upload', ['file' => $file()])->assertOk()->assertJsonPath('valid', 2)->assertJsonPath('list_id', null);
    expect(DB::table('core_email_lists')->count())->toBe(0);
    postJson('/manage/storefronts/1/mail-campaigns/upload', ['file' => $file(), 'save' => 1, 'consent' => 0])->assertStatus(422);
    expect(DB::table('core_email_lists')->count())->toBe(0);
    postJson('/manage/storefronts/1/mail-campaigns/upload', ['file' => $file(), 'save' => 1, 'consent' => 1, 'name' => 'Fair'])->assertOk();
    expect(DB::table('core_email_lists')->where('name', 'Fair')->value('rows'))->toBe(2);
});

it('sends in throttled batches within the shop\'s bulk allowance, a unique code per recipient, and skips who unsubscribed since', function () {
    $list = ListUpload::save(1, 'Test list', array_map(fn (int $i): array => ['email' => "batch{$i}@example.test", 'name' => null, 'phone' => null], range(1, 8)), 1);
    $id = (int) DB::table('core_email_campaigns')->insertGetId(['storefront_id' => 1, 'name' => 'Batches', 'subject_ar' => 'عرض', 'subject_en' => 'An offer',
        'body_ar' => 'نص', 'body_en' => 'Text', 'product_ids' => '[]', 'audience' => json_encode(EmailCampaigns::audience(['list_id' => $list])), 'list_id' => $list,
        'code_percent' => '15.00', 'code_cap' => '300.00', 'code_days' => 5, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    expect(EmailCampaigns::start($id))->toBe(8);
    // the shop may send 5 bulk e-mails today: 205 cap - 200 reserve
    StorefrontMailboxFixture::budget(1, 205, 200);
    $first = EmailCampaigns::dispatch(app(BulkMailer::class));
    expect($first['queued'])->toBe(5);
    // unsubscribed after being queued: skipped at send time
    ReEngagement::unsubscribe(1, 'batch1@example.test');
    $drained = app(BulkMailer::class)->drain(50);
    expect($drained['sent'])->toBe(4);
    Mail::assertSent(CampaignMail::class, 4);
    Mail::assertNotSent(CampaignMail::class, fn (CampaignMail $m): bool => $m->hasTo('batch1@example.test'));
    $codes = array_map(fn ($c): string => T::str($c), DB::table('core_personal_codes')->where('purpose', 'campaign')->pluck('code')->all());
    expect($codes)->toHaveCount(4)->and(array_unique($codes))->toHaveCount(4);
    // what CountSentMail records on a real send (Mail::fake fires no MessageSent): 4 of today's 5 are used
    foreach (range(1, 4) as $_) {
        MailBudget::record(MailBudget::BULK, 1);
    }
    // the next pass may queue only the one left; the rest waits for tomorrow's allowance
    expect(EmailCampaigns::dispatch(app(BulkMailer::class))['queued'])->toBe(1);
    expect(EmailCampaigns::progress($id))->toMatchArray(['sent' => 4, 'skipped' => 1, 'queued' => 1, 'pending' => 2]);
});

it('counts on the dashboard, sends the test to the staff address only, and starts a campaign with its recipients', function () {
    $admin = Staff::admin();
    actingAs($admin);
    get('/manage/storefronts/1/mail-campaigns')->assertOk();
    $email = mcEmail('dash');
    mcOrder($email, 5, 100.0);
    $count = postJson('/manage/storefronts/1/mail-campaigns/count', ['audience' => ['accounts' => false, 'guests' => true]])->assertOk()->json('count');
    expect(T::int($count))->toBeGreaterThan(0);

    post('/manage/storefronts/1/mail-campaigns', [
        'name' => 'Dash', 'subject_ar' => 'عنوان', 'subject_en' => 'Subject', 'body_ar' => 'نص', 'body_en' => 'Text', 'product_ids' => [],
        'audience' => ['accounts' => false, 'guests' => true], 'with_code' => false,
    ])->assertRedirect();
    $id = T::int(DB::table('core_email_campaigns')->where('name', 'Dash')->value('id'));
    get("/manage/storefronts/1/mail-campaigns/{$id}/preview")->assertOk()->assertSee('Subject', false);
    post("/manage/storefronts/1/mail-campaigns/{$id}/test")->assertRedirect();
    $staff = T::str(DB::table('users')->where('id', $admin->getAuthIdentifier())->value('email'));
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $m): bool => $m->hasTo($staff));
    expect(DB::table('core_email_campaign_recipients')->where('campaign_id', $id)->count())->toBe(0);   // a test reaches no customer

    post("/manage/storefronts/1/mail-campaigns/{$id}/send", ['when' => 'now'])->assertRedirect();
    expect(DB::table('core_email_campaigns')->where('id', $id)->value('status'))->toBe('sending')
        ->and(DB::table('core_email_campaign_recipients')->where('campaign_id', $id)->where('email', $email)->exists())->toBeTrue();
});
