<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * carts:prune — delete GUEST carts nobody has touched for 30 days (2026-09-28, C3).
 *
 * The legacy app did this (`carts:prune`, daily) and it stops when the legacy site's cron line is
 * removed. Without a replacement, every guest who ever added something leaves a cart behind for
 * ever — a slow leak in a table read on every add and every checkout.
 *
 * ── Why NOT the legacy rule ───────────────────────────────────────────────────────────────────
 *
 * The legacy prune deleted a guest cart when `expires_at < now()` OR it was 30 days stale. Core
 * stamps `expires_at` = creation + 7 days and never extends it (CompatCart::resolve), and an item
 * write touches the ITEM row's `updated_at`, not the cart's. So under the legacy rule a guest still
 * shopping on day 8 lost their cart. Here a cart's age is its LAST ACTIVITY: the newest of the
 * cart's own `updated_at` and every line's `updated_at` / `created_at`. `expires_at` is not read.
 *
 * Never a signed-in shopper's cart (`user_id` set): those are an account's, and never expire.
 *
 * Batched (500 carts per transaction, lines first — the only foreign key into `carts`), so a large
 * backlog on the first night deletes in short transactions instead of one long lock on the table
 * the checkout reads.
 *
 *   php artisan carts:prune --dry-run      # count what would go, change nothing
 */
final class CartsPruneCommand extends Command
{
    protected $signature = 'carts:prune
        {--days=30 : a guest cart idle this many days is deleted}
        {--dry-run : count the carts that would be deleted, change nothing}';

    protected $description = 'Delete guest carts with no activity for 30 days';

    private const BATCH = 500;

    public function handle(): int
    {
        $days = is_numeric($this->option('days')) ? (int) $this->option('days') : 30;
        if ($days < 14) {
            // Shorter than a shopper's normal "think about it" window: a real cart would be lost.
            $this->error("Refusing an idle window of {$days} days; 14 is the minimum.");

            return self::FAILURE;
        }
        $cutoff = now()->subDays($days);

        if ((bool) $this->option('dry-run')) {
            $this->line(sprintf('carts:prune --dry-run — %d guest cart(s) idle for %d+ days would be deleted.', count($this->idleIds($cutoff, PHP_INT_MAX)), $days));

            return self::SUCCESS;
        }

        $carts = 0;
        $lines = 0;
        while (($ids = $this->idleIds($cutoff, self::BATCH)) !== []) {
            DB::transaction(function () use ($ids, &$carts, &$lines): void {
                $lines += DB::table('cart_items')->whereIn('cart_id', $ids)->delete();
                $carts += DB::table('carts')->whereIn('id', $ids)->whereNull('user_id')->delete();
            });
        }

        $this->line("carts:prune — removed {$carts} guest cart(s) idle for {$days}+ days, with {$lines} line(s).");

        return self::SUCCESS;
    }

    /**
     * Guest carts whose LAST activity — the cart row or any of its lines — is before the cutoff.
     *
     * @return list<int>
     */
    private function idleIds(\DateTimeInterface $cutoff, int $limit): array
    {
        $ids = [];
        foreach (
            DB::table('carts as c')
                ->whereNull('c.user_id')
                ->whereNotNull('c.guest_token')
                ->where(fn (Builder $q) => $q->where('c.updated_at', '<', $cutoff)->orWhereNull('c.updated_at'))
                ->whereNotExists(fn (Builder $q) => $q->from('cart_items as i')->whereColumn('i.cart_id', 'c.id')
                    ->where(fn (Builder $w) => $w->where('i.updated_at', '>=', $cutoff)->orWhere('i.created_at', '>=', $cutoff)))
                ->orderBy('c.id')
                ->limit($limit)
                ->pluck('c.id') as $id
        ) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }
}
