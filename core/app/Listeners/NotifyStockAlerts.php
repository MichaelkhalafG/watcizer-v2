<?php

namespace App\Listeners;

use App\Domain\Notifications\StockAlerts;
use App\Events\StockChanged;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Units came back → tell the shoppers waiting for them (2026-10-01). `StockChanged` fires after the
 * stock transaction commits; a positive delta is a restock of that many units (a delivery, a
 * correction upwards, a cancelled order's units returning). The e-mails are only QUEUED here, on the
 * bulk channel; `bulk-mail:drain` sends them within the day's bulk budget.
 *
 * A failure here must never undo or block the stock movement that already happened: it is logged.
 */
final class NotifyStockAlerts
{
    public function __construct(private readonly StockAlerts $alerts) {}

    public function handle(StockChanged $event): void
    {
        if ($event->delta <= 0) {
            return;
        }
        try {
            $this->alerts->restocked($event->productId, $event->delta);
        } catch (Throwable $e) {
            Log::error("stock alerts not queued for product {$event->productId}: ".$e->getMessage());
        }
    }
}
