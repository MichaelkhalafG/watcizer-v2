<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * core:repoint-commerce-fks — FK step 2 (CLEAN_CORE_STUDY §2.8.2, the never-built M2, risk R2-02).
 *
 * `order_items.product_id` and `cart_items.product_id` referenced the LEGACY `products` table, which
 * the write switch froze. Every product created in the dashboard since exists only in
 * `catalog_products`, so the database refused it in a cart line and in an order line. Step 1 was done
 * on production by hand (2026-09-27: both keys dropped, rollback SQL at `~/fk-rollback-*.sql`); this
 * finishes it, and does step 1 too wherever it has not been done (the dev copy, a harness rebuild).
 *
 *   order_items.product_id → catalog_products.id  ON DELETE RESTRICT  (an ordered product cannot vanish)
 *   cart_items.product_id  → catalog_products.id  ON DELETE CASCADE   (one deliberate deviation from the
 *                                                 spec: ProductImporter can hard-delete a fresh product
 *                                                 sitting in a cart; its cart line goes with it)
 *   product_ratings.product_id → catalog_products.id  ON DELETE CASCADE  (B1, 2026-10-01: the rating write
 *                                                 made it matter — a product created on the dashboard
 *                                                 could not be rated; a rating goes with its product,
 *                                                 as it did on the legacy key)
 *
 * A COMMAND, not a migration: the harness runs `migrate` on a bare dump before the catalogue is
 * filled, where the orphan pre-flight would fail. Run it after `core:transform`. Idempotent: a key
 * already pointing at the right table with the right rule is left alone; one pointing anywhere else
 * on that column is dropped first. Out of scope on purpose: `wishlist_items` (gone), `offers` (frozen),
 * the legacy junctions (unwritten). `product_ratings` joined the list with B1 (ratings).
 *
 * Refuses to change anything while any line points at a product `catalog_products` does not have:
 * adding the key would fail half-way, and the orphan is the thing to look at.
 *
 *   php artisan core:repoint-commerce-fks --dry-run   # the plan and the rollback SQL, no change
 *   php artisan core:repoint-commerce-fks             # apply
 */
final class CoreRepointCommerceFksCommand extends Command
{
    protected $signature = 'core:repoint-commerce-fks {--dry-run : print the plan and the rollback SQL, change nothing}';

    protected $description = 'Point order_items/cart_items/product_ratings.product_id at catalog_products (FK step 2)';

    /** table => [the key's name, ON DELETE rule] */
    private const TARGET = [
        'order_items' => ['order_items_product_id_catalog_foreign', 'RESTRICT'],
        'cart_items' => ['cart_items_product_id_catalog_foreign', 'CASCADE'],
        'product_ratings' => ['product_ratings_product_id_catalog_foreign', 'CASCADE'],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        // ── Pre-flight: no line may point at a product the new catalogue does not have ──
        $orphans = false;
        foreach (array_keys(self::TARGET) as $table) {
            $n = DB::table($table.' as l')
                ->whereNotNull('l.product_id')
                ->whereNotExists(fn (Builder $q) => $q->from('catalog_products as c')->whereColumn('c.id', 'l.product_id'))
                ->count();
            if ($n > 0) {
                $orphans = true;
                $this->error("{$table}: {$n} line(s) point at a product not in catalog_products — nothing changed.");
            }
        }
        if ($orphans) {
            return self::FAILURE;
        }

        $plan = [];
        $rollback = [];
        foreach (self::TARGET as $table => [$name, $rule]) {
            $keys = $this->productKeys($table);
            $right = array_filter($keys, fn (array $k): bool => $k['ref'] === 'catalog_products' && $k['rule'] === $rule);
            foreach ($keys as $k) {
                if ($right !== [] && in_array($k, $right, true)) {
                    continue;
                }
                $plan[] = "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$k['name']}`";
                $rollback[] = "ALTER TABLE `{$table}` ADD CONSTRAINT `{$k['name']}` FOREIGN KEY (`product_id`) REFERENCES `{$k['ref']}` (`id`) ON DELETE {$k['rule']}";
            }
            if ($right === []) {
                $plan[] = "ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` FOREIGN KEY (`product_id`) REFERENCES `catalog_products` (`id`) ON DELETE {$rule}";
                array_unshift($rollback, "ALTER TABLE `{$table}` DROP FOREIGN KEY `{$name}`");
            }
        }

        if ($plan === []) {
            $this->info('core:repoint-commerce-fks — nothing to do: every key already points at catalog_products.');

            return self::SUCCESS;
        }

        $this->line($dry ? 'Would run:' : 'Running:');
        foreach ($plan as $sql) {
            $this->line('  '.$sql.';');
        }
        $this->line('Rollback, in this order:');
        foreach ($rollback as $sql) {
            $this->line('  '.$sql.';');
        }
        $this->line('  (Re-adding a key to `products` fails once any line holds a product that exists only in');
        $this->line('   catalog_products — the state this command exists to allow. Drop the new keys and stop there.)');
        if ($dry) {
            return self::SUCCESS;
        }

        // DDL commits on its own in MariaDB; each statement either lands or throws, and a re-run
        // picks up from wherever it stopped.
        foreach ($plan as $sql) {
            DB::statement($sql);
        }
        $this->info('core:repoint-commerce-fks — done.');

        return self::SUCCESS;
    }

    /**
     * Every foreign key on `{table}.product_id`, with where it points and its delete rule.
     *
     * @return list<array{name: string, ref: string, rule: string}>
     */
    private function productKeys(string $table): array
    {
        $out = [];
        foreach (DB::select(
            "SELECT k.constraint_name AS name, k.referenced_table_name AS ref, r.delete_rule AS rule
               FROM information_schema.key_column_usage k
               JOIN information_schema.referential_constraints r
                 ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name AND r.table_name = k.table_name
              WHERE k.table_schema = DATABASE() AND k.table_name = ? AND k.column_name = 'product_id'
                AND k.referenced_table_name IS NOT NULL
              ORDER BY k.constraint_name",
            [$table],
        ) as $row) {
            $r = (array) $row;
            if (is_string($r['name'] ?? null) && is_string($r['ref'] ?? null) && is_string($r['rule'] ?? null)) {
                $out[] = ['name' => $r['name'], 'ref' => $r['ref'], 'rule' => $r['rule']];
            }
        }

        return $out;
    }
}
