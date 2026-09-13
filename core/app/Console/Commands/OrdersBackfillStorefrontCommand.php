<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-time backfill: stamp the primary storefront on every order that predates the column
 * (developer decision 2026-09-13).
 *
 * ── Why a command and not a migration ────────────────────────────────────────────────────────
 *
 * A migration would run inside switch night's `php artisan migrate` — which happens BEFORE the
 * 65-table legacy baseline is captured (§3.4 step b). The baseline would therefore already contain
 * the backfilled data and the change would be invisible to the very gate that asks "did core write
 * to legacy tonight?". This backfill DOES change data, so it has to land on the **after** side of
 * that baseline where it shows up and can be attributed.
 *
 * And not hand-typed SQL either. The §3.4 lesson from the `storefront*` glob stands: a rule that
 * lives in code and a procedure a human types at 02:00 are two different things, and the typed one
 * is what goes wrong. This command is the one home for the statement.
 *
 * ── Why stamping 1 is CORRECT and not an approximation ───────────────────────────────────────
 *
 * Every order that can exist today came through the legacy app (Watchizer, one storefront) or
 * through core's compat layer, which is pinned to `config('compat.storefront_id')`. Brand Fashion
 * has never had a write path: v2 is read-only (seven routes, all GET) and its frontend is unbuilt.
 * So no row in this table can belong to another storefront.
 *
 * That argument has an expiry date, which is why this command re-checks it rather than trusting the
 * paragraph: if a second storefront ever appears among the orders, it refuses.
 *
 * ── Order of operations, and it is not reversible in the other direction ─────────────────────
 *
 * Run it AFTER `CompatCheckout` writes the column (it does since 2026-09-13). Run it before, and
 * the next order arrives NULL and the backfill has to be repeated. Idempotent by its own WHERE
 * clause, so a second run is a no-op.
 */
final class OrdersBackfillStorefrontCommand extends Command
{
    protected $signature = 'orders:backfill-storefront
                            {--dry-run : count what would change and write nothing}
                            {--force : required to write outside a dry run}';

    protected $description = 'Stamp the primary storefront on orders written before orders.storefront_id existed (one-time).';

    public function handle(): int
    {
        $primary = config()->integer('compat.storefront_id');
        $dry = (bool) $this->option('dry-run');

        $nulls = DB::table('orders')->whereNull('storefront_id')->count();
        $set = DB::table('orders')->whereNotNull('storefront_id')->count();

        $this->line("orders: {$nulls} with no storefront, {$set} already stamped");
        $this->line("primary storefront (config compat.storefront_id): {$primary}");

        // The premise, re-checked rather than trusted: no order may already name another storefront.
        $foreign = DB::table('orders')
            ->whereNotNull('storefront_id')
            ->where('storefront_id', '!=', $primary)
            ->count();

        if ($foreign > 0) {
            $this->error(
                "Refusing: {$foreign} order(s) already name a storefront other than {$primary}. "
                .'The assumption this backfill rests on — that every historical order belongs to the primary '
                .'storefront — no longer holds, so stamping the NULLs would mislabel them. Decide per storefront.'
            );

            return self::FAILURE;
        }

        if ($nulls === 0) {
            $this->info('Nothing to do: every order already carries a storefront.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->warn("DRY RUN — {$nulls} order(s) would be stamped with storefront {$primary}. Nothing written.");
            $this->line('Digest note: this WILL move the `orders` checksum, because unlike the enum widening it changes data.');

            return self::SUCCESS;
        }

        if (! (bool) $this->option('force')) {
            $this->error('This writes the shared `orders` table. Re-run with --force (or --dry-run to preview).');

            return self::FAILURE;
        }

        $before = self::checksum();
        $updated = DB::table('orders')->whereNull('storefront_id')->update(['storefront_id' => $primary]);
        $after = self::checksum();

        $remaining = DB::table('orders')->whereNull('storefront_id')->count();

        $this->info("stamped {$updated} order(s) with storefront {$primary}; {$remaining} still NULL");
        $this->line("orders CHECKSUM TABLE: {$before} → {$after}");
        $this->line('Record that pair: it is a REAL data change and belongs on the AFTER side of the');
        $this->line('switch-night baseline (§3.4 step b), unlike the variant_id and enum changes which');
        $this->line('move the checksum without touching a row.');

        // `updated_at` is deliberately NOT touched: these orders did not change in any sense the
        // customer or the shop would recognise, and moving the timestamp would make every one of
        // them look freshly edited to anything that reads it.
        return $remaining === 0 ? self::SUCCESS : self::FAILURE;
    }

    private static function checksum(): string
    {
        $row = DB::select('CHECKSUM TABLE `orders`')[0] ?? null;
        $value = is_object($row) && property_exists($row, 'Checksum') ? $row->Checksum : null;

        return is_scalar($value) ? (string) $value : 'unknown';
    }
}
