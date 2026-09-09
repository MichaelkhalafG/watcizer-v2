<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use Illuminate\Console\Command;
use Throwable;

/**
 * One contender in the release race. Started as its own OS process by
 * `inventory:prove-release-race`, so twelve cancellations of the same order really do collide
 * across twelve PHP processes, twelve PDO connections and twelve MariaDB sessions.
 *
 * Prints one line: `RELEASED <n>` (it wrote n movements), `NOOP` (it found the order already
 * released), `REFUSED` (the database refused its duplicate) or `ERROR <message>`.
 */
final class InventoryReleaseWorkerCommand extends Command
{
    protected $signature = 'inventory:release-worker
        {order : orders.id}
        {--reason=order_cancel : order_cancel|payment_failed}
        {--at= : unix timestamp (float) to start at, so every worker fires together}';

    protected $description = 'Internal: one racing releaseOrder() for inventory:prove-release-race';

    protected $hidden = true;

    public function handle(InventoryService $inventory): int
    {
        $at = (float) (is_scalar($this->option('at')) ? (string) $this->option('at') : '0');
        while ($at > 0.0 && microtime(true) < $at) {
            // spin: sleep granularity is coarser than the window this is trying to hit
        }

        try {
            $movements = $inventory->releaseOrder(
                (int) $this->argument('order'),
                is_scalar($this->option('reason')) ? (string) $this->option('reason') : 'order_cancel',
                Actor::system(),
            );
            $this->line($movements === [] ? 'NOOP' : 'RELEASED '.count($movements));

            return self::SUCCESS;
        } catch (Throwable $e) {
            // A duplicate-key refusal from M1e's unique index is the second layer doing its job,
            // and is reported as REFUSED rather than as an error: no stock was credited twice.
            if (str_contains($e->getMessage(), 'im_reference_once_unique') || str_contains($e->getMessage(), 'Duplicate entry')) {
                $this->line('REFUSED');

                return self::SUCCESS;
            }
            $this->line('ERROR '.preg_replace('/\s+/', ' ', $e->getMessage()));

            return self::FAILURE;
        }
    }
}
