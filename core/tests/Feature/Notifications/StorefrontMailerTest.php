<?php

use App\Domain\Customers\CustomerMail;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Notifications\BulkMailer;
use App\Domain\Notifications\MailBudget;
use App\Domain\Notifications\MailFailure;
use App\Domain\Notifications\OrderEmailData;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Notifications\StockAlerts;
use App\Domain\Notifications\StorefrontHasNoMailbox;
use App\Domain\Notifications\StorefrontMailer;
use App\Mail\AdminOrderNotification;
use App\Mail\CustomerEmailVerification;
use App\Mail\CustomerPasswordReset;
use App\Mail\OrderConfirmation;
use App\Mail\OrderStatusUpdate;
use App\Mail\PaymentExpired;
use App\Mail\PaymentExpiredAdmin;
use App\Mail\ReEngagementMail;
use App\Mail\ReEngagementPreviewMail;
use App\Mail\StockAlertMail;
use App\Models\Storefront\StorefrontMailbox;
use App\Storefront\StorefrontIdentity;
use App\Transform\Row;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use Tests\Support\PaymentFixture;
use Tests\Support\Shopper;
use Tests\Support\Shops;
use Tests\Support\StorefrontIdentityFixture;
use Tests\Support\StorefrontMailboxFixture;
use Tests\Support\T;

use function Pest\Laravel\artisan;

/*
 * ── Every mail leaves from ITS storefront's mailbox, or does not leave (W3/W4, developer: option A,
 *    2026-10-12; built 2026-10-13) ──────────────────────────────────────────────────────────────
 *
 * One mailbox row per storefront: the envelope, the transport, the budget and the team addresses.
 * A Brand Fashion mail is FROM Brand Fashion's address and is counted against Brand Fashion's
 * mailbox; a storefront with no usable row sends nothing and the row says so — never the other
 * shop's mailbox, never the env mailer. The transport itself is not opened here (`MAIL_MAILER=array`
 * / `Mail::fake()`); `OrderMailFailureTest` opens the fixture row's dead transport for real.
 */

beforeEach(function () {
    config([
        'notifications.send.inline' => true,
        'notifications.send.inside_transaction' => true,
        'notifications.send.park' => false,
        'storefront.asset_base' => 'https://api.watchizereg.test',
    ]);
});

/**
 * An ACTIVE product visible on BOTH shops (made visible on Watchizer when it is not), with no
 * stock and no alerts, so each shop can hold a restock alert for it.
 */
function sharedAlertProduct(int $fashion): int
{
    $product = T::int(DB::table('catalog_products as p')->join('storefront_product as sp', function (JoinClause $j) use ($fashion): void {
        $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $fashion)->where('sp.is_visible', '=', 1);
    })->whereNull('p.deleted_at')->where('p.is_active', 1)->orderBy('p.id')->value('p.id'));
    DB::table('storefront_product')->updateOrInsert(['storefront_id' => 1, 'product_id' => $product], ['is_visible' => 1, 'effective_price' => 100, 'effective_sale_price' => null, 'sort_order' => 0, 'slug' => 'mailbox-test-'.$product]);
    app(InventoryService::class)->set(StockTarget::product($product), 'express', 0, 'adjustment');
    app(InventoryService::class)->set(StockTarget::product($product), 'market', 0, 'adjustment');
    DB::table('core_stock_alerts')->where('product_id', $product)->delete();

    return $product;
}

/** @param  array<string, mixed>  $args */
function mailboxArtisan(string $command, array $args = []): PendingCommand
{
    $pending = artisan($command, $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }

    return $pending;
}

/** A COD order with a customer address on a storefront, so every order mail renders. */
function mailboxOrder(int $storefrontId): int
{
    $orderId = PaymentFixture::order(total: 100.0, storefrontId: $storefrontId);
    DB::table('orders')->where('id', $orderId)->update([
        'guest_email' => 'shopper@example.test', 'guest_name' => 'Mailbox Test', 'guest_phone' => '01000000000', 'payment_method' => 'cash',
    ]);

    return $orderId;
}

it('sends each shop\'s order mail FROM its own mailbox, to its own team, stamped with its storefront', function () {
    Mail::fake();
    $fashion = Shops::id('brandfashion');
    StorefrontMailboxFixture::recipients(1, ['team@watchizer.test']);
    StorefrontMailboxFixture::recipients($fashion, ['team@brandfashion.test', 'owner@brandfashion.test']);

    app(OrderMailer::class)->placedNow(mailboxOrder($fashion), notifyCustomer: true);
    app(OrderMailer::class)->placedNow(mailboxOrder(1), notifyCustomer: true);

    // The customer's copy: from the shop's address, with the shop's name.
    Mail::assertSent(OrderConfirmation::class, fn (OrderConfirmation $m) => $m->hasTo('shopper@example.test') && $m->hasFrom('no-reply@brandfashion.test', 'Brand Fashion'));
    Mail::assertSent(OrderConfirmation::class, fn (OrderConfirmation $m) => $m->hasTo('shopper@example.test') && $m->hasFrom('no-reply@watchizer.test', 'Watchizer'));
    // The admins' copies: Brand Fashion's two addresses and Watchizer's one, never crossed (C12).
    Mail::assertSent(AdminOrderNotification::class, 3);
    Mail::assertSent(AdminOrderNotification::class, fn (AdminOrderNotification $m) => $m->hasTo('team@brandfashion.test') && $m->hasFrom('no-reply@brandfashion.test'));
    Mail::assertSent(AdminOrderNotification::class, fn (AdminOrderNotification $m) => $m->hasTo('owner@brandfashion.test') && $m->hasFrom('no-reply@brandfashion.test'));
    Mail::assertSent(AdminOrderNotification::class, fn (AdminOrderNotification $m) => $m->hasTo('team@watchizer.test') && $m->hasFrom('no-reply@watchizer.test'));
    Mail::assertNotSent(AdminOrderNotification::class, fn (AdminOrderNotification $m) => $m->hasTo('team@watchizer.test') && $m->hasFrom('no-reply@brandfashion.test'));
    // (The storefront header the counter reads is proven on a REAL message in the next test and in
    // StockAlertTest: under Mail::fake() no Symfony message is built.)
});

it('counts a real send against the storefront named on the message, transactional or bulk', function () {
    // The suite's `array` transport fires MessageSent like SMTP does; the fixture row is the mailbox.
    DB::table('core_mail_daily')->delete();
    $fashion = Shops::id('brandfashion');

    app(OrderMailer::class)->placedNow(mailboxOrder($fashion), notifyCustomer: true);      // customer + 1 admin
    app(OrderMailer::class)->placedNow(mailboxOrder(1), notifyCustomer: false);            // 1 admin

    expect(MailBudget::sentToday($fashion))->toBe(2)
        ->and(MailBudget::sentToday($fashion, MailBudget::TRANSACTIONAL))->toBe(2)
        ->and(MailBudget::sentToday(1))->toBe(1)
        ->and(MailBudget::sentToday(0))->toBe(0)
        // 800 − 200 − 2 for Brand Fashion; Watchizer's own figure is untouched by Brand Fashion's sends.
        ->and(MailBudget::bulkRemaining($fashion))->toBe(598)
        ->and(MailBudget::bulkRemaining(1))->toBe(599);
});

it('sends NOTHING for a storefront with no mailbox row — the order row is retried, then parked as a CONFIG failure, never sent as the other shop', function () {
    Mail::fake();
    config(['notifications.send.max_attempts' => 2]);
    $fashion = Shops::id('brandfashion');
    $orderId = mailboxOrder($fashion);
    StorefrontMailboxFixture::remove($fashion);

    app(OrderMailer::class)->placedNow($orderId, notifyCustomer: true);

    Mail::assertNothingSent();
    $rows = DB::table('integration_outbox')->where('channel', OrderMailer::CHANNEL)->where('aggregate_id', $orderId)->orderBy('id')->get(['status', 'attempts', 'last_error', 'payload']);
    // Two rows: the customer's (retried: pending, 1 attempt) and the admins' "nobody configured" (failed at once).
    expect($rows)->toHaveCount(2);
    $customer = T::row($rows->first(fn (object $r) => str_contains(T::str($r->payload ?? ''), OrderMailer::KIND_CUSTOMER)));
    $admin = T::row($rows->first(fn (object $r) => ! str_contains(T::str($r->payload ?? ''), OrderMailer::KIND_CUSTOMER)));
    expect(Row::str($customer, 'status'))->toBe(OrderMailer::STATUS_PENDING)                         // a mailbox is minutes to fix: retried on the ladder
        ->and(Row::int($customer, 'attempts'))->toBe(1)
        ->and(Row::str($customer, 'last_error'))->toContain('[config]', "storefront {$fashion} has no usable mailbox", 'no storefront_mailboxes row')
        ->and(Row::str($admin, 'status'))->toBe(OrderMailer::STATUS_FAILED)
        ->and(Row::str($admin, 'last_error'))->toContain('[config]', 'no admin notification recipients');

    // The second attempt (the cron's) is the last: parked as failed, classified config, nothing sent.
    DB::table('integration_outbox')->where('channel', OrderMailer::CHANNEL)->where('aggregate_id', $orderId)->update(['available_at' => now()->subMinute()]);
    app(OrderMailer::class)->deliver(T::int(DB::table('integration_outbox')->where('channel', OrderMailer::CHANNEL)->where('aggregate_id', $orderId)->where('status', OrderMailer::STATUS_PENDING)->value('id')));
    Mail::assertNothingSent();
    $screen = OrderMailer::forOrder($orderId);
    expect(array_column($screen, 'status'))->each->toBe(OrderMailer::STATUS_FAILED)
        ->and(array_column($screen, 'error_kind'))->each->toBe(MailFailure::CONFIG);

    // Watchizer is untouched by Brand Fashion's emptiness.
    expect(fn () => StorefrontMailer::mailbox(1))->not->toThrow(StorefrontHasNoMailbox::class);
});

it('treats a DISABLED or incomplete row like no row, naming what is missing and never a value', function () {
    $fashion = Shops::id('brandfashion');
    StorefrontMailbox::query()->where('storefront_id', $fashion)->update(['is_enabled' => false]);
    StorefrontMailer::forget($fashion);
    expect(fn () => StorefrontMailer::mailbox($fashion))->toThrow(StorefrontHasNoMailbox::class, 'missing is_enabled');

    StorefrontMailbox::query()->where('storefront_id', $fashion)->update(['is_enabled' => true, 'from_address' => '', 'transactional_reserve' => 900]);
    StorefrontMailer::forget($fashion);
    $message = '';
    try {
        StorefrontMailer::mailbox($fashion);
    } catch (StorefrontHasNoMailbox $e) {
        $message = $e->getMessage();
    }
    expect($message)->toContain('from_address', 'transactional_reserve');
    expect($message)->not->toContain('suite-only', '127.0.0.1');

    // With a real transport selected, the SMTP contract is required too — the password by NAME only.
    config(['mail.default' => 'smtp']);
    StorefrontMailbox::query()->where('storefront_id', $fashion)->update(['from_address' => 'no-reply@brandfashion.test', 'transactional_reserve' => 200, 'credentials' => null, 'host' => null]);
    StorefrontMailer::forget($fashion);
    $message = '';
    try {
        StorefrontMailer::mailbox($fashion);
    } catch (StorefrontHasNoMailbox $e) {
        $message = $e->getMessage();
    }
    expect($message)->toContain('host', 'password');
    expect($message)->not->toContain('suite-only');
    config(['mail.default' => 'array']);
});

it('a BULK row of a storefront with no mailbox WAITS — pending, an hour later, attempt not counted — and the other shop\'s rows still go', function () {
    Mail::fake();
    $fashion = Shops::id('brandfashion');
    DB::table('core_mail_daily')->delete();
    $product = sharedAlertProduct($fashion);
    $alerts = app(StockAlerts::class);
    $alerts->subscribe(1, $product, 'w@example.test', null, 'en');
    $alerts->subscribe($fashion, $product, 'f@example.test', null, 'en');
    $alerts->restocked($product, 1);
    app(InventoryService::class)->set(StockTarget::product($product), 'express', 1, 'adjustment');
    StorefrontMailboxFixture::remove($fashion);

    $result = app(BulkMailer::class)->drain(50);

    expect($result['sent'])->toBe(1)->and($result['skipped'])->toBe(0)->and($result['budget_left'])->toBe([1 => 600, $fashion => 0]);   // Brand Fashion's row was never claimed: no mailbox = no budget (Watchizer's stays 600: under Mail::fake() nothing is counted)
    Mail::assertSent(StockAlertMail::class, fn (StockAlertMail $m) => $m->hasTo('w@example.test') && $m->hasFrom('no-reply@watchizer.test'));
    Mail::assertNotSent(StockAlertMail::class, fn (StockAlertMail $m) => $m->hasTo('f@example.test'));
    $waiting = DB::table('integration_outbox')->where('channel', BulkMailer::CHANNEL)->where('status', OrderMailer::STATUS_PENDING)->get(['payload']);
    expect($waiting)->toHaveCount(1)->and(Row::str(T::row($waiting->first()), 'payload'))->toContain('f@example.test');
});

it('sends the account mails from the storefront\'s mailbox, and a missing mailbox fails the mail, not the action', function () {
    Mail::fake();
    $fashion = Shops::id('brandfashion');
    $user = Shopper::register(['email' => Shopper::email()]);

    expect(app(CustomerMail::class)->sendEmailVerification($user, $fashion))->toBeTrue();
    Mail::assertSent(CustomerEmailVerification::class, fn (CustomerEmailVerification $m) => $m->hasFrom('no-reply@brandfashion.test', 'Brand Fashion'));

    StorefrontMailboxFixture::remove($fashion);
    expect(app(CustomerMail::class)->sendPasswordReset($user, 'token', $fashion))->toBeFalse();
    Mail::assertNotSent(CustomerPasswordReset::class);
});

it('the drain report reads per storefront, and the mailbox command never prints the password', function () {
    DB::table('core_mail_daily')->delete();
    MailBudget::record(MailBudget::BULK, 1);
    MailBudget::record(MailBudget::TRANSACTIONAL, Shops::id('brandfashion'));

    mailboxArtisan('bulk-mail:drain', ['--report' => true])
        ->expectsOutputToContain('storefront 1 · today: 1 sent (0 transactional, 1 bulk) · cap 800 · reserve 200 · bulk left 599')
        ->expectsOutputToContain('storefront '.Shops::id('brandfashion').' · today: 1 sent (1 transactional, 0 bulk) · cap 800 · reserve 200 · bulk left 599')
        ->assertExitCode(0);

    mailboxArtisan('mail:mailbox', ['code' => 'brandfashion', '--show' => true])
        ->expectsOutputToContain('password set')
        ->doesntExpectOutputToContain('suite-only')
        ->assertExitCode(0);
    // The password is read from an environment variable by NAME; an unset name is refused, nothing written.
    mailboxArtisan('mail:mailbox', ['code' => 'brandfashion', '--password-env' => 'WZ_TEST_MAIL_PW_UNSET_'.random_int(1000, 9999)])
        ->expectsOutputToContain('is not set or empty; the password is never passed as an argument')
        ->assertExitCode(1);
    // A code that names no storefront is refused.
    mailboxArtisan('mail:mailbox', ['code' => 'nobody'])->assertExitCode(1);
});

// ── §0(b), the developer's number (2026-10-13 brief) ─────────────────────────────────────────────

it('renders the DEVELOPER\'S WhatsApp number in NONE of the ten mail templates, for either shop', function () {
    /*
     * Two numbers, two roles (BF handover D22): `whatsapp_support` (customer service) is what a
     * customer may reach from a mail; `whatsapp_developer` is the storefront's header/footer only
     * (Footer.jsx:13, Header.jsx:668, Account.jsx:44) and must appear in NO e-mail. Every mailable
     * is rendered here for both storefronts and the developer's digits are counted: 0.
     */
    $developer = StorefrontIdentityFixture::VALUES['watchizer']['whatsapp_developer'];
    expect($developer)->toBe('201274550956')->and(StorefrontIdentityFixture::VALUES['brandfashion']['whatsapp_developer'])->toBe($developer);

    $rendered = [];
    foreach ([1, Shops::id('brandfashion')] as $storefrontId) {
        $identity = StorefrontIdentity::for($storefrontId);
        expect($identity->whatsappDeveloper)->toBe($developer);
        $orderId = mailboxOrder($storefrontId);
        $data = OrderEmailData::for($orderId) ?? [];
        DB::table('orders')->where('id', $orderId)->update(['payment_method' => 'paymob', 'status' => 'cancelled']);
        $expiredData = (OrderEmailData::for($orderId) ?? []) + ['reason' => OrderMailer::REASON_DECLINED];
        $card = ['name' => 'A product', 'price' => 100.0, 'image' => null, 'url' => 'https://example.test/p/1'];
        $reengage = ['email' => 'c@example.test', 'storefront_id' => $storefrontId, 'products' => [$card, $card, $card]];

        /** @var list<Mailable> $mailables */
        $mailables = [
            new OrderConfirmation($data),
            new AdminOrderNotification($data),
            new OrderStatusUpdate($data + ['status' => 'shipped']),
            new PaymentExpired($expiredData),
            new PaymentExpiredAdmin($expiredData),
            new CustomerEmailVerification('https://example.test/verify', 'Shopper', $identity),
            new CustomerPasswordReset('https://example.test/reset', 'Shopper', 60, $identity),
            new StockAlertMail(['email' => 'c@example.test', 'locale' => 'en', 'token' => 'tok', 'storefront_id' => $storefrontId, 'product' => $card], 'https://example.test/stop', $identity),
            new StockAlertMail(['email' => 'c@example.test', 'locale' => 'ar', 'token' => 'tok', 'storefront_id' => $storefrontId, 'product' => $card], 'https://example.test/stop', $identity),
            new ReEngagementMail($reengage, 'https://example.test/unsubscribe', $identity),
            new ReEngagementPreviewMail(['week' => '2026-W42', 'audience' => 'team', 'recipients' => 3, 'send_after' => '2026-10-14 09:00', 'sample' => $reengage], 'https://example.test/manage', $identity),
        ];
        foreach ($mailables as $mailable) {
            $html = $mailable->render();
            expect(mb_strlen($html))->toBeGreaterThan(500);
            $rendered[$storefrontId.':'.$mailable::class.($mailable instanceof StockAlertMail ? ':'.spl_object_id($mailable) : '')] = $html;
        }
    }

    // Ten templates, two shops: 22 renders (the stock alert in both languages), 0 developer numbers.
    $templates = glob(resource_path('views/emails/*.blade.php')) ?: [];
    $templates = array_values(array_filter($templates, fn (string $f): bool => ! str_contains($f, DIRECTORY_SEPARATOR.'partials'.DIRECTORY_SEPARATOR) && ! str_starts_with(basename($f), '_')));
    expect($rendered)->toHaveCount(22)->and(count($templates))->toBe(10);
    $hits = [];
    foreach ($rendered as $key => $html) {
        $n = substr_count($html, $developer) + substr_count($html, '01274550956');
        if ($n > 0) {
            $hits[$key] = $n;
        }
    }
    expect($hits)->toBe([]);
    // …and the customer-service number IS there, in every mail that has a footer.
    expect(substr_count($rendered['1:'.OrderConfirmation::class], 'wa.me/201551096234'))->toBeGreaterThan(0);
});
