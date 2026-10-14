<?php

use App\Compat\CartIdentity;
use App\Compat\CompatCart;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Shops;
use Tests\Support\T;

/*
 * ── C10: a cart per storefront (developer: option (c), 2026-10-12; built 2026-10-13) ────────────
 *
 * `carts.storefront_id NOT NULL DEFAULT 1`. The default exists for ONE reason — the local harness
 * boots the legacy app, which inserts carts without the column — and its cost is that a production
 * insert which forgets the storefront becomes Watchizer silently. That cost is CLOSED here, not
 * accepted: the one production inserter is read as source and must set the column explicitly, and
 * the behaviour is driven — one customer, two shops, two carts.
 */

it('has the column as decided: NOT NULL, DEFAULT 1, indexed with the user', function () {
    $column = T::row(DB::selectOne("SHOW COLUMNS FROM carts WHERE Field = 'storefront_id'"));
    expect(Row::str($column, 'Null'))->toBe('NO')
        ->and(Row::str($column, 'Default'))->toBe('1')
        ->and(Row::str($column, 'Type'))->toContain('bigint');

    $index = collect(DB::select('SHOW INDEX FROM carts WHERE Key_name = ?', ['carts_user_storefront_index']))
        ->map(fn (mixed $r): string => Row::str(Row::cast(T::row($r)), 'Column_name'))->all();
    expect($index)->toBe(['user_id', 'storefront_id']);

    // `cart_items` is untouched: a line belongs to its cart, and the cart knows its shop.
    expect(Schema::hasColumn('cart_items', 'storefront_id'))->toBeFalse();
});

it('the ONE production inserter sets storefront_id explicitly, so the default never fires in production', function () {
    /*
     * Read as SOURCE, on purpose: the default makes a forgotten column invisible at runtime (the
     * row simply becomes Watchizer's), so a behaviour test could pass with the explicit value
     * removed. This one cannot. Every `DB::table('carts')->insert` in app/ is listed and each must
     * name the column.
     */
    $inserters = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app')));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (preg_match_all("/DB::table\\('carts'\\)->insert(?:GetId)?\\(\\s*\\[(.*?)\\]\\s*\\)/s", $source, $matches) > 0) {
            foreach ($matches[1] as $i => $columns) {
                $inserters[str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).'#'.($i + 1)] = str_contains($columns, "'storefront_id' => \$this->storefrontId");
            }
        }
    }

    expect($inserters)->toBe([str_replace('/', DIRECTORY_SEPARATOR, 'app/Compat/CompatCart.php').'#1' => true]);
});

it('gives one signed-in customer a SEPARATE cart on each shop, and a line added on one is not in the other', function () {
    $fashion = Shops::id('brandfashion');
    $userId = T::int(DB::table('users')->orderBy('id')->value('id'));
    DB::table('carts')->where('user_id', $userId)->delete();          // rolled back with the test

    $watchizer = (new CompatCart(1))->resolve(CartIdentity::user($userId));
    $brandFashion = (new CompatCart($fashion))->resolve(CartIdentity::user($userId));

    expect(Row::int(Row::cast($watchizer), 'id'))->not->toBe(Row::int(Row::cast($brandFashion), 'id'))
        ->and(Row::int(Row::cast($watchizer), 'storefront_id'))->toBe(1)
        ->and(Row::int(Row::cast($brandFashion), 'storefront_id'))->toBe($fashion)
        // …and asking again finds the SAME row for each shop, never the other's.
        ->and(Row::int(Row::cast((new CompatCart(1))->resolve(CartIdentity::user($userId))), 'id'))->toBe(Row::int(Row::cast($watchizer), 'id'))
        ->and(Row::int(Row::cast((new CompatCart($fashion))->find(CartIdentity::user($userId)) ?? new stdClass), 'id'))->toBe(Row::int(Row::cast($brandFashion), 'id'))
        ->and(DB::table('carts')->where('user_id', $userId)->count())->toBe(2);

    // A guest token is per browser per site; its row still carries the shop it was opened on.
    $token = (string) Str::uuid();
    $guest = (new CompatCart($fashion))->resolve(CartIdentity::guest($token));
    expect(Row::int(Row::cast($guest), 'storefront_id'))->toBe($fashion)
        ->and((new CompatCart(1))->find(CartIdentity::guest($token)))->toBeNull();   // Watchizer does not see Brand Fashion's guest cart
});

it('the local database holds no cart without a storefront (the backfill)', function () {
    expect(DB::table('carts')->whereNull('storefront_id')->orWhere('storefront_id', 0)->count())->toBe(0);
});
