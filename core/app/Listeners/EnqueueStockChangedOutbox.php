<?php

namespace App\Listeners;

use App\Events\StockChanged;
use Illuminate\Support\Facades\DB;

/**
 * `StockChanged` → one `integration_outbox` row on channel `morabaa` (study §4.2 / §4.3).
 *
 * The ERP connector does not exist yet. The outbox does, because the seam is what wave 3 owed:
 * when the connector arrives it consumes rows from here with retry/back-off and needs no change
 * anywhere else. Until then `integration:drain` marks pending rows `skipped` so the table cannot
 * grow without bound — the no-op consumer the study asks for.
 *
 * The payload is deliberately flat and self-describing: the connector reads it without this
 * codebase, so it carries no PHP class names and no ids that only mean something here.
 */
final class EnqueueStockChangedOutbox
{
    public const CHANNEL = 'morabaa';

    public const EVENT = 'stock.changed';

    public function handle(StockChanged $event): void
    {
        DB::table('integration_outbox')->insert([
            'channel' => self::CHANNEL,
            'event' => self::EVENT,
            'aggregate_type' => 'catalog_products',
            'aggregate_id' => $event->productId,
            'payload' => (string) json_encode([
                'product_id' => $event->productId,
                'bucket' => $event->bucket,
                'quantity_delta' => $event->delta,
                'quantity_after' => $event->quantityAfter,
                'quantity_before' => $event->quantityBefore(),
                'reason' => $event->reason,
                'reference_type' => $event->reference?->type,
                'reference_id' => $event->reference?->id,
                'storefront_id' => $event->storefrontId,
                'external_ref' => $event->externalRef,
                'occurred_at' => now()->toIso8601String(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending',
            'attempts' => 0,
            'available_at' => now(),
            'created_at' => now(),
        ]);
    }
}
