<?php

use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Domain\Inventory\StockWriteGuard;
use Illuminate\Support\Facades\DB;
use Tests\Support\T;

/*
 * "No code anywhere else may touch a stock column" (wave 3 brief). This proves BOTH halves: the
 * guard blocks a write from outside the service, and it does not block the service itself.
 *
 * A model observer would prove neither, because the read path, the transform and the compat
 * layer are all query-builder code that never reaches one. The guard listens on QueryExecuted
 * instead, so it sees every statement the application sends.
 */

it('sees every SQL shape a raw stock write really takes (the reviewer 12-route matrix)', function (string $sql, array $tables) {
    expect(StockWriteGuard::stockWriteTables($sql))->toBe($tables);
})->with([
    // ── visible: the tripwire throws on all ten ──────────────────────────────
    'backticked update (Laravel)' => ['update `catalog_products` set `stock_express` = stock_express + (-1), `updated_at` = ? where `id` = ?', ['catalog_products']],
    'unquoted table and column' => ['update catalog_products set stock_market = 3 where id = 7', ['catalog_products']],
    'upper case over several lines' => ["UPDATE\n  catalog_products\nSET\n  STOCK_EXPRESS = 0\nWHERE id = 1", ['catalog_products']],
    'leading block comment' => ['/* nightly fixup */ update catalog_products set stock_express = 5', ['catalog_products']],
    'leading line comment' => ["-- quick fix\nupdate catalog_products set in_stock = 1", ['catalog_products']],
    'multi-table UPDATE … JOIN' => ['update catalog_products p join storefront_product sp on sp.product_id = p.id set p.stock_express = 0', ['catalog_products']],
    'alias-qualified column' => ['update `catalog_products` `p` set `p`.`stock_market` = 2 where `p`.`id` = 9', ['catalog_products']],
    'insert with a column list' => ['insert into `catalog_products` (`wa_code`, `stock_express`, `stock_market`) values (?, ?, ?)', ['catalog_products']],
    'insert … on duplicate key update' => ['insert into catalog_products (id, wa_code) values (1, ?) on duplicate key update stock_express = 4', ['catalog_products']],
    'legacy offers.stock' => ['update `offers` set `stock` = stock + (-1), `updated_at` = ? where `id` = ?', ['offers']],
    // ── correctly none of its business ───────────────────────────────────────
    'stock only in the WHERE clause' => ['update `catalog_products` set `rating_avg` = ? where `stock_express` > 0', []],
    'an unrelated write' => ['insert into `inventory_movements` (`quantity_after`, `bucket`) values (?, ?)', []],
    'a plain read' => ['select `stock_express` from `catalog_products` where `id` = ?', []],
    'deleting a product is not a stock mutation' => ['delete from `catalog_products` where `id` = ?', []],
    'a different table entirely' => ['update `storefront_product` set `is_visible` = 1', []],
]);

it('normalises comments, quoting, case and whitespace away before matching', function () {
    $messy = "/* a */ UPDATE  `catalog_products`\n   SET   `STOCK_EXPRESS`  =  1 -- trailing\n";

    expect(StockWriteGuard::normalise($messy))
        ->toBe('update catalog_products set stock_express = 1');
});

it('reports EVERY protected table a multi-table update touches', function () {
    // Nonsense as SQL, deliberate as a test: if two protected tables appear in one table clause
    // the guard must name both rather than stopping at the first.
    expect(StockWriteGuard::stockWriteTables('update catalog_products p join offers o on o.main_product_id = p.id set p.stock_express = 0, o.stock = 0'))
        ->toBe(['catalog_products', 'offers']);
});

it('documents the two routes it can never see', function () {
    // Not a behavioural assertion — a pin on the honest limit, so that removing the sentence from
    // the docblock breaks a test rather than quietly overstating the guarantee.
    $source = (string) file_get_contents(base_path('app/Domain/Inventory/StockWriteGuard.php'));

    expect($source)->toContain('PDO::exec()')
        ->and($source)->toContain('DEVELOPMENT TRIPWIRE')
        ->and($source)->toContain('inventory:verify')
        ->and($source)->toContain('It is not a security boundary.');
});

it('throws when a stock column is written outside the service', function () {
    $id = T::int(DB::table('catalog_products')->orderBy('id')->value('id'));

    expect(fn () => DB::table('catalog_products')->where('id', $id)->update(['stock_express' => 999]))
        ->toThrow(RuntimeException::class, 'Stock column written outside InventoryService');
});

it('lets the service through, and closes the window again afterwards', function () {
    $id = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));

    expect(StockWriteGuard::permitted())->toBeFalse();
    app(InventoryService::class)->adjust(StockTarget::product($id), 'market', 1, 'restock');
    expect(StockWriteGuard::permitted())->toBeFalse();

    // …and the guard is still armed after the service used it.
    expect(fn () => DB::table('catalog_products')->where('id', $id)->update(['stock_market' => 1]))
        ->toThrow(RuntimeException::class);
});

it('counts nesting, so a transform step calling the service does not close the window early', function () {
    $id = T::int(DB::table('catalog_products')->whereNull('deleted_at')->orderBy('id')->value('id'));

    StockWriteGuard::allow(function () use ($id): void {
        app(InventoryService::class)->adjust(StockTarget::product($id), 'market', 1, 'restock');   // opens and closes an inner window
        expect(StockWriteGuard::permitted())->toBeTrue();                     // the outer one is still open
        DB::table('catalog_products')->where('id', $id)->update(['stock_market' => DB::raw('`stock_market` + 0')]);
    });

    expect(StockWriteGuard::permitted())->toBeFalse();
});

it('leaves a non-stock column of catalog_products alone', function () {
    $id = T::int(DB::table('catalog_products')->orderBy('id')->value('id'));

    DB::table('catalog_products')->where('id', $id)->update(['search_keywords' => 'guard test']);

    expect(DB::table('catalog_products')->where('id', $id)->value('search_keywords'))->toBe('guard test');
});

it('names every file allowed to open the guard window', function () {
    // The runtime guard proves "nothing else writes a stock column" for the paths a test
    // exercises. This proves the complementary half for every line that exists: only three files
    // may open the window at all, and two of them are the transform and its concurrency probe.
    $openers = [];
    /** @var Iterator<string, SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $path => $file) {
        $path = (string) $path;
        if (! str_ends_with($path, '.php')) {
            continue;
        }
        if (str_ends_with($path, 'StockWriteGuard.php')) {
            continue;                                   // the guard's own docblock names the method
        }
        if (str_contains((string) file_get_contents($path), 'StockWriteGuard::allow(')) {
            $openers[] = str_replace([base_path().DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], ['', '/'], $path);
        }
    }
    sort($openers);

    expect($openers)->toBe([
        // the transform, and the two concurrency probes plus the fixture trait they share
        'app/Console/Commands/Concerns/CreatesProbeProduct.php',
        'app/Console/Commands/CoreTransformCommand.php',
        'app/Console/Commands/InventoryProveReleaseRaceCommand.php',
        'app/Domain/Inventory/InventoryService.php',
    ]);
});
