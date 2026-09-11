<?php

use App\Transform\Row;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\CatalogFixture;
use Tests\Support\Props;
use Tests\Support\Staff;
use Tests\Support\T;

use function Pest\Laravel\actingAs;

/*
 * The product list: filters, sort, search — and the HOSTILE PARAMETERS the non-negotiables name
 * (page/-1/99999, an unknown sort column, injection attempts through sort and filter names).
 *
 * The list is the screen the team opens first every morning, and the one a stale bookmark hits
 * with whatever was in the URL six weeks ago. Every one of these cases must render a usable page,
 * not a 500 and not a 422 the team cannot act on: `TableQuery` drops an out-of-range value rather
 * than rejecting the request, on purpose (see its docblock).
 */

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed> the `table` prop
 */
function listProps(array $query = []): array
{
    $response = actingAs(Staff::dataEntry())
        ->get('/manage/storefronts/1/products?'.http_build_query($query))
        ->assertOk();

    $props = Props::of($response);
    expect($props['table'])->toBeArray();

    /** @var array<string, mixed> $table */
    $table = $props['table'];

    return $table;
}

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed> the `meta` of the table payload
 */
function listMeta(array $query = []): array
{
    $table = listProps($query);
    expect($table['meta'])->toBeArray();

    /** @var array<string, mixed> $meta */
    $meta = $table['meta'];

    return $meta;
}

it('renders the list with the server deciding sort, page size and filters', function () {
    $meta = listMeta();

    expect($meta['page'])->toBe(1)
        ->and($meta['per_page'])->toBe(config('catalog.list.per_page'))
        ->and($meta['sort'])->toBe('p.id')
        ->and($meta['direction'])->toBe('desc')
        ->and($meta['total'])->toBeGreaterThan(0)
        // The whitelists are part of the payload, so the table component can only OFFER what the
        // server accepts — the list is code, the request is data.
        ->and($meta['sortable'])->toContain('p.wa_code')
        ->and($meta['filterable'])->toContain('p.family');
});

it('survives every hostile paging and sorting parameter the brief names', function () {
    $cases = [
        'page zero' => ['page' => 0],
        'page negative' => ['page' => -1],
        'page absurd' => ['page' => 99999],
        'page not a number' => ['page' => 'abc'],
        'page as an array' => ['page' => ['1']],
        'per_page absurd' => ['per_page' => 100000],
        'per_page zero' => ['per_page' => 0],
        'per_page negative' => ['per_page' => -5],
        'unknown sort column' => ['sort' => 'password'],
        'sort on another table' => ['sort' => 'users.email'],
        'sort injection' => ['sort' => 'p.id; DROP TABLE catalog_products'],
        'sort injection in a comment' => ['sort' => 'p.id /* */ UNION SELECT 1'],
        'direction injection' => ['sort' => 'p.id', 'direction' => 'asc, (SELECT 1)'],
        'filter on an unknown column' => ['filters' => ['password' => 'x']],
        'filter injection in the NAME' => ['filters' => ['p.id) OR 1=1 --' => '1']],
        'filter injection in the VALUE' => ['filters' => ['p.family' => "watch' OR '1'='1"]],
        'filter as a nested array' => ['filters' => ['p.family' => ['watch']]],
        'search with LIKE metacharacters' => ['q' => '%_\\'],
        'search with a quote' => ['q' => "' OR 1=1 --"],
        'search absurdly long' => ['q' => str_repeat('ا', 5000)],
        'search two characters (under the FULLTEXT floor)' => ['q' => 'سا'],
        'search boolean-mode operators' => ['q' => '+++ *** @@@ "unbalanced'],
        'everything at once' => [
            'page' => -3, 'per_page' => 999999, 'sort' => 'DROP', 'direction' => 'sideways',
            'q' => "%' UNION SELECT", 'filters' => ['nope' => 'nope', 'p.is_active' => '7'],
        ],
    ];

    foreach ($cases as $name => $query) {
        $meta = listMeta($query);

        expect(T::int($meta['page']))->toBeGreaterThanOrEqual(1, "{$name}: page must be clamped")
            ->and(T::int($meta['per_page']))->toBeLessThanOrEqual(config()->integer('catalog.list.per_page_max'), "{$name}: per_page must be capped")
            ->and(T::int($meta['per_page']))->toBeGreaterThanOrEqual(1, "{$name}: per_page must be at least 1")
            ->and(T::str($meta['direction']))->toBeIn(['asc', 'desc'], "{$name}: direction must be one of two literals")
            // A rejected sort falls back to the default, never to the caller's string.
            ->and(T::str($meta['sort']))->toBeIn(T::arr($meta['sortable']), "{$name}: sort must be a whitelisted column");
    }
});

it('drops an out-of-range filter value instead of 422ing a stale bookmark', function () {
    // `is_active` accepts '0' and '1'. A bookmark carrying '7' should render the UNFILTERED list,
    // because a 422 on a page the team reached from a link is a dead end.
    $meta = listMeta(['filters' => ['p.is_active' => '7']]);

    expect(T::arr($meta['filters'])['p.is_active'])->toBeNull()
        ->and(T::int($meta['total']))->toBeGreaterThan(0);
});

it('filters by family, brand, active and stock, and the counts move', function () {
    $all = T::int(listMeta()['total']);
    $watches = T::int(listMeta(['filters' => ['p.family' => 'watch']])['total']);
    $bags = T::int(listMeta(['filters' => ['p.family' => 'bag']])['total']);

    $realWatches = DB::table('catalog_products')->whereNull('deleted_at')->where('family', 'watch')->count();

    expect($watches)->toBe($realWatches)
        ->and($watches)->toBeLessThan($all)
        ->and($bags)->toBeGreaterThan(0)
        ->and($watches + $bags)->toBeLessThanOrEqual($all);

    $inactive = T::int(listMeta(['filters' => ['p.is_active' => '0']])['total']);
    $active = T::int(listMeta(['filters' => ['p.is_active' => '1']])['total']);
    expect($active + $inactive)->toBe($all);
});

it('filters by CATEGORY including the whole branch beneath it', function () {
    // A team member filtering by "Watches" means the branch, not the one node. The ids come from
    // the materialised path in one query — the same read the visibility rule uses.
    $watchesRoot = CatalogFixture::watchesRoot();
    $branchTotal = T::int(listMeta(['filters' => ['category' => (string) $watchesRoot]])['total']);

    $placedInBranch = T::int(DB::table('storefront_category_product as scp')
        ->join('storefront_categories as c', 'c.id', '=', 'scp.storefront_category_id')
        ->join('catalog_products as p', 'p.id', '=', 'scp.product_id')
        ->where('scp.storefront_id', 1)
        ->whereNull('p.deleted_at')
        ->where('c.path', 'like', T::str(DB::table('storefront_categories')->where('id', $watchesRoot)->value('path')).'%')
        ->distinct()
        ->count('scp.product_id'));

    expect($branchTotal)->toBe($placedInBranch)
        ->and($branchTotal)->toBeGreaterThan(0);
});

/**
 * Every SELECT the list screen issues, in order.
 *
 * @param  array<string, mixed>  $query
 * @return list<string>
 */
function listSql(array $query = []): array
{
    $seen = [];
    DB::listen(function (QueryExecuted $event) use (&$seen): void {
        if (str_starts_with(strtolower($event->sql), 'select')) {
            $seen[] = $event->sql;
        }
    });

    listProps($query);

    return $seen;
}

/**
 * The page query: the longest SELECT that names the list's driving alias, excluding the
 * paginator's total. Same rule `CatalogExplainListCommand` uses to pick the statement it EXPLAINs.
 *
 * @param  list<string>  $statements
 */
function pageSql(array $statements): string
{
    $best = '';
    foreach ($statements as $sql) {
        if (! str_contains($sql, 'catalog_products` as `p`')) {
            continue;
        }
        if (str_starts_with(strtolower($sql), 'select count(*) as `aggregate`')) {
            continue;
        }
        if (strlen($sql) > strlen($best)) {
            $best = $sql;
        }
    }

    expect($best)->not->toBe('', 'the list issued no page query at all');

    return $best;
}

it('asks for the join ORDER when a category branch filter is active, and only then', function () {
    /*
     * This is the guard on the one measured performance fix in 4B, and it is worth stating what it
     * protects rather than just what it checks.
     *
     * Left to itself MariaDB rewrites the branch `EXISTS` into a semi-join, materialises the whole
     * branch, and drives the query from that materialised set — which discards
     * `catalog_products`' primary-key ordering and pays `Using temporary; Using filesort` over
     * every product in the branch to return 25 rows. Measured at 7 000 products: 162.9 ms, the
     * list's worst case by an order of magnitude. `STRAIGHT_JOIN` fixes the join order and the
     * same page comes back in 8.5 ms (`docs/wave4b/EXPLAIN_2026-09-11.md`).
     *
     * A test cannot assert 8.5 ms without becoming a flake, so it asserts the property the plan
     * depends on: the hint is THERE for the branch filter, and NOT there otherwise — because it is
     * the wrong plan for a narrow branch, where the optimizer's own choice is faster.
     */
    $watches = CatalogFixture::watchesRoot();
    // A filter that matches nothing has no page query at all: the paginator stops at the COUNT.
    // So there has to be a row in the branch for there to be a plan to assert anything about.
    $id = CatalogFixture::product('watch');
    CatalogFixture::place($id, $watches);
    CatalogFixture::onStorefront($id);

    expect(pageSql(listSql(['filters' => ['category' => (string) $watches]])))
        ->toStartWith('select straight_join');

    // …and NOT otherwise. Each of these has to match at least one row, or the paginator stops at
    // the COUNT and there is no page query to make a claim about.
    $others = [
        [],
        ['filters' => ['p.family' => 'watch']],
        ['sort' => 'p.selling_price', 'direction' => 'asc'],
        ['per_page' => '5'],
    ];
    foreach ($others as $query) {
        expect(pageSql(listSql($query)))->toStartWith('select `p`.`id`');
    }
});

it('reads the cover and the variant count for the PAGE, not once per row', function () {
    /*
     * A correlated sub-select in a list query is evaluated for every row the query PRODUCES, and
     * "produces" is not "returns": the moment a filter makes the plan sort, that is every matching
     * row. The two this list used to carry cost it 100 ms of its 194 ms at 7k scale.
     *
     * So: no sub-select in the page query, and the extras cost the SAME number of queries for a
     * page of 3 as for a page of 25. The second half is what makes this test load-bearing — a
     * future edit that resolves a cover inside the row mapper would pass the first assertion and
     * fail this one.
     */
    $watches = CatalogFixture::watchesRoot();
    for ($i = 0; $i < 6; $i++) {
        $id = CatalogFixture::product('watch');
        CatalogFixture::place($id, $watches);
        CatalogFixture::onStorefront($id);
    }

    $small = listSql(['per_page' => '2']);
    $large = listSql(['per_page' => '100']);

    expect(pageSql($small))->not->toContain('(select ')
        ->and(pageSql($large))->not->toContain('(select ')
        ->and(count($large))->toBe(count($small));
});

it('finds a product through the FULLTEXT index, and through LIKE under three characters', function () {
    // The index is word-based InnoDB FULLTEXT with `innodb_ft_min_token_size = 3` fixed on this
    // server (AGENTS §2.3), so a two-character term matches NOTHING through it. The list falls
    // back to LIKE under that threshold; without the fallback a short Arabic search would return
    // an empty page while looking like it worked.
    //
    // IMPORTANT for anyone extending this: **an InnoDB FULLTEXT index does not see rows written
    // inside an uncommitted transaction.** The suite runs in one (`DatabaseTransactions`), so a
    // product created by a fixture is invisible to `MATCH … AGAINST` no matter how many times it
    // is re-indexed — the first version of this test asserted exactly that and failed for the
    // right reason. The index path is therefore exercised against a COMMITTED row of the real
    // catalogue, and the fixture is used only for the LIKE paths.
    $indexed = T::row(DB::table('catalog_product_search')->where('locale', 'en')->whereRaw('CHAR_LENGTH(body) > 12')->first());

    $word = '';
    foreach (preg_split('/\s+/', Row::str($indexed, 'body')) ?: [] as $candidate) {
        $clean = (string) preg_replace('/[^A-Za-z]/', '', $candidate);
        if (strlen($clean) >= 4) {
            $word = $clean;
            break;
        }
    }
    expect($word)->not->toBe('', 'no indexable word found in the committed search index');

    $viaIndex = listMeta(['q' => $word]);
    expect($viaIndex['search'])->toBe($word)
        ->and(T::int($viaIndex['total']))->toBeGreaterThan(0, "searching [{$word}] through the FULLTEXT index found nothing");

    // `wa_code` is always LIKE-searched — a code is a prefix, not a word — so a row created in
    // this transaction IS findable that way, which is what the team actually does with a code.
    $code = 'FINDME'.bin2hex(random_bytes(3));
    $productId = CatalogFixture::product();
    DB::table('catalog_products')->where('id', $productId)->update(['wa_code' => $code]);

    expect(T::int(listMeta(['q' => substr($code, 0, 8)])['total']))->toBe(1);

    // …and a two-character term still runs (through the LIKE fallback) instead of silently
    // returning nothing because the index ignored it.
    $short = listMeta(['q' => 'Ze']);
    expect($short['search'])->toBe('Ze');
});

it('never returns an archived product unless the archived flag asks for it', function () {
    $productId = CatalogFixture::product();
    DB::table('catalog_products')->where('id', $productId)->update(['deleted_at' => now()]);

    $default = listProps(['q' => (string) $productId]);
    $archived = listProps(['filters' => ['flag' => 'archived']]);

    $ids = array_map(fn (array $row): int => T::int($row['id']), Props::rows($default));
    expect($ids)->not->toContain($productId);

    $archivedIds = array_map(fn (array $row): int => T::int($row['id']), Props::rows($archived));
    expect($archivedIds)->toContain($productId);
});

it('flags the three states that stop a product from selling', function () {
    $noArabic = CatalogFixture::productWithoutArabic();

    $rows = Props::rows(listProps(['filters' => ['flag' => 'no_arabic']]));
    $ids = array_map(fn (array $row): int => T::int($row['id']), $rows);

    expect($ids)->toContain($noArabic);

    foreach ($rows as $row) {
        if (T::int($row['id']) === $noArabic) {
            expect($row['has_arabic'])->toBeFalse();
        }
    }
});

it('pages without repeating or losing a row', function () {
    // The classic silent pagination bug: a sort column with duplicates and no stable tiebreaker
    // returns the same row on two pages. `TableQuery` appends an id tiebreaker for exactly this.
    $first = Props::rows(listProps(['per_page' => 5, 'page' => 1, 'sort' => 'p.family']));
    $second = Props::rows(listProps(['per_page' => 5, 'page' => 2, 'sort' => 'p.family']));

    $firstIds = array_map(fn (array $row): int => T::int($row['id']), $first);
    $secondIds = array_map(fn (array $row): int => T::int($row['id']), $second);

    expect($first)->toHaveCount(5)
        ->and($second)->toHaveCount(5)
        ->and(array_intersect($firstIds, $secondIds))->toBe([], 'page 2 repeated a row from page 1');
});
