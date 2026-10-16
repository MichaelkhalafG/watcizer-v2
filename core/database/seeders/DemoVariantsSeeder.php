<?php

namespace Database\Seeders;

use App\Domain\Catalog\ProductWriter;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Models\Storefront\Storefront;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * DEMO variants on REAL products — the ground for the Brand Fashion product card (B3) and the
 * variant picker (B5). LOCAL ONLY: it refuses to run unless `APP_ENV=local` (an allow-list of one
 * value, not a deny-list of production — see {@see self::refuseUnlessLocal()}).
 *
 * Why it exists (2026-10-16): the catalogue carries 24 fashion products with 110 size-only
 * variant rows, none of them visible on either shop, no colour on any row, no price delta. A
 * card and a picker built against that would never meet a colour × size matrix, a hole in the
 * matrix, a colour whose every size is sold out, or a variant that costs more. This seeder puts
 * every one of those shapes on products that ARE visible, on both shops, so the storefront can
 * be built and looked at against real photos, titles and prices.
 *
 * What it attaches, per family, newest visible products first (visible on BOTH shops, active,
 * not deleted, without variants of their own):
 *
 *   watch        6   products 1–5: 3 colours × 3 case sizes (36/40/44 mm); product 6: sizes only
 *   bag          6   colours only (3)
 *   fashion      ALL visible (13 on the 2026-10-16 import; the brief said "all 10", written
 *                    against the 2026-10-15 count — "all" is the rule): 3 colours × 3 sizes (S/M/L)
 *   electronics  6   colours only (3) — EVERY row at 0 stock, because electronics are 100% out of
 *                    stock on production (72 of 72 visible, 2026-10-16); the demo keeps that fact
 *                    rather than inventing stock for a family the shop cannot sell today (accepted)
 *   wallet       2   colours only (3)
 *
 * The shapes, deterministic so two runs produce the same rows:
 *   - varied stock per cell (express/market from two small tables indexed by colour × size:
 *     cells with both buckets, express only, market only, and one cell sold out);
 *   - the FIRST matrix product of a family has a colour whose every size is out of stock;
 *   - the SECOND matrix product of a family has a hole (one colour × size combination absent);
 *   - price deltas: +150.00 on every 44 mm row of the first watch, +50.00 on every L row of the
 *     first fashion product, −25.00 on the second colour of the first bag — three non-zero deltas,
 *     two of them positive;
 *   - colours are the product's OWN attribute colours (`catalog_product_color`, dial before band)
 *     topped up from a fixed palette (Black 1, Silver 3, Blue 6, Brown 8, Gold 4) to three;
 *   - every product not touched here keeps working exactly as before — the seeder never edits
 *     a product row's own columns except through `InventoryService`.
 *
 * The inventory invariants (`inventory:verify`) hold after a run, because the seeder uses the
 * same door the dashboard does: a product's own stock is first SET to 0 through the service (a
 * product-level movement, note `DEMO-VARIANTS zero before conversion; original=N`), THEN the
 * variant rows are inserted (no stock columns named — `StockWriteGuard` refuses that), THEN each
 * variant's opening quantity is SET through the service, which keeps the product aggregate equal
 * to the sum of its variants and re-derives `in_stock`. The product-level ledger of a converted
 * product therefore sums to 0, as invariant 3 demands.
 *
 * WHAT CHANGES WHILE THE DEMO IS ON: a converted product's aggregate stock is the demo's, not
 * production's, and the compat layer (the Watchizer storefront's path) REFUSES to add any product
 * with variants to a cart — `CartCompatController` 422 "This product requires selecting an
 * option", `CompatCheckout::priceLines()` 422 — so these products are browsable and unbuyable on
 * the local Watchizer, and `CompatVariantInvariantTest` goes red by design for as long as the
 * demo is on. That is the finding this seeder makes visible, not a defect in it.
 *
 * Usage, from core/:
 *   php artisan db:seed --class=DemoVariantsSeeder          # removes any earlier demo, then seeds
 *   php artisan db:seed --class=DemoVariantsRemoveSeeder    # removes the demo, restores the stock
 *   php artisan inventory:verify                            # must be clean after either
 *
 * Removal, what it does: zero every DEMO variant through the service, delete the cart lines that
 * name a DEMO variant (local carts), delete the DEMO variants' ledger rows (a demo leaves no
 * history behind — the FK would otherwise re-level them onto the product as product-level rows
 * with no variant), delete the rows, then SET the product back to the quantity recorded in the
 * zeroing movement's note. An order line that names a DEMO variant stops the removal: delete the
 * test order first; the seeder never touches orders. The equivalent raw statement, for the case
 * where artisan itself cannot run, is in {@see self::RAW_REMOVAL} — read its caveat first.
 */
final class DemoVariantsSeeder extends Seeder
{
    public const SKU_PREFIX = 'DEMO-';

    public const NOTE_ZERO = 'DEMO-VARIANTS zero before conversion; original=';

    public const NOTE_OPENING = 'DEMO-VARIANTS opening quantity';

    public const NOTE_REMOVAL = 'DEMO-VARIANTS zero before removal';

    public const NOTE_RESTORE = 'DEMO-VARIANTS product level restored';

    /**
     * The raw equivalent of the removal, for when artisan cannot run. CAVEAT: it deletes the rows
     * and the ledger but it cannot restore the product aggregate (that needs `InventoryService`),
     * so after it the converted products read 0 stock until `inventory:verify --fix` or a manual
     * restock. Prefer `DemoVariantsRemoveSeeder`.
     */
    public const RAW_REMOVAL = "DELETE FROM cart_items WHERE variant_id IN (SELECT id FROM catalog_product_variants WHERE sku LIKE 'DEMO-%');"
        ." DELETE FROM inventory_movements WHERE variant_id IN (SELECT id FROM catalog_product_variants WHERE sku LIKE 'DEMO-%');"
        ." DELETE FROM catalog_product_variants WHERE sku LIKE 'DEMO-%';";

    /** @var array<string, int|null> products per family; null = every visible product of the family */
    private const PER_FAMILY = ['watch' => 6, 'bag' => 6, 'fashion' => null, 'electronics' => 6, 'wallet' => 2];

    /** @var list<int> catalog_colors ids: Black, Silver, Blue, Brown, Gold (the 2026-10-16 import) */
    private const PALETTE = [1, 3, 6, 8, 4];

    /** @var array<string, list<int>> catalog_sizes ids: watch 36/40/44 mm, clothing S/M/L */
    private const SIZES = ['watch' => [3, 5, 8], 'fashion' => [12, 13, 14]];

    private const COLOURS_PER_PRODUCT = 3;

    /** @var list<int> */
    private const EXPRESS = [4, 0, 2, 7, 1, 3, 0, 5, 2];

    /** @var list<int> */
    private const MARKET = [0, 3, 1, 0, 2, 0, 6, 1, 0];

    public function run(InventoryService $inventory, ProductWriter $products, string $mode = 'seed'): void
    {
        self::refuseUnlessLocal();
        $this->remove($inventory, $products);
        if ($mode === 'remove') {
            return;
        }
        $this->seed($inventory, $products);
    }

    /**
     * The environment guard: LOCAL only, not "anything but production". A seeder that writes 205
     * variant rows is one `artisan` command away from a database nobody meant it to touch, so the
     * door is an allow-list of one value, and the refusal names the value it saw.
     */
    public static function refuseUnlessLocal(): void
    {
        $env = app()->environment();
        if ($env !== 'local') {
            throw new RuntimeException(
                "DemoVariantsSeeder refused: it is demo data for a LOCAL database and runs only with APP_ENV=local; "
                ."this application's APP_ENV is '{$env}'. Nothing was written."
            );
        }
    }

    private function seed(InventoryService $inventory, ProductWriter $products): void
    {
        $colourNames = $this->names('catalog_color_translations', 'color_id');
        $sizeNames = $this->names('catalog_size_translations', 'size_id');
        $this->assertLookups($colourNames, $sizeNames);

        $rows = 0;
        $coloursUsed = [];
        $sizesUsed = [];
        foreach (self::PER_FAMILY as $family => $limit) {
            $ids = $this->pick($family, $limit);
            foreach ($ids as $index => $productId) {
                $plan = $this->plan($family, $index, $productId);
                foreach ($plan as $cell) {
                    if ($cell['color'] !== null) {
                        $coloursUsed[$cell['color']] = true;
                    }
                    if ($cell['size'] !== null) {
                        $sizesUsed[$cell['size']] = true;
                    }
                }
                $rows += $this->convert($inventory, $products, $productId, $plan, $colourNames, $sizeNames);
            }
            $this->say(sprintf('%-12s %2d products: %s', $family, count($ids), implode(', ', $ids)));
        }
        ksort($coloursUsed);
        ksort($sizesUsed);
        $this->say(sprintf(
            'seeded %d DEMO variant rows; colour ids %s; size ids %s',
            $rows,
            implode(',', array_keys($coloursUsed)),
            implode(',', array_keys($sizesUsed)),
        ));
    }

    /**
     * Remove every DEMO variant and put the products' own stock back. Public so the remove seeder
     * and a test can call it; idempotent — with nothing to remove it does nothing.
     */
    public function remove(InventoryService $inventory, ProductWriter $products): void
    {
        self::refuseUnlessLocal();
        $variants = DB::table('catalog_product_variants')
            ->where('sku', 'like', self::SKU_PREFIX.'%')
            ->orderBy('id')
            ->get(['id', 'product_id', 'stock_express', 'stock_market']);
        $variantIds = [];
        $productIds = [];
        foreach ($variants as $raw) {
            $variant = Row::cast($raw);
            $variantIds[] = Row::int($variant, 'id');
            $productIds[Row::int($variant, 'product_id')] = true;
        }
        if ($variantIds !== []) {
            $orderLines = DB::table('order_items')->whereIn('variant_id', $variantIds)->count();
            if ($orderLines > 0) {
                throw new RuntimeException("{$orderLines} order line(s) name a DEMO variant; delete those test orders first — the seeder does not touch orders.");
            }
            foreach ($variants as $raw) {
                $variant = Row::cast($raw);
                $target = StockTarget::variant(Row::int($variant, 'product_id'), Row::int($variant, 'id'));
                foreach (InventoryService::columns() as $bucket => $column) {
                    if (Row::int($variant, $column) > 0) {
                        $inventory->set(target: $target, bucket: $bucket, quantity: 0, reason: 'manual', actor: Actor::system(), note: self::NOTE_REMOVAL);
                    }
                }
            }
            DB::table('cart_items')->whereIn('variant_id', $variantIds)->delete();
            DB::table('inventory_movements')->whereIn('variant_id', $variantIds)->delete();
            DB::table('catalog_product_variants')->whereIn('id', $variantIds)->delete();
        }

        // Every (product, bucket) whose latest DEMO zeroing has no restore after it — which also
        // covers a run that was interrupted between the zeroing and the variant rows.
        $pending = DB::table('inventory_movements as z')
            ->whereNull('z.variant_id')
            ->where('z.note', 'like', self::NOTE_ZERO.'%')
            ->whereNotExists(function (Builder $q): void {
                $q->selectRaw('1')->from('inventory_movements as r')
                    ->whereColumn('r.product_id', 'z.product_id')
                    ->whereColumn('r.bucket', 'z.bucket')
                    ->whereColumn('r.id', '>', 'z.id')
                    ->where('r.note', self::NOTE_RESTORE);
            })
            ->orderBy('z.id')
            ->get(['z.product_id', 'z.bucket', 'z.quantity_delta']);
        $restored = 0;
        foreach ($pending as $raw) {
            $zero = Row::cast($raw);
            $productId = Row::int($zero, 'product_id');
            $original = -Row::int($zero, 'quantity_delta');
            if ($original > 0) {
                $inventory->set(
                    target: StockTarget::product($productId),
                    bucket: Row::str($zero, 'bucket'),
                    quantity: $original,
                    reason: 'manual',
                    actor: Actor::system(),
                    note: self::NOTE_RESTORE,
                );
                $restored++;
            }
            $productIds[$productId] = true;
        }
        foreach (array_keys($productIds) as $productId) {
            $inventory->recomputeInStock($productId);
            $products->flush($productId);
        }
        if ($variantIds !== [] || $restored > 0) {
            $this->say(sprintf('removed %d DEMO variant rows on %d products; %d product-level quantities restored', count($variantIds), count($productIds), $restored));
        }
    }

    /**
     * Newest visible products of a family, visible on BOTH shops, without variants.
     *
     * @return list<int>
     */
    private function pick(string $family, ?int $limit): array
    {
        $query = DB::table('catalog_products as p')
            ->join('storefront_product as s1', function (JoinClause $join): void {
                $join->on('s1.product_id', '=', 'p.id')->where('s1.storefront_id', Storefront::WATCHIZER_ID)->where('s1.is_visible', 1);
            })
            ->join('storefront_product as s2', function (JoinClause $join): void {
                $join->on('s2.product_id', '=', 'p.id')->where('s2.storefront_id', Storefront::BRAND_FASHION_ID)->where('s2.is_visible', 1);
            })
            ->where('p.is_active', 1)
            ->whereNull('p.deleted_at')
            ->where('p.family', $family)
            ->whereNotExists(function (Builder $q): void {
                $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id');
            })
            ->orderByDesc('p.created_at')
            ->orderByDesc('p.id');
        if ($limit !== null) {
            $query->limit($limit);
        }
        $ids = [];
        foreach ($query->pluck('p.id') as $id) {
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $ids;
    }

    /**
     * The cells of one product's matrix.
     *
     * @return list<array{color: int|null, size: int|null, delta: string, express: int, market: int}>
     */
    private function plan(string $family, int $index, int $productId): array
    {
        $sizes = self::SIZES[$family] ?? [];
        $colours = $this->coloursFor($productId);
        $matrix = $sizes !== [];
        $sizesOnly = $family === 'watch' && $index === 5;
        $deadColour = $matrix && $index === 0 ? $colours[2] : null;
        $hole = $matrix && $index === 1 ? [$colours[1], $sizes[2]] : null;
        $noStock = $family === 'electronics';

        $colourList = $sizesOnly ? [null] : $colours;
        $sizeList = $matrix ? $sizes : [null];

        $out = [];
        foreach ($colourList as $ci => $colour) {
            foreach ($sizeList as $si => $size) {
                if ($hole !== null && $colour === $hole[0] && $size === $hole[1]) {
                    continue;
                }
                $dead = $colour !== null && $colour === $deadColour;
                [$express, $market] = $noStock || $dead ? [0, 0] : self::quantities($ci, $si);
                $out[] = [
                    'color' => $colour,
                    'size' => $size,
                    'delta' => self::delta($family, $index, $ci, $size),
                    'express' => $express,
                    'market' => $market,
                ];
            }
        }

        return $out;
    }

    /** @return array{0: int, 1: int} */
    private static function quantities(int $ci, int $si): array
    {
        return [self::EXPRESS[($ci * 3 + $si) % 9], self::MARKET[($ci * 2 + $si * 3) % 9]];
    }

    private static function delta(string $family, int $index, int $ci, ?int $size): string
    {
        if ($family === 'watch' && $index === 0 && $size === 8) {
            return '150.00';
        }
        if ($family === 'fashion' && $index === 0 && $size === 14) {
            return '50.00';
        }
        if ($family === 'bag' && $index === 0 && $ci === 1) {
            return '-25.00';
        }

        return '0.00';
    }

    /**
     * The product's own attribute colours (dial before band, in position order), topped up from
     * the palette to {@see self::COLOURS_PER_PRODUCT} distinct ids.
     *
     * @return list<int>
     */
    private function coloursFor(int $productId): array
    {
        $own = DB::table('catalog_product_color')
            ->where('product_id', $productId)
            ->orderByRaw("FIELD(role, 'dial', 'band', 'main'), position, color_id")
            ->pluck('color_id');
        $colours = [];
        foreach ($own as $id) {
            if (is_numeric($id) && ! in_array((int) $id, $colours, true)) {
                $colours[] = (int) $id;
            }
        }
        foreach (self::PALETTE as $id) {
            if (count($colours) >= self::COLOURS_PER_PRODUCT) {
                break;
            }
            if (! in_array($id, $colours, true)) {
                $colours[] = $id;
            }
        }

        return array_slice($colours, 0, self::COLOURS_PER_PRODUCT);
    }

    /**
     * Convert one product: product level to 0 through the service, the rows, then each opening
     * quantity through the service. Returns the number of rows written.
     *
     * @param  list<array{color: int|null, size: int|null, delta: string, express: int, market: int}>  $plan
     * @param  array<int, string>  $colourNames
     * @param  array<int, string>  $sizeNames
     */
    private function convert(InventoryService $inventory, ProductWriter $products, int $productId, array $plan, array $colourNames, array $sizeNames): int
    {
        $raw = DB::table('catalog_products')->where('id', $productId)->first(['stock_express', 'stock_market']);
        if ($raw === null) {
            throw new RuntimeException("Product {$productId} vanished between the pick and the conversion.");
        }
        $product = Row::cast($raw);
        foreach (InventoryService::columns() as $bucket => $column) {
            $have = Row::int($product, $column);
            if ($have > 0) {
                $inventory->set(
                    target: StockTarget::product($productId),
                    bucket: $bucket,
                    quantity: 0,
                    reason: 'manual',
                    actor: Actor::system(),
                    note: self::NOTE_ZERO.$have,
                );
            }
        }

        $sort = 0;
        foreach ($plan as $cell) {
            // No stock columns here, on purpose: naming one — even as 0 — is a stock write that
            // StockWriteGuard refuses; the schema defaults them to 0 and the service fills them.
            $variantId = (int) DB::table('catalog_product_variants')->insertGetId([
                'product_id' => $productId,
                'sku' => self::sku($productId, $cell['color'], $cell['size']),
                'label' => self::label($cell['color'], $cell['size'], $colourNames, $sizeNames),
                'color_id' => $cell['color'],
                'size_id' => $cell['size'],
                'price_delta' => $cell['delta'],
                'is_active' => 1,
                'sort' => ++$sort,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            foreach (['express' => $cell['express'], 'market' => $cell['market']] as $bucket => $quantity) {
                if ($quantity > 0) {
                    $inventory->set(
                        target: StockTarget::variant($productId, $variantId),
                        bucket: $bucket,
                        quantity: $quantity,
                        reason: 'manual',
                        actor: Actor::system(),
                        note: self::NOTE_OPENING,
                    );
                }
            }
        }
        $inventory->recomputeInStock($productId);
        $products->flush($productId);

        return count($plan);
    }

    private static function sku(int $productId, ?int $colour, ?int $size): string
    {
        $sku = self::SKU_PREFIX.$productId;
        if ($colour !== null) {
            $sku .= '-C'.$colour;
        }
        if ($size !== null) {
            $sku .= '-S'.$size;
        }

        return $sku;
    }

    /**
     * @param  array<int, string>  $colourNames
     * @param  array<int, string>  $sizeNames
     */
    private static function label(?int $colour, ?int $size, array $colourNames, array $sizeNames): string
    {
        $parts = [];
        if ($colour !== null) {
            $parts[] = $colourNames[$colour] ?? "colour {$colour}";
        }
        if ($size !== null) {
            $parts[] = $sizeNames[$size] ?? "size {$size}";
        }

        return mb_substr(implode(' / ', $parts), 0, 100);
    }

    /**
     * English names by id from a translation table.
     *
     * @return array<int, string>
     */
    private function names(string $table, string $key): array
    {
        $names = [];
        foreach (DB::table($table)->where('locale', 'en')->get([$key, 'name']) as $raw) {
            $row = Row::cast($raw);
            $names[Row::int($row, $key)] = Row::str($row, 'name');
        }

        return $names;
    }

    /**
     * The palette and the size ids are constants read off one import; refuse loudly if the
     * lookups they name are not there rather than insert rows the FKs would reject one by one.
     *
     * @param  array<int, string>  $colourNames
     * @param  array<int, string>  $sizeNames
     */
    private function assertLookups(array $colourNames, array $sizeNames): void
    {
        foreach (self::PALETTE as $id) {
            if (! DB::table('catalog_colors')->where('id', $id)->exists()) {
                throw new RuntimeException("catalog_colors has no row {$id}; the palette constant was read off the 2026-10-16 import — re-check it.");
            }
        }
        foreach (self::SIZES as $ids) {
            foreach ($ids as $id) {
                if (! DB::table('catalog_sizes')->where('id', $id)->exists()) {
                    throw new RuntimeException("catalog_sizes has no row {$id}; the size constants were read off the 2026-10-16 import — re-check them.");
                }
            }
        }
        if ($colourNames === [] || $sizeNames === []) {
            throw new RuntimeException('No English colour or size names; the labels would be ids.');
        }
    }

    private function say(string $line): void
    {
        $this->command->line($line);
    }
}
