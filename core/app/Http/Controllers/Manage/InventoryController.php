<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\ProductSearch;
use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\InventoryService;
use App\Domain\Inventory\StockTarget;
use App\Models\Catalog\Product;
use App\Models\Storefront\Storefront;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Support\LocalisedName;
use App\Support\ManageText;
use App\Support\Sql;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Inventory — stock, the ledger, low stock, and the reconciliation panel (wave 4C).
 *
 * ── One door, and this screen is not it ──────────────────────────────────────────────────────
 *
 * Every quantity this screen changes goes through `InventoryService` (AGENTS §3, wave 3). The
 * controller computes NOTHING about stock: it validates a form, names a `StockTarget`, and calls
 * `set()` or `adjust()`. That is why data-entry may use it — the role decides who may ask, the
 * service decides what is allowed, and the ledger records what happened either way. `StockWriteGuard`
 * is armed outside production and would refuse any statement from here that named a stock column.
 *
 * ── The ledger is READ-ONLY for everybody ───────────────────────────────────────────────────
 *
 * There is no route on this controller that edits or deletes a movement, and there will not be: a
 * ledger you can edit is a ledger that cannot reconcile anything. A mistake is corrected by
 * POSTING THE OPPOSITE MOVEMENT, which is what an accountant would do and what the reasons list is
 * shaped for (`adjustment` with a note). `InventoryAuthorizationTest` asserts the absence of such a
 * route rather than trusting this paragraph.
 *
 * ── The reconciliation panel runs the COMMAND ───────────────────────────────────────────────
 *
 * `inventory:verify` holds the four invariants (ledger = column, variant sum = product aggregate,
 * `in_stock` derived, no product-level movement on a variant product). The panel runs that command
 * and shows its report. It does NOT re-implement the checks: a second implementation would be a
 * second answer, and the first time they disagreed nobody would know which to believe.
 */
final class InventoryController
{
    public function __construct(private readonly InventoryService $inventory) {}

    /**
     * Stock per product, with its variants, both buckets — and a low-stock view that is a FILTER
     * on this list rather than a separate screen, so the numbers cannot differ between the two.
     */
    public function index(Request $request): Response|StreamedResponse
    {
        /** @var array<int, list<array<string, mixed>>> $variants */
        $variants = [];

        $table = TableQuery::for($request)
            // `t.title` added 2026-09-20 (6.1): the list could be sorted by code and by three
            // numbers, and not by the one thing every row is headed with. Somebody hunting
            // “the brown leather one” had no way to bring the names into an order.
            ->sortable(['p.wa_code', 't.title', 'p.stock_express', 'p.stock_market', 'p.low_stock_threshold'], default: 'p.wa_code')
            /*
             * The SAME search the products list runs (D-8, 2026-09-19).
             *
             * Every row on this screen is headed by an Arabic product name, and the search could
             * not see names: typing `هوغو` into a list of rows reading *ساعة هوغو بوس للرجال*
             * returned "لا توجد منتجات". Reusing `ProductSearch` rather than re-deriving it here
             * is the point — two lists of the same products that answer "find me this" differently
             * is how the operator learns not to trust either one.
             *
             * The alias pair is this screen's own: it joins the translations as `t`/`te`.
             */
            ->searchUsing(function (EloquentBuilder|QueryBuilder $query, string $term): void {
                ProductSearch::apply($query, $term, 't', 'te');
            })
            // `view` and `bucket` are predicates, not columns: declared so they are whitelisted
            // and rendered, applied by hand below (the `flag` lesson of wave 4B).
            /*
             * ── Narrowing, which this screen had none of (6.1, 2026-09-20) ──────────────
             *
             * It offered `view` and `bucket` and nothing else: no brand, no family, no category.
             * Searching works and always did — `ProductSearch` covers the internal code, the model
             * number and the product NAME in both languages — but a search only helps somebody who
             * knows what to type. An operator hunting by memory had nothing to narrow by.
             *
             * The three added here are the products list's own filters, by the same keys and the
             * same labels, because two screens over one catalogue must not have two vocabularies.
             *
             * ── …and `storefront`, which is a FILTER and never a default ────────────────
             *
             * Decision, 2026-09-20: this screen stays CATALOGUE-WIDE. Stock lives on
             * `catalog_products` and nowhere else — `storefront_product` carries visibility,
             * ordering, slug and price overrides, but no stock — so there is ONE physical figure
             * per product backing both shops. Scoping the list to a storefront would show the same
             * twelve units on the shelf as Watchizer's twelve AND Brand Fashion's twelve: a wrong
             * number that looks right, which is worse than the confusion it would cure.
             *
             * So the shared warehouse stays the default and the filter is opt-in, for the operator
             * who deliberately wants “stock of the things we sell on Watchizer” and knows they are
             * asking about a subset.
             */
            ->filterable([
                'view' => ['', 'low', 'out'],
                'bucket' => ['', 'express', 'market', 'out'],
                'p.family' => Product::FAMILIES,
                'p.brand_id' => self::brandValues(),
                'category' => self::categoryValues(),
                'storefront' => self::storefrontValues(),
            ])
            ->virtual(['view', 'bucket', 'category', 'storefront'])
            /*
             * A stock take, as a file. `variants` is flattened to one cell rather than dropped:
             * a product with sizes is adjusted PER VARIANT, and a stock sheet that showed only
             * the aggregate would be counted against the wrong thing on the warehouse floor.
             */
            /*
             * Every heading is a key the INVENTORY or PRODUCTS screen already renders — the stock
             * sheet is the table as a file, so the two must name a column the same way.
             */
            ->exportable([
                'wa_code' => ManageText::t('products.wa_code', 'الكود الداخلي'),
                'sku' => ManageText::t('products.sku', 'رقم الموديل (SKU)'),
                // Both languages: the stock file is opened to bulk-edit and to send on, and one
                // language is half the record. Empty when there is no translation, never a fallback.
                'title' => [ManageText::t('common.name_ar', 'الاسم (عربي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['title'] ?? null)['ar'] ?? null)],
                'title_en' => [ManageText::t('common.name_en', 'الاسم (إنجليزي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['title'] ?? null)['en'] ?? null)],
                'family' => ManageText::t('products.family', 'العائلة'),
                'express' => ManageText::t('common.stock_express', 'إكسبريس'),
                'market' => ManageText::t('common.stock_market', 'ماركت'),
                'total' => ManageText::t('common.total', 'الإجمالي'),
                'threshold' => ManageText::t('inventory.threshold', 'حد التنبيه'),
                'is_low' => ManageText::t('inventory.low', 'منخفض'),
                'in_stock' => ManageText::t('products.in_stock', 'متوفر'),
                'variants' => [ManageText::t('inventory.variants', 'المقاسات/الألوان'), fn (array $row): string => implode(' | ', array_map(
                    static fn (mixed $variant): string => Coerce::str(Coerce::arr($variant)['label'] ?? null)
                        .': '.Coerce::str(Coerce::arr($variant)['express'] ?? 0)
                        .'/'.Coerce::str(Coerce::arr($variant)['market'] ?? 0),
                    Coerce::arr($row['variants'] ?? null),
                ))],
            ], 'stock');

        $query = DB::table('catalog_products as p')
            ->leftJoin('catalog_product_translations as t', function (JoinClause $join): void {
                $join->on('t.product_id', '=', 'p.id')->where('t.locale', '=', 'ar');
            })
            /*
             * The ENGLISH title too, for the CSV (2026-09-16). A second LEFT JOIN on the same table
             * at a different locale, which is what the products and placement screens already do:
             * the export must carry both languages of every translated field, and this query only
             * ever fetched one. It costs one indexed row per product on a screen that already joins
             * the table, and the screen itself still renders the Arabic.
             */
            ->leftJoin('catalog_product_translations as te', function (JoinClause $join): void {
                $join->on('te.product_id', '=', 'p.id')->where('te.locale', '=', 'en');
            })
            ->whereNull('p.deleted_at')
            ->select([
                'p.id', 'p.wa_code', 'p.sku', 'p.stock_express', 'p.stock_market', 'p.in_stock',
                'p.low_stock_threshold', 'p.family', 't.title as title_ar', 'te.title as title_en',
            ])
            /*
             * The BADGE, answered by the query rather than re-derived in PHP (B5).
             *
             * It was `($express + $market) <= $threshold` in the row mapper: two columns compared
             * again, after the query had already decided something else with three more clauses.
             * So every out-of-stock row was badged «منخفض» while the filter beside it excluded
             * all 2,578 of them — one screen contradicting itself.
             *
             * Selected from `Sql::lowStock()`, the badge cannot drift from the filter, because
             * there is no second expression left to drift.
             */
            ->selectRaw(Sql::lowStockSelect('p'));

        // `resolvedFilters()`, never raw request input — see OrderController for why.
        $filters = $table->resolvedFilters();

        /*
         * Low stock: the product's OWN threshold, not a global number — the column exists per
         * product because a watch and a keychain do not run low at the same count.
         *
         * The three clauses match `InventoryService::lowStockProducts()` exactly, so the rows this
         * filter returns are the rows the banner above counted and the rows the home tile counted.
         * Written through `Sql` rather than as a raw string because a fourth hand-written copy of
         * this predicate is how the first three drifted.
         */
        if (Coerce::str($filters['view'] ?? null) === 'low') {
            // The whole rule, and nothing beside it: the clauses that used to be written here are
            // inside `Sql::lowStock()` now, where the badge below reads them too (B5).
            $query->whereRaw(Sql::lowStock('p'));
        } elseif (Coerce::str($filters['view'] ?? null) === 'out') {
            $query->where('p.is_active', 1)->where('p.in_stock', 0);
        }
        /*
         * A category filter means the whole BRANCH, exactly as it does on the products list — an
         * operator filtering by "Watches" means the watches beneath it too. The node ids come from
         * the same materialised path the visibility rule reads, never from a recursive walk.
         */
        $category = Coerce::nint($filters['category'] ?? null);
        if ($category !== null) {
            $branch = self::branchNodeIds($category);
            $query->whereExists(function (QueryBuilder $sub) use ($branch): void {
                $sub->from('storefront_category_product as scp')
                    ->whereColumn('scp.product_id', 'p.id')
                    ->whereIn('scp.storefront_category_id', $branch === [] ? [-1] : $branch)
                    ->selectRaw('1')
                    ->limit(1);
            });
        }

        /*
         * The storefront filter: OPT-IN, never a default (decision 2026-09-20 — see the note on
         * `filterable()`). It narrows to the products a shop sells; it does not pretend the stock
         * belongs to that shop, because it does not.
         */
        $storefront = Coerce::nint($filters['storefront'] ?? null);
        if ($storefront !== null) {
            $query->whereExists(function (QueryBuilder $sub) use ($storefront): void {
                $sub->from('storefront_product as sp2')
                    ->whereColumn('sp2.product_id', 'p.id')
                    ->where('sp2.storefront_id', $storefront)
                    ->selectRaw('1')
                    ->limit(1);
            });
        }

        $bucket = Coerce::str($filters['bucket'] ?? null);
        if ($bucket === 'express') {
            $query->where('p.stock_express', '>', 0);
        } elseif ($bucket === 'market') {
            $query->where('p.stock_market', '>', 0);
        } elseif ($bucket === 'out') {
            $query->where('p.stock_express', 0)->where('p.stock_market', 0);
        }

        /** @var array<int, string> $covers */
        $covers = [];
        /** @var array<int, list<array{id: int, name: string}>> $soldOn */
        $soldOn = [];

        $prepare = function (array $rows) use (&$variants, &$covers, &$soldOn): void {
            $page = Coerce::objectList($rows);
            $variants = self::variantsForPage($page);
            // Two keyed reads for the PAGE, never a sub-select per row: the same shape the products
            // list uses, and for the same reason its docblock gives — the correlated versions were
            // that list's single biggest cost.
            $covers = self::coversForPage($page);
            $soldOn = self::soldOnForPage($page);
        };

        $map = function (object $raw) use (&$variants, &$covers, &$soldOn): array {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $express = Row::int($row, 'stock_express');
            $market = Row::int($row, 'stock_market');
            $threshold = Row::int($row, 'low_stock_threshold');

            return [
                'id' => $id,
                'wa_code' => Row::str($row, 'wa_code'),
                'sku' => Row::nstr($row, 'sku'),
                /*
                 * The PAIR, not the Arabic (2026-10-05).
                 *
                 * This screen sent `title` = the Arabic name and `title_en` alongside it for the
                 * CSV, and the screen rendered `title`. So the English dashboard listed
                 * «ساعة هوجو بوس للرجال» for an operator counting stock in English — item 1b's
                 * defect, in a screen that never adopted item 1b's fix.
                 *
                 * Sent as `{ar, en}` exactly as the products list sends it, so `ProductName` can
                 * choose and mark a fallback. The CSV keeps both columns; its two closures read
                 * the pair rather than two different keys.
                 */
                'title' => [
                    'ar' => Row::nstr($row, 'title_ar') ?? '',
                    'en' => Row::nstr($row, 'title_en') ?? '',
                ],
                // `title_en` is gone as a separate key (2026-10-05): both languages now travel in
                // `title`, and the CSV's second column reads the pair. One name in the payload,
                // one rule for choosing it.
                'family' => Row::nstr($row, 'family'),
                'express' => $express,
                'market' => $market,
                'total' => $express + $market,
                'threshold' => $threshold,
                'in_stock' => Row::bool($row, 'in_stock'),
                'is_low' => Row::bool($row, 'is_low'),
                /*
                 * The COVER (6.2, 2026-09-20). Every other catalogue screen has one and this did
                 * not — on the screen where recognising a product at a glance matters most,
                 * because the operator is holding the thing they are counting.
                 */
                'cover' => isset($covers[$id]) ? ImageUrl::src($covers[$id]) : null,
                /*
                 * WHICH SHOPS SELL IT (the scope decision, 2026-09-20).
                 *
                 * This list is catalogue-wide on purpose: stock is one physical figure per product
                 * and both shops draw on it, so scoping the screen to a storefront would show the
                 * same units twice. The cost of that correctness is a genuine question — "why is
                 * this product on my screen when I am looking at Watchizer?" — and this column is
                 * the answer, on the row, rather than a paragraph nobody reads.
                 */
                'sold_on' => $soldOn[$id] ?? [],
                // A product with variants is adjusted PER VARIANT: the aggregate is derived
                // and the service refuses a product-level movement on it (wave 3.5).
                'variants' => $variants[$id] ?? [],
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map, $prepare);
        }

        return Inertia::render('Manage/Inventory/Index', [
            'table' => $table->paginate($query, $map, $prepare),
            'filters' => [
                /*
                 * The VALUES are the predicate the query reads and stay codes; only the labels
                 * beside them are translated. `مخزون منخفض` is the products screen's own
                 * `low_stock` filter word, deliberately the same key: one filter, one name.
                 */
                'views' => [
                    ['value' => '', 'label' => ManageText::t('inventory.all_products', 'كل المنتجات')],
                    ['value' => 'low', 'label' => ManageText::t('products.low_stock', 'مخزون منخفض')],
                    // "Low" and "gone" are two different jobs for the buyer, so they are two
                    // entries. They used to be one number, added together, called an alert.
                    ['value' => 'out', 'label' => ManageText::t('common.out_of_stock', 'نفد المخزون')],
                ],
                'buckets' => [
                    ['value' => '', 'label' => ManageText::t('inventory.all_buckets', 'كل المخازن')],
                    ['value' => 'express', 'label' => ManageText::t('inventory.express_only', 'إكسبريس فقط')],
                    ['value' => 'market', 'label' => ManageText::t('inventory.market_only', 'ماركت فقط')],
                    ['value' => 'out', 'label' => ManageText::t('inventory.out_of_both', 'نفد بالكامل')],
                ],
                /*
                 * 6.1 — the three the products list already offers, by the same keys and the same
                 * labels. A stock screen and a catalogue screen that name "الماركة" differently
                 * teach the operator that the two lists are different products.
                 */
                'families' => self::familyOptions(),
                'brands' => self::brandOptions(),
                'categories' => self::categoryOptions(),
                /*
                 * …and the storefront one, which the screen renders LAST and never pre-selects.
                 * The decision and its reason live on `filterable()` above: stock is one physical
                 * figure backing both shops, so a scoped default would show the same units twice.
                 */
                'storefronts' => self::storefrontOptions(),
            ],
            'reasons' => self::adjustmentReasons(),
            'alerts' => self::stockAlerts(),
        ]);
    }

    /**
     * The movement ledger, filterable — the audit trail a human reads when a number surprises them.
     *
     * Read-only by construction: this method has no sibling that writes.
     */
    public function ledger(Request $request): Response|StreamedResponse
    {
        $table = TableQuery::for($request)
            ->sortable(['im.created_at', 'im.quantity_delta', 'im.id'], default: 'im.created_at', direction: 'desc')
            ->filterable([
                'reason' => InventoryService::REASONS,
                'bucket' => ['', 'express', 'market'],
                // `null` = any scalar; `[]` would be an allow-list of nothing (see OrderController).
                'product_id' => null,
                'storefront_id' => null,
                'reference_type' => null,
                'from' => null,
                'to' => null,
            ])
            ->virtual(['reason', 'bucket', 'product_id', 'storefront_id', 'reference_type', 'from', 'to'])
            /*
             * The ledger is the export the accountant actually asks for, and it is already past
             * 100 000 rows — which is why the whole mechanism streams. Every column here is a
             * movement fact; there is no customer or credential anywhere in this table.
             */
            ->exportable([
                'created_at' => ManageText::t('common.date', 'التاريخ'),
                'wa_code' => ManageText::t('products.wa_code', 'الكود الداخلي'),
                'product_id' => ManageText::t('common.product_number', 'رقم المنتج'),
                'variant' => ManageText::t('inventory.ledger_variant', 'المقاس/اللون'),
                'bucket' => ManageText::t('inventory.bucket', 'المخزن'),
                'delta' => ManageText::t('inventory.ledger_delta', 'التغيير'),
                'after' => ManageText::t('inventory.ledger_balance_after', 'الرصيد بعدها'),
                'reason' => ManageText::t('common.reason', 'السبب'),
                'reference' => ManageText::t('inventory.ledger_source', 'المصدر'),
                'reference_id' => ManageText::t('inventory.ledger_source_id', 'رقم المصدر'),
                'external_ref' => ManageText::t('inventory.ledger_external_ref', 'مرجع خارجي'),
                'actor' => ManageText::t('inventory.ledger_actor', 'مَن'),
                'actor_id' => ManageText::t('inventory.ledger_actor_id', 'رقم المستخدم'),
                'storefront_id' => ManageText::t('common.storefront', 'المتجر'),
                'note' => ManageText::t('common.note', 'ملاحظة'),
            ], 'stock-ledger');

        $query = DB::table('inventory_movements as im')
            ->leftJoin('catalog_products as p', 'p.id', '=', 'im.product_id')
            ->leftJoin('catalog_product_variants as v', 'v.id', '=', 'im.variant_id')
            /*
             * The ACTOR's name (D-11, 2026-09-19).
             *
             * The ledger used to print `user #5`. On the one screen whose product is
             * accountability, the person who moved the stock was a raw foreign key — while every
             * other screen in the dashboard resolves that id to a name. The join is conditional on
             * `actor_type` because the column is polymorphic: `system` rows carry an id that is
             * not a user id, and matching it against `users` would attribute a machine's movement
             * to whichever person happens to hold that number.
             */
            ->leftJoin('users as u', function (JoinClause $join): void {
                $join->on('u.id', '=', 'im.actor_id')->where('im.actor_type', '=', 'user');
            })
            ->select([
                'im.id', 'im.product_id', 'im.variant_id', 'im.bucket', 'im.quantity_delta',
                'im.quantity_after', 'im.reason', 'im.reference_type', 'im.reference_id',
                'im.actor_type', 'im.actor_id', 'im.storefront_id', 'im.note', 'im.external_ref',
                'im.created_at', 'p.wa_code', 'v.label as variant_label',
                // `users` carries first_name/last_name and NO `name` column (measured on the
                // production schema, and the users screen assembles it the same way). The e-mail
                // is the fallback, because a row with no name still has to identify a person.
                'u.first_name as actor_first', 'u.last_name as actor_last', 'u.email as actor_email',
            ]);

        $filters = $table->resolvedFilters();

        foreach ([
            'reason' => 'im.reason',
            'bucket' => 'im.bucket',
            'product_id' => 'im.product_id',
            'storefront_id' => 'im.storefront_id',
            'reference_type' => 'im.reference_type',
        ] as $input => $column) {
            $value = Coerce::nstr($filters[$input] ?? null);
            if ($value !== null && $value !== '') {
                $query->where($column, $value);
            }
        }
        $from = Coerce::nstr($filters['from'] ?? null);
        if ($from !== null && $from !== '') {
            $query->where('im.created_at', '>=', $from.' 00:00:00');
        }
        $to = Coerce::nstr($filters['to'] ?? null);
        if ($to !== null && $to !== '') {
            $query->where('im.created_at', '<=', $to.' 23:59:59');
        }

        $map = function (object $raw): array {
            $row = Row::cast($raw);

            return [
                'id' => Row::int($row, 'id'),
                'product_id' => Row::nint($row, 'product_id'),
                'wa_code' => Row::nstr($row, 'wa_code'),
                'variant' => Row::nstr($row, 'variant_label'),
                'bucket' => Row::str($row, 'bucket'),
                'delta' => Row::int($row, 'quantity_delta'),
                'after' => Row::int($row, 'quantity_after'),
                'reason' => Row::str($row, 'reason'),
                'reference' => Row::nstr($row, 'reference_type'),
                'reference_id' => Row::nint($row, 'reference_id'),
                'actor' => self::actorLabel(
                    Row::nstr($row, 'actor_type'),
                    self::personName($row),
                    Row::nint($row, 'actor_id'),
                ),
                'actor_id' => Row::nint($row, 'actor_id'),
                'storefront_id' => Row::nint($row, 'storefront_id'),
                'note' => Row::nstr($row, 'note'),
                'external_ref' => Row::nstr($row, 'external_ref'),
                'created_at' => Row::nstr($row, 'created_at'),
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map);
        }

        return Inertia::render('Manage/Inventory/Ledger', [
            'table' => $table->paginate($query, $map),
            'filters' => [
                'reasons' => array_map(
                    fn (string $r): array => ['value' => $r, 'label' => $r],
                    InventoryService::REASONS,
                ),
                'buckets' => [
                    ['value' => '', 'label' => ManageText::t('inventory.all_buckets', 'كل المخازن')],
                    /*
                     * These two carry the COLUMN VALUE in both fields, and the SCREEN translates
                     * them (item 10, 2026-09-18): `Ledger.tsx` renders `bucketLabel(t, option.value)`
                     * rather than `option.label`, so the filter says «إكسبريس» like the column beside
                     * it instead of `express`.
                     *
                     * Translated there rather than here because the same two words are needed on
                     * five screens and one vocabulary beats five — see `resources/js/lib/labels.ts`.
                     * The label stays as the value so this payload is still self-describing to
                     * anyone reading it without the screen.
                     */
                    ['value' => 'express', 'label' => 'express'],
                    ['value' => 'market', 'label' => 'market'],
                ],
                'references' => self::referenceTypes(),
            ],
        ]);
    }

    /**
     * The reconciliation panel: what `inventory:verify` says, right now, read-only.
     *
     * Running the command is the point. The four invariants live in ONE place and this screen shows
     * that place's answer; re-deriving them here would create a second answer that can disagree
     * with the command the runbook trusts.
     */
    public function reconciliation(Request $request): Response
    {
        $exitCode = Artisan::call('inventory:verify');
        $output = trim(Artisan::output());

        return Inertia::render('Manage/Inventory/Reconciliation', [
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            // The command's own words, verbatim, so the screen and the terminal agree.
            // `$output` itself is the COMMAND's words and is never translated — the screen has to
            // agree with the terminal. Only the "it said nothing" stand-in is UI text.
            'report' => $output === ''
                ? ManageText::t('inventory.recon_no_output', 'لم يُصدر الفحص أي مخرجات.')
                : $output,
            'ran_at' => now()->toDateTimeString(),
            'counts' => [
                'products' => DB::table('catalog_products')->whereNull('deleted_at')->count(),
                'variants' => DB::table('catalog_product_variants')->count(),
                'movements' => DB::table('inventory_movements')->count(),
            ],
        ]);
    }

    /**
     * An adjustment — the one write this controller makes, and it makes it through the service.
     *
     * `set` is an absolute count ("the shelf holds 7"), `adjust` a relative one ("three arrived").
     * Both are recorded with a reason and an optional note, and the service refuses:
     * a product-level movement on a product that has variants, a decrement below zero, and a
     * reason outside its own list.
     */
    public function adjust(Request $request): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'product_id' => ['required', 'integer', Rule::exists('catalog_products', 'id')->whereNull('deleted_at')],
            'variant_id' => ['nullable', 'integer', Rule::exists('catalog_product_variants', 'id')],
            'bucket' => ['required', 'string', Rule::in(['express', 'market'])],
            'mode' => ['required', 'string', Rule::in(['set', 'adjust'])],
            'quantity' => ['required', 'integer', 'min:-1000000', 'max:1000000'],
            'reason' => ['required', 'string', Rule::in(self::ADJUSTMENT_REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]));

        $productId = Coerce::int($data['product_id']);
        $variantId = Coerce::nint($data['variant_id'] ?? null);
        $quantity = Coerce::int($data['quantity']);
        $mode = Coerce::str($data['mode']);

        if ($variantId !== null) {
            // A variant must belong to the named product: the service checks it too, and asking
            // here turns a 500 into a field error the operator can act on.
            $belongs = DB::table('catalog_product_variants')
                ->where('id', $variantId)->where('product_id', $productId)->exists();
            if (! $belongs) {
                throw ValidationException::withMessages([
                    'variant_id' => ManageText::t(
                        'inventory.variant_not_of_product',
                        'هذا المقاس/اللون لا يتبع هذا المنتج.',
                    ),
                ]);
            }
        }

        if ($mode === 'adjust' && $quantity === 0) {
            throw ValidationException::withMessages([
                'quantity' => ManageText::t(
                    'inventory.adjust_zero_delta',
                    'التغيير النسبي لا يمكن أن يكون صفرًا. استخدم «تعيين» لضبط رقم مطلق.',
                ),
            ]);
        }
        if ($mode === 'set' && $quantity < 0) {
            throw ValidationException::withMessages([
                'quantity' => ManageText::t(
                    'inventory.set_negative',
                    'الكمية المطلقة لا يمكن أن تكون سالبة.',
                ),
            ]);
        }

        $target = $variantId === null
            ? StockTarget::product($productId)
            : StockTarget::variant($productId, $variantId);

        try {
            $movement = $mode === 'set'
                ? $this->inventory->set(
                    $target,
                    Coerce::str($data['bucket']),
                    $quantity,
                    Coerce::str($data['reason']),
                    actor: self::actor($request),
                    note: Coerce::nstr($data['note'] ?? null),
                )
                : $this->inventory->adjust(
                    $target,
                    Coerce::str($data['bucket']),
                    $quantity,
                    Coerce::str($data['reason']),
                    actor: self::actor($request),
                    note: Coerce::nstr($data['note'] ?? null),
                );
        } catch (InsufficientStock $e) {
            throw ValidationException::withMessages([
                'quantity' => ManageText::t(
                    'inventory.insufficient_stock',
                    'لا يوجد مخزون كافٍ لهذا الخصم: الكمية المطلوبة أكبر من المتاح.',
                ),
            ]);
        } catch (InvalidArgumentException $e) {
            /*
             * ── B-BUG-1: a product with sizes returned HTTP 500 (2026-09-17) ────────────────
             *
             * `InventoryService` is right to refuse a product-level movement on a product that has
             * variants — §2.5, and the aggregate would stop meaning anything. But it refuses with an
             * `InvalidArgumentException`, which is NOT a `RuntimeException`, so it fell past both
             * arms below and reached the operator as a Server Error page.
             *
             * Two defects in one, and the second is why this catch does not echo `$e->getMessage()`
             * the way the `RuntimeException` arm does. That text is a PROGRAMMING contract —
             *
             *     "Product 5593 has variants, so its stock moves through a variant, never through
             *      the product. Use StockTarget::variant()."
             *
             * — addressed to whoever wrote the caller, in English, naming a PHP class and method. It
             * is the right message for a developer and useless to the person holding the stock
             * sheet. So the domain keeps its contract message and the screen gets its own sentence,
             * which names the ACTION rather than the API: pick the size and adjust that.
             *
             * Brand Fashion sells shoes and clothing in sizes, so this is a first-week event, not an
             * edge case.
             */
            $sizes = DB::table('catalog_product_variants')
                ->where('product_id', $productId)
                ->where('is_active', true)
                ->count();

            throw ValidationException::withMessages([
                // ONE literal, not a concatenation across two lines: the coverage ratchet reads the
                // source line by line, so a continuation line holding bare Arabic is — correctly —
                // indistinguishable to it from an unwired string.
                'variant_id' => ManageText::t(
                    'inventory.product_has_variants',
                    'هذا المنتج له مقاسات/ألوان (:count)، والمخزون يُدار لكل مقاس على حدة. اختر المقاس ثم عدّل كميته — إجمالي المنتج يُحسب من مجموع المقاسات.',
                    ['count' => $sizes],
                ),
            ]);
        } catch (RuntimeException $e) {
            // The service's own refusals are already operator-readable (wave 3.5 wrote them in
            // Arabic where they can reach a screen); anything else is named plainly.
            throw ValidationException::withMessages(['quantity' => $e->getMessage()]);
        }

        return back()->with('status', $movement === null
            ? ManageText::t('inventory.no_movement_needed', 'الكمية المطلوبة مطابقة للحالية، فلم تُسجَّل حركة.')
            : ManageText::t('inventory.movement_recorded', 'تم تسجيل الحركة في السجل.'));
    }

    // ── reads ────────────────────────────────────────────────────────────────────────────────

    /**
     * Apply one stock action to the SELECTED rows (6.3, 2026-09-20).
     *
     * ── The two modes are two ACTIONS, not one action with a flag ────────────────────
     *
     * `count_to` and `receive` are separate values with separate controls on the screen, and that
     * is a safety decision rather than a styling one. The failure it prevents:
     *
     *     an operator means *"a shipment of 5 arrived"* and gets *"set the shelf to 5"*
     *
     * on a product holding 80 — which writes off 75 units, and the ledger records it as a
     * deliberate correction because that is exactly what it was told. There is no error to notice
     * afterwards: the number is plausible, the movement is signed, and the only trace is a stock
     * figure that is quietly wrong until somebody counts the shelf again.
     *
     * A dropdown makes those two one control apart. Two buttons make them two decisions apart, and
     * the screen shows the resulting number under each before it will submit.
     *
     * ── SELECTED rows only. There is no "apply to all matching" ──────────────────────
     *
     * The products list offers that for visibility and thresholds, which are reversible. This
     * writes the LEDGER: every movement is permanent, signed, and reconciled against nightly. An
     * "apply to everything matching" button on a stock screen is one crafted query away from
     * rewriting the warehouse, so the ids are explicit and capped.
     *
     * Every movement goes through `InventoryService` with its reason — no direct column write —
     * and the ledger records which MODE was used, so a later reader can tell a stock-take from a
     * delivery without inferring it from the sign of a number.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $data = Coerce::arr($request->validate([
            'action' => ['required', 'string', Rule::in(['count_to', 'receive', 'set_threshold', 'move_bucket'])],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::BULK_MAX],
            'ids.*' => ['integer', Rule::exists('catalog_products', 'id')->whereNull('deleted_at')],
            'bucket' => ['required_unless:action,set_threshold', 'nullable', 'string', Rule::in(['express', 'market'])],
            'to_bucket' => ['required_if:action,move_bucket', 'nullable', 'string', Rule::in(['express', 'market'])],
            'quantity' => ['required', 'integer', 'min:-1000000', 'max:1000000'],
            'reason' => ['required_unless:action,set_threshold', 'nullable', 'string', Rule::in(self::ADJUSTMENT_REASONS)],
            'note' => ['nullable', 'string', 'max:255'],
        ]));

        $action = Coerce::str($data['action']);
        $ids = Coerce::intList($data['ids']);
        $quantity = Coerce::int($data['quantity']);
        $bucket = Coerce::str($data['bucket'] ?? null, 'express');
        $note = Coerce::nstr($data['note'] ?? null);
        $reason = Coerce::str($data['reason'] ?? null, 'adjustment');

        if ($action === 'receive' && $quantity === 0) {
            throw ValidationException::withMessages([
                'quantity' => ManageText::t('inventory.adjust_zero_delta',
                    'التغيير النسبي لا يمكن أن يكون صفرًا. استخدم «تعيين» لضبط رقم مطلق.'),
            ]);
        }
        if ($action !== 'receive' && $quantity < 0) {
            throw ValidationException::withMessages([
                'quantity' => ManageText::t('inventory.set_negative', 'الكمية المطلقة لا يمكن أن تكون سالبة.'),
            ]);
        }
        if ($action === 'move_bucket' && Coerce::str($data['to_bucket'] ?? null) === $bucket) {
            throw ValidationException::withMessages([
                'to_bucket' => ManageText::t('inventory.move_same_bucket',
                    'المخزن المصدر والوجهة واحد. اختر مخزنين مختلفين.'),
            ]);
        }

        $actor = self::actor($request);
        $done = 0;
        /** @var list<string> $skipped */
        $skipped = [];

        foreach ($ids as $productId) {
            /*
             * A product with variants is skipped, NAMED, rather than failing the batch. Its stock
             * lives per variant and the service refuses a product-level movement on it (wave 3.5) —
             * which is right, and is not a reason to abandon the other forty rows the operator
             * selected. The same shape the category bulk actions use: do what can be done, and say
             * exactly what could not.
             */
            if (DB::table('catalog_product_variants')->where('product_id', $productId)->exists()) {
                $skipped[] = self::productCode($productId);

                continue;
            }

            try {
                match ($action) {
                    'count_to' => $this->inventory->set(
                        StockTarget::product($productId), $bucket, $quantity, $reason,
                        actor: $actor, note: $note,
                    ),
                    'receive' => $this->inventory->adjust(
                        StockTarget::product($productId), $bucket, $quantity, $reason,
                        actor: $actor, note: $note,
                    ),
                    'move_bucket' => $this->moveBetweenBuckets(
                        $productId, $bucket, Coerce::str($data['to_bucket'] ?? null), $quantity, $reason, $actor, $note,
                    ),
                    'set_threshold' => self::setThreshold($productId, $quantity),
                    default => null,
                };
                $done++;
            } catch (InsufficientStock) {
                $skipped[] = self::productCode($productId);
            } catch (InvalidArgumentException|RuntimeException) {
                $skipped[] = self::productCode($productId);
            }
        }

        if ($action !== 'set_threshold') {
            ActivityLog::record(
                'catalog_products',
                null,
                ActivityLog::ADJUSTED,
                ['bulk_stock' => null],
                ['bulk_stock' => $action, 'products' => $done, 'bucket' => $bucket, 'quantity' => $quantity],
                label: ManageText::t('inventory.bulk_label', ':count منتجًا', ['count' => $done]),
            );
        }

        $status = ManageText::t('inventory.bulk_done', 'تم تنفيذ الإجراء على :count منتجًا.', ['count' => $done]);
        if ($skipped !== []) {
            $status .= ' '.ManageText::t(
                'inventory.bulk_skipped',
                'تُركت :count منتجات (لها مقاسات، أو لا يوجد مخزون كافٍ): :codes',
                ['count' => count($skipped), 'codes' => implode(ManageText::t('common.list_separator', '، '), array_slice($skipped, 0, 10))],
            );
        }

        return back()->with('status', $status);
    }

    /**
     * Move stock from one bucket to the other: ONE transaction, TWO ledger movements.
     *
     * Not a single "transfer" movement, because the ledger's invariant is that the sum of a
     * bucket's movements equals that bucket's column — `inventory:verify` checks exactly that
     * nightly. A transfer that wrote one row would satisfy neither bucket. Two signed movements
     * sharing a note is what a transfer IS, in a ledger.
     */
    private function moveBetweenBuckets(
        int $productId, string $from, string $to, int $quantity, string $reason, Actor $actor, ?string $note,
    ): void {
        $target = StockTarget::product($productId);
        $shared = $note ?? ManageText::t('inventory.move_note', 'نقل بين المخازن');

        DB::transaction(function () use ($target, $from, $to, $quantity, $reason, $actor, $shared): void {
            $this->inventory->adjust($target, $from, -$quantity, $reason, actor: $actor, note: $shared);
            $this->inventory->adjust($target, $to, $quantity, $reason, actor: $actor, note: $shared);
        });
    }

    /**
     * The reorder point. NOT a stock movement and deliberately not through the service: it is a
     * setting about when to warn, not a quantity of anything, and the ledger has no opinion on it.
     */
    private static function setThreshold(int $productId, int $threshold): void
    {
        DB::table('catalog_products')->where('id', $productId)
            ->update(['low_stock_threshold' => max(0, $threshold), 'updated_at' => now()]);
    }

    /** The code an operator recognises, for a skipped row. */
    private static function productCode(int $productId): string
    {
        $code = DB::table('catalog_products')->where('id', $productId)->value('wa_code');

        return is_scalar($code) ? (string) $code : ('#'.$productId);
    }

    /**
     * The cover image of each product on the page, in one read.
     *
     * `is_cover` first, then `sort`, then `id` — the same ordering the products list uses, so the
     * two screens show the SAME picture for a product rather than each picking its own first row.
     *
     * @param  list<object>  $rows
     * @return array<int, string>
     */
    private static function coversForPage(array $rows): array
    {
        $ids = [];
        foreach ($rows as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }
        if ($ids === []) {
            return [];
        }

        $covers = [];
        foreach (
            DB::table('catalog_product_images')->whereIn('product_id', $ids)
                ->orderBy('product_id')->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')
                ->get(['product_id', 'path']) as $raw
        ) {
            $row = Row::cast($raw);
            $productId = Row::int($row, 'product_id');
            if (! array_key_exists($productId, $covers)) {
                $covers[$productId] = Row::str($row, 'path');
            }
        }

        return $covers;
    }

    /**
     * Which storefronts sell each product on the page, in one read.
     *
     * The NAME travels, not the id: "Brand Fashion" is what an operator recognises, and the id is
     * an implementation detail of a table they have never seen.
     *
     * @param  list<object>  $rows
     * @return array<int, list<array{id: int, name: string}>>
     */
    private static function soldOnForPage(array $rows): array
    {
        $ids = [];
        foreach ($rows as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }
        if ($ids === []) {
            return [];
        }

        $names = [];
        foreach (Storefront::query()->orderBy('id')->get(['id', 'name']) as $storefront) {
            $names[Coerce::int($storefront->getAttribute('id'))] = Coerce::str($storefront->getAttribute('name'));
        }

        $out = [];
        foreach (
            DB::table('storefront_product')->whereIn('product_id', $ids)
                ->orderBy('storefront_id')->get(['product_id', 'storefront_id']) as $raw
        ) {
            $row = Row::cast($raw);
            $productId = Row::int($row, 'product_id');
            $storefrontId = Row::int($row, 'storefront_id');
            if (! isset($names[$storefrontId])) {
                continue;
            }
            $out[$productId][] = ['id' => $storefrontId, 'name' => $names[$storefrontId]];
        }

        return $out;
    }

    /**
     * The three narrowing pickers, in the products list's own vocabulary (6.1).
     *
     * @return list<array{value: string, label: string}>
     */
    private static function familyOptions(): array
    {
        $out = [['value' => '', 'label' => ManageText::t('products.all_families', 'كل العائلات')]];
        foreach (Product::FAMILIES as $family) {
            $out[] = ['value' => $family, 'label' => ManageText::t('products.family_'.$family, $family)];
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function brandOptions(): array
    {
        $out = [['value' => '', 'label' => ManageText::t('products.all_brands', 'كل الماركات')]];
        foreach (
            DB::table('catalog_brands as b')
                ->leftJoin('catalog_brand_translations as t', function (JoinClause $join): void {
                    $join->on('t.brand_id', '=', 'b.id')->where('t.locale', '=', 'ar');
                })
                ->leftJoin('catalog_brand_translations as te', function (JoinClause $join): void {
                    $join->on('te.brand_id', '=', 'b.id')->where('te.locale', '=', 'en');
                })
                ->orderBy('b.id')->get(['b.id', 't.name as name_ar', 'te.name as name_en']) as $raw
        ) {
            $row = Row::cast($raw);
            $out[] = [
                'value' => (string) Row::int($row, 'id'),
                // The reader's language, through the same helper every other screen uses.
                'label' => LocalisedName::pick(Row::nstr($row, 'name_ar'), Row::nstr($row, 'name_en'), '#'.Row::int($row, 'id')),
            ];
        }

        return $out;
    }

    /**
     * Category nodes from EVERY storefront, each prefixed by its shop.
     *
     * The warehouse has no storefront of its own, so this picker cannot pick one tree. Both shops
     * have a node called "ساعات"; without the prefix the operator would choose between two
     * identical-looking entries and could not tell which they had picked.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function categoryOptions(): array
    {
        $shops = [];
        foreach (Storefront::query()->orderBy('id')->get(['id', 'name']) as $storefront) {
            $shops[Coerce::int($storefront->getAttribute('id'))] = Coerce::str($storefront->getAttribute('name'));
        }

        $out = [['value' => '', 'label' => ManageText::t('products.all_categories', 'كل التصنيفات')]];
        foreach (
            DB::table('storefront_categories as c')
                ->leftJoin('storefront_category_translations as t', function (JoinClause $join): void {
                    $join->on('t.storefront_category_id', '=', 'c.id')->where('t.locale', '=', 'ar');
                })
                ->leftJoin('storefront_category_translations as te', function (JoinClause $join): void {
                    $join->on('te.storefront_category_id', '=', 'c.id')->where('te.locale', '=', 'en');
                })
                ->orderBy('c.storefront_id')->orderBy('c.path')
                ->get(['c.id', 'c.storefront_id', 't.name as name_ar', 'te.name as name_en']) as $raw
        ) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $shop = $shops[Row::int($row, 'storefront_id')] ?? '';
            $name = LocalisedName::pick(Row::nstr($row, 'name_ar'), Row::nstr($row, 'name_en'), '#'.$id);
            $out[] = ['value' => (string) $id, 'label' => $shop === '' ? $name : $shop.' · '.$name];
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $out = [['value' => '', 'label' => ManageText::t('inventory.every_shop', 'كل المتاجر (المخزون مشترك)')]];
        foreach (Storefront::query()->whereIn('id', Storefront::activeIds())->orderBy('id')->get(['id', 'name']) as $storefront) {
            $out[] = [
                'value' => (string) Coerce::int($storefront->getAttribute('id')),
                'label' => Coerce::str($storefront->getAttribute('name')),
            ];
        }

        return $out;
    }

    /**
     * Brand ids, for the filter whitelist.
     *
     * @return list<string>
     */
    private static function brandValues(): array
    {
        $out = [];
        foreach (DB::table('catalog_brands')->orderBy('id')->pluck('id') as $id) {
            $out[] = (string) Coerce::int($id);
        }

        return $out;
    }

    /**
     * Category node ids across EVERY storefront.
     *
     * This screen has no storefront of its own — the warehouse does not — so the picker offers the
     * nodes of both trees, prefixed by shop name where they would otherwise read the same. A
     * category is a way of narrowing a stock take, not a claim about where a product is sold.
     *
     * @return list<string>
     */
    private static function categoryValues(): array
    {
        $out = [];
        foreach (DB::table('storefront_categories')->orderBy('id')->pluck('id') as $id) {
            $out[] = (string) Coerce::int($id);
        }

        return $out;
    }

    /** @return list<string> */
    private static function storefrontValues(): array
    {
        $out = [];
        foreach (Storefront::activeIds() as $id) {
            $out[] = (string) $id;
        }

        return $out;
    }

    /**
     * A category node and everything beneath it, from the materialised path.
     *
     * @return list<int>
     */
    private static function branchNodeIds(int $categoryId): array
    {
        $path = DB::table('storefront_categories')->where('id', $categoryId)->value('path');
        if (! is_string($path) || $path === '') {
            return [$categoryId];
        }

        $ids = [$categoryId];
        foreach (
            DB::table('storefront_categories')
                ->where('path', 'like', $path.'%')->pluck('id') as $id
        ) {
            $ids[] = Coerce::int($id);
        }

        return array_values(array_unique($ids));
    }

    /**
     * The reasons an OPERATOR may choose, which is not every reason the service knows.
     *
     * `order`, `order_cancel`, `payment_failed` and `transform` are written by machinery — an
     * operator picking "order" by hand would put a movement in the ledger that no order explains,
     * and the reconciliation would be right to call it a finding.
     *
     * @var list<string>
     */
    /**
     * How many rows one bulk stock action may touch.
     *
     * Lower than the products list's 500 on purpose: this writes the LEDGER, and every movement is
     * permanent and signed. A cap is not a performance limit here, it is a blast radius.
     */
    private const BULK_MAX = 100;

    private const ADJUSTMENT_REASONS = ['adjustment', 'restock', 'manual', 'import', 'erp_sync'];

    /** @return list<array{value: string, label: string}> */
    private static function adjustmentReasons(): array
    {
        /*
         * The KEYS are the `reason` column's own values and are never translated — they are
         * written to `inventory_movements.reason` and compared against `ADJUSTMENT_REASONS`.
         * Only the labels move.
         *
         * Four of the five are the vocabulary the ledger screen already renders, so they reuse its
         * keys. `restock` does NOT: the ledger calls it `توريد` and this picker calls it
         * `توريد جديد`, which are two different phrases, and one key cannot hold both.
         */
        $labels = [
            'adjustment' => ManageText::t('inventory.ledger_reason_adjustment', 'تسوية جرد'),
            'restock' => ManageText::t('inventory.reason_restock', 'توريد جديد'),
            'manual' => ManageText::t('common.reason_manual', 'تعديل يدوي'),
            'import' => ManageText::t('common.reason_import', 'استيراد'),
            'erp_sync' => ManageText::t('common.reason_erp_sync', 'مزامنة ERP'),
        ];

        $out = [];
        foreach (self::ADJUSTMENT_REASONS as $reason) {
            $out[] = ['value' => $reason, 'label' => $labels[$reason]];
        }

        return $out;
    }

    /**
     * The variants of the page's products, read once for the page (AGENTS §2.24).
     *
     * @param  list<object>  $rows
     * @return array<int, list<array<string, mixed>>>
     */
    private static function variantsForPage(array $rows): array
    {
        $ids = [];
        foreach ($rows as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }
        if ($ids === []) {
            return [];
        }

        $out = [];
        foreach (
            DB::table('catalog_product_variants')
                ->whereIn('product_id', $ids)
                ->orderBy('product_id')->orderBy('sort')->orderBy('id')
                ->get(['id', 'product_id', 'label', 'sku', 'stock_express', 'stock_market', 'is_active']) as $raw
        ) {
            $row = Row::cast($raw);
            $out[Row::int($row, 'product_id')][] = [
                'id' => Row::int($row, 'id'),
                'label' => Row::nstr($row, 'label'),
                'sku' => Row::nstr($row, 'sku'),
                'express' => Row::int($row, 'stock_express'),
                'market' => Row::int($row, 'stock_market'),
                'is_active' => Row::bool($row, 'is_active'),
            ];
        }

        return $out;
    }

    /**
     * Who moved this stock, as a person reads it.
     *
     * Four cases, and none of them is a bare id:
     *
     *  • a named user — their name, which is what every other screen shows;
     *  • a user whose account has since been deleted — the id is all that is left, so it is
     *    labelled as a user rather than printed as a naked number;
     *  • `system` — the machine did it, and saying so is the honest answer;
     *  • anything else the column grows later — shown verbatim, because a new actor kind is
     *    something to NOTICE rather than to swallow (the rule the whole `labels.ts` file follows).
     */
    /** First and last name, or the e-mail, or nothing — assembled exactly as the users screen does. */
    private static function personName(\stdClass $row): ?string
    {
        $name = trim((Row::nstr($row, 'actor_first') ?? '').' '.(Row::nstr($row, 'actor_last') ?? ''));
        if ($name !== '') {
            return $name;
        }

        return Row::nstr($row, 'actor_email');
    }

    private static function actorLabel(?string $type, ?string $name, ?int $id): string
    {
        if ($type === 'user') {
            return $name ?? ManageText::t('inventory.ledger_actor_gone', 'مستخدم محذوف #:id', ['id' => $id ?? 0]);
        }

        if ($type === null || $type === 'system') {
            return ManageText::t('common.system', 'النظام');
        }

        return $type;
    }

    /** @return array{low: int, out: int} */
    private static function stockAlerts(): array
    {
        return [
            'low' => InventoryService::lowStockProducts()->count(),
            'out' => InventoryService::outOfStockProducts()->count(),
        ];
    }

    /** @return list<array{value: string, label: string}> */
    private static function referenceTypes(): array
    {
        // The rows below carry the `reference_type` COLUMN VALUE as both value and label — a table
        // name the operator matches against the ledger, not a phrase to translate. Only the
        // "any source" row is UI text.
        $out = [['value' => '', 'label' => ManageText::t('inventory.ledger_all_sources', 'كل المصادر')]];
        foreach (
            DB::table('inventory_movements')->whereNotNull('reference_type')
                ->distinct()->orderBy('reference_type')->pluck('reference_type') as $value
        ) {
            if (is_string($value) && $value !== '') {
                $out[] = ['value' => $value, 'label' => $value];
            }
        }

        return $out;
    }

    private static function actor(Request $request): Actor
    {
        $user = $request->user();

        return Actor::user($user === null ? null : Coerce::int($user->getAuthIdentifier()));
    }
}
