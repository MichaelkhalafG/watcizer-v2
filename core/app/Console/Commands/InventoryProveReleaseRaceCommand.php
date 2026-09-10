<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Inventory\StockWriteGuard;
use App\Listeners\EnqueueStockChangedOutbox;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * inventory:prove-release-race — the proof for review finding 🔴-1.
 *
 * `releaseOrder()` used to be "idempotent" only in sequence: it asked `isReleased()` and then
 * wrote, which is a check-then-act. Twelve cancellations arriving at once — the Paymob failure
 * path, a dashboard cancel and ten ticks of the per-minute reconciler are not a hypothetical —
 * all read "not released yet" and all credited the stock back, inventing eleven units of stock
 * nobody ever returned.
 *
 * This builds a real order for a real product, commits its stock, then releases it from N
 * separate `php artisan` processes released into the same millisecond, and asserts:
 *
 *   • exactly ONE release movement exists per order line;
 *   • the stock column is back to the pre-order quantity and NOT one unit higher;
 *   • the ledger agrees with the column;
 *   • every worker that did not do the release said so (NOOP or REFUSED), and none errored.
 *
 * Both layers are exercised at once: the `SELECT … FOR UPDATE` on the order row serialises the
 * workers, and M1e's unique index refuses a duplicate underneath if one ever gets past it. It
 * tears its own fixture down and asserts the tables return to their starting row counts.
 *
 *   php artisan inventory:prove-release-race --workers=12
 */
final class InventoryProveReleaseRaceCommand extends Command
{
    protected $signature = 'inventory:prove-release-race
        {--workers=12 : concurrent cancellations of the same order}
        {--quantity=3 : units the order line reserves}
        {--lead=3 : seconds to give every worker to boot before the start instant}';

    protected $description = 'Prove releaseOrder() credits an order back exactly once under concurrency (review 🔴-1)';

    public function handle(InventoryService $inventory): int
    {
        $workers = max(2, self::intOption($this->option('workers')));
        $quantity = max(1, self::intOption($this->option('quantity')));

        $before = $this->counts();

        // This probe MUST use a real product, unlike the oversell probe: until M2 runs on switch
        // night, `order_items.product_id` still carries a foreign key to the LEGACY `products`
        // table, so an order line cannot reference a row that exists only in `catalog_products`.
        // The cost is that committing and releasing moves the real row's `updated_at`, which the
        // next transform would then report as an update it should not have had to make — so the
        // teardown puts the timestamp back exactly as it found it.
        $product = $this->product($quantity);
        $productId = Row::int($product, 'id');
        $stockBefore = Row::int($product, 'stock_express');
        $updatedAt = Row::nstr($product, 'updated_at');
        $orderId = 0;

        // The fixture is created INSIDE the try: a probe that throws while building its own
        // scaffolding must still tear it down, or it leaves a row behind that fails the next
        // transform for a reason that has nothing to do with the transform.
        try {
            $orderId = $this->createFixtureOrder($productId, $quantity);
            $this->info("fixture order {$orderId} created for product {$productId} (express stock {$stockBefore})");

            $inventory->commitOrder($orderId, Actor::system(), null);
            $reserved = $this->stock($productId);
            $this->line("committed: express stock {$stockBefore} → {$reserved}; releasing {$workers} concurrent cancellations");

            $results = $this->race($orderId, $workers);
            $released = count(array_filter($results, fn (string $r): bool => str_starts_with($r, 'RELEASED')));
            $noop = count(array_filter($results, fn (string $r): bool => $r === 'NOOP'));
            $refused = count(array_filter($results, fn (string $r): bool => $r === 'REFUSED'));
            $errors = array_values(array_filter($results, fn (string $r): bool => ! str_starts_with($r, 'RELEASED') && $r !== 'NOOP' && $r !== 'REFUSED'));

            $lines = DB::table('order_items')->where('order_id', $orderId)->whereNotNull('product_id')->count();
            $releaseMovements = DB::table('inventory_movements')
                ->where('reference_type', 'orders')->where('reference_id', $orderId)
                ->whereIn('reason', InventoryService::RELEASE_REASONS)->count();
            $after = $this->stock($productId);
            $ledger = $inventory->ledgerQuantity(StockTarget::product($productId), 'express');

            $this->newLine();
            $this->table(['what', 'expected', 'actual'], [
                ['workers that released', 1, $released],
                ['workers that found it done (NOOP)', $workers - 1, $noop],
                ['workers refused by the unique index', 0, $refused],
                ['worker errors', 0, $errors === [] ? 0 : count($errors)],
                ['release movements written', $lines, $releaseMovements],
                ['express column after', $stockBefore, $after],
                ['ledger Σ delta', $stockBefore, $ledger],
            ]);
            foreach ($errors as $error) {
                $this->error($error);
            }

            // The only shape that matters: exactly ONE worker moved stock, every other worker
            // accounted for itself, and the column landed on the real quantity rather than above
            // it. A REFUSED means the row lock let two workers through and M1e's unique index
            // stopped the second — the outcome is still correct, but it is worth saying out loud.
            $passed = $errors === []
                && $released === 1
                && $released + $noop + $refused === $workers
                && $releaseMovements === $lines
                && $after === $stockBefore
                && $ledger === $stockBefore;
            if ($refused > 0) {
                $this->warn("{$refused} worker(s) were refused by im_reference_once_unique: the row lock did not serialise them, the index did. No stock was credited twice.");
            }

            $this->newLine();
            $passed
                ? $this->info("PASS — {$workers} concurrent cancellations produced exactly {$lines} release movement(s). Column back to {$stockBefore}, not {$after}+. No double credit.")
                : $this->error('FAIL — the numbers above do not hold; the release is not exactly-once.');

            return $passed ? self::SUCCESS : self::FAILURE;
        } finally {
            $this->teardown($orderId, $productId, $updatedAt, $before);
        }
    }

    /** @return list<string> */
    private function race(int $orderId, int $workers): array
    {
        $at = microtime(true) + max(1.0, (float) (is_scalar($this->option('lead')) ? (string) $this->option('lead') : '3'));
        $procs = [];
        for ($i = 0; $i < $workers; $i++) {
            $command = [PHP_BINARY, base_path('artisan'), 'inventory:release-worker', (string) $orderId, '--reason=order_cancel', '--at='.$at];
            $pipes = [];
            $proc = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path());
            if (! is_resource($proc)) {
                throw new RuntimeException('Could not start a release worker.');
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
            $results[] = $out === '' ? ('ERROR (no output) '.$err) : (string) preg_replace('/\s+/', ' ', $out);
        }

        return $results;
    }

    private function product(int $quantity): \stdClass
    {
        $row = DB::table('catalog_products')->whereNull('deleted_at')
            ->where('stock_express', '>=', $quantity)->orderBy('id')
            ->first(['id', 'stock_express', 'updated_at']);
        if (! $row instanceof \stdClass) {
            throw new RuntimeException('No product with enough express stock — run core:transform first.');
        }

        return $row;
    }

    private function stock(int $productId): int
    {
        $value = DB::table('catalog_products')->where('id', $productId)->value('stock_express');

        return (int) (is_numeric($value) ? $value : 0);
    }

    /**
     * An order whose number is prefixed `ZZ` so a probe row is always identifiable, and whose
     * guest name says what made it. Removed again by teardown().
     */
    private function createFixtureOrder(int $productId, int $quantity): int
    {
        $addressId = DB::table('addresses')->orderBy('id')->value('id');
        if ($addressId === null) {
            throw new RuntimeException('No address row to hang a fixture order on.');
        }
        $now = now();
        $orderId = (int) DB::table('orders')->insertGetId([
            'user_id' => null,
            'address_id' => (int) (is_numeric($addressId) ? $addressId : 0),
            'total_price_for_order' => '0.00',
            'payment_method' => 'cash',
            'order_number' => 'ZZ'.random_int(100000, 999999),
            'status' => 'processing',
            'guest_name' => 'release-race',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'product_id' => $productId, 'offer_id' => null,
            'quantity' => $quantity, 'piece_price' => '0.00', 'total_price' => '0.00',
            'type_stock' => 'Express', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $orderId;
    }

    /** @param  array<string, int>  $before */
    private function teardown(int $orderId, int $productId, ?string $updatedAt, array $before): void
    {
        if ($orderId > 0) {
            DB::table('inventory_movements')->where('reference_type', 'orders')->where('reference_id', $orderId)->delete();
        }
        DB::table('integration_outbox')->where('channel', EnqueueStockChangedOutbox::CHANNEL)->where('aggregate_id', $productId)->delete();
        DB::table('integration_outbox')->where('aggregate_type', 'orders')->where('aggregate_id', $orderId)->delete();
        DB::table('order_items')->where('order_id', $orderId)->delete();
        DB::table('orders')->where('id', $orderId)->delete();

        // The stock number is already back (that is what the probe proved); this puts the row's
        // timestamp back too, so the product is byte-identical to how the probe found it and the
        // next transform reports zero net changes.
        if ($updatedAt !== null) {
            StockWriteGuard::allow(fn () => DB::table('catalog_products')->where('id', $productId)->update(['updated_at' => $updatedAt]));
        }

        $after = $this->counts();
        foreach ($before as $table => $count) {
            if (($after[$table] ?? -1) !== $count) {
                $this->error("teardown leak: {$table} was {$count}, is now ".($after[$table] ?? -1).'.');

                return;
            }
        }
        $this->line('teardown clean: catalog_products, orders, order_items, inventory_movements and integration_outbox are back to their starting row counts.');
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return [
            'catalog_products' => DB::table('catalog_products')->count(),
            'orders' => DB::table('orders')->count(),
            'order_items' => DB::table('order_items')->count(),
            'inventory_movements' => DB::table('inventory_movements')->count(),
            'integration_outbox' => DB::table('integration_outbox')->count(),
        ];
    }

    private static function intOption(mixed $value): int
    {
        return (int) (is_scalar($value) ? $value : 0);
    }
}
