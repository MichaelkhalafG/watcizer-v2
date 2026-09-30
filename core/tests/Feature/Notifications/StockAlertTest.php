<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\MailBudget;
use App\Domain\Notifications\StockAlerts;
use App\Mail\StockAlertMail;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Shopper;
use Tests\Support\T;

use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withHeaders;

/*
 * "E-mail me when it's back" (2026-10-01) and the bulk-mail budget that protects order mail.
 * Decisions: signed-in customers subscribe with one tap; 5 e-mails per unit restocked, oldest
 * first; notified rows kept 30 days, unanswered 180; the daily cap is a setting, and transactional
 * mail keeps a reserved floor that bulk mail can never use.
 */

const ALERT_KEY = 'stock-alert-test-key';

beforeEach(function () {
    config(['compat.api_key' => ALERT_KEY, 'notifications.bulk.daily_cap' => 100, 'notifications.bulk.transactional_reserve' => 40]);
    DB::table('core_mail_daily')->delete();
});

/** A visible product on storefront 1, set to exactly this stock (express). */
function alertProduct(int $stock): int
{
    $id = T::int(DB::table('catalog_products as p')->join('storefront_product as sp', function (JoinClause $j): void {
        $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', 1)->where('sp.is_visible', '=', 1);
    })->whereNull('p.deleted_at')->where('p.is_active', 1)->orderBy('p.id')->value('p.id'));
    app(InventoryService::class)->set(StockTarget::product($id), 'express', $stock, 'adjustment');
    app(InventoryService::class)->set(StockTarget::product($id), 'market', 0, 'adjustment');
    DB::table('core_stock_alerts')->where('product_id', $id)->delete();

    return $id;
}

/**
 * @param  array<string, mixed>  $body
 * @return TestResponse<Response>
 */
function alertPost(array $body, ?string $token = null): TestResponse
{
    $headers = ['Api-Code' => ALERT_KEY];
    if ($token !== null) {
        $headers['Authorization'] = 'Bearer '.$token;
    }

    return withHeaders($headers)->postJson('/api/stock-alerts', $body);
}

it('subscribes a guest by address, once; refuses a bad address; says so when the product is in stock', function () {
    $out = alertProduct(0);
    alertPost(['product_id' => $out, 'email' => 'Guest.One@Example.test', 'locale' => 'en'])->assertOk()->assertJson(['status' => 'subscribed', 'signed_in' => false]);
    alertPost(['product_id' => $out, 'email' => 'guest.one@example.test'])->assertOk()->assertJson(['status' => 'already']);
    alertPost(['product_id' => $out, 'email' => 'not-an-address'])->assertStatus(422);
    alertPost(['product_id' => $out])->assertStatus(422);

    $row = T::row(DB::table('core_stock_alerts')->where('product_id', $out)->first());
    expect(T::str($row->email))->toBe('guest.one@example.test')->and(T::str($row->locale))->toBe('en')->and(T::str($row->status))->toBe('waiting')
        ->and(DB::table('core_stock_alerts')->where('product_id', $out)->count())->toBe(1);
});

it('says so when the product is in stock, 404s one this shop does not show, and throttles a sixth try in a minute', function () {
    $in = alertProduct(3);
    alertPost(['product_id' => $in, 'email' => 'x@example.test'])->assertOk()->assertJson(['status' => 'in_stock']);
    expect(DB::table('core_stock_alerts')->where('product_id', $in)->exists())->toBeFalse();
    alertPost(['product_id' => 999999999, 'email' => 'x@example.test'])->assertNotFound();
    foreach (range(3, 5) as $i) {
        alertPost(['product_id' => $in, 'email' => "x{$i}@example.test"])->assertOk();
    }
    alertPost(['product_id' => $in, 'email' => 'x6@example.test'])->assertStatus(429);
});

it('subscribes a signed-in customer with one tap, using the account\'s own address', function () {
    [$user, $token] = Shopper::withToken();
    $out = alertProduct(0);

    alertPost(['product_id' => $out, 'email' => 'somebody-else@example.test'], $token)->assertOk()->assertJson(['status' => 'subscribed', 'signed_in' => true]);
    $row = T::row(DB::table('core_stock_alerts')->where('product_id', $out)->first());
    expect(T::str($row->email))->toBe(mb_strtolower(T::str($user->getAttribute('email'))))
        ->and(T::int($row->user_id))->toBe(T::int($user->getAttribute('id')));
});

it('tells 5 waiting shoppers per unit restocked, oldest first, when stock comes back', function () {
    $product = alertProduct(0);
    $alerts = app(StockAlerts::class);
    foreach (range(1, 12) as $i) {
        expect($alerts->subscribe(1, $product, "waiter{$i}@example.test", null, 'ar'))->toBe('subscribed');
        DB::table('core_stock_alerts')->where('email', "waiter{$i}@example.test")->update(['created_at' => now()->subMinutes(100 - $i)]);
    }

    // Two units arrive through the one door stock moves by — the listener does the rest.
    app(InventoryService::class)->adjust(StockTarget::product($product), 'express', 2, 'restock');

    $notified = DB::table('core_stock_alerts')->where('product_id', $product)->where('status', 'notified')->orderBy('created_at')->pluck('email')->all();
    expect($notified)->toBe(array_map(fn (int $i): string => "waiter{$i}@example.test", range(1, 10)))
        ->and(DB::table('core_stock_alerts')->where('product_id', $product)->where('status', 'waiting')->count())->toBe(2)
        ->and(DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->where('event', StockAlerts::KIND)
            ->whereIn('aggregate_id', DB::table('core_stock_alerts')->where('product_id', $product)->pluck('id'))->count())->toBe(10);

    // A sale (negative delta) tells nobody.
    app(InventoryService::class)->adjust(StockTarget::product($product), 'express', -1, 'manual');
    expect(DB::table('core_stock_alerts')->where('product_id', $product)->where('status', 'waiting')->count())->toBe(2);
});

it('sends bulk mail only within the budget, and never counts against transactional mail', function () {
    config(['notifications.bulk.daily_cap' => 10, 'notifications.bulk.transactional_reserve' => 4]);
    $product = alertProduct(0);
    $alerts = app(StockAlerts::class);
    foreach (range(1, 5) as $i) {
        $alerts->subscribe(1, $product, "budget{$i}@example.test", null, 'en');
    }
    $alerts->restocked($product, 1);                         // 5 queued
    app(InventoryService::class)->set(StockTarget::product($product), 'express', 1, 'adjustment');

    // Four transactional messages already went out today: 10 − 4 reserve − 4 sent = 2 bulk left.
    foreach (range(1, 4) as $i) {
        MailBudget::record(MailBudget::TRANSACTIONAL);
    }
    $result = app(BulkMailer::class)->drain(50);
    expect($result['sent'])->toBe(2)->and($result['budget_left'])->toBe(0)
        ->and(MailBudget::sentToday(MailBudget::BULK))->toBe(2);    // counted from MessageSent, as bulk

    // Transactional mail (anything without the bulk header) still goes out with the bulk budget at
    // zero: nothing on that path asks the budget. Counted as transactional.
    Mail::raw('Order #T-1 confirmed', fn (Message $m) => $m->to('order@example.test')->subject('Order'));
    expect(MailBudget::sentToday(MailBudget::TRANSACTIONAL))->toBe(5)
        ->and(MailBudget::bulkRemaining())->toBe(0);
});

it('drops the e-mail and puts the shopper back in line when the product sold out again before sending', function () {
    $product = alertProduct(0);
    $alerts = app(StockAlerts::class);
    $alerts->subscribe(1, $product, 'late@example.test', null, 'ar');
    $alerts->restocked($product, 1);
    // …and it sells out before the drain gets to it (stock stays 0 here).
    $result = app(BulkMailer::class)->drain(50);

    expect($result['sent'])->toBe(0)->and($result['skipped'])->toBeGreaterThanOrEqual(1)
        ->and(T::str(DB::table('core_stock_alerts')->where('email', 'late@example.test')->value('status')))->toBe('waiting');
});

it('renders the alert e-mail in the shopper\'s language with the product and a stop link, marked bulk', function () {
    $mail = new StockAlertMail(['email' => 'x@example.test', 'locale' => 'ar', 'token' => str_repeat('a', 40),
        'product' => ['name' => 'ساعة تجربة', 'price' => 1234.0, 'image' => null, 'url' => 'https://watchizereg.com/ar/product/test']]);
    $html = $mail->render();
    expect($html)->toContain('ساعة تجربة', '1,234 EGP', 'https://watchizereg.com/ar/product/test', '/stock-alerts/stop/'.str_repeat('a', 40), 'dir="rtl"');
});

it('stops an alert only from the button (a POST), never from opening the link', function () {
    $product = alertProduct(0);
    app(StockAlerts::class)->subscribe(1, $product, 'stop@example.test', null, 'en');
    $token = T::str(DB::table('core_stock_alerts')->where('email', 'stop@example.test')->value('token'));

    get("/stock-alerts/stop/{$token}")->assertOk()->assertSee('<form method="POST"', false);
    expect(T::str(DB::table('core_stock_alerts')->where('token', $token)->value('status')))->toBe('waiting');

    post("/stock-alerts/stop/{$token}")->assertOk()->assertSee('تم إلغاء الإشعار');
    expect(T::str(DB::table('core_stock_alerts')->where('token', $token)->value('status')))->toBe('cancelled');
    post("/stock-alerts/stop/{$token}")->assertOk()->assertSee('لا يوجد إشعار نشط');
});

it('prunes notified rows after 30 days and unanswered ones after 180', function () {
    $product = alertProduct(0);
    $alerts = app(StockAlerts::class);
    foreach (['old-notified', 'new-notified', 'old-waiting', 'new-waiting'] as $who) {
        $alerts->subscribe(1, $product, "{$who}@example.test", null, 'ar');
    }
    DB::table('core_stock_alerts')->where('email', 'old-notified@example.test')->update(['status' => 'notified', 'notified_at' => now()->subDays(31)]);
    DB::table('core_stock_alerts')->where('email', 'new-notified@example.test')->update(['status' => 'notified', 'notified_at' => now()->subDays(29)]);
    DB::table('core_stock_alerts')->where('email', 'old-waiting@example.test')->update(['created_at' => now()->subDays(181)]);
    DB::table('core_stock_alerts')->where('email', 'new-waiting@example.test')->update(['created_at' => now()->subDays(179)]);

    expect(StockAlerts::prune())->toBe(['notified' => 1, 'waiting' => 1])
        ->and(DB::table('core_stock_alerts')->where('product_id', $product)->orderBy('email')->pluck('email')->all())
        ->toBe(['new-notified@example.test', 'new-waiting@example.test']);
});

it('counts only the shoppers still waiting, per product and storefront — the dashboard\'s number', function () {
    $product = alertProduct(0);
    $alerts = app(StockAlerts::class);
    foreach (['a', 'b', 'c'] as $who) {
        $alerts->subscribe(1, $product, "{$who}@example.test", null, 'ar');
    }
    DB::table('core_stock_alerts')->where('email', 'c@example.test')->update(['status' => 'notified', 'notified_at' => now()]);

    expect(StockAlerts::waitingCounts([$product], 1))->toBe([$product => 2])
        ->and(StockAlerts::waitingCounts([$product], 2))->toBe([])
        ->and(StockAlerts::waitingCounts([]))->toBe([]);
});
