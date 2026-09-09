<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * One contender in the concurrency proof. Started as its own OS process by
 * `inventory:prove-concurrency`, so the decrement really does race through a separate PHP
 * process, a separate PDO connection and a separate MariaDB session — the only arrangement that
 * can prove an atomic UPDATE, since a single-process test only ever proves the code path.
 *
 * Prints exactly one line: `OK` or `REFUSED` or `ERROR <message>`.
 */
final class InventoryRaceWorkerCommand extends Command
{
    protected $signature = 'inventory:race-worker
        {product : catalog_products.id}
        {bucket : express|market}
        {qty=1 : units to take}
        {--at= : unix timestamp (float) to start at, so every worker fires together}
        {--ref= : external_ref to tag the movement with}';

    protected $description = 'Internal: one racing decrement for inventory:prove-concurrency';

    protected $hidden = true;

    public function handle(InventoryService $inventory): int
    {
        $at = (float) (is_scalar($this->option('at')) ? (string) $this->option('at') : '0');
        // Busy-wait rather than usleep: the point is that every worker enters the UPDATE inside
        // the same millisecond, and a sleep's wake-up granularity is coarser than that.
        while ($at > 0.0 && microtime(true) < $at) {
            // spin
        }

        try {
            $inventory->adjust(
                (int) $this->argument('product'),
                (string) $this->argument('bucket'),
                -(int) $this->argument('qty'),
                'order',
                null,
                Actor::system(),
                null,
                is_scalar($this->option('ref')) ? (string) $this->option('ref') : null,
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
