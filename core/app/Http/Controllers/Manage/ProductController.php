<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Catalog\ConversionGuard;
use App\Domain\Catalog\FamilyForCategory;
use App\Domain\Catalog\FieldRefusal;
use App\Domain\Catalog\PlacementWriter;
use App\Domain\Catalog\PreSwitch;
use App\Domain\Catalog\ProductIndexer;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Catalog\SpecBlocks;
use App\Domain\Catalog\VariantWriter;
use App\Http\Middleware\EnsureStorefrontScope;
use App\Models\Catalog\Product;
use App\Models\Storefront\Storefront;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Support\FullReplace;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use stdClass;

/**
 * /manage/products — the list and the product form (wave 4B scope items 1–3, 5).
 *
 * ── The list query, and why it is shaped the way it is (scope item 1) ────────────────────────
 *
 * Brand Fashion is 7 447 products and the list has to stay usable on a tablet, so every filter,
 * the sort, the search and the paging are SERVER-side through {@see TableQuery}. The query:
 *
 *   • reads `catalog_products` as the driving table — its primary key is the default sort, which
 *     is the only ordering that is index-ordered with no filter applied at all;
 *   • joins the two translation rows by `(product_id, locale)`, which is `cpt_fk_locale_unique`
 *     and therefore `eq_ref` — never a scan, even at scale;
 *   • joins ONE storefront row, the storefront the screen is looking at, through
 *     `sp_storefront_product_unique` — also `eq_ref`;
 *   • resolves brand and category NAMES from small pre-loaded maps rather than joining their
 *     translation tables, exactly as the storefront read layer does (`App\Storefront\Lookups`):
 *     a page of 25 rows costs no per-row lookup query;
 *   • searches through the FULLTEXT index `cps_body_ft` when the term is three characters or more,
 *     and falls back to `LIKE` under that, because MariaDB has no ngram parser and
 *     `innodb_ft_min_token_size = 3` is fixed (AGENTS §2.3, study §5.1 L6). A two-character
 *     Arabic term matches nothing through FULLTEXT — silently — which is exactly the kind of
 *     "works in the demo" bug the fallback exists to prevent.
 *
 * The EXPLAIN of this query at 7 000+ rows is in `new branding/docs/wave4b/EXPLAIN_2026-09-11.md`.
 *
 * ── Authorisation ────────────────────────────────────────────────────────────────────────────
 *
 * `can:manage-catalog` for the product itself, `can:manage-placement` for the per-storefront half,
 * and `EnsureStorefrontScope` wherever the URL names a storefront. Products are SHARED across
 * storefronts (D3), so the product rows themselves are not storefront-scoped — the placement is,
 * and that is the part a scoped grant restricts.
 */
final class ProductController
{
    /*
     * NOTE for every controller under a `{storefront}/…` route: Laravel passes route parameters
     * POSITIONALLY, not by name. A method must declare them in URL order
     * (`(Request $request, Storefront $storefront, int $product)`), or the first segment lands in
     * the wrong variable — which is exactly what happened here first: `edit(Request, int $product)`
     * received the STOREFRONT id and every product screen answered 404. Declaring the segment also
     * buys implicit binding, so a storefront that does not exist is a 404 before the controller
     * runs.
     */

    /** Columns a caller may sort by. Every one of them is a real column of the driving table. */
    private const SORTABLE = [
        'p.id', 'p.wa_code', 'p.family', 'p.selling_price', 'p.stock_express', 'p.stock_market',
        'p.is_active', 'p.in_stock', 'p.created_at', 'p.updated_at',
    ];

    public function __construct(
        private readonly ProductWriter $products,
        private readonly PlacementWriter $placements,
        private readonly VariantWriter $variants,
        private readonly FamilyForCategory $families,
        private readonly ConversionGuard $conversion,
    ) {}

    public function index(Request $request, Storefront $storefront): Response
    {
        $brands = self::brandOptions();
        $categories = self::categoryOptions($storefront->id);

        $table = TableQuery::for($request)
            ->sortable(self::SORTABLE, default: 'p.id', direction: 'desc')
            ->filterable([
                'p.family' => Product::FAMILIES,
                'p.brand_id' => self::optionValues($brands),
                'p.is_active' => ['0', '1'],
                'p.in_stock' => ['0', '1'],
                'sp.is_visible' => ['0', '1'],
                'sp.is_featured' => ['0', '1'],
                // Two filters that are not plain columns; applied by hand below and declared here
                // so the whitelist, the URL and the reset button all know about them.
                'category' => self::optionValues($categories),
                'flag' => ['low_stock', 'no_arabic', 'unplaced', 'has_variants', 'archived'],
            ])
            // …and declared VIRTUAL, because neither is a column: `category` is a whole branch of
            // the tree and `flag` is four different predicates. `listQuery()` applies them.
            ->virtual(['category', 'flag'])
            /** @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|Builder  $query */
            ->searchUsing(function (EloquentBuilder|Builder $query, string $term): void {
                self::applySearch($query, $term);
            })
            ->perPage(
                default: config()->integer('catalog.list.per_page'),
                max: config()->integer('catalog.list.per_page_max'),
            );

        $filters = $table->resolvedFilters();
        $query = $this->listQuery($storefront->id, $filters);

        $brandNames = self::nameMap('catalog_brands', 'catalog_brand_translations', 'brand_id');

        // Filled by the `prepare` callback below, before a single row is mapped. See
        // `TableQuery::paginate()` for why these two are not columns of the list query. The empty
        // state comes from the same function that fills it, so there is one definition of the shape.
        $extras = self::pageExtras([], $storefront->id);
        // Read ONCE for the page, never inside the row mapper.
        $activeStorefronts = Storefront::activeIds();

        return Inertia::render('Manage/Products/Index', [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'brands' => $brands,
            'categories' => $categories,
            'families' => self::familyOptions(),
            'table' => $table->paginate($query, function (object $raw) use ($brandNames, $storefront, $activeStorefronts, &$extras): array {
                $row = Row::cast($raw);
                $id = Row::int($row, 'id');
                $cover = $extras['covers'][$id] ?? null;
                $titleAr = Row::nstr($row, 'title_ar');

                return [
                    'id' => $id,
                    'wa_code' => Row::str($row, 'wa_code'),
                    'sku' => Row::nstr($row, 'sku'),
                    'title' => [
                        'ar' => $titleAr ?? '',
                        'en' => Row::nstr($row, 'title_en') ?? '',
                    ],
                    'family' => Row::str($row, 'family'),
                    'brand' => $brandNames[Row::int($row, 'brand_id')] ?? ['ar' => '', 'en' => ''],
                    'selling_price' => Row::str($row, 'selling_price'),
                    'sale_price' => Row::nstr($row, 'sale_price'),
                    'currency' => Row::str($row, 'currency'),
                    'stock_express' => Row::int($row, 'stock_express'),
                    'stock_market' => Row::int($row, 'stock_market'),
                    'in_stock' => Row::bool($row, 'in_stock'),
                    'is_active' => Row::bool($row, 'is_active'),
                    'archived' => Row::nstr($row, 'deleted_at') !== null,
                    'variants' => $extras['variants'][$id] ?? 0,
                    'is_visible' => Row::nstr($row, 'sp_id') === null ? null : Row::bool($row, 'is_visible'),
                    'is_featured' => Row::nstr($row, 'sp_id') === null ? null : Row::bool($row, 'is_featured'),
                    'slug' => Row::nstr($row, 'slug'),
                    // ── the four at-a-glance states (task 4.3) ────────────────────────────────
                    'has_arabic' => trim($titleAr ?? '') !== '',
                    'has_image' => $cover !== null,
                    'has_stock' => Row::bool($row, 'in_stock'),
                    /*
                     * Placement shape on THIS storefront (rehearsal #3):
                     *   'none'      — no category at all, so it appears in no listing;
                     *   'root_only' — on the root and nowhere else, no primary category. The
                     *                 transform leaves a legacy product with no `sub_type_id`
                     *                 exactly here, and the site shows it only under the top-level
                     *                 section with a one-step breadcrumb;
                     *   'placed'    — a primary category, the ordinary state.
                     */
                    'placement' => self::placementState($extras['placement'][$id] ?? null),
                    // Per storefront: true = visible, false = hidden, MISSING = no row at all.
                    'visibility' => self::visibilityFor($extras['storefronts'][$id] ?? [], $activeStorefronts),
                    'cover' => $cover === null ? null : ImageUrl::src($cover),
                    'updated_at' => Row::nstr($row, 'updated_at'),
                    'edit_url' => route('manage.products.edit', ['product' => $id, 'storefront' => $storefront->id]),
                ];
            }, function (array $rows) use (&$extras, $storefront): void {
                $extras = self::pageExtras($rows, $storefront->id);
            }),
            // Every active storefront, so the list can render one visibility chip each.
            'all_storefronts' => self::activeStorefrontsForList(),
            'pre_switch_notice' => self::preSwitchNotice(),
            'pre_switch' => PreSwitch::state('product'),
        ]);
    }

    /**
     * The three placement shapes, named once so the list, the form and the tests agree.
     *
     * `root_only` is the state rehearsal #3 (2026-09-12) put in front of the team: a legacy
     * product with a `category_type_id` and no `sub_type_id` is placed on the ROOT node with
     * `is_primary = 0`. It is a legitimate, served state — the storefront answers its page and
     * gives it a one-step breadcrumb, and its family comes from the root's own name — but it is
     * invisible in every sub-category listing, and until now the list showed it as an ordinary
     * placed product.
     *
     * @param  array{nodes: int, primaries: int}|null  $counts
     */
    private static function placementState(?array $counts): string
    {
        if ($counts === null || $counts['nodes'] === 0) {
            return 'none';
        }

        return $counts['primaries'] === 0 ? 'root_only' : 'placed';
    }

    /**
     * One entry per ACTIVE storefront: visible / hidden / not added.
     *
     * `$active` is passed IN rather than read here, and that is not style: this runs once per row,
     * so reading `Storefront::activeIds()` inside it put one query per row on the page — 100
     * extra queries on a 100-row page, which `ProductListTest`'s fixed-query-count guard caught
     * immediately. The list's whole performance rule is "per page, never per row"
     * (AGENTS §2.24), and this is the same mistake in a different shape.
     *
     * @param  array<int, bool>  $rowsByStorefront
     * @param  list<int>  $active
     * @return list<array{id: int, state: string}>
     */
    private static function visibilityFor(array $rowsByStorefront, array $active): array
    {
        $out = [];
        foreach ($active as $storefrontId) {
            $out[] = [
                'id' => $storefrontId,
                'state' => array_key_exists($storefrontId, $rowsByStorefront)
                    ? ($rowsByStorefront[$storefrontId] ? 'visible' : 'hidden')
                    : 'absent',
            ];
        }

        return $out;
    }

    /** @return list<array{id: int, code: string, name: string}> */
    private static function activeStorefrontsForList(): array
    {
        $out = [];
        foreach (Storefront::query()->where('is_active', true)->orderBy('id')->get(['id', 'code', 'name']) as $storefront) {
            $out[] = ['id' => (int) $storefront->id, 'code' => (string) $storefront->code, 'name' => (string) $storefront->name];
        }

        return $out;
    }

    public function create(Request $request, Storefront $storefront): Response
    {
        return Inertia::render('Manage/Products/Form', $this->formProps($storefront, null));
    }

    public function store(Request $request, Storefront $storefront): RedirectResponse
    {
        $data = $this->validated($request, null);

        try {
            $productId = $this->products->create($data, self::actorId($request));
        } catch (RuntimeException $e) {
            // The pre-switch refusal lands here. It is about the ACTION, not a field, so it is
            // reported under a name the form renders at the top — and the form already disabled
            // the button, which is presentation: this is the control.
            throw ValidationException::withMessages(['pre_switch' => $e->getMessage()]);
        }

        $this->saveStorefrontSide($productId, $data);

        return redirect()
            ->route('manage.products.edit', ['product' => $productId, 'storefront' => $storefront->id])
            ->with('status', 'تم إنشاء المنتج.');
    }

    public function edit(Request $request, Storefront $storefront, int $product): Response
    {
        return Inertia::render('Manage/Products/Form', $this->formProps($storefront, self::productRow($product)));
    }

    public function update(Request $request, Storefront $storefront, int $product): RedirectResponse
    {
        /*
         * This endpoint REPLACES the product: a key absent from the payload is cleared, which is
         * what lets the form empty a field, and what let a five-field probe payload wipe a live
         * product's grade, specs, descriptions, sale price and placements (see {@see FullReplace}).
         * The screen declares completeness; nothing else may.
         */
        FullReplace::assert($request, 'بيانات المنتج', 'wa_code');

        $productId = Row::int(self::productRow($product), 'id');
        $data = $this->validated($request, $productId);

        $this->products->update($productId, $data, self::actorId($request));
        $this->saveStorefrontSide($productId, $data);

        return redirect()
            ->route('manage.products.edit', ['product' => $productId, 'storefront' => $storefront->id])
            ->with('status', 'تم حفظ المنتج.');
    }

    /**
     * Bulk activate / deactivate / archive from the list's selection bar.
     *
     * Activating is a catalog act; making something VISIBLE is not, which is why visibility is not
     * in this list: it needs the Arabic gate per product, and a bulk action that silently skipped
     * half a selection would be worse than no bulk action.
     */
    public function bulk(Request $request, Storefront $storefront): RedirectResponse
    {
        $request->validate([
            'action' => ['required', 'string', Rule::in(['activate', 'deactivate', 'archive', 'restore'])],
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $ids = Coerce::intList($request->input('ids'));
        $action = $request->string('action')->toString();
        $actorId = self::actorId($request);

        $done = 0;
        foreach ($ids as $id) {
            match ($action) {
                'activate', 'deactivate' => $this->products->update(
                    $id,
                    self::currentPayload($id, ['is_active' => $action === 'activate']),
                    $actorId,
                ),
                'archive' => $this->products->archive($id, $actorId),
                'restore' => $this->products->restore($id, $actorId),
                default => null,
            };
            $done++;
        }

        return back()->with('status', "تم تنفيذ الإجراء على {$done} منتجًا.");
    }

    // ── the query ────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string|null>  $filters
     */
    private function listQuery(int $storefrontId, array $filters): Builder
    {
        // The branch is resolved BEFORE the query is built, because whether a category filter is
        // active changes the join ORDER this query has to ask for — see the `straight_join` note.
        $category = $filters['category'] ?? null;
        $branch = $category !== null && is_numeric($category)
            ? self::branchNodeIds($storefrontId, (int) $category)
            : null;

        /*
         * `STRAIGHT_JOIN` when — and only when — a category branch filter is active.
         *
         * It is an optimizer hint, and a hint owes a reason. Left to itself, MariaDB rewrites the
         * `EXISTS` below into a semi-join, MATERIALISES the whole branch, and then DRIVES the
         * query from that materialised set. Driving from it throws away `catalog_products`'
         * primary-key ordering, so the plan pays `Using temporary; Using filesort` over every
         * product in the branch to answer a page of 25:
         *
         *     | <subquery4> | ALL | 3964 rows | Using temporary; Using filesort |   162.9 ms
         *
         * `LIMIT 1` inside the subquery does NOT prevent that — it was tried and measured, and
         * the plan came back byte-identical. Neither does `FORCE INDEX (PRIMARY)` on `p` (77 ms),
         * nor rewriting the `EXISTS` as `IN (subquery)` (93 ms). All three leave the materialised
         * set as the DRIVING table, which is the actual problem.
         *
         * `STRAIGHT_JOIN` fixes it because it fixes the join ORDER: drive from `catalog_products`
         * in primary-key order, probe `scp_storefront_product_idx` once per candidate, stop at 25
         * — no temporary table, no sort:
         *
         *     | p | index | Using where | ... | scp | ref | Using where |              8.0 ms
         *
         * That is the plan wave 2 proved for the storefront listing at the same scale (study
         * §5.1 / `docs/wave2/SCALE_PROOF_2026-09-08.md`, L1–L3): the table that SUPPLIES THE
         * SORT drives, and `storefront_category_product` is reduced to a probe. Reading §5.1 as
         * "drive from `storefront_category_product`" is what the SLOW plan already does.
         *
         * The hint is scoped to this one filter because it is only right for this one filter. For
         * a NARROW branch the optimizer's own choice is better (0.6 ms against 4.1 ms for a full
         * index scan that finds nothing), and when another selective predicate is present it
         * reaches the good plan by itself (`branch + family + active`: 9.7 ms either way). Both
         * of those are already fast; the case this fixes is the one that was not.
         */
        $lead = $branch === null ? 'p.id' : DB::raw('straight_join `p`.`id`');

        $query = DB::table('catalog_products as p')
            ->leftJoin('catalog_product_translations as ar', function (JoinClause $join): void {
                $join->on('ar.product_id', '=', 'p.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('catalog_product_translations as en', function (JoinClause $join): void {
                $join->on('en.product_id', '=', 'p.id')->where('en.locale', '=', 'en');
            })
            ->leftJoin('storefront_product as sp', function (JoinClause $join) use ($storefrontId): void {
                $join->on('sp.product_id', '=', 'p.id')->where('sp.storefront_id', '=', $storefrontId);
            })
            // NOTE what this list deliberately does NOT select: the variant count and the cover
            // image. They were two correlated sub-selects here, and they were the list's single
            // biggest cost — `TableQuery::paginate()`'s `$prepare` docblock carries the
            // measurement. They are read for the PAGE in `index()` instead.
            ->select([
                $lead, 'p.wa_code', 'p.sku', 'p.family', 'p.brand_id', 'p.selling_price', 'p.sale_price',
                'p.currency', 'p.stock_express', 'p.stock_market', 'p.in_stock', 'p.is_active',
                'p.deleted_at', 'p.updated_at',
                'ar.title as title_ar', 'en.title as title_en',
                'sp.id as sp_id', 'sp.is_visible', 'sp.is_featured', 'sp.slug',
            ]);

        // Archived products are hidden unless the `archived` flag asks for them: a soft-deleted
        // product is still in the table and would otherwise pad every page.
        if (($filters['flag'] ?? null) === 'archived') {
            $query->whereNotNull('p.deleted_at');
        } else {
            $query->whereNull('p.deleted_at');
        }

        // A category filter includes the node's DESCENDANTS, because a team member filtering by
        // "Watches" means the whole branch. The ids come from the materialised path in one query
        // — the same read the visibility rule uses — and never from a recursive walk.
        if ($branch !== null) {
            $query->whereExists(function (Builder $sub) use ($branch, $storefrontId): void {
                $sub->from('storefront_category_product as scp')
                    ->whereColumn('scp.product_id', 'p.id')
                    ->where('scp.storefront_id', $storefrontId)
                    // `-1` for a category that resolved to nothing keeps the filter HONEST: an
                    // unknown id shows no products, never silently all of them.
                    ->whereIn('scp.storefront_category_id', $branch === [] ? [-1] : $branch)
                    ->selectRaw('1')
                    // `LIMIT 1` is what makes this fast, and it is not a micro-optimisation.
                    //
                    // Without it MariaDB flattens the EXISTS into a semi-join and MATERIALISES it,
                    // then drives the whole query from that materialised set — which throws away
                    // the primary-key ordering and pays `Using temporary; Using filesort` over
                    // every product in the branch. Measured at 7 000 products with ~4 000 in the
                    // branch: **150 ms**, the list's worst case by an order of magnitude.
                    //
                    // A subquery carrying LIMIT cannot be flattened, so the plan becomes: drive
                    // from `catalog_products` in PK order, probe `scp_storefront_product_idx` once
                    // per candidate row, stop at 25. Same answer, no filesort, and the same trick
                    // wave 2 used for the storefront listing at scale (study §5.1 / the L1–L3
                    // plans in `docs/wave2/SCALE_PROOF_2026-09-08.md`).
                    ->limit(1);
            });
        }

        match ($filters['flag'] ?? null) {
            'low_stock' => $query->whereRaw('(p.stock_express + p.stock_market) <= p.low_stock_threshold'),
            'no_arabic' => $query->where(function (Builder $inner): void {
                $inner->whereNull('ar.title')->orWhere('ar.title', '=', '');
            }),
            // `limit(1)` for the same reason as the category filter above: an un-flattened
            // subquery keeps the driving table's primary-key ordering.
            // `NOT EXISTS` is an anti-join: there is no semi-join for MariaDB to materialise, so
            // these two keep the driving table's ordering without a hint (both measured indexed).
            'unplaced' => $query->whereNotExists(function (Builder $sub) use ($storefrontId): void {
                $sub->from('storefront_category_product as scp2')
                    ->whereColumn('scp2.product_id', 'p.id')
                    ->where('scp2.storefront_id', $storefrontId)
                    ->selectRaw('1')
                    ->limit(1);
            }),
            'has_variants' => $query->whereExists(function (Builder $sub): void {
                $sub->from('catalog_product_variants as v2')->whereColumn('v2.product_id', 'p.id')->selectRaw('1')->limit(1);
            }),
            default => null,
        };

        return $query;
    }

    /**
     * The per-row extras the list query does not carry, read once for the page's rows.
     *
     * `storefronts` is task 4.3: the operator has to see, without opening anything, whether a
     * product is visible ON EACH SITE. It cannot be a column of the list query — that would be
     * one join per storefront and a row multiplied by N — and it must not be one sub-select per
     * row either (AGENTS §2.24). One query over the page's 25 ids answers it for every storefront
     * at once.
     *
     * `placement` is rehearsal #3's finding (2026-09-12). A product whose legacy row has a
     * `category_type_id` but no `sub_type_id` is placed on the ROOT and nowhere else, with
     * `is_primary = 0` — the state A-18 describes. Five live products arrived in that state and
     * the list showed them exactly like any other placed product, so nobody could see which ones
     * sit at the root of a site with no sub-category. The count is derived per page in one
     * grouped query, never per row (AGENTS §2.24).
     *
     * @param  list<object>  $rows
     * @return array{covers: array<int, string>, variants: array<int, int>, storefronts: array<int, array<int, bool>>, placement: array<int, array{nodes: int, primaries: int}>}
     */
    private static function pageExtras(array $rows, ?int $storefrontId = null): array
    {
        $ids = [];
        foreach ($rows as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }
        if ($ids === []) {
            return ['covers' => [], 'variants' => [], 'storefronts' => [], 'placement' => []];
        }

        // Placement shape on THIS storefront: how many nodes, and how many of them are primary.
        // `nodes > 0 && primaries === 0` is the root-only state; `nodes === 0` is "not placed".
        $placement = [];
        if ($storefrontId !== null) {
            foreach (
                DB::table('storefront_category_product')
                    ->where('storefront_id', $storefrontId)
                    ->whereIn('product_id', $ids)
                    ->groupBy('product_id')
                    ->get(['product_id', DB::raw('COUNT(*) as nodes'), DB::raw('COALESCE(SUM(is_primary), 0) as primaries')]) as $raw
            ) {
                $row = Row::cast($raw);
                $placement[Row::int($row, 'product_id')] = [
                    'nodes' => Row::int($row, 'nodes'),
                    'primaries' => Row::int($row, 'primaries'),
                ];
            }
        }

        // product id => storefront id => is_visible. A product with NO row on a storefront is
        // absent from the inner map, which the screen renders as "not added" rather than as
        // "hidden" — two different states, and the difference is what the team acts on.
        $visibility = [];
        foreach (
            DB::table('storefront_product')
                ->whereIn('product_id', $ids)
                ->get(['product_id', 'storefront_id', 'is_visible']) as $raw
        ) {
            $row = Row::cast($raw);
            $visibility[Row::int($row, 'product_id')][Row::int($row, 'storefront_id')] = Row::bool($row, 'is_visible');
        }

        $variants = [];
        foreach (
            DB::table('catalog_product_variants')
                ->whereIn('product_id', $ids)
                ->groupBy('product_id')
                ->get(['product_id', DB::raw('COUNT(*) as variant_count')]) as $raw
        ) {
            $row = Row::cast($raw);
            $variants[Row::int($row, 'product_id')] = Row::int($row, 'variant_count');
        }

        // The cover rule is the read layer's own — `is_cover` first, then `sort` — so the first
        // row seen per product wins. One ordered read over 25 ids, not one query per row.
        $covers = [];
        foreach (
            DB::table('catalog_product_images')
                ->whereIn('product_id', $ids)
                ->orderBy('product_id')->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')
                ->get(['product_id', 'path']) as $raw
        ) {
            $row = Row::cast($raw);
            $productId = Row::int($row, 'product_id');
            if (! array_key_exists($productId, $covers)) {
                $covers[$productId] = Row::str($row, 'path');
            }
        }

        return ['covers' => $covers, 'variants' => $variants, 'storefronts' => $visibility, 'placement' => $placement];
    }

    /**
     * Search: FULLTEXT over `catalog_product_search` for terms of three characters or more, and
     * `LIKE` under that.
     *
     * The threshold is not a preference — `innodb_ft_min_token_size` is 3 on this server and
     * cannot be changed on the shared host, so a shorter term matches NOTHING through the index
     * and would return an empty list while looking like it worked.
     *
     * `wa_code` is always searched with `LIKE`: it is a code, and a team member typing half of one
     * expects a prefix match, which a word-based index cannot give.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|Builder  $query
     */
    private static function applySearch(EloquentBuilder|Builder $query, string $term): void
    {
        $escaped = self::escapeLike($term);

        $query->where(function (Builder $inner) use ($term, $escaped): void {
            $inner->where('p.wa_code', 'like', '%'.$escaped.'%')
                ->orWhere('p.sku', 'like', '%'.$escaped.'%');

            if (mb_strlen($term) >= 3) {
                $inner->orWhereExists(function (Builder $sub) use ($term): void {
                    $sub->from('catalog_product_search as s')
                        ->whereColumn('s.product_id', 'p.id')
                        ->whereRaw('MATCH(s.body) AGAINST (? IN BOOLEAN MODE)', [self::booleanTerm($term)])
                        ->selectRaw('1');
                });

                return;
            }

            // Under three characters the index is blind, so fall back to the titles directly.
            $inner->orWhere('ar.title', 'like', '%'.$escaped.'%')
                ->orWhere('en.title', 'like', '%'.$escaped.'%');
        });
    }

    /**
     * The `value` of every option in a list, as a LIST of strings.
     *
     * `array_map` over a list of shapes keeps the keys, and `TableQuery::filterable()` wants a
     * list — so the values are re-indexed here rather than in three call sites.
     *
     * @param  list<array<string, mixed>>  $options
     * @return list<string>
     */
    private static function optionValues(array $options): array
    {
        $out = [];
        foreach ($options as $option) {
            $out[] = Coerce::str($option['value'] ?? null);
        }

        return $out;
    }

    /** An optional id as the string a form field wants, or '' — one place, three call sites. */
    private static function idString(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /**
     * A BOOLEAN MODE term built from the caller's words, with every operator character stripped.
     *
     * The user's string never reaches the parser as syntax: `+`, `-`, `*`, `"`, `(`, `)`, `~`, `<`,
     * `>` and `@` all mean something to MariaDB's boolean parser, and a stray `@distance` or an
     * unbalanced quote is a 1064 in the middle of a search box. Each remaining word gets a `*`
     * suffix so typing part of a model number still finds it.
     */
    private static function booleanTerm(string $term): string
    {
        $clean = (string) preg_replace('/[+\-*~<>()"@]+/u', ' ', $term);
        $words = array_values(array_filter(preg_split('/\s+/u', $clean) ?: [], fn (string $w): bool => mb_strlen($w) >= 3));

        if ($words === []) {
            // Every word was too short for the index; match nothing rather than everything.
            return '"'.'zzz-no-such-token'.'"';
        }

        return implode(' ', array_map(fn (string $w): string => '+'.$w.'*', $words));
    }

    // ── the form ─────────────────────────────────────────────────────────────────────────────

    /**
     * Everything both the create and the edit screen need.
     *
     * ALL spec blocks are shipped, not just the current family's: changing the category must
     * re-render the block immediately, and a round trip to ask the server which fields to show
     * would make the form feel broken. The server still decides the family on save — the blocks
     * are a rendering hint, never the authority.
     *
     * @return array<string, mixed>
     */
    private function formProps(Storefront $storefront, ?stdClass $product): array
    {
        $productId = $product === null ? null : Row::int($product, 'id');
        $placementRow = $productId === null ? null : DB::table('storefront_product')
            ->where('storefront_id', $storefront->id)->where('product_id', $productId)
            ->first(['is_visible', 'is_featured', 'sort_order', 'slug']);
        $placement = $placementRow === null ? null : Row::cast($placementRow);

        $primaryNode = $productId === null ? null : FamilyForCategory::primaryNodeFor($productId);
        $specs = $product === null ? [] : self::currentSpecs(Row::int($product, 'id'), Row::str($product, 'family'));
        $sections = $this->storefrontSections($productId);

        return [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'product' => $product === null ? null : [
                'id' => $productId,
                'wa_code' => Row::str($product, 'wa_code'),
                'sku' => Row::nstr($product, 'sku') ?? '',
                'model_number' => Row::nstr($product, 'model_number') ?? '',
                'hs_code' => Row::nstr($product, 'hs_code') ?? '',
                'brand_id' => (string) Row::int($product, 'brand_id'),
                'grade_id' => self::idString(Row::nint($product, 'grade_id')),
                'family' => Row::str($product, 'family'),
                'purchase_price' => Row::money($product, 'purchase_price'),
                'selling_price' => Row::money($product, 'selling_price'),
                'sale_price' => Row::nmoney($product, 'sale_price') ?? '',
                'currency' => Row::str($product, 'currency'),
                'low_stock_threshold' => Row::int($product, 'low_stock_threshold'),
                'warranty_years' => self::idString(Row::nint($product, 'warranty_years')),
                'is_active' => Row::bool($product, 'is_active'),
                'search_keywords' => Row::nstr($product, 'search_keywords') ?? '',
                'translations' => self::translations($productId),
                'specs' => $specs,
                'images' => self::images($productId),
                'feature_ids' => self::pivotIds('catalog_product_feature', 'feature_id', $productId),
                'gender_ids' => self::pivotIds('catalog_product_gender', 'gender_id', $productId),
                'colors' => self::colorRows($productId),
                'stock_express' => Row::int($product, 'stock_express'),
                'stock_market' => Row::int($product, 'stock_market'),
                'in_stock' => Row::bool($product, 'in_stock'),
            ],
            /*
             * ONE SECTION PER ACTIVE STOREFRONT — the task-1 finding.
             *
             * The form used to render a single categories card and a single visibility/SEO card,
             * for whichever storefront the URL named, so placing a product on both sites meant
             * visiting two URLs and remembering to. Each section below carries its own category
             * set, its own single primary category, and its own visibility / featured / order /
             * slug, because those are exactly the per-storefront columns of `storefront_product`
             * (AGENTS §2.4: the product CONTENT is shared and only these are not).
             */
            'sections' => $sections,
            // Slug editing is one rule for every storefront (it is the flag, not the storefront),
            // so it is shipped once rather than per section.
            'slug_lock' => PreSwitch::slugState(),
            'missing_arabic' => $productId === null ? [] : $this->placements->missingArabic($productId),
            // The storefront whose primary category decides the family and therefore the spec
            // block, named so the screen can say it out loud.
            'family_storefront' => self::familyStorefront(),
            // The family and the REASON, so the screen can say why this block is on screen — plus
            // the family the product is SAVED with, which is what lets the form warn that a
            // category change will discard the block it is showing.
            'family' => $this->families->explain($primaryNode, $product === null ? null : Row::str($product, 'family')),
            'blocks' => SpecBlocks::all(),
            'lookups' => self::allLookupOptions(),
            // EVERY option carries the family the SERVER resolves for it (task 4.1). The browser
            // looks it up when the category changes instead of mirroring the rule in TypeScript —
            // the mirror is what was broken, and a second implementation of one rule always is.
            'categories' => $this->categoryOptionsWithFamily($storefront->id),
            'brands' => self::brandOptions(),
            'families' => self::familyOptions(),
            'variants' => [
                'rows' => $productId === null ? [] : $this->variants->rows($productId),
                'state' => $productId === null
                    ? ['has_variants' => false, 'may_convert' => false, 'reason' => 'احفظ المنتج أولًا ثم أضف المقاسات.', 'write_switch_completed' => ConversionGuard::writeSwitchCompleted(), 'legacy_backed' => false]
                    : $this->conversion->state($productId),
            ],
            'pre_switch_notice' => self::preSwitchNotice(),
            // Per-action state, so the screen can disable the control it must not offer AND say
            // why. The server refuses again on the write path.
            'pre_switch' => PreSwitch::state('product'),
            'pre_switch_variant' => PreSwitch::state('variant'),
        ];
    }

    /**
     * Validation. The family block's rules come from {@see SpecBlocks}, for the family the SERVER
     * derives from the chosen category — not for the family the request claims.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $productId): array
    {
        $base = [
            'wa_code' => ['required', 'string', 'max:64', Rule::unique('catalog_products', 'wa_code')->ignore($productId)],
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('catalog_products', 'sku')->ignore($productId)],
            'model_number' => ['nullable', 'string', 'max:100'],
            'hs_code' => ['nullable', 'string', 'max:32'],
            'brand_id' => ['required', 'integer', Rule::exists('catalog_brands', 'id')],
            'grade_id' => ['nullable', 'integer', Rule::exists('catalog_grades', 'id')],
            'purchase_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'warranty_years' => ['nullable', 'integer', 'min:0', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'search_keywords' => ['nullable', 'string', 'max:65535'],

            'title.ar' => ['required', 'string', 'max:255'],
            'title.en' => ['nullable', 'string', 'max:255'],
            'short_description.ar' => ['nullable', 'string', 'max:65535'],
            'short_description.en' => ['nullable', 'string', 'max:65535'],
            'long_description.ar' => ['nullable', 'string'],
            'long_description.en' => ['nullable', 'string'],
            'model_name.ar' => ['nullable', 'string', 'max:255'],
            'model_name.en' => ['nullable', 'string', 'max:255'],
            'country.ar' => ['nullable', 'string', 'max:255'],
            'country.en' => ['nullable', 'string', 'max:255'],
            'stone.ar' => ['nullable', 'string', 'max:255'],
            'stone.en' => ['nullable', 'string', 'max:255'],
            'meta_title.ar' => ['nullable', 'string', 'max:255'],
            'meta_title.en' => ['nullable', 'string', 'max:255'],
            'meta_description.ar' => ['nullable', 'string', 'max:255'],
            'meta_description.en' => ['nullable', 'string', 'max:255'],

            'images' => ['nullable', 'array', 'max:40'],
            'images.*.id' => ['nullable', 'integer'],
            'images.*.path' => ['required', 'string', 'max:255'],
            'images.*.is_cover' => ['nullable', 'boolean'],
            'images.*.alt_ar' => ['nullable', 'string', 'max:255'],
            'images.*.alt_en' => ['nullable', 'string', 'max:255'],
            'images.*.width' => ['nullable', 'integer'],
            'images.*.height' => ['nullable', 'integer'],
            'images.*.renditions' => ['nullable', 'array'],

            'feature_ids' => ['nullable', 'array'],
            'feature_ids.*' => ['integer', Rule::exists('catalog_features', 'id')],
            'gender_ids' => ['nullable', 'array'],
            'gender_ids.*' => ['integer', Rule::exists('catalog_genders', 'id')],
            'colors' => ['nullable', 'array', 'max:30'],
            'colors.*.color_id' => ['required', 'integer', Rule::exists('catalog_colors', 'id')],
            'colors.*.role' => ['required', 'string', Rule::in(['dial', 'band', 'main'])],

            /*
             * The per-storefront half, keyed by storefront id. One shape reaches the writer: a
             * flat `is_visible`/`category_ids`/… payload is folded into `storefronts[<url id>]`
             * before validation, which is what keeps the form, the API-less console paths and the
             * tests speaking the same language instead of two.
             *
             * The two CATEGORY ID keys are not here: they are generated per section key below,
             * because the rule they need is `exists … where storefront_id = <that section>` and a
             * `storefronts.*` wildcard cannot know which storefront it is standing in. That gap is
             * precisely what review 🟠-1 found.
             */
            'storefronts' => ['required', 'array', 'min:1'],
            'storefronts.*.category_ids' => ['nullable', 'array', 'max:30'],
            'storefronts.*.is_visible' => ['required', 'boolean'],
            'storefronts.*.is_featured' => ['required', 'boolean'],
            'storefronts.*.sort_order' => ['nullable', 'integer', 'min:-2147483648', 'max:2147483647'],
            'storefronts.*.slug' => ['nullable', 'string', 'max:191'],
        ];

        self::foldFlatStorefront($request);

        /*
         * The category ids are validated FIRST, on their own, and the order is the fix rather than
         * a tidiness preference (review 🟠-1).
         *
         * The family has to be resolved before the main rules are assembled, because the spec
         * block's rules depend on it — and resolving it means asking `FamilyForCategory` about a
         * node id that came straight from the request. With a stale or foreign id that resolver
         * throws `Category node N does not exist`, which reached the browser as a **500**: the
         * exact symptom the reviewer staged by deleting a category with a form open. Scoping the
         * ids is only half the answer; they have to be scoped BEFORE anything reads them.
         */
        $scopeRules = self::categoryScopeRules($request);
        if ($scopeRules !== []) {
            $request->validate($scopeRules);
        }
        $base = array_merge($base, $scopeRules);

        // The family the server will actually store, derived from the category the form chose ON
        // THE STOREFRONT THAT DECIDES (the primary one). Validating the block for the REQUESTED
        // family would let a payload send watch specs for a bag by naming the family itself —
        // which is why there is no family field at all.
        $family = $this->products->familyFor(self::familyPayload($request, $productId), $productId);

        $validated = Coerce::arr($request->validate(array_merge($base, SpecBlocks::rules($family))));
        // `specs` is validated key by key (`specs.case_size`, …), so the nested array itself is
        // not in the validator's output — it is re-attached here, narrowed.
        $validated['specs'] = Coerce::arr($request->input('specs'));

        /*
         * And the DECIDING storefront's primary category is re-attached at the top level, because
         * `ProductWriter` writes ONE product row with ONE family and must not have to know about
         * storefront sections. Without this the writer saw no `primary_category_id` at all (it
         * moved inside `storefronts`), fell back to the product's EXISTING placement — which the
         * save has not written yet — and stored the OLD family with the OLD block. That is the
         * task-4.1 bug re-entering through the multi-storefront refactor, which is why the
         * `CategoryChangesFamilyTest` cases are the ones that caught it.
         */
        $validated['primary_category_id'] = self::familyPayload($request, $productId)['primary_category_id'];

        self::assertSectionsCoherent($validated, $productId === null);

        return $validated;
    }

    /**
     * The per-storefront half of a product save: placement, then visibility/slug/sort.
     *
     * Placement first, because {@see FamilyForCategory} reads the primary placement and
     * {@see ProductIndexer} reads the category names — an order that matters
     * only because it is the difference between indexing the new categories and the old ones.
     *
     * @param  array<string, mixed>  $data
     */
    private function saveStorefrontSide(int $productId, array $data): void
    {
        $sections = Coerce::arr($data['storefronts'] ?? null);
        $active = Storefront::activeIds();

        foreach ($sections as $key => $raw) {
            $storefrontId = is_numeric($key) ? (int) $key : 0;
            // A storefront id the payload invented, or one that is switched off, is not an error
            // message naming it — it is simply not written (study §3.11.14: never confirm that a
            // row the caller may not see exists).
            if (! in_array($storefrontId, $active, true)) {
                continue;
            }
            $section = Coerce::arr($raw);
            try {
                $this->placements->place(
                    $storefrontId,
                    $productId,
                    Coerce::intList($section['category_ids'] ?? null),
                    Coerce::nint($section['primary_category_id'] ?? null),
                );
                $this->placements->save($storefrontId, $productId, $section);
            } catch (RuntimeException $e) {
                /*
                 * A refusal from the writer becomes an error ON THE FIELD OF THAT STOREFRONT'S
                 * SECTION, not a flash message at the top of a form with two sections. With three
                 * storefronts and one bad slug, "this slug is taken" without saying WHERE is a
                 * puzzle, and the operator's next move is to guess.
                 */
                // The refusal carries its own field since review 🟡-4's message landed on the
                // wrong one ({@see FieldRefusal}); a plain RuntimeException is action-level and
                // keeps the visibility toggle as its home.
                $field = FieldRefusal::fieldOf($e);
                throw ValidationException::withMessages([
                    "storefronts.{$storefrontId}.{$field}" => $e->getMessage(),
                ]);
            }
        }

        // The family follows the placement, so a product moved from Watches to Bags is re-derived
        // and its spec block re-written. Doing it here rather than inside ProductWriter keeps the
        // order explicit: place everywhere, then re-derive once — the family is one column on a
        // shared product and follows the PRIMARY storefront (FamilyForCategory::primaryNodeFor).
        $this->products->update($productId, self::currentPayload($productId, []), null);
    }

    /**
     * `exists` rules for the category ids of EVERY submitted section, scoped to that section's
     * storefront (review 🟠-1).
     *
     * ── Why per key and not `storefronts.*.category_ids.*` ──────────────────────────────────
     *
     * The check that was missing is not "does this node exist" but "does it exist IN THIS
     * STOREFRONT", and a wildcard rule has no way to name the storefront it is validating. The
     * consequences were two shapes of the same hole: a node deleted while a form was open was
     * accepted (and the write then failed or silently dropped it), and a crafted payload could put
     * ANOTHER storefront's node id into this storefront's section — at which point `place()`
     * filtered it out as foreign, saw an empty desired set, and deleted every existing placement
     * for that product. A write scoped to storefront 1 wiped storefront 1's data because the
     * attacker named storefront 2.
     *
     * A section key that is not a storefront id at all gets `storefront_id = 0`, so the rule fails
     * closed: no node can exist there. The id came from the caller, so refusing it confirms nothing
     * (§3.11.14).
     *
     * @return array<string, list<mixed>>
     */
    private static function categoryScopeRules(Request $request): array
    {
        $rules = [];
        foreach (array_keys(Coerce::arr($request->input('storefronts'))) as $key) {
            $storefrontId = is_numeric($key) ? (int) $key : 0;
            $scoped = Rule::exists('storefront_categories', 'id')->where('storefront_id', $storefrontId);

            $rules["storefronts.{$key}.category_ids.*"] = ['integer', 'min:1', $scoped];
            $rules["storefronts.{$key}.primary_category_id"] = ['nullable', 'integer', 'min:1', $scoped];
        }

        return $rules;
    }

    /**
     * Rules that involve two fields at once, refused at the FIELD rather than corrected silently.
     *
     * Laravel's rule strings cannot express "must be one of the ids in the sibling array", and a
     * closure rule would hide the reason in a generic message, so the two checks are written out:
     *
     *  1. **A chosen set with no primary is refused.** The primary decides the family and the
     *     product's canonical path; `place()` would fall back to the first id, and a silent
     *     fallback is exactly what task 4.2 is about.
     *  2. **A primary outside the chosen set is refused.** The one-primary invariant is about a row
     *     that exists, so a primary that was never placed could only become an SQL error later.
     *
     * And on CREATE the deciding storefront must have at least one category, because a product
     * with no category has no family, and a spec block chosen by the configured DEFAULT is not a
     * decision anybody made.
     *
     * @param  array<string, mixed>  $validated
     *
     * @throws ValidationException
     */
    private static function assertSectionsCoherent(array $validated, bool $creating): void
    {
        $sections = Coerce::arr($validated['storefronts'] ?? null);
        $decider = self::familyStorefront();
        $errors = [];

        foreach ($sections as $key => $raw) {
            $section = Coerce::arr($raw);
            $ids = Coerce::intList($section['category_ids'] ?? null);
            $primary = Coerce::nint($section['primary_category_id'] ?? null);
            $field = "storefronts.{$key}.primary_category_id";

            if ($ids !== [] && $primary === null) {
                $errors[$field] = 'اختر التصنيف الأساسي من بين التصنيفات المحددة: هو الذي يحدد مسار المنتج على هذا المتجر، '
                    .'ومنه تُشتق مواصفاته.';

                continue;
            }
            if ($primary !== null && ! in_array($primary, $ids, true)) {
                $errors[$field] = 'التصنيف الأساسي يجب أن يكون واحدًا من التصنيفات المحددة بالأعلى. ضع علامة على التصنيف أولًا.';

                continue;
            }
            if ($creating && (int) $key === $decider && $ids === []) {
                $errors["storefronts.{$key}.category_ids"] = 'اختر تصنيفًا واحدًا على الأقل: التصنيف هو ما يحدد نوع المنتج '
                    .'وقائمة مواصفاته، ولا يمكن إنشاء منتج بلا تصنيف.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Fold a FLAT per-storefront payload into `storefronts[<url storefront>]`.
     *
     * The form posts the map; this exists so that a caller which knows about one storefront — a
     * console path, a future importer, the placement screen's own shape — does not have to learn
     * a nested key to do the same thing, and so that exactly ONE shape reaches the writer.
     */
    private static function foldFlatStorefront(Request $request): void
    {
        if (is_array($request->input('storefronts'))) {
            return;
        }

        $storefront = $request->route(EnsureStorefrontScope::PARAMETER);
        $id = $storefront instanceof Storefront ? $storefront->id : Coerce::nint($storefront);
        if ($id === null) {
            return;
        }

        $request->merge(['storefronts' => [(string) $id => [
            'category_ids' => $request->input('category_ids', []),
            'primary_category_id' => $request->input('primary_category_id'),
            'is_visible' => $request->boolean('is_visible'),
            'is_featured' => $request->boolean('is_featured'),
            'sort_order' => $request->input('sort_order', 0),
            'slug' => $request->input('slug'),
        ]]]);
    }

    /**
     * The payload `ProductWriter::familyFor()` reads: the primary category of the storefront that
     * DECIDES the family, plus the specs.
     *
     * Without this the writer would see no `primary_category_id` at all (it moved inside
     * `storefronts`) and would fall back to the product's existing placement — which is exactly
     * the "the category does not change the fields" bug, one level up.
     *
     * @return array<string, mixed>
     */
    private static function familyPayload(Request $request, ?int $productId): array
    {
        $sections = Coerce::arr($request->input('storefronts'));
        $decider = self::familyStorefront();
        // The key arrives as a string from a form and as an int from a console caller, so both are
        // looked up rather than assuming which one a caller used.
        $raw = null;
        foreach ($sections as $key => $value) {
            if ((string) $key === (string) $decider) {
                $raw = $value;
            }
        }
        $section = Coerce::arr($raw);

        $primary = Coerce::nint($section['primary_category_id'] ?? null);
        if ($primary === null) {
            // The deciding storefront has no primary in this payload; the next-best answer is its
            // first chosen category, and after that the product's existing placement.
            $ids = Coerce::intList($section['category_ids'] ?? null);
            $primary = $ids === [] ? null : $ids[0];
        }

        return [
            'primary_category_id' => $primary,
            'specs' => $request->input('specs'),
        ];
    }

    /**
     * The storefront whose primary category decides the shared `family` column: the lowest active
     * id, which is Watchizer by construction (study §2.9.3).
     */
    private static function familyStorefront(): int
    {
        $active = Storefront::activeIds();

        return $active === [] ? Storefront::WATCHIZER_ID : $active[0];
    }

    /**
     * One editable section per ACTIVE storefront: its categories, its single primary, and its
     * visibility / featured / order / slug.
     *
     * @return list<array<string, mixed>>
     */
    private function storefrontSections(?int $productId): array
    {
        $rows = [];
        if ($productId !== null) {
            foreach (
                DB::table('storefront_product')
                    ->where('product_id', $productId)
                    ->get(['storefront_id', 'is_visible', 'is_featured', 'sort_order', 'slug']) as $raw
            ) {
                $row = Row::cast($raw);
                $rows[Row::int($row, 'storefront_id')] = $row;
            }
        }

        $out = [];
        foreach (Storefront::query()->where('is_active', true)->orderBy('id')->get(['id', 'code', 'name']) as $storefront) {
            $id = (int) $storefront->id;
            $row = $rows[$id] ?? null;
            $categoryIds = $productId === null ? [] : self::placedCategoryIds($id, $productId);
            $out[] = [
                'storefront' => ['id' => $id, 'code' => (string) $storefront->code, 'name' => (string) $storefront->name],
                'decides_family' => $id === self::familyStorefront(),
                'categories' => $this->categoryOptionsWithFamily($id),
                'placement' => [
                    'is_visible' => $row !== null && Row::bool($row, 'is_visible'),
                    'is_featured' => $row !== null && Row::bool($row, 'is_featured'),
                    'sort_order' => $row === null ? 0 : Row::int($row, 'sort_order'),
                    'slug' => $row === null ? '' : Row::str($row, 'slug'),
                    'category_ids' => $categoryIds,
                    'primary_category_id' => $productId === null ? null : self::primaryNodeOn($id, $productId),
                ],
                /*
                 * What the placement IS right now, not what the form will submit (rehearsal #3).
                 * `root_only` means the product sits on the top-level section with no primary
                 * category — the shape the transform gives a legacy product with no sub-type. It
                 * matters on this screen because `primaryNodeOn()` prefills that root as the
                 * primary, so an ordinary save would quietly GIVE the product a primary category
                 * it does not have today. The section says so instead of doing it silently.
                 */
                'placement_state' => $productId === null ? 'none' : self::placementStateOn($id, $productId),
            ];
        }

        return $out;
    }

    /** One product's placement shape on one storefront: placed / root_only / none. */
    private static function placementStateOn(int $storefrontId, int $productId): string
    {
        // COALESCE, because an aggregate over ZERO rows returns COUNT 0 and SUM **NULL** — and a
        // product with no placement at all is the ordinary case on a storefront it was never added
        // to. Without it this read threw on `none` and the edit screen answered 500.
        $row = DB::table('storefront_category_product')
            ->where('storefront_id', $storefrontId)->where('product_id', $productId)
            ->first([DB::raw('COUNT(*) as nodes'), DB::raw('COALESCE(SUM(is_primary), 0) as primaries')]);

        if (! $row instanceof stdClass) {
            return 'none';
        }

        $cast = Row::cast($row);

        return self::placementState(['nodes' => Row::int($cast, 'nodes'), 'primaries' => Row::int($cast, 'primaries')]);
    }

    /** A product's primary category ON ONE STOREFRONT — the per-section answer. */
    private static function primaryNodeOn(int $storefrontId, int $productId): ?int
    {
        $value = DB::table('storefront_category_product')
            ->where('storefront_id', $storefrontId)->where('product_id', $productId)
            ->orderByDesc('is_primary')->orderBy('id')
            ->value('storefront_category_id');

        return is_numeric($value) ? (int) $value : null;
    }

    // ── small reads (all of them cheap, all of them narrowed for level 10) ───────────────────

    /**
     * A product row, or 404.
     *
     * Returned through `Row::cast()` — the transform's own STRICT narrowing helper — so every
     * column read below is checked instead of cast from `mixed`. A dashboard screen has no
     * business inventing a second convention for reading a raw row.
     */
    private static function productRow(int $productId): stdClass
    {
        $row = DB::table('catalog_products')->where('id', $productId)->first();
        if ($row === null) {
            abort(404);
        }

        return Row::cast($row);
    }

    private static function actorId(Request $request): ?int
    {
        $user = $request->user();

        // `getAuthIdentifier()` is `mixed` by the Authenticatable contract, so it is narrowed
        // rather than cast — the actor id ends up in the ledger and must be a real id or null.
        return $user === null ? null : Coerce::nint($user->getAuthIdentifier());
    }

    /**
     * The product's current values as a save payload, for the paths that change ONE field
     * (a bulk activate, or the family re-derivation after a placement change).
     *
     * Reading the row back and re-submitting it is deliberate: `ProductWriter::update()` writes a
     * full column list, so a partial payload would blank the columns it omitted. Assembling the
     * payload here — in one place — is what stops that being discovered by a team member whose
     * bulk "activate" also cleared every price.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function currentPayload(int $productId, array $overrides): array
    {
        $raw = DB::table('catalog_products')->where('id', $productId)->first();
        if ($raw === null) {
            throw new RuntimeException("Product {$productId} does not exist.");
        }
        $product = Row::cast($raw);
        $family = Row::str($product, 'family');

        $payload = [
            'wa_code' => Row::str($product, 'wa_code'),
            'sku' => Row::nstr($product, 'sku'),
            'model_number' => Row::nstr($product, 'model_number'),
            'hs_code' => Row::nstr($product, 'hs_code'),
            'brand_id' => Row::int($product, 'brand_id'),
            'grade_id' => Row::nint($product, 'grade_id'),
            'purchase_price' => Row::money($product, 'purchase_price'),
            'selling_price' => Row::money($product, 'selling_price'),
            'sale_price' => Row::nmoney($product, 'sale_price'),
            'currency' => Row::str($product, 'currency'),
            'low_stock_threshold' => Row::int($product, 'low_stock_threshold'),
            'warranty_years' => Row::nint($product, 'warranty_years'),
            'is_active' => Row::bool($product, 'is_active'),
            'search_keywords' => Row::nstr($product, 'search_keywords'),
            'specs' => self::currentSpecs($productId, $family),
        ];

        foreach (self::translations($productId) as $column => $pair) {
            $payload[$column] = $pair;
        }

        return array_merge($payload, $overrides);
    }

    /**
     * The product's current spec values, from whichever store its family uses.
     *
     * @return array<string, mixed>
     */
    private static function currentSpecs(int $productId, string $family): array
    {
        $block = SpecBlocks::for($family);
        if ($block === null) {
            return [];
        }

        if ($block['table'] === 'watch_specs') {
            $row = DB::table(SpecBlocks::WATCH_SPECS_TABLE)->where('product_id', $productId)->first();
            if ($row === null) {
                return [];
            }
            $out = [];
            foreach (Coerce::arr((array) $row) as $column => $value) {
                if ($column === 'product_id') {
                    continue;
                }
                $out[$column] = is_scalar($value) ? $value : null;
            }

            return $out;
        }

        $specs = DB::table('catalog_products')->where('id', $productId)->value('specs');
        $decoded = is_string($specs) ? json_decode($specs, true) : null;

        return Coerce::arr($decoded);
    }

    /** @return array<string, array<string, string>> column => locale => value */
    private static function translations(int $productId): array
    {
        $out = [];
        foreach (ProductWriter::TRANSLATED as $column) {
            $out[$column] = ['ar' => '', 'en' => ''];
        }

        $rows = DB::table('catalog_product_translations')->where('product_id', $productId)->get();
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $locale = Row::nstr($row, 'locale');
            if ($locale !== 'ar' && $locale !== 'en') {
                continue;
            }
            foreach (ProductWriter::TRANSLATED as $column) {
                $out[$column][$locale] = Row::nstr($row, $column) ?? '';
            }
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private static function images(int $productId): array
    {
        $rows = DB::table('catalog_product_images')
            ->where('product_id', $productId)
            ->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')
            ->get(['id', 'path', 'is_cover', 'sort', 'alt_ar', 'alt_en', 'width', 'height', 'renditions']);

        $out = [];
        foreach ($rows as $row) {
            $image = Row::cast($row);
            $path = Row::str($image, 'path');
            $renditions = Row::nstr($image, 'renditions');
            $out[] = [
                'id' => Row::int($image, 'id'),
                'path' => $path,
                'url' => ImageUrl::src($path),
                'is_cover' => Row::bool($image, 'is_cover'),
                'alt_ar' => Row::nstr($image, 'alt_ar') ?? '',
                'alt_en' => Row::nstr($image, 'alt_en') ?? '',
                'width' => Row::nint($image, 'width'),
                'height' => Row::nint($image, 'height'),
                'renditions' => $renditions === null ? [] : Coerce::arr(json_decode($renditions, true)),
            ];
        }

        return $out;
    }

    /** @return list<int> */
    private static function pivotIds(string $table, string $column, int $productId): array
    {
        $out = [];
        foreach (DB::table($table)->where('product_id', $productId)->pluck($column) as $id) {
            if (is_numeric($id)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /** @return list<array{color_id: int, role: string}> */
    private static function colorRows(int $productId): array
    {
        $out = [];
        foreach (DB::table('catalog_product_color')->where('product_id', $productId)->orderBy('role')->get(['color_id', 'role']) as $raw) {
            $row = Row::cast($raw);
            $out[] = ['color_id' => Row::int($row, 'color_id'), 'role' => Row::str($row, 'role')];
        }

        return $out;
    }

    /** @return list<int> */
    private static function placedCategoryIds(int $storefrontId, int $productId): array
    {
        $out = [];
        $ids = DB::table('storefront_category_product')
            ->where('storefront_id', $storefrontId)->where('product_id', $productId)
            ->pluck('storefront_category_id');
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /**
     * A node and every node beneath it, from the materialised path.
     *
     * @return list<int>
     */
    private static function branchNodeIds(int $storefrontId, int $nodeId): array
    {
        $path = DB::table('storefront_categories')
            ->where('storefront_id', $storefrontId)->where('id', $nodeId)->value('path');
        if (! is_string($path) || $path === '') {
            return [];
        }

        $escaped = self::escapeLike($path);
        $out = [];
        $ids = DB::table('storefront_categories')
            ->where('storefront_id', $storefrontId)
            ->where('path', 'like', $escaped.'%')
            ->pluck('id');
        foreach ($ids as $id) {
            if (is_numeric($id)) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function brandOptions(): array
    {
        return SpecBlocks::options('brands');
    }

    /** @return list<array{value: string, label: string}> */
    private static function familyOptions(): array
    {
        // One label per family in Product::FAMILIES — the list is exhaustive by construction,
        // so a family added there without a label here is a PHPStan error rather than a screen
        // showing the raw key.
        $labels = [
            'watch' => 'ساعات', 'fashion' => 'أزياء', 'bag' => 'حقائب', 'wallet' => 'محافظ',
            'perfume' => 'عطور', 'electronics' => 'إلكترونيات', 'other' => 'أخرى',
        ];
        $out = [];
        foreach (Product::FAMILIES as $family) {
            $out[] = ['value' => $family, 'label' => $labels[$family]];
        }

        return $out;
    }

    /**
     * The tree as a flat option list, indented by depth so a select shows the hierarchy.
     *
     * @return list<array{value: string, label: string, depth: int, path: string}>
     */
    /**
     * The category options, each carrying the FAMILY the server resolves for it (task 4.1).
     *
     * This is what makes the product form reactive without a round trip and without a second
     * implementation of the derivation rule: the browser reads `option.family` when the operator
     * changes the primary category, and the save re-derives the same answer from the same class.
     * `FamilyForCategory::explainMany()` costs two queries for the whole tree.
     *
     * @return list<array{value: string, label: string, depth: int, path: string, family: string, family_reason: string}>
     */
    private function categoryOptionsWithFamily(int $storefrontId): array
    {
        $options = self::categoryOptions($storefrontId);
        $ids = [];
        foreach ($options as $option) {
            $ids[] = (int) $option['value'];
        }
        $families = $this->families->explainMany($ids);

        $out = [];
        foreach ($options as $option) {
            $id = (int) $option['value'];
            $resolved = $families[$id] ?? null;
            $out[] = [
                'value' => $option['value'],
                'label' => $option['label'],
                'depth' => $option['depth'],
                'path' => $option['path'],
                // A node with a malformed `path` has no family here; the SAVE refuses it loudly
                // rather than the dropdown guessing one.
                'family' => $resolved === null ? '' : $resolved['family'],
                'family_reason' => $resolved === null
                    ? 'مسار هذا التصنيف غير سليم في قاعدة البيانات — لا يمكن اشتقاق العائلة منه.'
                    : $resolved['reason'],
            ];
        }

        return $out;
    }

    /**
     * Every category of one storefront, deepest-path order, labelled for a dropdown.
     *
     * @return list<array{value: string, label: string, depth: int, path: string}>
     */
    private static function categoryOptions(int $storefrontId): array
    {
        $rows = DB::table('storefront_categories as c')
            ->leftJoin('storefront_category_translations as ar', function (JoinClause $join): void {
                $join->on('ar.storefront_category_id', '=', 'c.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('storefront_category_translations as en', function (JoinClause $join): void {
                $join->on('en.storefront_category_id', '=', 'c.id')->where('en.locale', '=', 'en');
            })
            ->where('c.storefront_id', $storefrontId)
            ->orderBy('c.path')
            ->get(['c.id', 'c.depth', 'c.path', 'c.slug', 'ar.name as name_ar', 'en.name as name_en']);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $depth = Row::int($row, 'depth');
            $ar = trim(Row::nstr($row, 'name_ar') ?? '');
            $en = trim(Row::nstr($row, 'name_en') ?? '');
            $label = $ar !== '' ? $ar : ($en !== '' ? $en : (Row::nstr($row, 'slug') ?? ('#'.$id)));
            $out[] = [
                'value' => (string) $id,
                'label' => str_repeat('— ', max(0, $depth - 1)).$label,
                'depth' => $depth,
                'path' => Row::str($row, 'path'),
            ];
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $out = [];
        foreach (DB::table('storefronts')->orderBy('id')->get(['id', 'name', 'is_active']) as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $name = Row::nstr($row, 'name') ?? ('#'.$id);
            $out[] = ['value' => (string) $id, 'label' => $name.(Row::bool($row, 'is_active') ? '' : ' (معطّل)')];
        }

        return $out;
    }

    /**
     * Every lookup list the form's blocks and shared fields need, in one payload.
     *
     * @return array<string, list<array{value: string, label: string}>>
     */
    private static function allLookupOptions(): array
    {
        $out = [];
        foreach (array_keys(Coerce::arr(config('catalog.lookups', []))) as $key) {
            $out[$key] = SpecBlocks::options($key);
        }

        return $out;
    }

    /**
     * @return array<int, array{ar: string, en: string}>
     */
    private static function nameMap(string $master, string $translations, string $fk): array
    {
        $rows = DB::table($master.' as m')
            ->leftJoin($translations.' as t', function (JoinClause $join) use ($fk): void {
                $join->on('t.'.$fk, '=', 'm.id')->whereIn('t.locale', ['ar', 'en']);
            })
            ->get(['m.id', 't.locale', 't.name']);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $out[$id] ??= ['ar' => '', 'en' => ''];
            $locale = Row::nstr($row, 'locale');
            if ($locale === 'ar' || $locale === 'en') {
                $out[$id][$locale] = Row::nstr($row, 'name') ?? '';
            }
        }

        return $out;
    }

    /**
     * The sentence every catalogue screen carries until the write-switch.
     *
     * It is not decoration. Until the switch the legacy Blade dashboard is the system of record
     * and switch night REBUILDS these tables from legacy (`core:drop-clean` → `migrate` →
     * `core:transform`), so a product authored here now is replaced by the rebuild — exactly the
     * class of loss AGENTS §2.20 was written for, with the difference that `catalog_products` IS
     * transform output and so cannot simply be excluded from the drop list. The team has to know
     * that before they spend an afternoon typing.
     *
     * @return array{pre_switch: bool, message: string}|null
     */
    /**
     * Delegated to `PreSwitch::noticeFor()` since review 🟠-3, which found this screen's generic
     * sentence doing duty on the placement screen where it named none of the work that screen
     * actually loses. One home for the wording, one per-screen switch inside it.
     *
     * @return array{pre_switch: bool, message: string}|null
     */
    private static function preSwitchNotice(): ?array
    {
        return PreSwitch::noticeFor('product');
    }

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
