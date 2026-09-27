<?php

namespace App\Console\Commands;

use App\Domain\Orders\UnpaidOrders;
use Illuminate\Console\Command;

/**
 * Cancel card orders still unpaid after the window, and give their stock back.
 *
 * A shopper who leaves Paymob's page without trying to pay produces no callback, so without this
 * the order held its units forever (see UnpaidOrders). Runs every minute from routes/console.php;
 * each order is its own claimed transaction, so a tick that overlaps a payment callback, a
 * dashboard cancel or another tick can never release anything twice.
 *
 *   php artisan orders:expire-unpaid --dry-run     # list what would be cancelled, change nothing
 */
final class OrdersExpireUnpaidCommand extends Command
{
    protected $signature = 'orders:expire-unpaid
        {--dry-run : list the orders that would be cancelled, change nothing}
        {--minutes= : override compat.unpaid.expire_after_minutes for this run}';

    protected $description = 'Cancel card orders still unpaid after the window and return their stock';

    public function handle(UnpaidOrders $unpaid): int
    {
        $minutes = is_numeric($this->option('minutes'))
            ? (int) $this->option('minutes')
            : config()->integer('compat.unpaid.expire_after_minutes');
        if ($minutes < 15) {
            // Shorter than a slow 3-D Secure round trip: a shopper still paying would lose the order.
            $this->error("Refusing a window of {$minutes} minutes; 15 is the minimum.");

            return self::FAILURE;
        }

        $ids = $unpaid->expiredIds($minutes);
        if ($ids === []) {
            $this->info("Nothing to expire: no card order has been unpaid for more than {$minutes} minutes.");

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Would cancel and release: #'.implode(', #', $ids));

            return self::SUCCESS;
        }

        $cancelled = array_values(array_filter($ids, fn (int $id): bool => $unpaid->cancel($id, UnpaidOrders::EXPIRED)));
        $this->info(count($cancelled).' unpaid order(s) cancelled and released'.($cancelled === [] ? '.' : ': #'.implode(', #', $cancelled)));

        return self::SUCCESS;
    }
}
