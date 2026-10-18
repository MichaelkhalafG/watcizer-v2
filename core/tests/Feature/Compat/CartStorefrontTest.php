<?php

use App\Compat\CartIdentity;
use App\Compat\CompatCart;
use App\Transform\Row;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Shops;
use Tests\Support\T;

use function Pest\Laravel\postJson;

/*
 * ── C10: a cart per storefront (developer: option (c), 2026-10-12; built 2026-10-13) ────────────
 *
 * `carts.storefront_id NOT NULL DEFAULT 1`. The default exists for ONE reason — the local harness
 * boots the legacy app, which inserts carts without the column — and its cost is that a production
 * insert which forgets the storefront becomes Watchizer silently. That cost is CLOSED here, not
 * accepted: every production inserter is read as source and must set the column explicitly, and
 * the behaviour is driven — one customer, two shops, two carts.
 *
 * Round 6 (2026-10-18, Michael): there are TWO creators now — compat's cart and v2's (B7 S1) — allowed on the
 * condition that every cart either one creates carries its storefront; the test asserts the tagging, not the
 * number. Whether compat's cart retires once v2's write half is complete is a direction, not a decision
 * (POST_CUTOVER_BACKLOG.md, CARTS-TWO-CREATORS).
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

it('tags EVERY cart that either creator makes with its storefront: read as source, then driven on both shops', function () {
    /*
     * Decided by Michael, round 6 (2026-10-18): two cart creators are allowed — compat's (`CompatCart`) and v2's
     * (`StorefrontCart`, B7 S1) — on ONE condition, asserted here: every cart either of them creates carries its
     * storefront. The test asserts the TAGGING, not the number of creators.
     *
     * Read as SOURCE first, because `carts.storefront_id` defaults to 1: an insert that forgot the column would
     * silently become Watchizer's, and a runtime check on Watchizer could not tell. So every way app/ can create a
     * cart row is found — the query builder's whole insert family, raw SQL, a model on `carts` — and each must be a
     * literal column list that NAMES `storefront_id` with a value taken from its storefront (a variable or a call:
     * never a literal, never null). A creation form this test cannot read is a FAILURE, not a pass.
     */
    $creating = ['insert', 'insertGetId', 'insertOrIgnore', 'upsert', 'updateOrInsert', 'insertUsing'];
    $sites = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $where = str_replace(DIRECTORY_SEPARATOR, '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
        if (preg_match_all("/DB::table\(\s*'carts'\s*\)\s*->\s*(\w+)\s*\(/", $source, $m, PREG_OFFSET_CAPTURE) > 0) {
            $n = 0;
            foreach ($m[1] as [$method, $offset]) {
                if (! in_array($method, $creating, true)) {
                    continue;   // a read, an update of an existing row, a delete: none of them creates a cart
                }
                $key = $where.'#'.(++$n).' '.$method;
                if (! in_array($method, ['insert', 'insertGetId', 'insertOrIgnore'], true)) {
                    $sites[$key] = "unverifiable: {$method} — creates a cart in a form this test cannot read";

                    continue;
                }
                // The argument must be a literal array; walk to its matching bracket.
                $open = strpos($source, '(', $offset + strlen($method));
                $rest = ltrim(substr($source, $open === false ? 0 : $open + 1));
                if (! str_starts_with($rest, '[')) {
                    $sites[$key] = 'unverifiable: the columns are not a literal array';

                    continue;
                }
                $depth = 0;
                $columns = '';
                foreach (str_split($rest) as $char) {
                    $columns .= $char;
                    $depth += $char === '[' ? 1 : ($char === ']' ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                }
                if (preg_match("/'storefront_id'\s*=>\s*([^,\n]+)/", $columns, $value) !== 1) {
                    $sites[$key] = 'UNTAGGED: no storefront_id — the default would make it Watchizer\'s';

                    continue;
                }
                $value = trim($value[1]);
                $sites[$key] = str_starts_with($value, '$') ? 'tagged' : "UNTAGGED: storefront_id is the literal {$value}";
            }
        }
        if (preg_match('/insert\s+(?:ignore\s+)?into\s+`?carts`?\b/i', $source) === 1) {
            $sites[$where.' raw SQL'] = 'unverifiable: a raw INSERT INTO carts';
        }
        if (preg_match('/protected\s+\$table\s*=\s*\'carts\'/', $source) === 1) {
            $sites[$where.' model'] = 'unverifiable: an Eloquent model on carts';
        }
    }

    // The scanner must find the two creators it is meant to watch — a scanner that finds nothing proves nothing.
    expect(array_keys($sites))->toContain('app/Compat/CompatCart.php#1 insert', 'app/Storefront/StorefrontCart.php#1 insertGetId');
    $untagged = array_filter($sites, fn (string $verdict): bool => $verdict !== 'tagged');
    expect($untagged)->toBe([]);

    // Driven: each creator, on each shop, writes the shop it was made for — Brand Fashion's 2 can only come from the
    // explicit value, never from the default.
    $fashion = Shops::id('brandfashion');
    $plain = function (int $storefrontId): int {
        return T::int(DB::table('catalog_products as p')
            ->join('storefront_product as sp', fn (JoinClause $j) => $j->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId)->where('sp.is_visible', '=', 1))
            ->where('p.is_active', 1)->whereNull('p.deleted_at')->where('p.stock_express', '>=', 1)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('catalog_product_variants as v')->whereColumn('v.product_id', 'p.id')->where('v.is_active', 1))
            ->orderBy('p.id')->value('p.id'));
    };
    $made = [];
    foreach ([1 => 'watchizer', $fashion => 'brandfashion'] as $id => $code) {
        $compat = (new CompatCart($id))->resolve(CartIdentity::guest((string) Str::uuid()));
        $made["compat {$code}"] = [$id, Row::int(Row::cast($compat), 'storefront_id')];

        $token = T::str(postJson("/api/v2/{$code}/cart/items", ['product_id' => $plain($id), 'quantity' => 1])->assertOk()->headers->get('X-Guest-Token'));
        $made["v2 {$code}"] = [$id, T::int(DB::table('carts')->where('guest_token', $token)->value('storefront_id'))];
    }
    $wrong = array_filter($made, fn (array $pair): bool => $pair[0] !== $pair[1]);
    expect($wrong)->toBe([])->and($made)->toHaveCount(4);
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
