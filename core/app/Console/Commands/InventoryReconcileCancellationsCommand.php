<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * inventory:reconcile-cancellations — the fix for legacy defect #6 (study §4.1 row 6):
 * **cancelling an order in the dashboard never gave the stock back.**
 *
 * `backend/app/Http/Controllers/Admin/OrderController.php:53-78` sets `orders.status` and stops
 * there, so every cancelled order has been quietly leaking its reserved quantities for as long
 * as the dashboard has existed. The customer-facing cancellations (Paymob session failure,
 * failed callback) always restored; only the admin path did not.
 *
 * This is a DELIBERATE, DOCUMENTED DEVIATION from legacy behaviour — deviation D-20. After the
 * switch, core's stock numbers will differ from what the legacy code would have produced, and
 * that difference is the bug being fixed.
 *
 * It is written as a reconciler rather than a hook because the cancellation does not come
 * through core: the Blade dashboard writes `orders.status` directly, and it keeps running until
 * it is retired at the switch. A reconciler catches every route to `cancelled` — the dashboard,
 * a hand-edited row, a future customer-cancel button — with no coupling to any of them.
 * `releaseOrder()` is idempotent (an order that already carries a release movement returns an
 * empty list), so running this every minute costs one indexed query and can never double-credit.
 */
final class InventoryReconcileCancellationsCommand extends Command
{
    protected $signature = 'inventory:reconcile-cancellations
        {--dry-run : report what would be released, write nothing}
        {--limit=500 : most orders to process in one pass}';

    protected $description = 'Give back the stock of orders that were cancelled without a release (legacy defect #6)';

    public function handle(InventoryService $inventory): int
    {
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        // Cancelled orders that HAVE a reservation and have NOT been released. Both halves are
        // read off the ledger, so no column is added to the shared `orders` table.
        $orders = DB::table('orders as o')
            ->where('o.status', 'cancelled')
            ->whereExists(fn (Builder $q) => $q->selectRaw('1')->from('inventory_movements as m')
                ->whereColumn('m.reference_id', 'o.id')->where('m.reference_type', 'orders')->where('m.reason', 'order'))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('inventory_movements as m')
                ->whereColumn('m.reference_id', 'o.id')->where('m.reference_type', 'orders')->whereIn('m.reason', InventoryService::RELEASE_REASONS))
            ->orderBy('o.id')
            ->limit($limit)
            ->get(['o.id', 'o.order_number', 'o.status']);

        if ($orders->isEmpty()) {
            $this->info('Nothing to reconcile: every cancelled order has already given its stock back.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($orders as $order) {
            $id = Row::int($order, 'id');
            $movements = $dryRun ? [] : $inventory->releaseOrder($id, 'order_cancel', Actor::system(), config()->integer('compat.storefront_id'));
            $rows[] = [$id, Row::str($order, 'order_number'), $dryRun ? 'would release' : count($movements).' movement(s)'];
        }

        $this->table(['order', 'number', $dryRun ? 'action' : 'released'], $rows);
        $this->info(($dryRun ? 'DRY RUN — ' : '').count($rows).' cancelled order(s) reconciled (legacy defect #6, deviation D-20).');

        return self::SUCCESS;
    }
}
