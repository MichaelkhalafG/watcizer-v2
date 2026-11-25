<?php

use App\Console\Commands\StorefrontSupportEmailCommand;
use App\Domain\Notifications\OrderMailer;
use App\Mail\OrderConfirmation;
use App\Storefront\StorefrontIdentity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\PaymentFixture;
use Tests\Support\SideDdl;
use Tests\Support\StorefrontMailboxFixture;
use Tests\Support\T;

/*
 * Each shop's support e-mail (Michael, 2026-11-25): set by a four-pass data step on the shop's identity, read by every
 * e-mail as its contact line, and editable on the Storefronts screen. The phone line of the e-mails is unchanged.
 */

beforeEach(function () {
    SideDdl::run(StorefrontSupportEmailCommand::backupDdl(), freshSnapshot: true);
    DB::table(StorefrontSupportEmailCommand::BACKUP)->delete();
    // as MailIdentityTest: the order e-mails are sent in the request, inside the test's transaction, never parked
    config(['notifications.send.inline' => true, 'notifications.send.inside_transaction' => true, 'notifications.send.park' => false]);
    StorefrontMailboxFixture::recipients(1, ['ops@example.test']);
    StorefrontMailboxFixture::recipients(2, ['ops@example.test']);
    Mail::fake();
    // as production has it today: no support e-mail on either shop
    foreach ([1, 2] as $sf) {
        $settings = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', $sf)->value('settings') ?? '{}'), true) ?: []);
        $identity = T::arr($settings['identity'] ?? []);
        unset($identity['support_email']);
        $settings['identity'] = $identity;
        DB::table('storefronts')->where('id', $sf)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }
});

it('sets both shops in four passes: a dry run, the apply, a second run with nothing to do, an exact undo', function () {
    $all = fn (): array => DB::table('storefronts')->orderBy('id')->pluck('settings', 'id')->all();
    $before = $all();
    expect(Artisan::call('storefronts:support-email'))->toBe(0);
    expect(Artisan::output())->toContain('shop 1 (watchizer): (none) -> watchizer@eleganceeg.com')->toContain('DRY RUN: 2 shop(s) would be set');
    expect($all())->toBe($before);

    expect(Artisan::call('storefronts:support-email', ['--apply' => true]))->toBe(0);
    expect(Artisan::output())->toContain('APPLIED: 2 shop(s) set.');
    expect(StorefrontIdentity::for(1)->supportEmail)->toBe('watchizer@eleganceeg.com')
        ->and(StorefrontIdentity::for(2)->supportEmail)->toBe('brandfashion@eleganceeg.com');
    $after = $all();

    expect(Artisan::call('storefronts:support-email', ['--apply' => true]))->toBe(0);
    expect(Artisan::output())->toContain('NOTHING TO DO');
    expect($all())->toBe($after);

    expect(Artisan::call('storefronts:support-email', ['--undo' => true]))->toBe(0);
    expect(Artisan::output())->toContain('UNDONE: 2 shop(s) back; 0 left');
    expect($all())->toBe($before);
});

it('stops without writing when a shop already holds another address', function () {
    $settings = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', 2)->value('settings')), true));
    $settings['identity'] = ['support_email' => 'someone@example.test'] + T::arr($settings['identity'] ?? []);
    DB::table('storefronts')->where('id', 2)->update(['settings' => json_encode($settings)]);
    $before = DB::table('storefronts')->orderBy('id')->pluck('settings', 'id')->all();
    expect(Artisan::call('storefronts:support-email', ['--apply' => true]))->toBe(1);
    expect(Artisan::output())->toContain('STOP-ROW storefront 2')->toContain('nothing was written');
    expect(DB::table('storefronts')->orderBy('id')->pluck('settings', 'id')->all())->toBe($before);
});

it('puts the shop\'s own address in its order e-mails, and none of the other shop\'s', function () {
    Artisan::call('storefronts:support-email', ['--apply' => true]);
    $orders = [];
    foreach ([1, 2] as $sf) {
        $orders[$sf] = PaymentFixture::order(total: 100.0, storefrontId: $sf);
        DB::table('orders')->where('id', $orders[$sf])->update(['guest_email' => 'shopper@example.test', 'guest_name' => 'Support Test', 'guest_phone' => '01000000000', 'payment_method' => 'cash']);
        app(OrderMailer::class)->placedNow($orders[$sf], notifyCustomer: true);
    }
    $rendered = [];
    Mail::assertSent(OrderConfirmation::class, function (OrderConfirmation $m) use (&$rendered): bool {
        $rendered[] = $m->render();

        return true;
    });
    [$fashion, $watchizer] = str_contains($rendered[0], 'brandfashion-mail-wordmark') ? [$rendered[0], $rendered[1]] : [$rendered[1], $rendered[0]];
    expect($watchizer)->toContain('mailto:watchizer@eleganceeg.com')->toContain('Or e-mail us')->toContain('wa.me/201551096234')
        ->and(str_contains($watchizer, 'brandfashion@eleganceeg.com'))->toBeFalse()
        ->and($fashion)->toContain('mailto:brandfashion@eleganceeg.com')->toContain('wa.me/201014179820')
        ->and(str_contains($fashion, 'watchizer@eleganceeg.com'))->toBeFalse();
});

it('leaves the contact line out for a shop without an address', function () {
    expect(StorefrontIdentity::for(1)->supportEmail)->toBeNull();
    $html = view('emails.partials.footer', StorefrontIdentity::for(1)->viewData())->render();
    expect($html)->not->toContain('mailto:')->and($html)->toContain('wa.me/');
});

it('makes the customer e-mails reply to the shop\'s support address, keeps the sender, and leaves the team\'s own mails alone', function () {
    Artisan::call('storefronts:support-email', ['--apply' => true]);
    $order = PaymentFixture::order(total: 100.0, storefrontId: 2);
    DB::table('orders')->where('id', $order)->update(['guest_email' => 'shopper@example.test', 'guest_name' => 'Reply Test', 'guest_phone' => '01000000000', 'payment_method' => 'cash']);
    app(OrderMailer::class)->placedNow($order, notifyCustomer: true);
    Mail::assertSent(OrderConfirmation::class, fn (OrderConfirmation $m): bool => $m->hasReplyTo('brandfashion@eleganceeg.com')
        && $m->hasFrom(T::str(DB::table('storefront_mailboxes')->where('storefront_id', 2)->value('from_address'))));
    Mail::assertSent(\App\Mail\AdminOrderNotification::class, fn (\App\Mail\AdminOrderNotification $m): bool => ! $m->hasReplyTo('brandfashion@eleganceeg.com'));
    expect(\App\Domain\Notifications\StorefrontMailer::REPLY_TO_SUPPORT)->toContain(\App\Mail\CartReminderMail::class, \App\Mail\CampaignMail::class);
});
