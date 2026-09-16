<?php

namespace App\Support\Table;

use App\Domain\Access\Role;
use App\Support\ManageText;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 * @phpstan-type TableMeta array{page: int, per_page: int, total: int, last_page: int, from: int|null, to: int|null, sort: string|null, direction: string, search: string|null, filters: array<string, string|null>, sortable: list<string>, filterable: list<string>, virtual: list<string>, exportable: bool}
 */
final class TableQuery
{
    /** @var list<string> */
    private array $sortable = [];

    private ?string $defaultSort = null;

    private string $defaultDirection = 'asc';

    /**
     * The column that breaks a tie, so page 2 never repeats a row from page 1.
     *
     * `id` is right for a table and WRONG for a derived one: the customers screen is a UNION of
     * accounts and guest groups and has no `id` at all — the first query over it failed with a
     * 1054 on the tiebreaker, not on anything the screen asked for. A caller whose result set is
     * keyed by something else names it.
     */
    private ?string $tiebreaker = null;

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

    /**
     * The CSV columns, in file order: the KEYS of the row the screen already renders.
     *
     * Declared next to `sortable()`/`filterable()` because it is the same kind of statement — what
     * this screen offers — and because the export must never grow a column list of its own. See
     * {@see TableExport} for why picking from the rendered row is what makes a leak structurally
     * impossible rather than merely unintended.
     *
     * @var list<array{key: string, label: string, value: (callable(array<string, mixed>): string)|null}>
     */
    private array $exportColumns = [];

    private string $exportName = 'export';

    private function __construct(private readonly Request $request) {}

    public static function for(Request $request): self
    {
        return new self($request);
    }

    /**
     * @param  list<string>  $columns  columns (or `table.column`) a caller may sort by
     */
    public function sortable(array $columns, ?string $default = null, string $direction = 'asc', ?string $tiebreaker = null): self
    {
        $this->sortable = $columns;
        $this->defaultSort = $default ?? ($columns[0] ?? null);
        $this->defaultDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        $this->tiebreaker = $tiebreaker;

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

    /**
     * Offer a CSV of the CURRENT VIEW.
     *
     * Each entry is either `key => 'Arabic label'` for a prop that is already a scalar, or
     * `key => ['Arabic label', fn (array $row): string => …]` when the prop is a shape (a
     * translated pair, a list of badges) and the file wants one column of it.
     *
     * @param  array<string, string|array{0: string, 1: callable(array<string, mixed>): string}>  $columns
     * @param  string  $name  the filename stem, ASCII — `products`, `stock-ledger`
     */
    public function exportable(array $columns, string $name): self
    {
        $this->exportName = $name;
        $this->exportColumns = [];

        foreach ($columns as $key => $column) {
            $this->exportColumns[] = is_array($column)
                ? ['key' => $key, 'label' => $column[0], 'value' => $column[1]]
                : ['key' => $key, 'label' => $column, 'value' => null];
        }

        return $this;
    }

    /**
     * Did this request ask for the file rather than the page?
     *
     * `?export=csv` on the INDEX url, answered by the index action — so the export sits behind the
     * screen's own middleware, gate and storefront scope, and there is no second authorisation
     * path that could drift out of step with the first.
     */
    public function wantsExport(): bool
    {
        if ($this->exportColumns === [] || $this->request->query('export') !== 'csv') {
            return false;
        }

        self::assertMayExport();

        return true;
    }

    /**
     * THE EXPORT DOOR (review 🔴-2, 2026-09-15).
     *
     * Server-side, in the one place every paginated export passes through, so a typed URL meets
     * the same refusal as a click and no screen has to remember. Hiding the button is presentation
     * and was never the control.
     *
     * A 403 with a SENTENCE, not a bare status: the operator is not doing anything wrong, they
     * simply do not hold this, and a blank refusal sends them to ask why.
     */
    public static function assertMayExport(): void
    {
        if (Gate::allows(Role::EXPORT_DATA)) {
            return;
        }

        abort(403, ManageText::t('table.export_forbidden', 'تصدير البيانات ليس ضمن صلاحياتك. الشاشة متاحة لك بالكامل، أما تنزيل الملف فيحتاج صلاحية مدير.'));
    }

    /**
     * Stream the current view as CSV. Same query, same filters, same row callback as the screen.
     *
     * @template TQuery of EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder
     *
     * @param  TQuery  $query
     * @param  callable(object): array<string, mixed>  $map  the SCREEN's row callback, unchanged
     * @param  (callable(list<object>): void)|null  $prepare  the screen's batch loader, per chunk
     */
    public function export(EloquentBuilder|QueryBuilder $query, callable $map, ?callable $prepare = null): StreamedResponse
    {
        $applied = $this->apply($query);
        $total = $applied->getCountForPagination();

        return TableExport::respond($this->exportName, $total, $this->exportColumns, $this->stream($applied, $map, $prepare));
    }

    /**
     * The rows, a batch at a time, never the whole set.
     *
     * `cursor()` keeps the query's exact ordering and holds one row in PHP at a time; the batch
     * exists only so `$prepare` — the screen's "load the covers for these 500 ids" step — still
     * gets to do one query per batch instead of one per row.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|QueryBuilder  $applied
     * @param  callable(object): array<string, mixed>  $map
     * @param  (callable(list<object>): void)|null  $prepare
     * @return iterable<int, array<string, mixed>>
     */
    private function stream(EloquentBuilder|QueryBuilder $applied, callable $map, ?callable $prepare): iterable
    {
        /** @var list<object> $batch */
        $batch = [];

        foreach ($applied->cursor() as $item) {
            $batch[] = $item;
            if (count($batch) < 500) {
                continue;
            }
            yield from self::mapBatch($batch, $map, $prepare);
            $batch = [];
        }

        if ($batch !== []) {
            yield from self::mapBatch($batch, $map, $prepare);
        }
    }

    /**
     * @param  list<object>  $batch
     * @param  callable(object): array<string, mixed>  $map
     * @param  (callable(list<object>): void)|null  $prepare
     * @return list<array<string, mixed>>
     */
    private static function mapBatch(array $batch, callable $map, ?callable $prepare): array
    {
        if ($prepare !== null) {
            $prepare($batch);
        }

        $rows = [];
        foreach ($batch as $item) {
            $rows[] = $map($item);
        }

        return $rows;
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
            $direction = $this->resolvedDirection() === 'desc' ? 'desc' : 'asc';
            $query->orderBy($sort, $direction);
            // A stable tiebreaker, so page 2 never repeats a row from page 1 when the sort column
            // has duplicates — the classic silent pagination bug.
            $tiebreaker = $this->tiebreaker ?? (str_contains($sort, 'id') ? null : self::qualify($query, 'id'));
            if ($tiebreaker !== null && $tiebreaker !== $sort) {
                /*
                 * ── The tiebreaker follows the SORT's direction, and that is a performance fix ──
                 *
                 * This was hard-coded `asc`. Stability does not care — any deterministic order
                 * breaks the tie — but the DATABASE cares enormously, because `ORDER BY col DESC,
                 * id ASC` is a MIXED-direction sort and no index can serve one. MariaDB answers it
                 * by sorting the whole result set and throwing away everything but the page.
                 *
                 * Measured on a 20,000-row table, `ORDER BY created_at …, id … LIMIT 25`:
                 *
                 *   created_at DESC, id ASC    filesort over 19,621 rows    4.71 ms
                 *   created_at DESC, id DESC   rows=25, Using index         0.45 ms
                 *
                 * …and the deep page (OFFSET 10000) goes 7.29 ms → 4.40 ms. The mixed sort is also
                 * why adding an index to `orders.created_at` on its own made the screen SLOWER:
                 * the optimiser walked the index AND still filesorted. Matching the direction is
                 * what lets the index do its job — the two changes only work as a pair.
                 */
                $query->orderBy($tiebreaker, $direction);
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
                /*
                 * The screen renders the export button only when the server offered one — and the
                 * server offers one only to somebody who may actually use it. Presentation follows
                 * authorisation; it never decides it (the route refuses either way).
                 */
                'exportable' => $this->exportColumns !== [] && Gate::allows(Role::EXPORT_DATA),
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
