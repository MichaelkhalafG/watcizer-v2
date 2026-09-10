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
 * inventory:prove-set-race — the review's 🔴-1 reproduction and its proof, with real processes.
 *
 * The defect: `set()` opened its own transaction, took the VARIANT row's write lock to read the
 * current quantity, and only then called `adjust()`, which takes the PARENT product's lock first.
 * Two rows, two acquisition orders — so a dashboard `set()` and a checkout `adjust()` racing on the
 * same variant formed a cycle and MariaDB killed one of them with error 1213. A single-process
 * test cannot see this: the deadlock needs two sessions holding one lock each.
 *
 * The probe therefore releases N processes into the same millisecond, HALF through the absolute
 * door and half through the relative one, all on one variant of one product. The pass condition is
 * not a particular final quantity — concurrent absolute sets are interleaving-dependent by nature,
 * and that is the caller's business — it is:
 *
 *   1. **zero worker errors, and in particular zero 1213s**, and
 *   2. `Σ quantity_delta = the variant column` and the product aggregate equal to it,
 *
 * i.e. whatever order they landed in, the ledger tells the truth about it and nobody was killed.
 * Run against the pre-fix service this reports deadlocks; against the fixed one it must be clean.
 *
 *   php artisan inventory:prove-set-race --workers=8 --stock=40
 */
final class InventoryProveSetRaceCommand extends Command
{
    use Concerns\CreatesProbeProduct;

    protected $signature = 'inventory:prove-set-race
        {--workers=8 : concurrent processes, alternating set() and adjust()}
        {--stock=40 : opening quantity; each set-worker aims at a DISTINCT value just below it}
        {--bucket=express : express|market}
        {--product : race the PRODUCT level instead of a variant (there is only one row, so it cannot deadlock — a control run)}
        {--lead=3 : seconds to give every worker to boot before the start instant}';

    protected $description = 'Prove set() and adjust() share ONE lock order, with real concurrent processes (review 2026-09-10 🔴-1)';

    public function handle(InventoryService $inventory): int
    {
        $workers = max(2, self::intOption($this->option('workers')));
        $stock = max($workers, self::intOption($this->option('stock')));
        $bucket = (string) $this->option('bucket');
        $column = InventoryService::columns()[$bucket] ?? null;
        if ($column === null) {
            $this->error("--bucket must be express or market (got [{$bucket}]).");

            return self::INVALID;
        }
        $atVariant = ! (bool) $this->option('product');

        $before = $this->counts();
        $ref = 'probe:setrace:'.bin2hex(random_bytes(6));
        $productId = 0;

        try {
            $productId = $this->createProbeProduct($ref);
            $variantId = $atVariant ? $this->createProbeVariants($productId, 1)[0] : null;
            $target = $variantId === null ? StockTarget::product($productId) : StockTarget::variant($productId, $variantId);

            $inventory->set($target, $bucket, $stock, 'manual', null, Actor::system(), null, $ref, 'set-vs-adjust probe opening');
            $this->info('fixture '.$target->describe()." opened at {$stock} in {$bucket}");
            $this->line("releasing {$workers} processes into one millisecond: every even one calls set() at its own target just below {$stock}, every odd one calls adjust(-1)");

            $results = $this->race($productId, $variantId, $bucket, $workers, $stock, $ref);

            $applied = count(array_filter($results, fn (string $r): bool => $r === 'OK' || $r === 'SET'));
            $noop = count(array_filter($results, fn (string $r): bool => $r === 'NOOP'));
            $refused = count(array_filter($results, fn (string $r): bool => $r === 'REFUSED'));
            $errors = array_values(array_filter($results, fn (string $r): bool => ! in_array($r, ['OK', 'SET', 'NOOP', 'REFUSED'], true)));
            $deadlocks = count(array_filter($errors, fn (string $e): bool => str_contains($e, '1213') || stripos($e, 'deadlock') !== false));

            $columnValue = self::intOption(DB::table($target->table())->where('id', $target->rowId())->value($column));
            $ledger = $inventory->ledgerQuantity($target, $bucket);
            $aggregate = self::intOption(DB::table('catalog_products')->where('id', $productId)->value($column));

            $this->newLine();
            $this->table(['what', 'expected', 'actual'], [
                ['workers that applied a change', 'any', $applied],
                ['workers that were a no-op (set to the value it already held)', 'any', $noop],
                ['workers refused (insufficient stock)', 'any', $refused],
                ['worker errors', 0, count($errors)],
                ['DEADLOCKS (MariaDB 1213)', 0, $deadlocks],
                ["column {$column}", 'ledger Σ', $columnValue],
                ['ledger Σ delta', $columnValue, $ledger],
                ['product aggregate', $columnValue, $aggregate],
            ]);
            foreach ($errors as $error) {
                $this->error($error);
            }

            $passed = $errors === []
                && $deadlocks === 0
                && $ledger === $columnValue
                && $aggregate === $columnValue
                && $applied + $noop + $refused === $workers;

            $this->newLine();
            $passed
                ? $this->info("PASS — {$workers} processes mixed the absolute and relative doors on one row with ZERO deadlocks, and the ledger equals the column ({$columnValue}).")
                : $this->error('FAIL — see the rows above; a deadlock or a lost update means the two doors do not share one lock order.');

            return $passed ? self::SUCCESS : self::FAILURE;
        } finally {
            $this->teardown($productId, $before);
        }
    }

    /**
     * Even workers go through `set()` (absolute), odd ones through `adjust()` (relative). Both
     * touch the same row, so before the fix each pair was a candidate cycle.
     *
     * @return list<string>
     */
    private function race(int $productId, ?int $variantId, string $bucket, int $workers, int $stock, string $ref): array
    {
        $at = microtime(true) + max(1.0, (float) $this->option('lead'));
        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $command = [PHP_BINARY, base_path('artisan'), 'inventory:race-worker', (string) $productId, $bucket, '1', '--at='.$at, '--ref='.$ref];
            if ($variantId !== null) {
                $command[] = '--variant='.$variantId;
            }
            if ($i % 2 === 0) {
                // A DISTINCT target per set-worker. Aiming every one at the opening quantity made
                // most of them no-ops (`set()` returns early when the bucket already holds the
                // value, before it ever takes the second lock), which is exactly the case that
                // CANNOT deadlock — the probe was quietly proving the wrong thing. Distinct
                // targets force every set through the full lock pair.
                $command[] = '--set='.max(1, $stock - intdiv($i, 2));
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
            $results[] = match (true) {
                str_starts_with($line, 'OK') => 'OK',
                str_starts_with($line, 'SET') => 'SET',
                str_starts_with($line, 'NOOP') => 'NOOP',
                str_starts_with($line, 'REFUSED') => 'REFUSED',
                default => $line,
            };
        }

        return $results;
    }

    private static function intOption(mixed $value): int
    {
        return (int) (is_scalar($value) ? $value : 0);
    }

    /** @param  array<string, int>  $before */
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
        $this->line('teardown clean: every table is back to its starting row count.');
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
