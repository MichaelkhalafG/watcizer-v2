<?php

use App\Domain\Catalog\LookupWriter;
use App\Storefront\Lookups;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\post;

/*
 * Brands and the eleven lookup lists (scope item 6) — one screen, twelve datasets, driven by
 * `config('catalog.lookups')`.
 *
 * The usage count is the point: every reference from a product or a variant is a `RESTRICT`
 * foreign key (M1 `restrictOnDelete()`), so deleting a colour 200 products use fails at the
 * DATABASE with a 1451 that reaches a team member as a 500 page. The count, and the refusal that
 * names it, is the message; the foreign key stays the mechanism.
 */

it('exposes every configured list, and every list points at real tables', function () {
    $lists = LookupWriter::all();

    expect($lists)->toHaveCount(12)
        ->and($lists)->toHaveKey('brands')
        ->and($lists)->toHaveKey('colors')
        ->and($lists)->toHaveKey('units');

    $broken = [];
    foreach ($lists as $key => $def) {
        foreach ([$def['master'], $def['translations']] as $table) {
            if (! Schema::hasTable($table)) {
                $broken[] = "{$key}: table {$table} does not exist";
            }
        }
        if (! Schema::hasColumn($def['translations'], $def['fk'])) {
            $broken[] = "{$key}: {$def['translations']}.{$def['fk']} does not exist";
        }
        foreach ($def['usage'] as [$table, $column]) {
            if (! Schema::hasColumn($table, $column)) {
                $broken[] = "{$key}: usage {$table}.{$column} does not exist";
            }
        }
        foreach (array_keys($def['extra']) as $column) {
            if (! Schema::hasColumn($def['master'], $column)) {
                $broken[] = "{$key}: extra column {$def['master']}.{$column} does not exist";
            }
        }
    }

    expect($broken)->toBe([], 'config/catalog.php names something the schema does not have');
});

it('shares its keys with the storefront read layer, so the two cannot disagree', function () {
    // `App\Storefront\Lookups::TABLES` is what the storefront caches. A list the dashboard calls
    // `movements` and the read layer calls `movement_types` would be two names for one dataset.
    $shared = array_intersect(array_keys(LookupWriter::all()), array_keys(Lookups::TABLES));

    expect(count($shared))->toBeGreaterThanOrEqual(11);

    foreach ($shared as $key) {
        expect(LookupWriter::definition($key)['master'])->toBe(Lookups::TABLES[$key][0], "list [{$key}] points at a different table than the read layer");
    }
});

it('creates a row with both names and its extra columns', function () {
    CatalogFixture::assumeSwitched();
    actingAs(Staff::dataEntry())->post('/manage/lookups/colors', [
        'name' => ['ar' => 'أزرق اختبار', 'en' => 'Test Blue'],
        'extra' => ['hex' => '#1a2b3c'],
    ])->assertSessionHasNoErrors();

    $row = T::one(DB::table('catalog_colors')->orderByDesc('id'));
    // Stored upper-case, as the transform stores it (deviation D-07): CSS does not care, but a
    // mixed-case column makes two identical colours look different.
    expect(Row::str($row, 'hex'))->toBe('#1A2B3C');

    $names = DB::table('catalog_color_translations')->where('color_id', Row::int($row, 'id'))->pluck('name', 'locale');
    expect($names['ar'])->toBe('أزرق اختبار')->and($names['en'])->toBe('Test Blue');
});

it('refuses a row with no Arabic name', function () {
    CatalogFixture::assumeSwitched();
    actingAs(Staff::dataEntry())->post('/manage/lookups/colors', [
        'name' => ['ar' => '', 'en' => 'English only'],
        'extra' => ['hex' => '#000000'],
    ])->assertSessionHasErrors('name.ar');
});

it('REFUSES an emptied English name, because both languages are required', function () {
    /*
     * This asserted the opposite until 2026-09-18: an emptied English name deleted the row rather
     * than storing a blank. The developer adopted the legacy rule instead — *"name required in both
     * languages, min:2, unique per language"* — after the audit found what it would cost: across
     * all twelve lists and both locales, **0 duplicate names and 0 blank English names**. The rule
     * locks in what the data already satisfies.
     *
     * The English row therefore survives untouched, because a refusal writes nothing.
     */
    CatalogFixture::assumeSwitched();
    actingAs(Staff::admin())->post('/manage/lookups/materials', [
        'name' => ['ar' => 'مادة', 'en' => 'Material'],
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('catalog_materials')->orderByDesc('id')->value('id'));

    actingAs(Staff::admin())->put("/manage/lookups/materials/{$id}", [
        '_complete' => 1,
        'name' => ['ar' => 'مادة', 'en' => ''],
    ])->assertSessionHasErrors('name.en');

    expect(T::str(DB::table('catalog_material_translations')->where('material_id', $id)->where('locale', 'en')->value('name')))
        ->toBe('Material');
});

it('REFUSES a duplicate name within one language, and allows the same spelling across languages', function () {
    /*
     * The rule legacy enforced on every one of these lists, and the reason it matters: two brands
     * called "Rolex" split a catalogue in half and nobody notices, because both look right.
     *
     * Per LANGUAGE, deliberately — a lookup whose Arabic and English read the same («Rolex» /
     * "Rolex") is ordinary, and refusing that would make the rule unusable for exactly the brands
     * it most needs to protect.
     */
    CatalogFixture::assumeSwitched();

    actingAs(Staff::admin())->post('/manage/lookups/materials', [
        'name' => ['ar' => 'Steel', 'en' => 'Steel'],
        // No errors AT ALL, not just on one key: the same spelling in both languages is an ordinary
        // save and nothing about it should be refused.
    ])->assertSessionHasNoErrors();

    actingAs(Staff::admin())->post('/manage/lookups/materials', [
        'name' => ['ar' => 'مادة أخرى', 'en' => 'Steel'],
    ])->assertSessionHasErrors('name.en');
});

it('counts usage, and REFUSES to delete a row anything references', function () {
    // The real case: a colour used by a product pivot. The FK is RESTRICT, so the refusal here is
    // the message and not the mechanism — but the message is what stops the 500.
    $colorId = T::int(DB::table('catalog_product_color')->orderBy('color_id')->value('color_id'));
    $uses = DB::table('catalog_product_color')->where('color_id', $colorId)->count();
    expect($uses)->toBeGreaterThan(0);

    actingAs(Staff::admin())->delete("/manage/lookups/colors/{$colorId}")->assertSessionHasErrors('delete');

    expect(DB::table('catalog_colors')->where('id', $colorId)->exists())->toBeTrue();
    expect(T::err('delete'))->toContain((string) $uses);
});

it('deletes a row nothing references', function () {
    CatalogFixture::assumeSwitched();
    actingAs(Staff::admin())->post('/manage/lookups/colors', [
        'name' => ['ar' => 'لون غير مستخدم', 'en' => 'Unused colour'],
        'extra' => ['hex' => '#abcdef'],
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('catalog_colors')->orderByDesc('id')->value('id'));
    expect(DB::table('catalog_product_color')->where('color_id', $id)->count())->toBe(0);

    actingAs(Staff::admin())->delete("/manage/lookups/colors/{$id}")->assertSessionHasNoErrors();

    expect(DB::table('catalog_colors')->where('id', $id)->exists())->toBeFalse()
        ->and(DB::table('catalog_color_translations')->where('color_id', $id)->exists())->toBeFalse();
});

it('shows a usage count per row, computed in one query per reference pair', function () {
    $props = Props::of(actingAs(Staff::dataEntry())->get('/manage/lookups/colors')->assertOk());
    /** @var list<array<string, mixed>> $rows */
    $rows = $props['rows'];

    expect($rows)->not->toBe([]);

    $used = 0;
    foreach ($rows as $row) {
        expect($row)->toHaveKey('uses');
        if (T::int($row['uses']) > 0) {
            $used++;
        }
    }
    expect($used)->toBeGreaterThan(0, 'no colour on the screen shows a usage count, which cannot be right');

    // Spot-check one against the database, so the number is real and not a placeholder.
    $first = $rows[0];
    $id = T::int($first['id']);
    $actual = DB::table('catalog_product_color')->where('color_id', $id)->count()
        + DB::table('catalog_product_variants')->where('color_id', $id)->count();
    expect(T::int($first['uses']))->toBe($actual);
});

it('generates a brand slug from the English name and refuses an Arabic-only one', function () {
    CatalogFixture::assumeSwitched();
    // Public URLs are `LegacySlug`-shaped: Arabic characters are dropped, so an Arabic-only name
    // yields '' and the writer refuses rather than creating a brand with an empty URL.
    actingAs(Staff::admin())->post('/manage/lookups/brands', [
        'name' => ['ar' => 'ماركة اختبار', 'en' => 'Test Brand Name'],
        'extra' => ['slug' => '', 'is_active' => true],
    ])->assertSessionHasNoErrors();

    expect(T::str(DB::table('catalog_brands')->orderByDesc('id')->value('slug')))->toBe('test-brand-name');

    actingAs(Staff::admin())->post('/manage/lookups/brands', [
        'name' => ['ar' => 'ماركة عربية فقط', 'en' => ''],
        'extra' => ['slug' => '', 'is_active' => true],
    ])->assertSessionHasErrors();
});

it('refuses a duplicate brand slug with a field error', function () {
    CatalogFixture::assumeSwitched();
    $existing = T::str(DB::table('catalog_brands')->orderBy('id')->value('slug'));

    actingAs(Staff::admin())->post('/manage/lookups/brands', [
        'name' => ['ar' => 'مكرر', 'en' => 'Duplicate'],
        'extra' => ['slug' => $existing, 'is_active' => true],
    ])->assertSessionHasErrors('extra.slug');
});

it('validates a hex colour instead of storing whatever arrives', function () {
    CatalogFixture::assumeSwitched();
    foreach (['nope', '#12345', 'red', '#1234567'] as $bad) {
        actingAs(Staff::admin())->post('/manage/lookups/colors', [
            'name' => ['ar' => 'سيئ', 'en' => 'Bad'],
            'extra' => ['hex' => $bad],
        ])->assertSessionHasErrors('extra.hex');
    }
});

it('bumps every storefront cache version when a lookup name changes', function () {
    // A lookup name is in `catalog/meta`, the compat `names` payload and every product DTO, so a
    // rename must invalidate every storefront.
    $cache = app(StorefrontCache::class);
    $before = $cache->version(1);

    $id = T::int(DB::table('catalog_colors')->orderBy('id')->value('id'));
    actingAs(Staff::admin())->put("/manage/lookups/colors/{$id}", [
        '_complete' => 1,
        'name' => ['ar' => 'اسم جديد', 'en' => 'New name'],
        'extra' => ['hex' => '#111111'],
    ])->assertSessionHasNoErrors();

    expect($cache->version(1))->toBeGreaterThan($before);
});

it('renders every list without error, which is what twelve datasets on one screen has to mean', function () {
    foreach (array_keys(LookupWriter::all()) as $key) {
        $props = Props::of(actingAs(Staff::dataEntry())->get("/manage/lookups/{$key}")->assertOk());

        expect($props['list'])->toBeArray();
        /** @var array<string, mixed> $list */
        $list = $props['list'];
        expect($list['key'])->toBe($key)
            ->and($props['rows'])->toBeArray()
            ->and($props['lists'])->toBeArray();
    }
});

it('keeps the lookup screens catalogue-wide, with no storefront in the URL', function () {
    // A colour is a colour on every storefront (D3: one shared catalogue), so these routes name no
    // storefront and carry no scope middleware — and the authorisation test asserts the opposite
    // for the routes that DO name one.
    $route = Route::getRoutes()->getByName('manage.lookups.index');
    expect($route)->not->toBeNull();
    if ($route === null) {
        return;
    }
    expect($route->uri())->not->toContain('{storefront}');

    // It is still storefront-AWARE where it matters: a rename invalidates every storefront's
    // cache, which the test above proves.
    expect(CatalogFixture::STOREFRONT)->toBe(1);
});

/*
 * ── The boolean that was never a boolean (item 11, 2026-09-18) ───────────────────────────────
 *
 * The developer reported "brands are all inactive". They are not: all 78 rows of `catalog_brands`
 * carry `is_active = 1`. The screen was reading the flag wrong, and the two tests below are the
 * two halves of what that cost.
 *
 * `LookupWriter::rows()` passed the column through `is_scalar($value) ? $value : null`, so a
 * MariaDB TINYINT arrived at the browser as the JSON number `1`. The switch renders
 * `checked={value === true}`, and `1 === true` is false in JavaScript — so every brand on the
 * screen showed as switched OFF while every brand in the database was on.
 *
 * The second half is the one that matters more, and nobody had hit it yet: the same screen sends
 * the row back on save. `extraPayload()` maps a boolean column to `value === true`, which for the
 * incoming `1` is FALSE. Renaming a brand — or setting its logo, or fixing its slug — would have
 * written `is_active = 0` on the way out. A display bug that deactivates 78 brands the first time
 * somebody edits one is a data bug wearing a display bug's clothes.
 *
 * Fixed at the SOURCE rather than in the browser: `config/catalog.php` declares the column's type
 * and the server holds that config, so the server is what owes the client the right shape. A cast
 * in the React file would have left the wrong value on the wire for the CSV export and for every
 * screen added later.
 */

it('sends a declared boolean as a real boolean, not as the digit the database returns', function () {
    $rows = app(LookupWriter::class)->rows('brands');

    expect($rows)->not->toBe([], 'no brands — this test proves nothing');

    $wrong = [];
    foreach ($rows as $row) {
        $value = T::arr($row['extra'] ?? null)['is_active'] ?? null;
        if (! is_bool($value)) {
            $wrong[] = T::int($row['id'] ?? null).': '.get_debug_type($value);
        }
    }

    expect($wrong)->toBe([], 'is_active reached the client as something other than a boolean');
});

it('round-trips a row unchanged: what the screen was GIVEN, sent back, changes nothing', function () {
    CatalogFixture::assumeSwitched();

    /*
     * This is the assertion that would have caught the whole defect, and it is deliberately not
     * written as "is_active stays 1". It takes the row exactly as `rows()` hands it to the browser,
     * posts it straight back through the screen's own `_complete` endpoint, and requires the
     * database to be unchanged.
     *
     * That property is what a full-replace endpoint owes its own screen. Break it in either
     * direction — the server sending a shape the form cannot round-trip, or the form sending a
     * shape the server reads differently — and an operator who opened a brand to fix a typo saves
     * a silent change to a column they never touched.
     */
    $rows = app(LookupWriter::class)->rows('brands');
    $row = null;
    foreach ($rows as $candidate) {
        if (T::int($candidate['uses'] ?? null) > 0) {
            $row = $candidate;
            break;
        }
    }

    expect($row)->not->toBeNull('no brand has any products — this test proves nothing');
    if ($row === null) {
        return;
    }

    $id = T::int($row['id'] ?? null);
    $before = T::one(DB::table('catalog_brands')->where('id', $id));
    $names = T::arr($row['name'] ?? null);

    actingAs(Staff::admin())->put("/manage/lookups/brands/{$id}", [
        '_complete' => 1,
        'name' => ['ar' => T::str($names['ar'] ?? null), 'en' => T::str($names['en'] ?? null)],
        'extra' => T::arr($row['extra'] ?? null),
    ])->assertSessionHasNoErrors();

    $after = T::one(DB::table('catalog_brands')->where('id', $id));

    $drifted = [];
    foreach (['slug', 'logo_path', 'is_active'] as $column) {
        // Compared as strings because the two reads come back through different paths and `1` and
        // `'1'` are the same stored value — the point of this test is DRIFT, not representation.
        $was = Coerce::str($before->{$column} ?? null);
        $now = Coerce::str($after->{$column} ?? null);
        if ($was !== $now) {
            $drifted[] = "{$column}: '{$was}' -> '{$now}'";
        }
    }

    expect($drifted)->toBe([], 'a save that changed nothing changed something');
});

/*
 * ── "Any brand with products should be active" — as a rule, not as an UPDATE (item 11) ───────
 *
 * The developer asked for the state fixed and the rule made to hold. The state needed nothing:
 * all 78 brands were already active, and the report came from the boolean defect above. So the
 * whole of this item is the rule, written where it can actually hold.
 *
 * What `is_active` does is the reason it deserves one. It does NOT hide the brand's products —
 * they keep selling. It removes the brand from `Storefront\Meta`'s brand list and stops
 * `Storefront\Sitemaps` emitting its page. Switching Rolex off drops a browse path and a URL
 * Google has indexed, while 125 products carry on as if nothing happened. That is exactly the
 * kind of change that is never noticed until somebody asks why traffic fell.
 */

it('refuses to switch off a lookup row that products still use, and says what it would cost', function () {
    CatalogFixture::assumeSwitched();

    $rows = app(LookupWriter::class)->rows('brands');
    $used = null;
    foreach ($rows as $row) {
        if (T::int($row['uses'] ?? null) > 0 && (T::arr($row['extra'] ?? null)['is_active'] ?? null) === true) {
            $used = $row;
            break;
        }
    }

    expect($used)->not->toBeNull('no active brand has products — this test proves nothing');
    if ($used === null) {
        return;
    }

    $id = T::int($used['id'] ?? null);
    $names = T::arr($used['name'] ?? null);
    $extra = T::arr($used['extra'] ?? null);
    $extra['is_active'] = false;

    actingAs(Staff::admin())->put("/manage/lookups/brands/{$id}", [
        '_complete' => 1,
        'name' => ['ar' => T::str($names['ar'] ?? null), 'en' => T::str($names['en'] ?? null)],
        'extra' => $extra,
    ])->assertSessionHasErrors('extra.is_active');

    // …and the refusal actually held: the column is untouched.
    expect((bool) Row::int(T::one(DB::table('catalog_brands')->where('id', $id)), 'is_active'))->toBeTrue();
});

it('still lets a row nothing uses be switched off', function () {
    CatalogFixture::assumeSwitched();

    /*
     * The half that makes the guard a rule rather than a wall. A brand with no products is exactly
     * what somebody retires, and a refusal there would be the screen inventing a policy nobody
     * asked for — 51 of the 78 brands carry no products at all.
     */
    $rows = app(LookupWriter::class)->rows('brands');
    $unused = null;
    foreach ($rows as $row) {
        if (T::int($row['uses'] ?? null) === 0 && (T::arr($row['extra'] ?? null)['is_active'] ?? null) === true) {
            $unused = $row;
            break;
        }
    }

    expect($unused)->not->toBeNull('every brand has products — this test proves nothing');
    if ($unused === null) {
        return;
    }

    $id = T::int($unused['id'] ?? null);
    $names = T::arr($unused['name'] ?? null);
    $extra = T::arr($unused['extra'] ?? null);
    $extra['is_active'] = false;

    actingAs(Staff::admin())->put("/manage/lookups/brands/{$id}", [
        '_complete' => 1,
        'name' => ['ar' => T::str($names['ar'] ?? null), 'en' => T::str($names['en'] ?? null)],
        'extra' => $extra,
    ])->assertSessionHasNoErrors();

    expect((bool) Row::int(T::one(DB::table('catalog_brands')->where('id', $id)), 'is_active'))->toBeFalse();
});

/*
 * ── The colour picker writes what the hex box writes (item 9, 2026-09-18) ────────────────────
 *
 * The swatch on this screen used to be a read-only `<span>`: it SHOWED the colour and could not set
 * it, so the only way in was to type six hexadecimal digits from memory. Nobody knows that
 * ذهبي وردي is `#B76E79`.
 *
 * Both controls now write. The picker is React and this tree has no JavaScript test runner, so what
 * is asserted here is the half a browser test could not tell you anyway: that the value a picker
 * produces — always lower-case `#rrggbb`, that is what the platform gives — survives the round trip
 * and lands in the column the same way a typed one does.
 */

it('accepts the lower-case hex a colour picker produces, and stores it like any other', function () {
    CatalogFixture::assumeSwitched();

    actingAs(Staff::admin())->post('/manage/lookups/colors', [
        'name' => ['ar' => 'لون المنتقي', 'en' => 'Picker colour'],
        // Exactly what `<input type="color">` hands back.
        'extra' => ['hex' => '#b76e79'],
    ])->assertSessionHasNoErrors();

    $row = T::one(DB::table('catalog_colors')->orderByDesc('id'));

    // Upper-cased on the way in, as the transform stores it (deviation D-07) — so one colour
    // cannot appear twice in the list because two people typed it in different cases.
    expect(Row::str($row, 'hex'))->toBe('#B76E79');
});

it('still refuses a hex that no picker could have produced', function () {
    CatalogFixture::assumeSwitched();

    // The hex BOX stays free-text on purpose — it is for pasting a code a supplier sent, and
    // normalising as somebody types fights the cursor. So the server is what refuses, and it must
    // keep doing so now that a second control writes the same field.
    actingAs(Staff::admin())->post('/manage/lookups/colors', [
        'name' => ['ar' => 'لون غير صالح', 'en' => 'Bad colour'],
        'extra' => ['hex' => '#B7'],
    ])->assertSessionHasErrors();
});

/*
 * ── The record twelve lists leave behind (2026-10-05) ────────────────────────────────────────
 *
 * Brands, colours, materials, shapes, genders, features, sizes, movements, closures, display types
 * and grades are all edited from here, and none of it was recorded. "Who deleted this brand?" had
 * no answer on the one screen where a delete is permanent.
 *
 * ONE subject type for all twelve, with the list in the LABEL — because the question is about the
 * brand, not about the table, and twelve subject types would be twelve filter entries on a screen
 * most people touch twice a year. The list key travels in `changes` as well, so a history per list
 * is still reachable without matching on a label.
 *
 * `ActivityLog::record()` swallows its own exceptions by design: a call that stopped working would
 * never say so, which is the whole reason this test is not optional.
 */

it('records who added, renamed and deleted a list row — with the list named in the label', function () {
    CatalogFixture::assumeSwitched();

    // Signed in FIRST: the role grant is itself an audited event.
    $admin = Staff::admin();
    actingAs($admin);

    // Read from the config rather than typed here, so the assertion holds in either language —
    // `definition()` resolves the list's own name through the translation seam.
    $list = Coerce::str(LookupWriter::definition('colors')['label']);

    post('/manage/lookups/colors', [
        'name' => ['ar' => 'لون السجل', 'en' => 'Log colour'],
        'extra' => ['hex' => '#123456'],
    ])->assertSessionHasNoErrors();

    $id = T::int(DB::table('catalog_colors')->orderByDesc('id')->value('id'));

    $created = T::one(DB::table('core_activity_log')
        ->where('subject_type', 'catalog_lookups')->where('subject_id', $id)
        ->where('action', 'created')->orderByDesc('id'));

    expect(T::int($created->user_id))->toBe(T::int($admin->getAttribute('id')))
        ->and(T::str($created->user_name))->toBe(Staff::nameOf($admin))
        // `الألوان: لون السجل` — the list, then the row, which is how somebody would say it.
        ->and(T::str($created->subject_label))->toBe($list.': لون السجل');

    $changes = T::arr(json_decode(T::str($created->changes), true));
    expect(T::str(T::arr($changes['list'] ?? null)['to'] ?? null))->toBe('colors')
        // Both names, because either can be the one somebody is searching for…
        ->and(T::str(T::arr($changes['name_en'] ?? null)['to'] ?? null))->toBe('Log colour')
        // …and whatever extra column THIS list declares, read from the config rather than a
        // hard-coded set — so a list that gains a column is recorded without anyone remembering.
        ->and(T::str(T::arr($changes['hex'] ?? null)['to'] ?? null))->toBe('#123456');

    // ── a rename ─────────────────────────────────────────────────────────────────────────────
    DB::table('core_activity_log')->where('subject_type', 'catalog_lookups')->delete();

    actingAs($admin)->put("/manage/lookups/colors/{$id}", [
        'name' => ['ar' => 'لون السجل', 'en' => 'Renamed colour'],
        'extra' => ['hex' => '#123456'],
        // This endpoint replaces the whole record, so it refuses a payload that has not declared
        // itself complete (§2.9.7). The screen sends it; a test posting by hand has to as well.
        '_complete' => 1,
    ])->assertSessionHasNoErrors();

    $updated = T::one(DB::table('core_activity_log')
        ->where('subject_type', 'catalog_lookups')->where('subject_id', $id)->orderByDesc('id'));

    $name = T::arr(T::arr(json_decode(T::str($updated->changes), true))['name_en'] ?? null);
    expect(T::str($updated->action))->toBe('updated')
        ->and(T::str($name['from'] ?? null))->toBe('Log colour')
        ->and(T::str($name['to'] ?? null))->toBe('Renamed colour');

    // ── a delete, which on this screen is permanent ──────────────────────────────────────────
    actingAs($admin)->delete("/manage/lookups/colors/{$id}")->assertSessionHasNoErrors();

    $deleted = T::one(DB::table('core_activity_log')
        ->where('subject_type', 'catalog_lookups')->where('subject_id', $id)
        ->where('action', 'deleted')->orderByDesc('id'));

    // The row is gone from its own table, so this entry is the only surviving description of it —
    // which is exactly why the snapshot is captured BEFORE the delete and not read back after.
    expect(T::str($deleted->subject_label))->toBe($list.': لون السجل');

    $gone = T::arr(json_decode(T::str($deleted->changes), true));
    expect(T::str(T::arr($gone['name_en'] ?? null)['from'] ?? null))->toBe('Renamed colour')
        ->and(T::arr($gone['name_en'] ?? null)['to'] ?? null)->toBeNull();
});

it('records NOTHING for a delete the screen refused, because nothing was deleted', function () {
    $admin = Staff::admin();
    actingAs($admin);

    // A colour a product still uses: the screen refuses, and an entry saying it was deleted would
    // be describing something that never happened.
    $colorId = T::int(DB::table('catalog_product_color')->orderBy('color_id')->value('color_id'));
    $before = DB::table('core_activity_log')->where('subject_type', 'catalog_lookups')->count();

    delete("/manage/lookups/colors/{$colorId}")->assertSessionHasErrors('delete');

    expect(DB::table('core_activity_log')->where('subject_type', 'catalog_lookups')->count())
        ->toBe($before);
});
