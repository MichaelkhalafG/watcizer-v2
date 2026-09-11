<?php

namespace App\Support\Table;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * The server half of the dashboard's table system: read the request, apply it to a query, and
 * return the payload the React `DataTable` expects.
 *
 * Everything is SERVER-SIDE, for the same reason the storefront listing is (§5.1): Brand Fashion's
 * catalogue is 7 447 products and the orders table only grows. A client-side table would ship the
 * whole set to a tablet on 4G and sort it there.
 *
 * ── Whitelists, not passthrough ──────────────────────────────────────────────────────────────
 *
 * `sort`, `filters[…]` and the search columns are declared by the CALLER and validated here. A
 * request asking to sort by `password` gets the default sort instead of an error page, and no
 * request can ever name a column the screen did not offer. That is the difference between a
 * feature and an injection surface: the column list is code, the request is data.
 *
 * Usage from a controller (this is the whole contract a 4B screen needs):
 *
 *   $table = TableQuery::for($request)
 *       ->sortable(['id', 'wa_code', 'stock_express', 'created_at'], default: 'created_at', direction: 'desc')
 *       ->searchable(['wa_code', 'sku'])                       // LIKE over these columns
 *       ->filterable(['family' => ['watch', 'bag'], 'is_active' => ['0', '1'], 'flag' => ['low_stock']])
 *       ->virtual(['flag'])                                    // declared, applied by the caller
 *       ->perPage(default: 25, max: 100);
 *
 *   return Inertia::render('Products/Index', [
 *       'table' => $table->paginate($query, fn (object $row): array => [...]),
 *   ]);
 *
 * @phpstan-type TableMeta array{page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null, sort: string|null, direction: string, search: string|null, filters: array<string, string|null>, sortable: list<string>, filterable: list<string>, virtual: list<string>}
 */
final class TableQuery
{
    /** @var list<string> */
    private array $sortable = [];

    private ?string $defaultSort = null;

    private string $defaultDirection = 'asc';

    /** @var list<string> */
    private array $searchable = [];

    /** @var array<string, list<string>|null> column => allowed values, or null for "any scalar" */
    private array $filterable = [];

    /**
     * Declared filters that are NOT columns.
     *
     * A screen sometimes filters by something the query builder cannot express as
     * `where(column, value)` — wave 4B's product list filters by a whole BRANCH of the category
     * tree (resolved through the materialised path) and by a `flag` whose values are four
     * different predicates. Those still need everything `filterable()` gives them: a whitelist,
     * a place in the query string, a seat in the reset button, and an entry in the payload the
     * React table renders its controls from.
     *
     * What they must NOT get is `apply()`'s automatic `where()`, which emitted
     * `where('flag', 'no_arabic')` and came back as a 1054 the first time the list ran.
     *
     * So they are declared alongside the columns and listed here; `apply()` skips them and the
     * CALLER applies them, with the resolved value from {@see self::resolvedFilters()}.
     *
     * @var list<string>
     */
    private array $virtual = [];

    private int $perPageDefault = 25;

    private int $perPageMax = 100;

    /** @var callable(EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder, string): void|null */
    private $searchUsing = null;

    private function __construct(private readonly Request $request) {}

    public static function for(Request $request): self
    {
        return new self($request);
    }

    /**
     * @param  list<string>  $columns  columns (or `table.column`) a caller may sort by
     */
    public function sortable(array $columns, ?string $default = null, string $direction = 'asc'): self
    {
        $this->sortable = $columns;
        $this->defaultSort = $default ?? ($columns[0] ?? null);
        $this->defaultDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $this;
    }

    /**
     * Columns the `q` box searches with `LIKE %term%`.
     *
     * @param  list<string>  $columns
     */
    public function searchable(array $columns): self
    {
        $this->searchable = $columns;

        return $this;
    }

    /**
     * A screen whose search needs more than LIKE (a join, a FULLTEXT match, a translated name)
     * supplies its own closure; the term still arrives trimmed and length-capped.
     *
     * @param  callable(EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder, string): void  $callback
     */
    public function searchUsing(callable $callback): self
    {
        $this->searchUsing = $callback;

        return $this;
    }

    /**
     * @param  array<string, list<string>|null>  $filters  column => allowed values (null = any scalar)
     */
    public function filterable(array $filters): self
    {
        $this->filterable = $filters;

        return $this;
    }

    /**
     * Mark declared filters as VIRTUAL: validated and reported, never applied as a column.
     *
     * @param  list<string>  $keys  keys that also appear in `filterable()`
     */
    public function virtual(array $keys): self
    {
        $this->virtual = $keys;

        return $this;
    }

    /** Is this declared filter a column `apply()` may use, or the caller's own business? */
    public function isVirtual(string $key): bool
    {
        return in_array($key, $this->virtual, true);
    }

    public function perPage(int $default = 25, int $max = 100): self
    {
        $this->perPageDefault = max(1, $default);
        $this->perPageMax = max($this->perPageDefault, $max);

        return $this;
    }

    // ── the resolved request ─────────────────────────────────────────────────────────────────

    public function resolvedPerPage(): int
    {
        $value = $this->request->query('per_page');
        $requested = is_numeric($value) ? (int) $value : $this->perPageDefault;

        return max(1, min($requested, $this->perPageMax));
    }

    public function resolvedPage(): int
    {
        $value = $this->request->query('page');

        return max(1, is_numeric($value) ? (int) $value : 1);
    }

    /** The sort column, or null when the screen declared none. Never a column outside the whitelist. */
    public function resolvedSort(): ?string
    {
        $value = $this->request->query('sort');
        $requested = is_string($value) ? $value : null;

        return $requested !== null && in_array($requested, $this->sortable, true) ? $requested : $this->defaultSort;
    }

    public function resolvedDirection(): string
    {
        $value = $this->request->query('direction');
        $requested = is_string($value) ? strtolower($value) : null;

        return in_array($requested, ['asc', 'desc'], true) ? $requested : $this->defaultDirection;
    }

    /** The search term: trimmed, capped at 100 characters, null when empty. */
    public function resolvedSearch(): ?string
    {
        $value = $this->request->query('q');
        if (! is_string($value)) {
            return null;
        }
        $term = trim($value);

        return $term === '' ? null : mb_substr($term, 0, 100);
    }

    /** @return array<string, string|null> */
    public function resolvedFilters(): array
    {
        /** @var array<string, mixed> $raw */
        $raw = is_array($this->request->query('filters')) ? $this->request->query('filters') : [];
        $out = [];

        foreach ($this->filterable as $column => $allowed) {
            $value = $raw[$column] ?? null;
            if (! is_scalar($value)) {
                $out[$column] = null;

                continue;
            }
            $value = (string) $value;
            if ($value === '') {
                $out[$column] = null;

                continue;
            }
            // An out-of-range value is dropped, not rejected: a stale bookmark should render the
            // unfiltered screen rather than a 422 the team cannot act on.
            $out[$column] = $allowed === null || in_array($value, $allowed, true) ? $value : null;
        }

        return $out;
    }

    // ── applying it ──────────────────────────────────────────────────────────────────────────

    /**
     * Apply search, filters and sort to a query. Pagination is applied by {@see self::paginate()}.
     *
     * @template TQuery of EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply(EloquentBuilder|QueryBuilder $query): EloquentBuilder|QueryBuilder
    {
        $search = $this->resolvedSearch();
        if ($search !== null) {
            if ($this->searchUsing !== null) {
                ($this->searchUsing)($query, $search);
            } elseif ($this->searchable !== []) {
                $columns = $this->searchable;
                $query->where(function (BuilderContract $inner) use ($columns, $search): void {
                    foreach ($columns as $column) {
                        // `LIKE ?` with a bound parameter: the term never becomes SQL, and the
                        // wildcards are escaped so a customer searching for "50%" finds "50%".
                        $inner->orWhere($column, 'like', '%'.self::escapeLike($search).'%');
                    }
                });
            }
        }

        foreach ($this->resolvedFilters() as $column => $value) {
            // A virtual filter is the caller's to apply: it is declared here so the whitelist,
            // the URL and the reset button know about it, but it is not a column.
            if ($value !== null && ! $this->isVirtual($column)) {
                $query->where($column, $value);
            }
        }

        $sort = $this->resolvedSort();
        if ($sort !== null) {
            // The direction is one of exactly two literals by construction (resolvedDirection),
            // which is what `orderBy()` asks for at level 10.
            $query->orderBy($sort, $this->resolvedDirection() === 'desc' ? 'desc' : 'asc');
            // A stable tiebreaker, so page 2 never repeats a row from page 1 when the sort column
            // has duplicates — the classic silent pagination bug.
            if (! str_contains($sort, 'id')) {
                $query->orderBy(self::qualify($query, 'id'), 'asc');
            }
        }

        return $query;
    }

    /**
     * Run the query and build the payload.
     *
     * @template TQuery of EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder
     *
     * @param  TQuery  $query
     * @param  callable(object): array<string, mixed>  $map  one row of the response per DB row
     * @param  (callable(list<object>): void)|null  $prepare  run ONCE with the page's raw rows,
     *                                                        before `$map` — see below
     * @return array{data: list<array<string, mixed>>, meta: TableMeta}
     */
    public function paginate(EloquentBuilder|QueryBuilder $query, callable $map, ?callable $prepare = null): array
    {
        $perPage = $this->resolvedPerPage();
        $page = $this->resolvedPage();

        $paginator = $this->apply($query)->paginate(perPage: $perPage, page: $page);

        $items = [];
        foreach ($paginator->items() as $item) {
            if (! is_object($item)) {
                throw new InvalidArgumentException('A table row must be an object (model or stdClass).');
            }
            $items[] = $item;
        }

        /*
         * `$prepare` is where a screen resolves the per-row extras a LIST QUERY must not carry:
         * a cover image, a child count — anything that would otherwise be a correlated sub-select
         * in the SELECT list.
         *
         * The reason is measured, not stylistic. A sub-select in the SELECT list is evaluated for
         * every row the query PRODUCES, and "produces" is not "returns": as soon as a filter makes
         * the plan sort (`Using filesort`), every matching row is built before the LIMIT applies.
         * Wave 4B's category-branch filter is exactly that case — at 7 000 products with ~7 200
         * placements in the branch, two sub-selects ran ~4 000 times each for a page of 25 and the
         * query took 194 ms. Removing them halved it on the spot (measured: 92 ms); fixing the
         * plan on top took it to 8 ms (`docs/wave4b/EXPLAIN_2026-09-11.md`).
         *
         * With `prepare` the caller reads those extras for the PAGE — one query each, 25 ids —
         * after the page is known, which is the discipline the storefront read layer already
         * follows for its lookups (study §5.1).
         */
        if ($prepare !== null) {
            $prepare($items);
        }

        $rows = [];
        foreach ($items as $item) {
            $rows[] = $map($item);
        }

        return [
            'data' => $rows,
            'meta' => [
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'sort' => $this->resolvedSort(),
                'direction' => $this->resolvedDirection(),
                'search' => $this->resolvedSearch(),
                'filters' => $this->resolvedFilters(),
                'sortable' => $this->sortable,
                'filterable' => array_keys($this->filterable),
                'virtual' => $this->virtual,
            ],
        ];
    }

    /** `%`, `_` and `\` are LIKE metacharacters; a search term must not carry them into the pattern. */
    private static function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);
    }

    /**
     * Qualify the tiebreaker with the query's own table, so a join cannot make `id` ambiguous.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $query
     */
    private static function qualify(EloquentBuilder|QueryBuilder $query, string $column): string
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $from = $base->from;
        if (! is_string($from) || $from === '') {
            return $column;
        }
        // `table as alias` → alias.
        $parts = preg_split('/\s+as\s+/i', $from);
        $table = is_array($parts) ? trim((string) end($parts)) : $from;

        return $table.'.'.$column;
    }
}
