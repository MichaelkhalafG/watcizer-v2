<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Notifications\StorefrontMailer;
use App\Models\Storefront\StorefrontMailbox;
use App\Storefront\StorefrontIdentity;
use App\Storefront\StorefrontUrls;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Support\Shops;
use Tests\Support\Staff;
use Tests\Support\StorefrontIdentityFixture;
use Tests\Support\StorefrontMailboxFixture;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * ── §4.1 (2026-10-13): the storefront settings screen owns what was SQL-only ────────────────────
 *
 * Backlog SF-SETTINGS-SCREEN reverses SF-URL-SETTINGS (2026-10-12): the domain, the four
 * `settings.urls.*` overrides, the five `settings.identity.*` values and the mailbox row are set
 * on ONE screen, `/manage/storefronts/{id}/edit`, validated before the write with the readers'
 * own refusals, and the save reaches the shop at once. Reachable by `manage-storefronts` holders
 * only, scoped: a Brand-Fashion-scoped admin edits Brand Fashion and is refused Watchizer.
 */

/**
 * The full form body the screen posts, for one storefront, with the three sections.
 *
 * @param  array<array-key, mixed>  $overrides
 * @return array<array-key, mixed>
 */
function settingsBody(int $storefrontId, array $overrides = []): array
{
    $code = $storefrontId === 1 ? 'watchizer' : 'brandfashion';

    return array_replace_recursive([
        'name' => $storefrontId === 1 ? 'Watchizer' : 'Brand Fashion',
        'domain' => Shops::domain($code),
        'locales' => ['ar', 'en'],
        'default_locale' => 'en',
        'currency' => 'EGP',
        'is_active' => true,
        'money_rewards' => false,
        'urls' => ['frontend' => '', 'payment_return' => '', 'api_host' => '', 'asset_base' => ''],
        'identity' => StorefrontIdentityFixture::VALUES[$code],
        'mailbox' => [
            'is_enabled' => true, 'host' => 'smtp.example.test', 'port' => 465, 'encryption' => 'ssl', 'username' => 'no-reply@'.$code.'.test',
            'password' => '', 'from_address' => 'no-reply@'.$code.'.test', 'from_name' => ucfirst($code),
            'daily_cap' => 800, 'transactional_reserve' => 200, 'order_recipients' => "team@{$code}.test\nowner@{$code}.test",
        ],
    ], $overrides);
}

it('shows the three sections with the stored values, the derived URLs, and NEVER the mailbox password', function () {
    $fashion = Shops::id('brandfashion');
    $page = actingAs(Staff::admin())->get("/manage/storefronts/{$fashion}/edit")->assertOk();

    $page->assertInertia(fn (AssertableInertia $p) => $p
        ->component('Manage/Storefronts/Edit')
        ->where('storefront.identity.whatsapp_support', '201283888490')
        ->where('storefront.identity.mail_wordmark_exists', true)
        ->where('storefront.derived_urls.api_host', Shops::api('brandfashion'))
        ->where('storefront.urls.api_host', '')
        ->where('storefront.mailbox.exists', true)
        ->where('storefront.mailbox.password_set', true)
        ->where('storefront.mailbox.daily_cap', 800)
        ->missing('storefront.mailbox.password')
        ->missing('storefront.mailbox.credentials'));

    // The page's HTML carries no trace of the stored password either.
    expect($page->getContent())->not->toContain('suite-only');
});

it('saves all three sections, and the shop sees them AT ONCE — the cache is cleared, proven', function () {
    $fashion = Shops::id('brandfashion');
    // Prime every cache a reader uses, so a stale answer would be visible.
    expect(StorefrontUrls::apiHost($fashion))->toBe(Shops::api('brandfashion'))
        ->and(StorefrontIdentity::for($fashion)->whatsappSupport)->toBe('201283888490')
        ->and(StorefrontMailer::mailbox($fashion)->fromAddress())->toBe('no-reply@brandfashion.test');

    actingAs(Staff::admin())->put("/manage/storefronts/{$fashion}", settingsBody($fashion, [
        'urls' => ['api_host' => 'https://api.bf.michaelkhalaf.com/', 'frontend' => 'https://bf.michaelkhalaf.com'],
        'identity' => ['whatsapp_support' => '201000000001', 'copyright' => '© 2026 Brand Fashion Ltd.'],
        'mailbox' => ['from_address' => 'orders@brandfashion.test', 'password' => 'a-new-password', 'order_recipients' => "a@brandfashion.test, b@brandfashion.test\nb@brandfashion.test"],
    ]))->assertRedirect('/manage/storefronts')->assertSessionHasNoErrors();

    // Readers answer the new values with no cache:clear and no wait.
    expect(StorefrontUrls::apiHost($fashion))->toBe('https://api.bf.michaelkhalaf.com')          // trailing slash dropped
        ->and(StorefrontUrls::frontend($fashion))->toBe('https://bf.michaelkhalaf.com')
        ->and(StorefrontUrls::assetBase($fashion))->toBe(Shops::api('brandfashion'))              // untouched: still derived from the domain
        ->and(StorefrontIdentity::for($fashion)->whatsappSupport)->toBe('201000000001')
        ->and(StorefrontIdentity::for($fashion)->copyright)->toBe('© 2026 Brand Fashion Ltd.')
        ->and(StorefrontIdentity::for($fashion)->whatsappDeveloper)->toBe('201274550956');
    $row = StorefrontMailbox::query()->where('storefront_id', $fashion)->firstOrFail();
    expect($row->fromAddress())->toBe('orders@brandfashion.test')
        ->and($row->password())->toBe('a-new-password')
        ->and($row->orderRecipients())->toBe(['a@brandfashion.test', 'b@brandfashion.test'])      // split on comma or newline, de-duplicated
        ->and(StorefrontMailer::mailbox($fashion)->fromAddress())->toBe('orders@brandfashion.test');

    // Settings keys this screen does not own survive the save.
    $settings = T::arr(json_decode(T::str(DB::table('storefronts')->where('id', $fashion)->value('settings')), true));
    expect($settings['urls'] ?? null)->toBe(['frontend' => 'https://bf.michaelkhalaf.com', 'api_host' => 'https://api.bf.michaelkhalaf.com']);

    // Logged: the changed fields by name, the password as a marker, never the value.
    $log = T::row(DB::table(ActivityLog::TABLE)->where('subject_type', 'storefronts')->where('subject_id', $fashion)->where('action', ActivityLog::UPDATED)->orderByDesc('id')->first());
    $changes = T::arr(json_decode(T::str($log->changes ?? ''), true));
    expect(array_keys($changes))->toContain('urls.api_host', 'identity.whatsapp_support', 'mailbox.from_address', 'password')
        ->and(T::arr($changes['password'] ?? [])['to'] ?? null)->toBe(ActivityLog::REDACTED)
        ->and(json_encode($changes))->not->toContain('a-new-password');
});

it('keeps the stored password when the field is left blank', function () {
    $fashion = Shops::id('brandfashion');
    actingAs(Staff::admin())->put("/manage/storefronts/{$fashion}", settingsBody($fashion))->assertSessionHasNoErrors();
    expect(StorefrontMailbox::query()->where('storefront_id', $fashion)->firstOrFail()->password())->toBe('suite-only');
});

it('refuses UP FRONT what the readers would refuse at send time: no host, bad URL, missing wordmark file, bad number, empty copyright', function () {
    $fashion = Shops::id('brandfashion');
    $before = T::str(DB::table('storefronts')->where('id', $fashion)->value('settings'));

    // No domain and no overrides: the storefront would have no host.
    actingAs(Staff::admin())->put("/manage/storefronts/{$fashion}", settingsBody($fashion, ['domain' => '']))
        ->assertSessionHasErrors(['urls.frontend', 'urls.api_host', 'urls.asset_base']);

    actingAs(Staff::admin())->put("/manage/storefronts/{$fashion}", settingsBody($fashion, [
        'domain' => 'https://brandfashionegy.com',
        'urls' => ['api_host' => 'http://api.example.test', 'frontend' => 'https://example.test/shop', 'payment_return' => 'https://example.test/return?x=1'],
        'identity' => ['mail_wordmark' => 'assets/brand/not-there.png', 'whatsapp_support' => '+20 155 109 6234', 'copyright' => '  '],
        'mailbox' => ['transactional_reserve' => 900, 'order_recipients' => 'not-an-address'],
    ]))->assertSessionHasErrors([
        'domain', 'urls.api_host', 'urls.frontend', 'urls.payment_return',
        'identity.mail_wordmark', 'identity.whatsapp_support', 'identity.copyright',
        'mailbox.transactional_reserve', 'mailbox.order_recipients',
    ]);

    // Nothing was written by a refused save.
    expect(T::str(DB::table('storefronts')->where('id', $fashion)->value('settings')))->toBe($before)
        ->and(StorefrontIdentity::for($fashion)->whatsappSupport)->toBe('201283888490');
});

it('still accepts the pre-§4.1 form — a body without the three sections changes none of them', function () {
    $fashion = Shops::id('brandfashion');
    actingAs(Staff::admin())->put("/manage/storefronts/{$fashion}", [
        'name' => 'Brand Fashion', 'domain' => Shops::domain('brandfashion'), 'locales' => ['ar', 'en'], 'default_locale' => 'en',
        'currency' => 'EGP', 'is_active' => true, 'money_rewards' => false,
    ])->assertSessionHasNoErrors();
    expect(StorefrontIdentity::for($fashion)->whatsappSupport)->toBe('201283888490')
        ->and(StorefrontMailer::mailbox($fashion)->fromAddress())->toBe('no-reply@brandfashion.test');
});

it('is reachable by manage-storefronts holders only, within their scope', function () {
    $fashion = Shops::id('brandfashion');
    // Nobody: the sign-in page. (First, before any actingAs: the acting user persists for the test.)
    get("/manage/storefronts/{$fashion}/edit")->assertRedirect();
    // A Brand-Fashion-scoped admin: their own shop, not the live one (EnsureStorefrontScope answers
    // 404 for a storefront outside the grant, so the live shop's existence is not confirmed either).
    actingAs(Staff::adminFor($fashion))->get("/manage/storefronts/{$fashion}/edit")->assertOk();
    actingAs(Staff::adminFor($fashion))->get('/manage/storefronts/1/edit')->assertNotFound();
    actingAs(Staff::adminFor($fashion))->put('/manage/storefronts/1', settingsBody(1))->assertNotFound();
    // Data entry: refused outright.
    actingAs(Staff::dataEntry())->get("/manage/storefronts/{$fashion}/edit")->assertForbidden();
});

it('creates the mailbox row for a storefront that has none, and reports what a real send still needs', function () {
    $fashion = Shops::id('brandfashion');
    StorefrontMailboxFixture::remove($fashion);
    actingAs(Staff::admin())->get("/manage/storefronts/{$fashion}/edit")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.mailbox.exists', false)->where('storefront.mailbox.password_set', false));

    actingAs(Staff::admin())->put("/manage/storefronts/{$fashion}", settingsBody($fashion, ['mailbox' => ['host' => '', 'password' => '']]))->assertSessionHasNoErrors();

    $row = StorefrontMailbox::query()->where('storefront_id', $fashion)->firstOrFail();
    expect($row->credentialsSet())->toBeFalse()
        ->and($row->missing(needsTransport: true))->toBe(['host', 'password'])
        ->and($row->orderRecipients())->toBe(['team@brandfashion.test', 'owner@brandfashion.test']);
    actingAs(Staff::admin())->get("/manage/storefronts/{$fashion}/edit")
        ->assertInertia(fn (AssertableInertia $p) => $p->where('storefront.mailbox.exists', true)->where('storefront.mailbox.missing', ['host', 'password']));
});
