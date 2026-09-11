<?php

use App\Support\Table\TableQuery;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/*
 * The server half of the table system. These are the tests that keep a future screen honest:
 * a request may only name what the screen offered, and the pagination must not repeat a row.
 */

/** @param array<string, mixed> $query */
function tableFor(array $query): TableQuery
{
    return TableQuery::for(Request::create('/manage/storefronts', 'GET', $query))
        ->sortable(['id', 'code', 'name'], default: 'id')
        ->searchable(['code', 'name'])
        ->filterable(['is_active' => ['0', '1'], 'currency' => null])
        ->perPage(default: 10, max: 50);
}

it('applies the declared default sort when the request names none', function () {
    expect(tableFor([])->resolvedSort())->toBe('id')
        ->and(tableFor([])->resolvedDirection())->toBe('asc');
});

it('IGNORES a sort column the screen did not declare', function () {
    // The whole point of the whitelist: `password` is a real column on another table, and a
    // request asking to sort by it gets the default instead of an error or a query.
    expect(tableFor(['sort' => 'password'])->resolvedSort())->toBe('id')
        ->and(tableFor(['sort' => 'code'])->resolvedSort())->toBe('code');
});

it('accepts only asc and desc as a direction', function () {
    expect(tableFor(['direction' => 'desc'])->resolvedDirection())->toBe('desc')
        ->and(tableFor(['direction' => 'sideways'])->resolvedDirection())->toBe('asc')
        ->and(tableFor(['direction' => 'DESC'])->resolvedDirection())->toBe('desc');
});

it('caps per_page at the maximum the screen allows', function () {
    expect(tableFor(['per_page' => 25])->resolvedPerPage())->toBe(25)
        ->and(tableFor(['per_page' => 5000])->resolvedPerPage())->toBe(50)
        ->and(tableFor(['per_page' => 0])->resolvedPerPage())->toBe(1)
        ->and(tableFor(['per_page' => 'many'])->resolvedPerPage())->toBe(10);
});

it('trims and caps the search term', function () {
    expect(tableFor(['q' => '  watch  '])->resolvedSearch())->toBe('watch')
        ->and(tableFor(['q' => '   '])->resolvedSearch())->toBeNull()
        ->and(mb_strlen((string) tableFor(['q' => str_repeat('x', 500)])->resolvedSearch()))->toBe(100);
});

it('drops a filter value outside the allowed set instead of erroring', function () {
    // A stale bookmark should render the unfiltered screen, not a 422 the team cannot act on.
    expect(tableFor(['filters' => ['is_active' => '1']])->resolvedFilters())->toBe(['is_active' => '1', 'currency' => null])
        ->and(tableFor(['filters' => ['is_active' => 'maybe']])->resolvedFilters())->toBe(['is_active' => null, 'currency' => null])
        // A filter declared with `null` allows any scalar (currency codes are open-ended).
        ->and(tableFor(['filters' => ['currency' => 'EGP']])->resolvedFilters()['currency'])->toBe('EGP');
});

it('escapes LIKE metacharacters, so a term containing % is a literal search', function () {
    $sql = tableFor(['q' => '50%'])->apply(DB::table('catalog_products'))->toRawSql();

    // The wildcard is escaped in the pattern; it does not become "match everything".
    expect($sql)->toContain('50\\\\%')->and($sql)->toContain('like');
});

it('adds a stable tiebreaker so page 2 cannot repeat a row from page 1', function () {
    $sql = TableQuery::for(Request::create('/x', 'GET', ['sort' => 'name']))
        ->sortable(['name'], default: 'name')
        ->apply(DB::table('storefronts'))
        ->toRawSql();

    expect($sql)->toContain('order by `name` asc')->toContain('`storefronts`.`id` asc');
});

it('qualifies the tiebreaker with the alias, so a join cannot make id ambiguous', function () {
    $sql = TableQuery::for(Request::create('/x', 'GET', []))
        ->sortable(['sp.slug'], default: 'sp.slug')
        ->apply(DB::table('storefront_product as sp')->join('catalog_products as p', 'p.id', '=', 'sp.product_id'))
        ->toRawSql();

    expect($sql)->toContain('`sp`.`id` asc');
});

it('returns the meta a client needs to render its own controls', function () {
    $payload = tableFor(['per_page' => 1])->paginate(
        DB::table('storefronts'),
        fn (object $row): array => ['id' => (int) (is_numeric($row->id ?? null) ? $row->id : 0)],
    );

    expect($payload['meta'])->toHaveKeys(['page', 'per_page', 'total', 'last_page', 'from', 'to', 'sort', 'direction', 'search', 'filters', 'sortable', 'filterable'])
        ->and($payload['meta']['per_page'])->toBe(1)
        ->and($payload['meta']['sortable'])->toBe(['id', 'code', 'name'])
        ->and($payload['meta']['filterable'])->toBe(['is_active', 'currency'])
        ->and($payload['data'])->toHaveCount(1);
});

it('honours a screen-supplied search closure instead of LIKE', function () {
    $table = TableQuery::for(Request::create('/x', 'GET', ['q' => 'rolex']))
        ->sortable(['id'], default: 'id')
        ->searchUsing(function (EloquentBuilder|Builder $query, string $term): void {
            $query->where('wa_code', $term);
        });

    expect($table->apply(DB::table('catalog_products'))->toRawSql())
        ->toContain("`wa_code` = 'rolex'");
});
