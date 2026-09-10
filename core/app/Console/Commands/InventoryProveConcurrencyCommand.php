<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Listeners\EnqueueStockChangedOutbox;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * inventory:prove-concurrency — proves the oversell guard with REAL concurrency.
 *
 * A Pest test cannot prove this. It runs in one process, inside one transaction that is rolled
 * back, so its "concurrent" decrements are serialised by construction and would pass even
 * against a read-modify-write. This command instead starts N separate `php artisan` processes,
 * each with its own PDO connection and MariaDB session, all released into the same millisecond,
 * and lets them fight over a bucket that cannot satisfy all of them.
 *
 * It builds its own throwaway product, so it never touches a real one, and it tears everything
 * down afterwards — the fixture row, its movements and its outbox rows — then asserts that all
 * three tables are back to the row counts it started with. This is the same
 * build-it-prove-it-drop-it technique the wave-1 audit proofs use with shadow tables, and the
 * deletion of its own scaffolding is the one place a movement row is ever removed.
 *
 *   php artisan inventory:prove-concurrency --stock=5 --workers=20
 */
final class InventoryProveConcurrencyCommand extends Command
{
    use Concerns\CreatesProbeProduct;

    protected $signature = 'inventory:prove-concurrency
        {--stock=5 : units the bucket holds before the race}
        {--workers=20 : concurrent processes, each taking one unit}
        {--bucket=express : express|market}
        {--variants=0 : 0 = race the PRODUCT (wave 3); 1 = race one VARIANT; 2+ = race that many variants of one product at once}
        {--lead=3 : seconds to give every worker to boot before the start instant}';

    protected $description = 'Prove the atomic decrement with real concurrent processes (wave 3)';

    public function handle(InventoryService $inventory): int
    {
        $stock = max(1, self::intOption($this->option('stock')));
        $workers = max($stock + 1, self::intOption($this->option('workers')));
        $bucket = (string) $this->option('bucket');
        $column = InventoryService::columns()[$bucket] ?? null;
        if ($column === null) {
            $this->error("--bucket must be express or market (got [{$bucket}]).");

            return self::INVALID;
        }

        $before = $this->counts();
        $variantCount = max(0, self::intOption($this->option('variants')));
        $ref = 'probe:oversell:'.bin2hex(random_bytes(6));
        $productId = 0;
        /** @var list<int> $variantIds */
        $variantIds = [];

        // Created INSIDE the try, so a failure while opening the bucket still tears the fixture
        // down instead of leaving a probe product for the next transform to trip over.
        try {
            $productId = $this->createProbeProduct($ref);
            $this->info("fixture product {$productId} created (wa_code {$ref})");

            if ($variantCount === 0) {
                $inventory->set(StockTarget::product($productId), $bucket, $stock, 'manual', null, Actor::system(), null, $ref, 'concurrency probe opening');
                $this->line("bucket {$bucket} opened at {$stock} on the PRODUCT; releasing {$workers} processes, each taking 1");
            } else {
                // Wave 3.5: the stock lives on the variants and the product column is the
                // aggregate. Each variant is opened at the SAME quantity, so the arithmetic below
                // stays one number per variant and the aggregate is simply variants × stock.
                $variantIds = $this->createProbeVariants($productId, $variantCount);
                foreach ($variantIds as $variantId) {
                    $inventory->set(StockTarget::variant($productId, $variantId), $bucket, $stock, 'manual', null, Actor::system(), null, $ref, 'concurrency probe opening');
                }
                $this->line("bucket {$bucket} opened at {$stock} on EACH of ".count($variantIds).' variant(s) '.implode(', ', $variantIds)."; releasing {$workers} processes, each taking 1");
            }

            $results = $this->race($productId, $bucket, $workers, $ref, $variantIds);

            $ok = count(array_filter($results, fn (string $r): bool => $r === 'OK'));
            $refused = count(array_filter($results, fn (string $r): bool => $r === 'REFUSED'));
            $errors = array_values(array_filter($results, fn (string $r): bool => $r !== 'OK' && $r !== 'REFUSED'));

            // Whatever the level, the SELLABLE total is what the workers could ever take.
            $sellable = $variantCount === 0 ? $stock : $stock * count($variantIds);
            $after = self::intOption(DB::table('catalog_products')->where('id', $productId)->value($column));
            $ledger = $variantCount === 0
                ? $inventory->ledgerQuantity(StockTarget::product($productId), $bucket)
                : array_sum(array_map(fn (int $v): int => $inventory->ledgerQuantity(StockTarget::variant($productId, $v), $bucket), $variantIds));
            $movements = DB::table('inventory_movements')->where('product_id', $productId)->where('reason', 'order')->count();
            $inStock = self::intOption(DB::table('catalog_products')->where('id', $productId)->value('in_stock'));

            $rows = [
                ['workers that succeeded', $sellable, $ok],
                ['workers refused', $workers - $sellable, $refused],
                ['worker errors', 0, count($errors)],
                ["product column {$column} after (aggregate)", 0, $after],
                ['ledger Σ delta', 0, $ledger],
                ['order movements written', $sellable, $movements],
                ['in_stock flag', 0, $inStock],
            ];

            // Per-variant: each one must land exactly on zero on its own, which is the claim that
            // two variants of one product do not spend each other's stock.
            $perVariantOk = true;
            foreach ($variantIds as $variantId) {
                $left = self::intOption(DB::table('catalog_product_variants')->where('id', $variantId)->value($column));
                $variantLedger = $inventory->ledgerQuantity(StockTarget::variant($productId, $variantId), $bucket);
                $taken = DB::table('inventory_movements')->where('variant_id', $variantId)->where('reason', 'order')->count();
                $rows[] = ["variant {$variantId}: column / ledger / movements", "0 / 0 / {$stock}", "{$left} / {$variantLedger} / {$taken}"];
                $perVariantOk = $perVariantOk && $left === 0 && $variantLedger === 0 && $taken === $stock;
            }

            $this->newLine();
            $this->table(['what', 'expected', 'actual'], $rows);
            foreach ($errors as $error) {
                $this->error($error);
            }

            $passed = $ok === $sellable
                && $refused === $workers - $sellable
                && $errors === []
                && $after === 0
                && $ledger === 0
                && $movements === $sellable
                && $inStock === 0
                && $perVariantOk;

            $level = $variantCount === 0 ? 'the product' : count($variantIds).' variant(s)';
            $this->newLine();
            $passed
                ? $this->info("PASS — {$workers} concurrent processes took exactly {$sellable} units across {$level}. No oversell, no lost update, ledger equals the column, aggregate equals the parts.")
                : $this->error('FAIL — the numbers above do not hold; the decrement is not atomic.');

            return $passed ? self::SUCCESS : self::FAILURE;
        } finally {
            $this->teardown($productId, $before);
        }
    }

    /**
     * Start every worker, then release them all at one instant. `--lead` seconds is the budget
     * for booting N copies of the framework; the workers busy-wait on the shared timestamp, so
     * the decrements land together however long the boot took.
     *
     * @param  list<int>  $variantIds  empty for a product-level race; otherwise the workers are
     *                                 dealt round-robin across these variants
     * @return list<string>
     */
    private function race(int $productId, string $bucket, int $workers, string $ref, array $variantIds = []): array
    {
        $at = microtime(true) + max(1.0, (float) $this->option('lead'));
        $binary = PHP_BINARY;
        $artisan = base_path('artisan');

        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $command = [$binary, $artisan, 'inventory:race-worker', (string) $productId, $bucket, '1', '--at='.$at, '--ref='.$ref];
            if ($variantIds !== []) {
                // Round-robin, so every variant is contended by roughly the same number of
                // processes and they all collide in the same millisecond.
                $command[] = '--variant='.$variantIds[$i % count($variantIds)];
            }
            $pipes = [];
            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($proc)) {
                throw new RuntimeException('Could not start a race worker.');
            }
            $procs[] = ['proc' => $proc, 'pipes' => $pipes];
        }

        $results = [];
        foreach ($procs as $entry) {
            $out = trim((string) stream_get_contents($entry['pipes'][1]));
            $err = trim((string) stream_get_contents($entry['pipes'][2]));
            foreach ($entry['pipes'] as $pipe) {
                fclose($pipe);
            }
            proc_close($entry['proc']);
            $line = $out === '' ? ('ERROR (no output) '.$err) : (string) preg_replace('/\s+/', ' ', $out);
            $results[] = str_starts_with($line, 'OK') ? 'OK' : (str_starts_with($line, 'REFUSED') ? 'REFUSED' : $line);
        }

        return $results;
    }

    private static function intOption(mixed $value): int
    {
        return (int) (is_scalar($value) ? $value : 0);
    }

    /**
     * Remove every trace of the probe, then prove it: the three tables must hold exactly the
     * rows they held before. This is the only code that deletes an `inventory_movements` row,
     * and it only ever deletes rows for a product it created moments earlier.
     *
     * @param  array<string, int>  $before
     */
    private function teardown(int $productId, array $before): void
    {
        if ($productId <= 0) {
            return;
        }
        DB::table('inventory_movements')->where('product_id', $productId)->delete();
        DB::table('catalog_product_variants')->where('product_id', $productId)->delete();
        DB::table('integration_outbox')->where('aggregate_type', 'catalog_products')->where('aggregate_id', $productId)->delete();
        DB::table('integration_outbox')->where('channel', EnqueueStockChangedOutbox::CHANNEL)->where('aggregate_id', $productId)->delete();
        $this->deleteProbeProduct($productId);

        $after = $this->counts();
        foreach ($before as $table => $count) {
            if (($after[$table] ?? -1) !== $count) {
                $this->error("teardown leak: {$table} was {$count}, is now ".($after[$table] ?? -1).'.');

                return;
            }
        }
        $this->line('teardown clean: catalog_products, inventory_movements and integration_outbox are back to their starting row counts.');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'catalog_products' => DB::table('catalog_products')->count(),
            'catalog_product_variants' => DB::table('catalog_product_variants')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'integration_outbox' => DB::table('integration_outbox')->count(),
        ];
    }
}
