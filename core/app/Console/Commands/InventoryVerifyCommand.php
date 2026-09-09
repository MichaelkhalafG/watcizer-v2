<?php

namespace App\Console\Commands;

use App\Domain\Inventory\InventoryService;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * inventory:verify — the nightly reconciliation the study asks for (§4.2): for every product and
 * bucket, `Σ quantity_delta` over the whole ledger must equal the stock column.
 *
 * This is the invariant that makes an append-only ledger worth having. It holds only if nothing
 * ever writes a stock column behind the service's back, so a failure here is not a rounding
 * problem — it names the row a rogue writer touched. In production (where StockWriteGuard is not
 * armed) this command IS the net.
 *
 *   --fix   append a `reason = adjustment` movement that re-bases the ledger onto the column.
 *           Never edits an existing row; the drift stays visible in the history.
 */
final class InventoryVerifyCommand extends Command
{
    protected $signature = 'inventory:verify {--fix : append an adjustment movement for each drifted bucket} {--json}';

    protected $description = 'Assert Σ quantity_delta = the stock column for every product and bucket';

    public function handle(InventoryService $inventory): int
    {
        /** @var array<string, int> $ledger  "product:bucket" => Σ delta */
        $ledger = [];
        foreach (DB::table('inventory_movements')->selectRaw('product_id, bucket, SUM(quantity_delta) AS d')->groupBy('product_id', 'bucket')->cursor() as $row) {
            $ledger[Row::int($row, 'product_id').':'.Row::str($row, 'bucket')] = (int) (Row::nfloat($row, 'd') ?? 0.0);
        }

        $drift = [];
        $checked = 0;
        foreach (DB::table('catalog_products')->select(['id', 'stock_express', 'stock_market'])->orderBy('id')->cursor() as $product) {
            $id = Row::int($product, 'id');
            foreach (InventoryService::columns() as $bucket => $column) {
                $checked++;
                $column_value = Row::int($product, $column);
                $ledger_value = $ledger["$id:$bucket"] ?? 0;
                if ($column_value !== $ledger_value) {
                    $drift[] = ['product_id' => $id, 'bucket' => $bucket, 'column' => $column_value, 'ledger' => $ledger_value];
                }
            }
        }

        // A movement for a product that no longer exists cannot be reconciled against a column.
        $orphans = DB::table('inventory_movements as im')
            ->leftJoin('catalog_products as cp', 'cp.id', '=', 'im.product_id')
            ->whereNull('cp.id')->count();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['checked' => $checked, 'drift' => $drift, 'orphan_movements' => $orphans], JSON_PRETTY_PRINT));

            return $drift === [] && $orphans === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info("inventory:verify — {$checked} (product, bucket) pairs checked");
        if ($orphans > 0) {
            $this->error("{$orphans} movement(s) reference a product that no longer exists.");
        }
        if ($drift === []) {
            $this->info('Ledger and columns agree everywhere.');

            return $orphans === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->table(['product', 'bucket', 'column', 'ledger'], array_map(fn (array $d): array => [$d['product_id'], $d['bucket'], $d['column'], $d['ledger']], $drift));

        if (! (bool) $this->option('fix')) {
            $this->error(count($drift).' bucket(s) drifted. Re-run with --fix to append re-basing adjustments.');

            return self::FAILURE;
        }

        foreach ($drift as $d) {
            // The column is what the storefront sold against, so the column wins and the LEDGER
            // is corrected to it — by appending a movement, never by editing one, and never by
            // moving the column (which would change what is for sale).
            $inventory->rebase($d['product_id'], $d['bucket'], 'inventory:verify re-base');
        }
        $this->info(count($drift).' adjustment movement(s) appended.');

        return self::SUCCESS;
    }
}
