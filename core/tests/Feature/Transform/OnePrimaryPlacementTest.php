<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Transform\LegacySource;
use App\Transform\Row;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Tests\Support\LegacyShadow;

use function Pest\Laravel\artisan;

/*
 * Milestone audit 🔴-2 — exactly one primary placement per (storefront, product).
 *
 * The audit's case: a product moves from Sport to Chronograph in legacy. The additive transform
 * kept the Sport placement flagged primary and added a second primary on Chronograph, so the
 * emitted category (`sub_type_id` in compat, `primary_category` in v2) became order-dependent.
 *
 * Reproduced on a TEMPORARY shadow of `products` (wave-1 recipe, Tests\Support\LegacyShadow):
 * the legacy base table is never written, and the 65-table digest is asserted unchanged.
 */

/**
 * Run step 19 alone. `$expectedExit` is 1 once the catalog carries a stale placement: an
 * additive transform never deletes the abandoned row, so the reconciliation's exact count
 * (products-with-type + products-with-pair) no longer matches and says so loudly. That is the
 * designed behaviour — and the reason switch night is a drop-and-rebuild (milestone audit 🔴-1),
 * not an incremental run.
 */
function primaryPlacementRun(int $expectedExit = 0): void
{
    $dir = storage_path('framework/testing/one-primary-'.getmypid());
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    $pending = artisan('core:transform', ['--only' => '19', '--output' => $dir, '--force' => true]);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertExitCode($expectedExit);
    $pending->run();
}

/** @return array<string, mixed>|null the reconciliation row for a table, from the run's summary.json */
function reconciliationRow(string $table): ?array
{
    $json = file_get_contents(storage_path('framework/testing/one-primary-'.getmypid()).'/summary.json');
    $data = json_decode($json === false ? '{}' : $json, true);
    $rows = is_array($data) && is_array($data['reconciliation'] ?? null) ? ($data['reconciliation']['rows'] ?? []) : [];
    foreach (is_array($rows) ? $rows : [] as $row) {
        if (is_array($row) && ($row['table'] ?? null) === $table) {
            $out = [];
            foreach ($row as $k => $v) {
                $out[(string) $k] = $v;
            }

            return $out;
        }
    }

    return null;
}

/** @return array<int, int> node id => is_primary */
function placementsOf(int $productId): array
{
    $out = [];
    foreach (DB::table('storefront_category_product')->where('storefront_id', 1)->where('product_id', $productId)->orderBy('storefront_category_id')->get(['storefront_category_id', 'is_primary']) as $row) {
        $out[Row::int($row, 'storefront_category_id')] = Row::int($row, 'is_primary');
    }

    return $out;
}

function intValue(mixed $value): int
{
    return is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);
}

function subTypeNodeId(int $legacySubTypeId): int
{
    return intValue(DB::table('storefront_categories')->where('storefront_id', 1)->where('legacy_source', 'sub_type')->where('legacy_id', $legacySubTypeId)->value('id'));
}

afterEach(fn () => LegacyShadow::closeAll());

it('refuses a second primary placement at the database level (M1d)', function () {
    $productId = intValue(DB::table('storefront_category_product')->where('storefront_id', 1)->where('is_primary', 1)->orderBy('product_id')->value('product_id'));
    $free = intValue(DB::table('storefront_categories')->where('storefront_id', 1)->where('depth', 2)
        ->whereNotIn('id', DB::table('storefront_category_product')->where('product_id', $productId)->pluck('storefront_category_id'))
        ->orderBy('id')->value('id'));

    $insert = fn (int $isPrimary) => DB::table('storefront_category_product')->insert([
        'storefront_id' => 1, 'storefront_category_id' => $free, 'product_id' => $productId,
        'sort_order' => 0, 'is_primary' => $isPrimary, 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(fn () => $insert(1))->toThrow(UniqueConstraintViolationException::class);   // second primary → 1062
    $insert(0);                                                                // a non-primary placement on the same node is fine
    expect(DB::table('storefront_category_product')->where('storefront_id', 1)->where('product_id', $productId)->where('is_primary', 1)->count())->toBe(1);
});

it('moves the primary when the legacy sub type changes, keeps the old row, and never has two (Sport → Chronograph)', function () {
    $digestBefore = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];

    $productId = intValue(DB::connection('legacy')->table('products')->where('category_type_id', 1)->where('sub_type_id', 4)->orderBy('id')->value('id'));
    expect($productId)->toBeGreaterThan(0);
    $sport = subTypeNodeId(4);
    $chronograph = subTypeNodeId(2);
    expect($sport)->toBeGreaterThan(0)->and($chronograph)->toBeGreaterThan(0);

    expect(placementsOf($productId)[$sport] ?? null)->toBe(1);

    // Legacy moves the product to Chronograph — on the shadow, never the real table.
    LegacyShadow::open('products', function (callable $table) use ($productId) {
        $table()->where('id', $productId)->update(['sub_type_id' => 2]);
    });
    primaryPlacementRun(expectedExit: 1);                          // the abandoned Sport row breaks the exact count
    LegacyShadow::closeAll();

    $after = placementsOf($productId);
    $primaries = array_keys(array_filter($after, fn (int $flag) => $flag === 1));

    expect($primaries)->toBe([$chronograph])                       // exactly one, and it is the new sub type
        ->and($after)->toHaveKey($sport)                           // the old placement row survives (additive transform)
        ->and($after[$sport])->toBe(0)                             // demoted, not deleted
        ->and(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($digestBefore);

    // …and the run said so out loud rather than leaving a silent second primary.
    $recon = reconciliationRow('storefront_category_product');
    expect($recon)->not->toBeNull();
    expect($recon['ok'] ?? null)->toBeFalse();
    expect(intValue($recon['actual'] ?? null))->toBe(intValue($recon['expected'] ?? null) + 1);

    // Legacy still says Sport, so the next run moves the primary back (the stale row stays).
    primaryPlacementRun(expectedExit: 1);
    expect(array_keys(array_filter(placementsOf($productId), fn (int $flag) => $flag === 1)))->toBe([$sport]);
});

it('leaves the whole catalog with at most one primary per product', function () {
    $multi = DB::table('storefront_category_product')
        ->where('storefront_id', 1)->where('is_primary', 1)
        ->groupBy('product_id')->havingRaw('COUNT(*) > 1')
        ->pluck('product_id');

    expect($multi->all())->toBe([]);
});
