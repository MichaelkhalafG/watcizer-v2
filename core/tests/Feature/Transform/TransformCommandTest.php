<?php

use App\Console\Commands\CoreChecksumCommand;
use App\Support\LegacySlug;
use App\Transform\LegacySource;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

/*
 * Runs against the local legacy copy inside the test transaction (DatabaseTransactions on
 * both connections): every clean-table write below is rolled back when the test ends.
 * The command's own per-step transactions become savepoints.
 */

function transformOutputDir(string $suffix): string
{
    $dir = storage_path('framework/testing/transform-'.$suffix.'-'.getmypid());
    if (! is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    return $dir;
}

/** @param  array<string, mixed>  $args */
function runTransform(array $args, int $expectedExit = 0): void
{
    $pending = artisan('core:transform', $args);
    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() did not return a PendingCommand');
    }
    $pending->assertExitCode($expectedExit);
}

/** @return array<string, mixed> */
function readSummary(string $dir): array
{
    $json = file_get_contents($dir.'/summary.json');
    $data = json_decode($json === false ? '{}' : $json, true);
    $out = [];
    if (is_array($data)) {
        foreach ($data as $k => $v) {
            if (is_string($k)) {
                $out[$k] = $v;
            }
        }
    }

    return $out;
}

/** @param  array<string, mixed>  $summary */
function summaryPath(array $summary, string ...$keys): mixed
{
    $v = $summary;
    foreach ($keys as $key) {
        if (! is_array($v) || ! array_key_exists($key, $v)) {
            return null;
        }
        $v = $v[$key];
    }

    return $v;
}

function legacyFirstProduct(): stdClass
{
    $row = DB::connection('legacy')->table('products')->orderBy('id')->first(['id', 'wa_code', 'stock', 'market_stock']);
    if (! $row instanceof stdClass) {
        throw new RuntimeException('legacy products is empty');
    }

    return $row;
}

it('runs the audit alone, writes every code, and does not block on this data', function () {
    $dir = transformOutputDir('audit');
    $cleanBefore = CoreChecksumCommand::compute(CoreChecksumCommand::CLEAN_TABLES)['digest'];

    runTransform(['--audit' => true, '--output' => $dir, '--force' => true]);

    $csv = file_get_contents($dir.'/audit.csv');
    expect($csv)->toBeString();
    foreach (array_merge(array_map(fn (int $n) => sprintf('A-%02d', $n), range(1, 25)), ['X-01', 'X-02', 'X-03', 'X-04', 'X-05', 'X-06']) as $code) {
        expect($csv)->toContain("\n$code,");
    }
    expect(file_exists($dir.'/audit.md'))->toBeTrue()
        ->and(summaryPath(readSummary($dir), 'audit_blocked'))->toBeFalse()
        ->and(CoreChecksumCommand::compute(CoreChecksumCommand::CLEAN_TABLES)['digest'])->toBe($cleanBefore);
});

it('dry-runs the full transform, rolls everything back, and leaves legacy untouched', function () {
    $dir = transformOutputDir('dry');
    $productsBefore = DB::table('catalog_products')->count();
    $legacyBefore = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];
    $cleanBefore = CoreChecksumCommand::compute(CoreChecksumCommand::CLEAN_TABLES)['digest'];

    runTransform(['--dry-run' => true, '--output' => $dir, '--force' => true]);

    $summary = readSummary($dir);
    expect(summaryPath($summary, 'status'))->toBe('dry-run ok')
        ->and(summaryPath($summary, 'reconciliation', 'passed'))->toBeTrue()
        ->and($productsBefore === 0 ? summaryPath($summary, 'totals', 'inserted') : 0)->toBeGreaterThanOrEqual($productsBefore === 0 ? 1 : 0)
        ->and(summaryPath($summary, 'totals', 'updated'))->toBe(0)
        ->and(DB::table('catalog_products')->count())->toBe($productsBefore)
        ->and(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($legacyBefore)
        ->and(CoreChecksumCommand::compute(CoreChecksumCommand::CLEAN_TABLES)['digest'])->toBe($cleanBefore);
});

it('transforms the legacy copy, reconciles every count, preserves ids and slugs, and converges on a second run', function () {
    $dir1 = transformOutputDir('real1');
    $dir2 = transformOutputDir('real2');
    $legacyBefore = CoreChecksumCommand::compute(LegacySource::TABLES)['digest'];

    runTransform(['--output' => $dir1, '--force' => true]);

    $first = readSummary($dir1);
    $touched = (int) (is_int(summaryPath($first, 'totals', 'inserted')) ? summaryPath($first, 'totals', 'inserted') : 0)
        + (int) (is_int(summaryPath($first, 'totals', 'unchanged')) ? summaryPath($first, 'totals', 'unchanged') : 0);
    expect(summaryPath($first, 'status'))->toBe('ok')
        ->and(summaryPath($first, 'reconciliation', 'passed'))->toBeTrue()
        ->and($touched)->toBeGreaterThan(0);

    $products = DB::connection('legacy')->table('products')->count();
    expect(DB::table('catalog_products')->count())->toBe($products)
        ->and(DB::table('storefront_product')->where('storefront_id', 1)->count())->toBe($products)
        ->and(DB::table('catalog_product_translations')->count())->toBe(2 * $products)
        ->and(DB::table('inventory_movements')->where('reason', 'transform')->count())->toBe(2 * $products);

    // ids preserved + slug rule = legacy sitemap/SPA rule
    $legacyFirst = legacyFirstProduct();
    $id = Row::int($legacyFirst, 'id');
    $clean = DB::table('catalog_products')->where('id', $id)->first(['wa_code', 'stock_express', 'stock_market', 'family']);
    if (! $clean instanceof stdClass) {
        throw new RuntimeException("catalog_products $id missing");
    }
    expect(Row::str($clean, 'wa_code'))->toBe(Row::str($legacyFirst, 'wa_code'))
        ->and(Row::int($clean, 'stock_express'))->toBe(Row::int($legacyFirst, 'stock'))
        ->and(Row::int($clean, 'stock_market'))->toBe(Row::nint($legacyFirst, 'market_stock') ?? 0);

    $enTitle = DB::connection('legacy')->table('product_translations')->where('product_id', $id)->where('locale', 'en')->value('product_title');
    $slug = DB::table('storefront_product')->where('storefront_id', 1)->where('product_id', $id)->value('slug');
    $expectedSlug = LegacySlug::orId(is_string($enTitle) ? $enTitle : '', $id);
    expect(is_string($slug) && str_starts_with($slug, $expectedSlug))->toBeTrue('slug ['.var_export($slug, true)."] should start with [$expectedSlug]");

    // watch products own the watch family; the depth-1 node keeps today's /category/watches slug
    $watchType = DB::connection('legacy')->table('category_type_translations')->where('locale', 'en')->where('category_type_name', 'Watches')->value('category_type_id');
    if (is_numeric($watchType)) {
        $watchProducts = DB::connection('legacy')->table('products')->where('category_type_id', (int) $watchType)->count();
        expect(DB::table('catalog_products')->where('family', 'watch')->count())->toBe($watchProducts)
            ->and(DB::table('storefront_categories')->where('legacy_source', 'category_type')->where('legacy_id', (int) $watchType)->value('slug'))->toBe('watches');
    }

    // second run: zero net changes, clean checksums identical
    $cleanAfterFirst = CoreChecksumCommand::compute(CoreChecksumCommand::CLEAN_TABLES)['digest'];
    runTransform(['--output' => $dir2, '--force' => true]);
    $second = readSummary($dir2);

    expect(summaryPath($second, 'totals', 'inserted'))->toBe(0)
        ->and(summaryPath($second, 'totals', 'updated'))->toBe(0)
        ->and(summaryPath($second, 'reconciliation', 'passed'))->toBeTrue()
        ->and(CoreChecksumCommand::compute(CoreChecksumCommand::CLEAN_TABLES)['digest'])->toBe($cleanAfterFirst)
        ->and(CoreChecksumCommand::compute(LegacySource::TABLES)['digest'])->toBe($legacyBefore);
});
