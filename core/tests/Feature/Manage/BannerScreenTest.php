<?php

use App\Domain\Activity\ActivityLog;
use App\Domain\Content\BannerState;
use App\Domain\Content\BannerWriter;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\put;

/*
 * Home-page banners (wave 4D).
 *
 * The banner is the most visible row in the shop, and the ways to get it wrong are few and
 * expensive: a link that goes nowhere, a window that closed without anyone noticing, an image
 * nobody uploaded. Every one of those is a refusal in the WRITER, so a console caller meets the
 * same door as the screen.
 */

beforeEach(function () {
    DB::table('storefront_banners')->delete();
});

/**
 * A saved banner on one storefront. Returns its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function makeBanner(array $overrides = [], int $storefrontId = 1): int
{
    return app(BannerWriter::class)->save($overrides + [
        'storefront_id' => $storefrontId,
        'image_path' => '1700_2026-01-01_abc.webp',
        'target' => 'none',
        'sort_order' => 0,
        'is_active' => true,
    ], null);
}

/**
 * The oldest LEGACY product — one the transform made, never one the importer is writing right now.
 * Deliberate: this suite may run while a long import holds locks on the rows it is creating, and a
 * test has no business waiting on one.
 */
function legacyProduct(): int
{
    return T::int(DB::table('catalog_products')
        ->whereNull('deleted_at')->whereNull('import_ref')->orderBy('id')->value('id'));
}

// ── 1. the refusals ──────────────────────────────────────────────────────────────────────────

it('refuses a banner with no image — a banner IS an image', function () {
    expect(fn () => makeBanner(['image_path' => '']))->toThrow(ValidationException::class);
    expect(T::int(DB::table('storefront_banners')->count()))->toBe(0);
});

it('refuses a destination that is named but not given', function () {
    expect(fn () => makeBanner(['target' => 'product']))->toThrow(ValidationException::class);
    expect(fn () => makeBanner(['target' => 'category']))->toThrow(ValidationException::class);
    expect(fn () => makeBanner(['target' => 'url']))->toThrow(ValidationException::class);
});

it('refuses a link that is not a URL or an in-site path', function () {
    // `javascript:` is either a broken link or an injection wearing one.
    expect(fn () => makeBanner(['target' => 'url', 'link_url' => 'javascript:alert(1)']))
        ->toThrow(ValidationException::class);
    expect(fn () => makeBanner(['target' => 'url', 'link_url' => 'watches']))
        ->toThrow(ValidationException::class);

    // …and accepts the two shapes that work.
    $absolute = makeBanner(['target' => 'url', 'link_url' => 'https://watchizereg.com/offers']);
    $relative = makeBanner(['target' => 'url', 'link_url' => '/category/watches']);
    expect($absolute)->toBeGreaterThan(0)->and($relative)->toBeGreaterThan(0);
});

it('refuses an end date that is not after the start', function () {
    expect(fn () => makeBanner([
        'starts_at' => '2026-10-01 00:00:00',
        'ends_at' => '2026-09-01 00:00:00',
    ]))->toThrow(ValidationException::class);
});

it('refuses a category from ANOTHER storefront, which would be a dead link', function () {
    $foreign = T::int(DB::table('storefront_categories')->where('storefront_id', 2)->value('id'));

    expect(fn () => makeBanner(['target' => 'category', 'storefront_category_id' => $foreign], storefrontId: 1))
        ->toThrow(ValidationException::class);
});

it('refuses a product that is not on this storefront', function () {
    // A product nobody added to storefront 1 has no page there, so the banner would open a 404.
    $product = legacyProduct();
    DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $product)->delete();

    expect(fn () => makeBanner(['target' => 'product', 'product_id' => $product], storefrontId: 1))
        ->toThrow(ValidationException::class);
});

it('stores exactly ONE destination, never two', function () {
    // The payload names a product AND a URL; only the named target survives.
    $id = makeBanner([
        'target' => 'url',
        'link_url' => '/category/watches',
        'product_id' => legacyProduct(),
    ]);

    $row = T::row(DB::table('storefront_banners')->where('id', $id)->first());

    expect(Row::nstr($row, 'link_url'))->toBe('/category/watches')
        ->and(Row::nint($row, 'product_id'))->toBeNull()
        ->and(Row::nint($row, 'storefront_category_id'))->toBeNull();
});

it('writes the HOME placement and never takes one from the payload', function () {
    // The developer's decision: home only. The column stays open (see BannerWriter), so the
    // constraint has to be in the writer — and a payload cannot slip past it.
    $id = makeBanner(['placement' => 'side']);

    expect(T::str(DB::table('storefront_banners')->where('id', $id)->value('placement')))
        ->toBe(BannerWriter::PLACEMENTS[0])
        ->toBe('home');
});

// ── 2. the at-a-glance state ─────────────────────────────────────────────────────────────────

it('says RUNNING, SCHEDULED, EXPIRED and INACTIVE for the four cases', function () {
    $now = '2026-09-14 12:00:00';

    $state = fn (array $row): string => BannerState::of((object) $row, $now)['state'];

    expect($state(['is_active' => 1, 'starts_at' => null, 'ends_at' => null]))->toBe(BannerState::RUNNING)
        ->and($state(['is_active' => 1, 'starts_at' => '2026-10-01 00:00:00', 'ends_at' => null]))->toBe(BannerState::SCHEDULED)
        ->and($state(['is_active' => 1, 'starts_at' => null, 'ends_at' => '2026-09-01 00:00:00']))->toBe(BannerState::EXPIRED)
        ->and($state(['is_active' => 0, 'starts_at' => null, 'ends_at' => null]))->toBe(BannerState::INACTIVE);

    // An EXPIRED banner is loud even when it is also switched off: the window closing is the thing
    // nobody noticed.
    expect($state(['is_active' => 0, 'starts_at' => null, 'ends_at' => '2026-09-01 00:00:00']))->toBe(BannerState::EXPIRED)
        ->and(BannerState::tone(BannerState::EXPIRED))->toBe('destructive');
});

// ── 3. the screen ────────────────────────────────────────────────────────────────────────────

it('renders the list with its state, and the state filter CHANGES the rows', function () {
    actingAs(Staff::dataEntry());

    makeBanner(['sort_order' => 1]);
    makeBanner(['sort_order' => 2, 'is_active' => false]);
    makeBanner(['sort_order' => 3, 'ends_at' => '2026-01-01 00:00:00']);

    $all = Props::table(get('/manage/storefronts/1/banners'));
    $running = Props::table(get('/manage/storefronts/1/banners?filters[state]=running'));
    $expired = Props::table(get('/manage/storefronts/1/banners?filters[state]=expired'));

    $total = fn (array $table): int => T::int(T::arr($table['meta'] ?? null)['total'] ?? null);

    // §4 law: a filter must CHANGE the set.
    expect($total($all))->toBe(3)
        ->and($total($running))->toBe(1)
        ->and($total($expired))->toBe(1);

    $states = array_column(Props::rows($all), 'state');
    expect($states)->toContain(BannerState::RUNNING)
        ->and($states)->toContain(BannerState::INACTIVE)
        ->and($states)->toContain(BannerState::EXPIRED);
});

it('creates, updates and deletes through the screen', function () {
    actingAs(Staff::dataEntry());

    post('/manage/storefronts/1/banners', [
        'image_path' => '1700_2026-01-01_abc.webp',
        'target' => 'url',
        'link_url' => '/category/watches',
        'sort_order' => 5,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('storefront_banners')->orderByDesc('id')->value('id'));

    put("/manage/storefronts/1/banners/{$id}", [
        'image_path' => '1700_2026-01-01_abc.webp',
        'target' => 'none',
        'sort_order' => 9,
        'is_active' => false,
    ])->assertSessionHasNoErrors();

    expect(T::int(DB::table('storefront_banners')->where('id', $id)->value('sort_order')))->toBe(9)
        ->and(T::int(DB::table('storefront_banners')->where('id', $id)->value('is_active')))->toBe(0);

    delete("/manage/storefronts/1/banners/{$id}")->assertSessionHasNoErrors();
    expect(DB::table('storefront_banners')->where('id', $id)->exists())->toBeFalse();
});

it('404s a banner that belongs to another storefront, never 403', function () {
    actingAs(Staff::admin());
    $other = makeBanner([], storefrontId: 2);

    // §3.11.14: an id is guessable, and a 403 would confirm the guess.
    put("/manage/storefronts/1/banners/{$other}", [
        'image_path' => 'x.webp', 'target' => 'none', 'is_active' => true,
    ])->assertNotFound();

    delete("/manage/storefronts/1/banners/{$other}")->assertNotFound();
});

it('records what the home page used to show, and who changed it', function () {
    /*
     * The gap this closes (2026-10-05). A banner is the first thing a customer sees and every
     * failure it has is silent — an empty hero slot, a link that goes nowhere, a window that
     * closed. Nothing here recorded anything, so "the offer banner is gone" and "it opens the wrong
     * category" had the same answer: nobody knows, and nobody knows what it pointed at before.
     */
    $admin = Staff::admin();
    actingAs($admin);

    post('/manage/storefronts/1/banners', [
        'image_path' => '1700_2026-01-01_abc.webp',
        'target' => 'url',
        'link_url' => '/category/watches',
        'sort_order' => 5,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('storefront_banners')->orderByDesc('id')->value('id'));

    put("/manage/storefronts/1/banners/{$id}", [
        'image_path' => '1700_2026-01-01_abc.webp',
        'target' => 'none',
        'sort_order' => 5,
        'is_active' => true,
    ])->assertSessionHasNoErrors();

    $actions = DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'storefront_banners')->where('subject_id', $id)
        ->orderBy('id')->pluck('action')->all();

    expect($actions)->toContain(ActivityLog::CREATED);
    expect($actions)->toContain(ActivityLog::UPDATED);

    $row = T::one(DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'storefront_banners')->where('subject_id', $id)
        ->where('action', ActivityLog::UPDATED)->orderByDesc('id'));

    expect(T::int($row->user_id))->toBe(T::int($admin->getAttribute('id')))
        ->and(T::str($row->user_name))->toBe(Staff::nameOf($admin))
        // A banner has no name: the image FILE is what identifies one, which is why the export
        // carries the same column.
        ->and(T::str($row->subject_label))->toBe('1700_2026-01-01_abc.webp')
        ->and(T::int($row->storefront_id))->toBe(1);

    // Where it USED to send people — the destination is the whole question a banner's log answers.
    $changes = T::arr(json_decode(T::str($row->changes), true));
    $link = T::arr($changes['link_url'] ?? null);

    expect(T::str($link['from'] ?? null))->toBe('/category/watches')
        // Nulled, not merely absent: the banner now points nowhere, and `?? …` would hide that.
        ->and($link)->toHaveKey('to')
        ->and($link['to'])->toBeNull();

    // …and the delete says which banner left the home page, after the row is gone.
    delete("/manage/storefronts/1/banners/{$id}")->assertSessionHasNoErrors();

    $deleted = T::one(DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'storefront_banners')->where('subject_id', $id)
        ->where('action', ActivityLog::DELETED)->orderByDesc('id'));

    expect(T::str($deleted->subject_label))->toBe('1700_2026-01-01_abc.webp');
});
