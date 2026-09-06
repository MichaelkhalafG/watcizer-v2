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
