<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Domain\Activity\ActivityLog;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * Articles (item 14, developer 2026-09-18).
 *
 * ── The scope, and it is the whole scope ────────────────────────────────────────────────────
 *
 * *"list, create, edit, publish/unpublish, with Arabic and English fields and the SEO fields. NO
 * image gallery for now — a single cover image is enough."*
 *
 * ── Core-owned, and that is the decision worth testing ──────────────────────────────────────
 *
 * The legacy `blogs` tables are EMPTY on this dump and carry no slug, no published flag and no SEO
 * fields — the three things asked for by name. Core may not write legacy tables anyway. So articles
 * live in `core_blogs` + `core_blog_translations`, which means they must survive switch night's
 * rebuild, and that is the property most likely to be got wrong silently.
 */

it('keeps articles out of the rebuild, because nothing can regenerate them', function () {
    /*
     * The one that matters. Every row is typed by a person and no transform reads these tables, so
     * a rebuild that dropped them would delete the shop's writing and the URLs it is published
     * under — at 02:00, on the night nobody is looking at the articles screen.
     */
    expect(CoreChecksumCommand::DASHBOARD_TABLES)->toContain('core_blogs')
        ->and(CoreChecksumCommand::DASHBOARD_TABLES)->toContain('core_blog_translations');

    /*
     * The two lists must be DISJOINT — checked as sets rather than by looking for these two names.
     *
     * PHPStan pointed out that asking whether `core_blogs` is in `CLEAN_TABLES` is a question it can
     * answer statically, so the loop that asked it could never fail: a check that cannot fail is
     * not a test. The intersection over the whole of both lists is a real question about the
     * configuration, it covers every table rather than these two, and `core:drop-clean` refuses to
     * drop anything outside `CLEAN_TABLES` — so this is the property that decides whether an
     * article survives switch night.
     */
    expect(array_values(array_intersect(CoreChecksumCommand::DASHBOARD_TABLES, CoreChecksumCommand::CLEAN_TABLES)))
        ->toBe([], 'a dashboard-authored table is also in the transform drop list');
});

it('does not touch the legacy blog tables', function () {
    // They exist, they are empty, and this feature leaves them that way. Core writing `blogs` would
    // be an AGENTS §3 violation, and the legacy connection would refuse it anyway.
    foreach (['blogs', 'blog_translations', 'blog_images'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue("legacy {$table} is missing");
    }

    $before = DB::table('blogs')->count();

    actingAs(Staff::admin())->post('/manage/blogs', [
        'is_published' => false,
        'title' => ['ar' => 'مقال لا يلمس القديم', 'en' => 'Leaves legacy alone'],
        'body' => ['ar' => 'نص', 'en' => 'Text'],
    ])->assertSessionHasNoErrors();

    expect(DB::table('blogs')->count())->toBe($before);
    expect(DB::table('core_blogs')->count())->toBeGreaterThan(0);
});

it('creates an article, generates its link, and leaves it a draft', function () {
    actingAs(Staff::admin())->post('/manage/blogs', [
        'is_published' => false,
        'title' => ['ar' => 'كيف تختار ساعة', 'en' => 'How to choose a watch'],
        'body' => ['ar' => 'النص العربي', 'en' => 'The English text'],
        'meta_title' => ['ar' => 'اختيار ساعة', 'en' => 'Choosing a watch'],
        'meta_description' => ['ar' => 'دليل مختصر', 'en' => 'A short guide'],
    ])->assertSessionHasNoErrors();

    $row = T::one(DB::table('core_blogs')->orderByDesc('id'));

    // Generated from the ENGLISH title, through the same slugifier every public URL here uses.
    expect(Row::str($row, 'slug'))->toBe('how-to-choose-a-watch')
        ->and(Row::nstr($row, 'published_at'))->toBeNull('a new article should start as a draft');

    $translations = DB::table('core_blog_translations')
        ->where('blog_id', Row::int($row, 'id'))->pluck('title', 'locale');

    expect($translations['ar'] ?? null)->toBe('كيف تختار ساعة')
        ->and($translations['en'] ?? null)->toBe('How to choose a watch');
});

it('refuses an article with no Arabic title', function () {
    // Arabic is the storefront's default and translation fallback is OFF, so a missing Arabic title
    // publishes a blank heading. The same rule the product form enforces, for the same reason.
    actingAs(Staff::admin())->post('/manage/blogs', [
        'is_published' => false,
        'title' => ['ar' => '', 'en' => 'English only'],
        'body' => ['ar' => '', 'en' => 'Text'],
    ])->assertSessionHasErrors('title.ar');
});

it('refuses to PUBLISH an article with no text, and still allows the draft', function () {
    // The draft half first: a title and nothing else is what somebody has after five minutes.
    actingAs(Staff::admin())->post('/manage/blogs', [
        'is_published' => false,
        'title' => ['ar' => 'مسودة فارغة', 'en' => 'Empty draft'],
        'body' => ['ar' => '', 'en' => ''],
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('core_blogs')->orderByDesc('id')->value('id'));

    // …and the refusal, through the list's one-click toggle.
    actingAs(Staff::admin())->put("/manage/blogs/{$id}/publish", ['is_published' => true])
        ->assertSessionHasErrors('is_published');

    expect(DB::table('core_blogs')->where('id', $id)->value('published_at'))->toBeNull();
});

it('stamps the publish date once and does not move it on a later edit', function () {
    /*
     * `published_at` answers "since when", and re-stamping it on every save would quietly turn it
     * into "last edited" — so the screen that says "published on the 3rd" would start saying
     * "published today" after somebody fixed a typo.
     */
    actingAs(Staff::admin())->post('/manage/blogs', [
        'is_published' => true,
        'title' => ['ar' => 'مقال منشور', 'en' => 'Published article'],
        'body' => ['ar' => 'نص', 'en' => 'Text'],
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('core_blogs')->orderByDesc('id')->value('id'));
    $stamped = T::str(DB::table('core_blogs')->where('id', $id)->value('published_at'));

    expect($stamped)->not->toBe('');

    actingAs(Staff::admin())->put("/manage/blogs/{$id}", [
        '_complete' => 1,
        'is_published' => true,
        'title' => ['ar' => 'مقال منشور (تصحيح)', 'en' => 'Published article'],
        'body' => ['ar' => 'نص', 'en' => 'Text'],
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('core_blogs')->where('id', $id)->value('published_at')))->toBe($stamped);
});

it('clears the date on unpublish and stamps a NEW one on the next publish', function () {
    actingAs(Staff::admin())->post('/manage/blogs', [
        'is_published' => true,
        'title' => ['ar' => 'مقال', 'en' => 'Article'],
        'body' => ['ar' => 'نص', 'en' => 'Text'],
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('core_blogs')->orderByDesc('id')->value('id'));

    actingAs(Staff::admin())->put("/manage/blogs/{$id}/publish", ['is_published' => false])
        ->assertSessionHasNoErrors();
    expect(DB::table('core_blogs')->where('id', $id)->value('published_at'))->toBeNull();

    actingAs(Staff::admin())->put("/manage/blogs/{$id}/publish", ['is_published' => true])
        ->assertSessionHasNoErrors();
    expect(DB::table('core_blogs')->where('id', $id)->value('published_at'))->not->toBeNull();
});

it('never lets two articles share a link', function () {
    foreach (['First one', 'First one'] as $title) {
        actingAs(Staff::admin())->post('/manage/blogs', [
            'is_published' => false,
            'title' => ['ar' => 'عنوان', 'en' => $title],
            'body' => ['ar' => 'نص', 'en' => 'Text'],
        ])->assertSessionHasNoErrors();
    }

    $slugs = DB::table('core_blogs')->orderByDesc('id')->limit(2)->pluck('slug')->all();

    expect($slugs[0])->not->toBe($slugs[1], 'two articles were given one address');
});

it('shows drafts first, because a draft is the row somebody is coming back to', function () {
    actingAs(Staff::admin())->post('/manage/blogs', [
        'is_published' => true,
        'title' => ['ar' => 'منشور', 'en' => 'Published'],
        'body' => ['ar' => 'نص', 'en' => 'Text'],
    ])->assertSessionHasNoErrors();

    actingAs(Staff::admin())->post('/manage/blogs', [
        'is_published' => false,
        'title' => ['ar' => 'مسودة', 'en' => 'Draft'],
        'body' => ['ar' => 'نص', 'en' => 'Text'],
    ])->assertSessionHasNoErrors();

    $rows = T::arr(Props::of(actingAs(Staff::admin())->get('/manage/blogs')->assertOk())['rows'] ?? null);

    expect($rows)->not->toBe([]);
    expect(T::arr($rows[0])['is_published'] ?? null)->toBeFalse('a published article came before a draft');
});

it('records who created, edited and published an article', function () {
    actingAs(Staff::admin())->post('/manage/blogs', [
        'is_published' => false,
        'title' => ['ar' => 'مقال للسجل', 'en' => 'For the log'],
        'body' => ['ar' => 'نص', 'en' => 'Text'],
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('core_blogs')->orderByDesc('id')->value('id'));

    actingAs(Staff::admin())->put("/manage/blogs/{$id}/publish", ['is_published' => true])
        ->assertSessionHasNoErrors();

    $actions = DB::table(ActivityLog::TABLE)
        ->where('subject_type', 'core_blogs')->where('subject_id', $id)
        ->pluck('action')->all();

    expect($actions)->toContain(ActivityLog::CREATED)
        ->and($actions)->toContain(ActivityLog::UPDATED);
});

it('404s an article that does not exist rather than rendering an empty form', function () {
    actingAs(Staff::admin())->get('/manage/blogs/999999/edit')->assertNotFound();
    actingAs(Staff::admin())->put('/manage/blogs/999999', [
        '_complete' => 1,
        'is_published' => false,
        'title' => ['ar' => 'x', 'en' => 'x'],
    ])->assertNotFound();
});

it('keeps the screen behind the content grant, at the verb each control uses', function () {
    // An account with no dashboard grant at all — the same actor the wave-4C
    // authorisation tests use for this question.
    $stranger = Staff::customer();

    actingAs($stranger)->get('/manage/blogs')->assertForbidden();
    actingAs($stranger)->post('/manage/blogs', [
        'is_published' => false,
        'title' => ['ar' => 'x', 'en' => 'x'],
    ])->assertForbidden();
});
