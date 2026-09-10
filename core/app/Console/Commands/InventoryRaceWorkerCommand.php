<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use Illuminate\Console\Command;
use Throwable;

/**
 * One contender in the concurrency proof. Started as its own OS process by
 * `inventory:prove-concurrency`, so the decrement really does race through a separate PHP
 * process, a separate PDO connection and a separate MariaDB session — the only arrangement that
 * can prove an atomic UPDATE, since a single-process test only ever proves the code path.
 *
 * Prints exactly one line: `OK` / `SET` / `NOOP` / `REFUSED` / `ERROR <message>`.
 *
 * With `--set=N` the worker goes through `InventoryService::set()` — the ABSOLUTE door — instead
 * of `adjust()`. That is what `inventory:prove-set-race` needs: before the 2026-09-10 review the
 * two doors took the same two rows in OPPOSITE orders, so mixing them under contention deadlocked.
 */
final class InventoryRaceWorkerCommand extends Command
{
    protected $signature = 'inventory:race-worker
        {product : catalog_products.id}
        {bucket : express|market}
        {qty=1 : units to take}
        {--at= : unix timestamp (float) to start at, so every worker fires together}
        {--ref= : external_ref to tag the movement with}
        {--variant= : catalog_product_variants.id, when the race is over a VARIANT}
        {--set= : go through set() with this ABSOLUTE quantity instead of adjust()}';

    protected $description = 'Internal: one racing decrement for inventory:prove-concurrency';

    protected $hidden = true;

    /** The row this worker fights over: a product, or one variant of it. */
    private static function target(self $command): StockTarget
    {
        $variant = $command->option('variant');
        $productId = (int) $command->argument('product');

        return is_scalar($variant) && (int) $variant > 0
            ? StockTarget::variant($productId, (int) $variant)
            : StockTarget::product($productId);
    }

    public function handle(InventoryService $inventory): int
    {
        $at = (float) (is_scalar($this->option('at')) ? (string) $this->option('at') : '0');
        // Busy-wait rather than usleep: the point is that every worker enters the UPDATE inside
        // the same millisecond, and a sleep's wake-up granularity is coarser than that.
        while ($at > 0.0 && microtime(true) < $at) {
            // spin
        }

        $ref = is_scalar($this->option('ref')) ? (string) $this->option('ref') : null;
        $absolute = $this->option('set');

        try {
            if (is_scalar($absolute) && $absolute !== '') {
                // The absolute door. `manual` rather than `order` because a set is a dashboard or
                // import edit, and because a negative-going set against a withdrawn variant must
                // stay legal (review 🟠-3).
                $movement = $inventory->set(
                    self::target($this),
                    (string) $this->argument('bucket'),
                    (int) $absolute,
                    'manual',
                    null,
                    Actor::system(),
                    null,
                    $ref,
                    'set-vs-adjust probe',
                );
                $this->line($movement === null ? 'NOOP' : 'SET');

                return self::SUCCESS;
            }

            $inventory->adjust(
                self::target($this),
                (string) $this->argument('bucket'),
                -(int) $this->argument('qty'),
                'order',
                null,
                Actor::system(),
                null,
                $ref,
                'concurrency probe',
            );
            $this->line('OK');

            return self::SUCCESS;
        } catch (InsufficientStock) {
            $this->line('REFUSED');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line('ERROR '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
