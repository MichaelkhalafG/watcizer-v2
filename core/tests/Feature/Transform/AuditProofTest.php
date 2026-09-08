<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Transform\Audit\AuditFinding;
use App\Transform\Audit\AuditReport;
use App\Transform\Audit\AuditRunner;
use App\Transform\Config;
use App\Transform\LegacySource;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/*
 * 🟠-2 (review 2026-09-07): prove the zero-count audit codes FIRE. Method: a session-scoped
 * TEMPORARY table that shadows the legacy table for this connection only (a fixture copy of the
 * real rows plus injected bad rows). The legacy base table is never written; the shadow vanishes
 * with the session and is dropped explicitly after every test. A digest of the legacy tables,
 * taken through the default connection (which never sees the shadow), is asserted unchanged.
 */

final class ShadowFixtures
{
    /** @var list<string> */
    public static array $tables = [];

    public static string $digest = '';
}

/**
 * The callback receives a FACTORY: every statement gets a fresh builder (a reused builder
 * would stack its where clauses and silently touch nothing).
 *
 * @param  callable(callable(): Builder): void  $inject
 */
function shadow(string $table, callable $inject): void
{
    $c = DB::connection('legacy');
    $c->statement("CREATE TEMPORARY TABLE fx_$table LIKE `$table`");
    $c->statement("INSERT INTO fx_$table SELECT * FROM `$table`");
    $c->statement("ALTER TABLE fx_$table RENAME TO `$table`");
    ShadowFixtures::$tables[] = $table;
    $inject(fn (): Builder => $c->table($table));
}

function audit(): AuditReport
{
    return (new AuditRunner(new LegacySource(DB::connection('legacy')), base_path('../backend/public/Uploads_Images'), Config::stringKeyed(config('transform'))))->run();
}

function finding(AuditReport $r, string $code): AuditFinding
{
    $f = $r->get($code);
    if ($f === null) {
        throw new RuntimeException("no finding $code");
    }

    return $f;
}

/** The n-th legacy product id (0-based, by id). */
function legacyProductId(int $nth): int
{
    $row = DB::connection('legacy')->table('products')->select(['id'])->orderBy('id')->skip($nth)->first();
    if (! $row instanceof stdClass) {
        throw new RuntimeException("fewer than $nth legacy products");
    }

    return Row::int($row, 'id');
}

beforeEach(function () {
    ShadowFixtures::$digest = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];
});

afterEach(function () {
    foreach (array_reverse(ShadowFixtures::$tables) as $table) {
        DB::connection('legacy')->statement("DROP TEMPORARY TABLE IF EXISTS `$table`");
    }
    ShadowFixtures::$tables = [];
    expect(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe(ShadowFixtures::$digest);
});

it('baseline: the real data shows the known zero counts (A-06, A-09, A-19, A-20, A-24, A-25)', function () {
    $r = audit();
    foreach (['A-06', 'A-09', 'A-19', 'A-20', 'A-24', 'A-25'] as $code) {
        expect(finding($r, $code)->count())->toBe(0);
    }
});

it('A-19 fires on an orphan pivot row and on a duplicate pair', function () {
    shadow('feature_product', function (callable $q): void {
        $q()->insert(['product_id' => 999999, 'feature_id' => 1]);               // orphan product
        $q()->insert(['product_id' => legacyProductId(0), 'feature_id' => 999999]);   // orphan feature
        $first = $q()->select(['product_id', 'feature_id'])->orderBy('id')->first();
        if ($first instanceof stdClass) {
            $q()->insert(['product_id' => Row::int($first, 'product_id'), 'feature_id' => Row::int($first, 'feature_id')]);   // duplicate pair
        }
    });

    $f = finding(audit(), 'A-19');
    $details = implode(' | ', array_map(fn (array $row) => $row['entity'].':'.$row['id'].':'.$row['detail'], $f->rows));

    expect($f->count())->toBe(3)
        ->and($details)->toContain('orphan: product_id=999999')
        ->and($details)->toContain('feature_id=999999')
        ->and($details)->toContain('duplicate × 2');
});

it('A-20 (blocking) fires on an order_items row pointing at a missing product', function () {
    shadow('order_items', function (callable $q): void {
        $q()->insert(['order_id' => 1, 'product_id' => 999999, 'offer_id' => null, 'quantity' => 1, 'piece_price' => 1, 'total_price' => 1, 'type_stock' => 'Market']);
    });

    $r = audit();
    $f = finding($r, 'A-20');
    expect($f->count())->toBe(1)
        ->and($f->blocks())->toBeTrue()
        ->and($r->isBlocked())->toBeTrue()
        ->and($f->rows[0]['detail'])->toContain('product_id=999999 missing');
});

it('A-24 (blocking) fires on a products row whose brand_id / case_size_type_id do not exist', function () {
    $victim = legacyProductId(0);
    shadow('products', function (callable $q) use ($victim): void {
        $q()->where('id', $victim)->update(['brand_id' => 999999, 'case_size_type_id' => 888888]);
    });

    $f = finding(audit(), 'A-24');
    $details = implode(' | ', array_map(fn (array $row) => $row['detail'], $f->rows));
    expect($f->count())->toBe(2)
        ->and($f->blocks())->toBeTrue()
        ->and($details)->toContain('brand_id=999999 not in brands')
        ->and($details)->toContain('case_size_type_id=888888 not in size_types');
});

it('A-06 (blocking) fires on a duplicated and on an empty wa_code', function () {
    [$a, $b, $c] = [legacyProductId(0), legacyProductId(1), legacyProductId(2)];
    shadow('products', function (callable $q) use ($a, $b, $c): void {
        $code = $q()->where('id', $b)->value('wa_code');
        $q()->where('id', $a)->update(['wa_code' => is_string($code) ? $code : 'dup']);   // duplicate of $b
        $q()->where('id', $c)->update(['wa_code' => '']);                                // empty
    });

    $f = finding(audit(), 'A-06');
    expect($f->count())->toBe(2)->and($f->blocks())->toBeTrue();
});

it('A-09 fires when a product loses its Arabic title', function () {
    [$a, $b] = [legacyProductId(0), legacyProductId(1)];
    shadow('product_translations', function (callable $q) use ($a, $b): void {
        $q()->where('product_id', $a)->where('locale', 'ar')->delete();
        $q()->where('product_id', $b)->where('locale', 'ar')->update(['product_title' => '']);
    });

    $f = finding(audit(), 'A-09');
    $ids = array_map(fn (array $row) => $row['id'], $f->rows);
    expect($f->count())->toBe(2)->and($ids)->toContain((string) $a)->and($ids)->toContain((string) $b);
});

it('A-12 fires on a gallery row whose file cannot exist, listing that row', function () {
    $before = finding(audit(), 'A-12')->count();
    shadow('product_images', function (callable $q): void {
        $q()->insert(['product_id' => legacyProductId(0), 'image' => 'definitely-not-on-disk.webp', 'is_cover' => 0, 'sort' => 99]);
    });

    $f = finding(audit(), 'A-12');
    expect($f->count())->toBe($before + 1)
        ->and(implode(' ', array_map(fn (array $row) => $row['detail'], $f->rows)))->toContain('definitely-not-on-disk.webp');
});

it('re-verification 2026-09-07: A-19 fires on a fresh injection of my own (gender orphan + band-colour duplicate)', function () {
    shadow('gender_product', function (callable $q): void {
        $q()->insert(['product_id' => legacyProductId(3), 'gender_id' => 777777]);        // orphan gender
    });
    shadow('color_band_product', function (callable $q): void {
        $first = $q()->select(['product_id', 'color_id'])->orderBy('id')->first();
        if ($first instanceof stdClass) {
            $q()->insert(['product_id' => Row::int($first, 'product_id'), 'color_id' => Row::int($first, 'color_id')]);   // duplicate pair
        }
    });

    $f = finding(audit(), 'A-19');
    $details = implode(' | ', array_map(fn (array $row) => $row['entity'].':'.$row['id'].':'.$row['detail'], $f->rows));
    expect($f->count())->toBe(2)
        ->and($details)->toContain('gender_product:')
        ->and($details)->toContain('gender_id=777777')
        ->and($details)->toContain('color_band_product:')
        ->and($details)->toContain('duplicate × 2');
});

/*
 * A-26 (milestone audit 2026-09-08) — a new sub type with no products must never be placed by the
 * majority rule. The proof injects one into a shadow of `sub_types` (+ its translations) and shows
 * the code fires and BLOCKS; pinning it in config silences the code; a pin to a category type that
 * does not exist is caught as well.
 */

/** @param  array<int, string>  $subTypes  id => EN/AR name */
function injectOrphanSubTypes(array $subTypes): void
{
    shadow('sub_types', function (callable $q) use ($subTypes): void {
        foreach (array_keys($subTypes) as $id) {
            $q()->insert(['id' => $id, 'image' => null, 'created_at' => now(), 'updated_at' => now()]);
        }
    });
    shadow('sub_type_translations', function (callable $q) use ($subTypes): void {
        foreach ($subTypes as $id => $name) {
            $q()->insert(['locale' => 'en', 'sub_type_id' => $id, 'sub_type_name' => $name]);
            $q()->insert(['locale' => 'ar', 'sub_type_id' => $id, 'sub_type_name' => $name]);
        }
    });
}

function injectOrphanSubType(int $id, string $name): void
{
    injectOrphanSubTypes([$id => $name]);
}

/**
 * The category type the majority rule would guess right now: the one carrying the most DISTINCT
 * sub types among products. Derived, never hard-coded — the answer flipped from Fashion to
 * Watches between rehearsals #1 and #2 as the team added watches, and a literal here would have
 * turned this proof into a data-dependent flake.
 */
function majorityCategoryTypeName(): string
{
    $rows = DB::connection('legacy')->table('products')
        ->selectRaw('category_type_id, COUNT(DISTINCT sub_type_id) AS n')
        ->whereNotNull('category_type_id')->whereNotNull('sub_type_id')
        ->groupBy('category_type_id')->orderByDesc('n')->orderBy('category_type_id')
        ->first();
    $id = $rows === null ? 0 : Row::int($rows, 'category_type_id');

    $name = DB::connection('legacy')->table('category_type_translations')
        ->where('category_type_id', $id)->where('locale', 'en')->value('category_type_name');

    return is_string($name) ? $name : '';
}

it('A-26 BLOCKS on a new sub type that no pin covers, naming it and the category type the majority rule would have guessed', function () {
    expect(finding(audit(), 'A-26')->count())->toBe(0);          // every orphan on today's data is pinned

    injectOrphanSubType(9101, 'Automatic');

    $f = finding(audit(), 'A-26');
    $details = implode(' | ', array_map(fn (array $row) => $row['entity'].':'.$row['id'].':'.$row['detail'], $f->rows));

    expect($f->count())->toBe(1)
        ->and($f->blocking)->toBeTrue()
        ->and($f->blocks())->toBeTrue()                          // a run would abort before writing anything
        ->and($details)->toContain('sub_types:9101')
        ->and($details)->toContain('Automatic')
        ->and($details)->toContain('no pin')
        ->and($details)->toContain(majorityCategoryTypeName());  // the silent fallback it prevents, whichever it is today
});

it('A-26 BLOCKS on a pin whose sub type does not exist in legacy (the rehearsal #2 phantom pins)', function () {
    expect(finding(audit(), 'A-26')->count())->toBe(0);

    $pins = config('transform.orphan_sub_type_parents');
    config(['transform.orphan_sub_type_parents' => (is_array($pins) ? $pins : []) + [9401 => 1, 9402 => 2]]);

    $f = finding(audit(), 'A-26');
    $details = implode(' | ', array_map(fn (array $row) => $row['entity'].':'.$row['id'].':'.$row['detail'], $f->rows));

    expect($f->count())->toBe(2)
        ->and($f->blocks())->toBeTrue()
        ->and($details)->toContain('config:9401')
        ->and($details)->toContain('does not exist in legacy')
        ->and($details)->toContain('delete the pin');
});

it('A-26 and A-27 stay quiet on the corrected pin map', function () {
    $report = audit();

    expect(finding($report, 'A-26')->count())->toBe(0)
        ->and(finding($report, 'A-26')->blocks())->toBeFalse()
        ->and(finding($report, 'A-27')->count())->toBe(0);

    // …and the pin that rehearsal #2 corrected is the one in force: Automatic under Watches.
    $note = finding($report, 'A-26')->note;
    $automatic = DB::connection('legacy')->table('sub_type_translations')->where('locale', 'en')->where('sub_type_name', 'Automatic')->value('sub_type_id');
    if (is_int($automatic)) {
        expect($note)->toContain($automatic.' [Automatic] → 1 [Watches]');
    }
});

it('A-27 warns (without blocking) when a pin contradicts the sub type name, in either direction', function () {
    $pins = config('transform.orphan_sub_type_parents');
    $pins = is_array($pins) ? $pins : [];

    injectOrphanSubTypes([9501 => 'Chronograph Automatic', 9502 => 'Leather Wallet']);   // one reads as a watch, one as fashion
    config(['transform.orphan_sub_type_parents' => $pins + [9501 => 2, 9502 => 1]]);   // both pinned the wrong way

    $f = finding(audit(), 'A-27');
    $details = implode(' | ', array_map(fn (array $row) => $row['entity'].':'.$row['id'].':'.$row['detail'], $f->rows));

    expect($f->count())->toBe(2)
        ->and($f->blocking)->toBeFalse()
        ->and($f->blocks())->toBeFalse()                         // a warning never stops a run
        ->and($details)->toContain('reads as a WATCH sub type but is pinned to category_type 2')
        ->and($details)->toContain('reads as a FASHION sub type but is pinned to category_type 1');

    // The same names pinned the right way round produce nothing.
    config(['transform.orphan_sub_type_parents' => $pins + [9501 => 1, 9502 => 2]]);
    expect(finding(audit(), 'A-27')->count())->toBe(0);
});

it('A-26 goes quiet once the sub type is pinned, and the pin is echoed in the note', function () {
    injectOrphanSubType(9102, 'Chronometer');
    expect(finding(audit(), 'A-26')->count())->toBe(1);

    $pins = config('transform.orphan_sub_type_parents');
    config(['transform.orphan_sub_type_parents' => (is_array($pins) ? $pins : []) + [9102 => 1]]);

    $f = finding(audit(), 'A-26');
    expect($f->count())->toBe(0)
        ->and($f->blocks())->toBeFalse()
        ->and($f->note)->toContain('9102 [Chronometer] → 1 [Watches]');
});

it('A-26 catches a pin that points at a category type which does not exist', function () {
    injectOrphanSubType(9103, 'Sandglass');
    $pins = config('transform.orphan_sub_type_parents');
    config(['transform.orphan_sub_type_parents' => (is_array($pins) ? $pins : []) + [9103 => 4242]]);

    $f = finding(audit(), 'A-26');
    expect($f->count())->toBe(1)
        ->and($f->rows[0]['detail'])->toContain('pinned to category_type 4242, which does not exist');
});

it('every pin in the map names a sub type that exists, and the real Automatic row is pinned to Watches', function () {
    $pins = config('transform.orphan_sub_type_parents');
    $pins = is_array($pins) ? $pins : [];
    $existing = DB::connection('legacy')->table('sub_types')->pluck('id')->map(fn (mixed $v) => (int) (is_numeric($v) ? $v : 0))->all();

    expect($pins)->not->toBeEmpty();
    foreach (array_keys($pins) as $subId) {
        expect((int) $subId)->toBeIn($existing);                 // no phantom pins (rehearsal #2)
    }

    $automatic = DB::connection('legacy')->table('sub_type_translations')->where('locale', 'en')->where('sub_type_name', 'Automatic')->value('sub_type_id');
    $automatic = is_int($automatic) ? $automatic : 0;
    expect($pins[$automatic] ?? null)->toBe(1);                  // watch complication → Watches

    // …and with that row present but unpinned, A-26 blocks the rehearsal.
    config(['transform.orphan_sub_type_parents' => array_diff_key($pins, [$automatic => null])]);
    expect(finding(audit(), 'A-26')->blocks())->toBeTrue();
})->skip(fn () => DB::connection('legacy')->table('sub_type_translations')->where('locale', 'en')->where('sub_type_name', 'Automatic')->value('sub_type_id') === null, 'no Automatic sub type in this dump');
