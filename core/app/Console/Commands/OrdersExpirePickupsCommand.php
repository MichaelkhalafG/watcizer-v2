<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pickup\PickupLifecycle;
use Illuminate\Console\Command;

/**
 * Release pay-at-the-store pickup reservations nobody collected in time (click & collect, 2026-11-14).
 *
 * A READY pickup paid at the store whose collect-by time (`order_pickups.expires_at`) has passed is claimed
 * (ready → expired) and its order cancelled through OrderFulfilment::cancel(), which returns the stock through the
 * ledger and e-mails the customer that the reservation was released. A claim per row: a tick that overlaps a
 * collection, an extension, a dashboard cancel or another tick changes nothing twice. Prepaid pickups are never
 * cancelled here (that would owe a refund); the dashboard shows them as overdue.
 *
 * Runs every five minutes from routes/console.php.
 *
 *   php artisan orders:expire-pickups --dry-run     # list what would be released, change nothing
 */
final class OrdersExpirePickupsCommand extends Command
{
    protected $signature = 'orders:expire-pickups
        {--dry-run : list the reservations that would be released, change nothing}
        {--limit=100 : at most this many per run}';

    protected $description = 'Release pay-at-the-store pickup reservations past their collect-by time and return their stock';

    public function handle(PickupLifecycle $pickups): int
    {
        $limit = is_numeric($this->option('limit')) ? max(1, (int) $this->option('limit')) : 100;
        $dry = (bool) $this->option('dry-run');

        $numbers = $pickups->expireDue($limit, $dry);
        if ($numbers === []) {
            $this->info('Nothing to release: no pay-at-the-store pickup is past its collect-by time.');

            return self::SUCCESS;
        }

        $this->info(($dry ? 'Would release: #' : count($numbers).' pickup reservation(s) released: #').implode(', #', $numbers));

        return self::SUCCESS;
    }
}
