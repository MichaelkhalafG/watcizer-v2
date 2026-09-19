<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Access\Role;
use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\ConversionGuard;
use App\Domain\Catalog\FamilyForCategory;
use App\Domain\Catalog\FieldRefusal;
use App\Domain\Catalog\PlacementWriter;
use App\Domain\Catalog\PreSwitch;
use App\Domain\Catalog\ProductIndexer;
use App\Domain\Catalog\ProductSearch;
use App\Domain\Catalog\ProductWriter;
use App\Domain\Catalog\SpecBlocks;
use App\Domain\Catalog\VariantWriter;
use App\Http\Middleware\EnsureStorefrontScope;
use App\Models\Catalog\Product;
use App\Models\Storefront\Storefront;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
use App\Support\FullReplace;
use App\Support\LocalisedName;
use App\Support\ManageText;
use App\Support\Table\TableQuery;
use App\Transform\Row;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * The list's DECLARATION — sortable columns, filter whitelists, search.
     *
     * Extracted (W-3, 2026-09-19) so that `bulk()` can answer "every row matching what is on
     * screen" by re-running the SAME declaration against the screen's query string. That is the
     * whole safety argument for select-all: the ids a bulk action touches are produced by the same
     * whitelists the list renders from, so a hand-written request cannot reach a row the screen
     * would not have shown. Two copies of these lists would be two things to keep in step, and the
     * day they drifted the bulk action would quietly act on a different set from the one selected.
     *
     * @param  list<array<string, mixed>>  $brands
     * @param  list<array<string, mixed>>  $categories
     */
    private function listTable(Request $request, array $brands, array $categories): TableQuery
    {
        return TableQuery::for($request)
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
                'flag' => ['low_stock', 'no_arabic', 'unplaced', 'absent', 'has_variants', 'archived',
                    // …and the one the dropped UNIQUE index used to make impossible: a supplier
                    // code carried by more than one product (2026-10-05).
                    'shared_sku',
                    // Wave 4D, the importer's two: everything a row is MISSING (derived, never
                    // stored — a marker that cannot go stale while somebody fixes the data), and
                    // the Arabic titles a machine wrote, so the team can work through them.
                    // …and M1s: an image that exists but did not process cleanly.
                    'missing_data', 'machine_ar', 'image_problem'],
            ])
            // …and declared VIRTUAL, because neither is a column: `category` is a whole branch of
            // the tree and `flag` is four different predicates. `listQuery()` applies them.
            ->virtual(['category', 'flag'])
            /** @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|Builder  $query */
            ->searchUsing(function (EloquentBuilder|Builder $query, string $term): void {
                self::applySearch($query, $term);
            });
    }

    public function index(Request $request, Storefront $storefront): Response|StreamedResponse
    {
        $brands = self::brandOptions();
        $categories = self::categoryOptions($storefront->id);

        $table = $this->listTable($request, $brands, $categories)
            ->perPage(
                default: config()->integer('catalog.list.per_page'),
                max: config()->integer('catalog.list.per_page_max'),
            )
            /*
             * ── What leaves in the CSV, and what deliberately does not ──────────────────────
             *
             * These are KEYS OF THE ROW THE SCREEN ALREADY RENDERS, so the file is the list the
             * operator is looking at, filters and all. Two consequences worth stating out loud:
             *
             *  • `purchase_price` is absent. Not excluded by a rule — it was never in the list
             *    payload, because the product LIST does not show cost. The form does, behind
             *    `MANAGE_CATALOG`; the list never did and so the export cannot.
             *  • `edit_url`, `cover` and the derived booleans the badges are drawn from are left
             *    out as noise: a URL column in a spreadsheet helps nobody, and `has_image` says
             *    the same thing the cover column would.
             */
            /*
             * The column HEADINGS go through the seam like anything else an operator reads: the
             * file lands on the desk of whoever asked for it, and a spreadsheet of Arabic headings
             * is exactly as unreadable to an English-speaking buyer as the screen was. Most of
             * them reuse the key the LIST already renders above the same column — a second key
             * with the same text is two Englishes waiting to drift.
             */
            ->exportable([
                'wa_code' => ManageText::t('products.wa_code', 'الكود الداخلي'),
                'sku' => ManageText::t('products.sku', 'رقم الموديل (SKU)'),
                'title' => [ManageText::t('common.name_ar', 'الاسم (عربي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['title'] ?? null)['ar'] ?? null)],
                'title_en' => [ManageText::t('common.name_en', 'الاسم (إنجليزي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['title'] ?? null)['en'] ?? null)],
                /*
                 * BOTH languages, like `title` above (2026-09-16). The database stores `ar` and
                 * `en` for every translated thing, and an export that emits one of them loses half
                 * the record — the file is opened to bulk-edit and to send to the client, and both
                 * of those need the pair. A MISSING translation is an EMPTY cell, never the other
                 * language: an empty cell is information, a duplicated one hides the gap.
                 */
                'brand' => [ManageText::t('products.brand_ar', 'الماركة (عربي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['brand'] ?? null)['ar'] ?? null)],
                'brand_en' => [ManageText::t('products.brand_en', 'الماركة (إنجليزي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['brand'] ?? null)['en'] ?? null)],
                'family' => ManageText::t('products.family', 'العائلة'),
                'selling_price' => ManageText::t('common.price', 'السعر'),
                // NOT `products.sale_price`: that one's Arabic is «سعر التخفيض» and this column says
                // «سعر العرض». Same column, two Arabic names — one key would mean one English
                // standing for two different Arabic strings, which is the defect, not the tidy-up.
                'sale_price' => ManageText::t('products.offer_price', 'سعر العرض'),
                'currency' => ManageText::t('common.currency', 'العملة'),
                'stock_express' => ManageText::t('common.stock_express', 'إكسبريس'),
                'stock_market' => ManageText::t('common.stock_market', 'ماركت'),
                'in_stock' => ManageText::t('products.in_stock', 'متوفر'),
                'is_active' => ManageText::t('common.active', 'مفعّل'),
                'is_visible' => [ManageText::t('products.visible_on_this_storefront', 'ظاهر على هذا المتجر'), fn (array $row): string => match ($row['is_visible'] ?? null) {
                    true => ManageText::t('common.yes', 'نعم'),
                    false => ManageText::t('common.no', 'لا'),
                    default => ManageText::t('home.not_added', 'غير مضاف'),
                }],
                'is_featured' => [ManageText::t('products.featured', 'مميّز'), fn (array $row): string => $row['is_featured'] === true
                    ? ManageText::t('common.yes', 'نعم')
                    : ManageText::t('common.no', 'لا')],
                'variants' => ManageText::t('inventory.variants', 'المقاسات/الألوان'),
                'placement' => ManageText::t('banners.category', 'التصنيف'),
                'missing' => [ManageText::t('products.missing_data_filter', 'بيانات ناقصة'), fn (array $row): string => implode(' | ', array_map(
                    static fn (mixed $token): string => Coerce::str($token),
                    Coerce::arr($row['missing'] ?? null),
                ))],
                'machine_ar' => ManageText::t('products.machine_translation', 'ترجمة آلية'),
                'has_image' => ManageText::t('products.has_image', 'له صورة'),
                'updated_at' => ManageText::t('products.last_edited', 'آخر تعديل'),
            ], 'products');

        $filters = $table->resolvedFilters();
        $query = $this->listQuery($storefront->id, $filters);

        $brandNames = self::nameMap('catalog_brands', 'catalog_brand_translations', 'brand_id');
        // One read for the page: the missing-data marker needs to know which brand means "nobody
        // has assigned one yet" (the importer's `Generic`).
        $genericBrand = self::genericBrandId();

        // Filled by the `prepare` callback below, before a single row is mapped. See
        // `TableQuery::paginate()` for why these two are not columns of the list query. The empty
        // state comes from the same function that fills it, so there is one definition of the shape.
        $extras = self::pageExtras([], $storefront->id);
        // Read ONCE for the page, never inside the row mapper.
        $activeStorefronts = Storefront::activeIds();

        /*
         * The row callback and the batch loader are NAMED, because the CSV export runs the very
         * same two. An export that built its own rows would be a second definition of "what this
         * screen shows" — and the first time the two drifted, the file would carry a column the
         * operator's role does not see.
         */
        $prepare = function (array $rows) use (&$extras, $storefront): void {
            $extras = self::pageExtras(Coerce::objectList($rows), $storefront->id);
        };

        $map = function (object $raw) use ($brandNames, $storefront, $activeStorefronts, $genericBrand, &$extras): array {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $cover = $extras['covers'][$id] ?? null;
            $titleAr = Row::nstr($row, 'title_ar');

            // Lowercased to match `pageExtras`' key: the column's collation is case-insensitive
            // and PHP's array lookup is not — the real pair differs by one letter's case.
            $code = Row::nstr($row, 'sku');
            $sharedCode = $code === null || $code === ''
                ? 0
                : ($extras['shared_sku'][mb_strtolower($code)] ?? 0);

            return [
                'id' => $id,
                'wa_code' => Row::str($row, 'wa_code'),
                'sku' => Row::nstr($row, 'sku'),
                /*
                 * How many live products carry this supplier code, counting this one — so `2` is
                 * "shared with one other" and `0`/`1` is "nobody else". Shown beside the code and
                 * selectable with `flag=shared_sku`, because since 2026-10-05 the database allows
                 * it and a thing the database allows has to be a thing the screen can show.
                 */
                'sku_shared' => $sharedCode,
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
                /*
                 * ── wave 4D: what this row is MISSING, and who wrote its Arabic ──────────
                 *
                 * Derived on every render rather than stored, deliberately. A stored marker
                 * would have to be recomputed by every write path in the application, and the
                 * first one that forgot would leave a product wearing a warning it had already
                 * earned its way out of. These six are all answerable from the row in front of
                 * us, so they are always true.
                 *
                 * `machine_ar` is the exception and IS stored, because "a machine wrote this"
                 * is history and cannot be derived from the text. `ProductWriter` clears it the
                 * moment a human edits that translation.
                 */
                'missing' => self::missingFor($row, $cover, $extras['placement'][$id] ?? null, $genericBrand,
                    ($extras['image_problem'][$id] ?? false) === true,
                    array_key_exists($storefront->id, $extras['storefronts'][$id] ?? [])),
                'machine_ar' => Row::nbool($row, 'ar_is_machine') === true,
                'has_image' => $cover !== null,
                'has_stock' => Row::bool($row, 'in_stock'),
                /*
                 * Placement shape on THIS storefront (rehearsal #3):
                 *   'absent'    — not on this storefront at all. NOT a fault and NOT a to-do
                 *                 (D-3): it is the ordinary state of the 7,087 Brand Fashion
                 *                 products when Watchizer is the shop being looked at;
                 *   'none'      — on this storefront, with no category, so it appears in no
                 *                 listing. THIS is the one worth a red badge;
                 *   'root_only' — on the root and nowhere else, no primary category. The
                 *                 transform leaves a legacy product with no `sub_type_id`
                 *                 exactly here, and the site shows it only under the top-level
                 *                 section with a one-step breadcrumb;
                 *   'placed'    — a primary category, the ordinary state.
                 */
                'placement' => self::placementState(
                    $extras['placement'][$id] ?? null,
                    array_key_exists($storefront->id, $extras['storefronts'][$id] ?? []),
                ),
                // Per storefront: true = visible, false = hidden, MISSING = no row at all.
                'visibility' => self::visibilityFor($extras['storefronts'][$id] ?? [], $activeStorefronts),
                'cover' => $cover === null ? null : ImageUrl::src($cover),
                'updated_at' => Row::nstr($row, 'updated_at'),
                'edit_url' => route('manage.products.edit', ['product' => $id, 'storefront' => $storefront->id]),
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map, $prepare);
        }

        return Inertia::render('Manage/Products/Index', [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'brands' => $brands,
            'categories' => $categories,
            'families' => self::familyOptions(),
            'table' => $table->paginate($query, $map, $prepare),
            // Every active storefront, so the list can render one visibility chip each.
            'all_storefronts' => self::activeStorefrontsForList(),
            'pre_switch' => PreSwitch::state('product'),
        ]);
    }

    /**
     * The FOUR placement shapes, named once so the list, the form and the tests agree.
     *
     * `root_only` is the state rehearsal #3 (2026-09-12) put in front of the team: a legacy
     * product with a `category_type_id` and no `sub_type_id` is placed on the ROOT node with
     * `is_primary = 0`. It is a legitimate, served state — the storefront answers its page and
     * gives it a one-step breadcrumb, and its family comes from the root's own name — but it is
     * invisible in every sub-category listing, and until now the list showed it as an ordinary
     * placed product.
     *
     * ── `absent` — the fourth state, and the reason this method was dangerous (D-3 / J-3) ────
     *
     * 7,087 of the catalogue's 7,713 products wore a red `بلا تصنيف` badge whose tooltip told the
     * operator to *"choose a category for it from the product screen or the placement screen"*.
     *
     * They do not need one. They are the Brand Fashion catalogue, and they are **not sold on
     * Watchizer at all** — which the very same row states correctly two columns away as
     * `— Watchizer`. Home has always called the same set `غير مضاف` and reported Watchizer's
     * `بلا تصنيف` as **0**, because `HomeController` counts products that HAVE a
     * `storefront_product` row and no category; this method did not look at that row at all.
     *
     * So: one Arabic phrase, two predicates, two screens, and a 7,087-row disagreement. The
     * dangerous half is the instruction. A diligent junior working that list as a to-do would file
     * the entire Brand Fashion catalogue into the Watchizer taxonomy — the Watchizer tree fills
     * with bonnets and phone chargers, and `غير مضاف`, the number that says what is NOT on this
     * site, collapses to zero and stops being a signal. Nothing would refuse any of it: every
     * individual action is legal.
     *
     * The red badge now means what it says — on this shop, with no category — and that is 0 rows
     * on Watchizer, which is the truth. Being absent is a neutral FACT, badged with the word Home
     * already uses.
     *
     * @param  array{nodes: int, primaries: int}|null  $counts
     * @param  bool  $onStorefront  does a `storefront_product` row exist for this shop at all?
     */
    private static function placementState(?array $counts, bool $onStorefront): string
    {
        if (! $onStorefront) {
            return 'absent';
        }
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

        return $this->withDemotions(
            redirect()->route('manage.products.edit', ['product' => $productId, 'storefront' => $storefront->id])
                ->with('status', ManageText::t('products.created', 'تم إنشاء المنتج.'))
        );
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
        // The record's NAME, which {@see FullReplace} drops into the sentence it shows the
        // operator — so it goes through the seam even though the sentence around it does not
        // belong to this file.
        FullReplace::assert($request, ManageText::t('products.record_name', 'بيانات المنتج'), 'wa_code');

        $productId = Row::int(self::productRow($product), 'id');
        $data = $this->validated($request, $productId);

        try {
            $this->products->update($productId, $data, self::actorId($request));
        } catch (FieldRefusal $e) {
            // A writer refusal becomes an error on the field it named, not a 500 — the same shape
            // the placement writer's refusals already take.
            throw ValidationException::withMessages([$e->field() => $e->getMessage()]);
        }

        $this->saveStorefrontSide($productId, $data);

        return $this->withDemotions(
            redirect()->route('manage.products.edit', ['product' => $productId, 'storefront' => $storefront->id])
                ->with('status', ManageText::t('products.saved', 'تم حفظ المنتج.'))
        );
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
            'action' => ['required', 'string', Rule::in(['activate', 'deactivate', 'archive', 'restore', 'set_threshold'])],
            /*
             * `ids` OR `scope=matching` (W-3, 2026-09-19).
             *
             * The header checkbox selects the 25 rows ON SCREEN, and nothing offered "all 627
             * matching" — so every bulk action was capped at 25 per round trip, and the one this
             * screen most needs (`set_threshold` over 7,578 products) was unusable.
             *
             * "All matching" is NOT a list of ids: 7,578 of them would not fit a request, would
             * blow the 500 cap, and would be stale by the time they arrived. It is a SCOPE — the
             * screen's own query string — re-resolved server-side through the same declaration the
             * list renders from ({@see self::listTable()}). Nothing a caller writes can select a
             * row the screen would not have shown.
             */
            'scope' => ['nullable', 'string', Rule::in(['ids', 'matching'])],
            'ids' => ['required_unless:scope,matching', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'min:1'],
            // The screen's query string, verbatim, so the server can reproduce exactly the rows
            // the operator was looking at. Parsed, then discarded — only the declared whitelists
            // survive into the query.
            'query' => ['nullable', 'string', 'max:2000'],
            /*
             * Required only for `set_threshold`, and bounded by the column (W-2).
             *
             * `min:0` rather than `min:1`: **0 is the value this action exists to set.** 7,578 of
             * 7,713 products sit on the old default of 5 while their stock is 0–3, which is why
             * the low-stock alert covered 97.5% of the catalogue. Turning the alert OFF for the
             * products nobody has set a reorder point for is the single most useful thing this
             * action does, and a validator that refused 0 would forbid exactly that.
             */
            'threshold' => ['required_if:action,set_threshold', 'integer', 'min:0', 'max:65535'],
        ]);

        $action = $request->string('action')->toString();
        $actorId = self::actorId($request);
        $matching = $request->string('scope')->toString() === 'matching';

        $ids = $matching
            ? $this->idsMatching($request, $storefront)
            : Coerce::intList($request->input('ids'));

        if ($ids === []) {
            return back()->with('error', ManageText::t(
                'products.bulk_nothing',
                'لم يُحدَّد أي منتج، فلم يتغيّر شيء.',
            ));
        }

        /*
         * ── Why `set_threshold` is answered in ONE statement, and the others row by row ──────
         *
         * The four original actions each have a per-product consequence that the writer has to
         * compute — a product's visibility gate, its slug, its search row, its audit entry — so
         * they go through `ProductWriter` one at a time and are capped at what a page can select.
         * That cost is the price of the guarantees, and it is documented below.
         *
         * A reorder threshold has none of that. It is one unsigned integer with no translation, no
         * effect on what the storefront shows, and nothing derived from it. Running 7,578 products
         * through the full writer would be ~220,000 queries and several minutes holding row locks,
         * to achieve exactly what one `UPDATE … WHERE id IN (…)` achieves — and the whole reason
         * this action exists (W-2) is that 7,578 is the number that needs fixing.
         *
         * The audit is ONE row for the batch rather than 7,578, which is the same call
         * `CategoryController::reorder()` already makes for the same reason: "who reordered this
         * level?" is a question about the level. "Who set thresholds to 0?" is a question about
         * the batch, and 7,578 identical rows would bury it.
         */
        if ($action === 'set_threshold') {
            return $this->bulkThreshold($request, $ids, $actorId);
        }

        if (count($ids) > 500) {
            return back()->with('error', ManageText::t(
                'products.bulk_too_many',
                'هذا الإجراء يُنفَّذ منتجًا منتجًا، فحدّه :max منتج في المرة. :count منتج مطابق الآن — ضيّق التصفية ثم أعد المحاولة.',
                ['max' => 500, 'count' => count($ids)],
            ));
        }

        /*
         * ── A-BUG-2: one stale id used to crash the batch HALF-APPLIED (2026-09-17) ─────────
         *
         * `currentPayload()` throws for an id that is not in the table, and this loop had no
         * transaction — so ids `[real, 99999999, real]` produced an HTTP 500 with the FIRST product
         * already deactivated and the third untouched. The operator got a crash page and no way to
         * tell how much of their batch had applied; the only way to find out was to go and look.
         *
         * It takes nothing exotic to trigger. A tab left open while a colleague archives a product,
         * or a selection made before someone else's delete, is enough.
         *
         * Two changes, and they answer different halves:
         *
         *  1. **The unknown ids are found FIRST and skipped**, so the common case never throws at
         *     all. Reported rather than silently dropped — an operator who selected 25 and sees "23
         *     done" must be told why, or the number reads as a bug.
         *
         *  2. **The rest runs in ONE transaction**, so anything else that refuses mid-batch — a
         *     writer rule, a deadlock, a constraint — leaves the catalogue exactly as it was rather
         *     than half-changed. "Nothing happened" is a state an operator can act on; "some of it
         *     happened, in an order nobody recorded" is not.
         *
         * The lock cost is accepted deliberately: a 100-id batch is ~2,900 queries and a couple of
         * seconds (the review measured it, and the per-product write cost is on the "live with it"
         * list), so this holds row locks for that long. A partial write is the worse failure, and
         * the selection is capped at one page.
         *
         * No `deleted_at` filter on the existence read: `restore` acts on archived rows, so
         * "exists" here means the row is in the table, which is exactly what the writers require.
         */
        $existing = DB::table('catalog_products')->whereIn('id', $ids)->pluck('id')->all();
        $known = [];
        foreach ($existing as $value) {
            $known[] = Coerce::int($value);
        }
        $missing = array_values(array_diff($ids, $known));
        $targets = array_values(array_intersect($ids, $known));

        $done = 0;
        DB::transaction(function () use ($targets, $action, $actorId, &$done): void {
            foreach ($targets as $id) {
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
        });

        $status = ManageText::t('products.bulk_done', 'تم تنفيذ الإجراء على :count منتجًا.', ['count' => $done]);

        if ($missing !== []) {
            // The COUNT, not the ids: an operator cannot act on `99999999`, and the number is what
            // explains the gap between what they selected and what the first sentence reports.
            $status .= ' '.ManageText::t(
                'products.bulk_skipped_missing',
                'وتُخطّي :count منتجًا لم يعد موجودًا — على الأرجح حُذف أو أُرشف بينما كانت الصفحة مفتوحة. حدّث الصفحة لترى الحالة الجديدة.',
                ['count' => count($missing)],
            );
        }

        return back()->with('status', $status);
    }

    /**
     * Every product id matching what is on the operator's screen (W-3).
     *
     * The screen's query string arrives as data and is re-resolved through the SAME declaration
     * the list renders from, so:
     *
     *  • an unknown filter key is dropped by `filterable()`'s whitelist,
     *  • an unknown value for a known filter is dropped the same way,
     *  • an unknown sort column falls back to the default,
     *  • the search runs through `ProductSearch`, identically.
     *
     * A hand-written request therefore cannot reach a product the screen would not have listed —
     * which is the property that makes "apply to all 7,578 matching" safe to offer at all.
     *
     * Ordering and paging are irrelevant here and deliberately not applied: this is a SET.
     *
     * @return list<int>
     */
    private function idsMatching(Request $request, Storefront $storefront): array
    {
        $params = [];
        parse_str(ltrim(Coerce::str($request->input('query')), '?'), $params);

        // A GET request carrying only the screen's own query string — nothing of the POST body
        // reaches the whitelists, so `action`, `ids` and `threshold` cannot become filters.
        $scoped = Request::create('/', 'GET', $params);

        $table = $this->listTable($scoped, self::brandOptions(), self::categoryOptions($storefront->id));

        /*
         * BOTH halves, and forgetting the second one is a trap this very method fell into.
         *
         * `listQuery()` applies only the VIRTUAL filters — `category` (a whole branch) and `flag`
         * (several predicates). Every plain column filter, and the search, are applied by
         * `TableQuery::apply()`, which the list reaches through `paginate()`. Resolving a scope
         * without calling it produced a query with no filters at all: "all matching watches"
         * silently meant "all 7,713 products", and the first version of the test could not see it
         * because it counted the intersection rather than the whole set.
         *
         * `apply()` also adds ORDER BY, which is harmless here and not worth a second code path.
         */
        $query = $this->listQuery($storefront->id, $table->resolvedFilters());

        $out = [];
        foreach ($table->apply($query)->pluck('p.id') as $value) {
            $out[] = Coerce::int($value);
        }

        return $out;
    }

    /**
     * Set the reorder threshold on a batch, in one statement (W-2).
     *
     * See the note in `bulk()` for why this does not go through `ProductWriter` row by row. The
     * short version: there is nothing per-product to compute, and the batch that needs fixing is
     * 7,578 rows.
     *
     * `updated_by` and `updated_at` are written here rather than left to the column default,
     * because "who changed this and when" is exactly what a bulk edit must not lose.
     *
     * @param  list<int>  $ids
     */
    private function bulkThreshold(Request $request, array $ids, ?int $actorId): RedirectResponse
    {
        $threshold = Coerce::int($request->input('threshold'));

        $done = DB::table('catalog_products')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->update([
                'low_stock_threshold' => $threshold,
                'updated_by' => $actorId,
                'updated_at' => now(),
            ]);

        /*
         * ONE audit row for the batch, like `CategoryController::reorder()` and for the same
         * reason: "who set thresholds to 0?" is a question about the batch, and 7,578 identical
         * rows saying `حد التنبيه: 0 ← 5` would bury the answer rather than record it.
         */
        if ($done > 0) {
            ActivityLog::record(
                'catalog_products', null, ActivityLog::UPDATED,
                ['low_stock_threshold' => null], ['low_stock_threshold' => $threshold],
                label: ManageText::t('products.bulk_threshold_label', ':count منتجًا', ['count' => $done]),
            );
        }

        return back()->with('status', ManageText::t(
            'products.bulk_threshold_done',
            'تم ضبط حد التنبيه على :threshold لـ :count منتجًا.',
            ['count' => $done, 'threshold' => $threshold],
        ));
    }

    /**
     * Archive one product, from its own form (W-6).
     *
     * A soft delete, exactly as the bulk action's `archive` is: order lines, the stock ledger and
     * the legacy id map all point at the row, and §2.9.6 rule 2 is that this application does not
     * delete catalogue history. {@see ProductWriter::archive()} is the one writer for both paths,
     * so "archive" cannot come to mean two different things depending on which screen said it.
     *
     * Lands back on the LIST rather than on the form: the form now shows an archived product, and
     * a screen that stays put after an action looks like a screen where nothing happened (D-20).
     */
    public function destroy(Request $request, Storefront $storefront, int $product): RedirectResponse
    {
        $exists = DB::table('catalog_products')->where('id', $product)->exists();
        abort_if(! $exists, 404);

        $this->products->archive($product, self::actorId($request));

        return redirect()
            ->route('manage.products.index', ['storefront' => $storefront->id])
            ->with('status', ManageText::t('products.archived_one', 'تمت أرشفة المنتج. يمكن إرجاعه من قائمة «المؤرشف».'));
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
                'ar.title as title_ar', 'en.title as title_en', 'ar.is_machine as ar_is_machine',
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
            /*
             * ── `unplaced` now means what its badge says (D-3 / J-3, 2026-09-19) ────────────
             *
             * It used to select "no category row on this storefront" WITHOUT asking whether the
             * product is on this storefront at all — so it returned 7,087 rows on Watchizer while
             * Home, counting the same phrase correctly, reported 0. The filter and the badge were
             * both telling the operator to fix 7,087 products that have nothing wrong with them.
             *
             * It is now the same predicate Home uses: ON this shop, and in no category here. The
             * "not on this shop" set has its own entry below, under its own honest name.
             *
             * `limit(1)` for the same reason as the category filter above: an un-flattened
             * subquery keeps the driving table's primary-key ordering. `NOT EXISTS` is an
             * anti-join — no semi-join for MariaDB to materialise — so these keep the driving
             * table's ordering without a hint (all measured indexed).
             */
            'unplaced' => $query
                ->whereExists(function (Builder $sub) use ($storefrontId): void {
                    $sub->from('storefront_product as sp2')
                        ->whereColumn('sp2.product_id', 'p.id')
                        ->where('sp2.storefront_id', $storefrontId)
                        ->selectRaw('1')
                        ->limit(1);
                })
                ->whereNotExists(function (Builder $sub) use ($storefrontId): void {
                    $sub->from('storefront_category_product as scp2')
                        ->whereColumn('scp2.product_id', 'p.id')
                        ->where('scp2.storefront_id', $storefrontId)
                        ->selectRaw('1')
                        ->limit(1);
                }),
            // "Not sold here." A fact, not a fault — and the list the team uses when they are
            // deciding what to ADD to this shop, which is a different job from fixing anything.
            'absent' => $query->whereNotExists(function (Builder $sub) use ($storefrontId): void {
                $sub->from('storefront_product as sp2')
                    ->whereColumn('sp2.product_id', 'p.id')
                    ->where('sp2.storefront_id', $storefrontId)
                    ->selectRaw('1')
                    ->limit(1);
            }),
            'has_variants' => $query->whereExists(function (Builder $sub): void {
                $sub->from('catalog_product_variants as v2')->whereColumn('v2.product_id', 'p.id')->selectRaw('1')->limit(1);
            }),
            /*
             * "Show me everything that is not finished." The SEVEN conditions are exactly the seven
             * markers the row renders, because a filter that selects a different set from the one
             * the badge shows is worse than no filter (§4). image_problem joined them in M1s, and
             * it had to join here at the same time for that reason.
             */
            'missing_data' => $query->where(function (Builder $inner) use ($storefrontId): void {
                $generic = self::genericBrandId();
                $inner->whereNull('p.sku')
                    ->orWhere('p.selling_price', '<=', 0)
                    ->orWhereNull('ar.title')
                    ->orWhere('ar.title', '=', '')
                    ->orWhereNotExists(function (Builder $sub): void {
                        $sub->from('catalog_product_images as i2')
                            ->whereColumn('i2.product_id', 'p.id')->selectRaw('1')->limit(1);
                    })
                    ->orWhereNotExists(function (Builder $sub) use ($storefrontId): void {
                        $sub->from('storefront_category_product as scp3')
                            ->whereColumn('scp3.product_id', 'p.id')
                            ->where('scp3.storefront_id', $storefrontId)
                            ->selectRaw('1')->limit(1);
                    });
                $inner->orWhereExists(function (Builder $sub): void {
                    $sub->from('catalog_product_images as i4')
                        ->whereColumn('i4.product_id', 'p.id')
                        ->whereNotNull('i4.renditions_failed')
                        ->selectRaw('1')->limit(1);
                });
                if ($generic !== null) {
                    $inner->orWhere('p.brand_id', $generic);
                }
            }),
            'machine_ar' => $query->where('ar.is_machine', 1),
            /*
             * ── A supplier code two products carry (2026-10-05) ─────────────────────────────
             *
             * This filter exists because the UNIQUE index that used to forbid this was dropped:
             * the SKU is the manufacturer's code and two of our products may legitimately share
             * one. The constraint moved out of the schema and onto the screen, and this is the
             * screen half — without it, a duplicate is simply invisible until a customer finds it.
             *
             * `limit(1)` for the same reason as every EXISTS above: an un-flattened subquery keeps
             * the driving table's primary-key ordering. The probe is served by
             * `catalog_products_sku_index`, which the same migration added in the unique index's
             * place precisely so this question stays cheap.
             *
             * The comparison is the COLUMN's own collation, which is case-insensitive — so
             * `Ap0012` and `AP0012` are one code here, exactly as the database read them when it
             * still refused the pair.
             */
            'shared_sku' => $query
                ->whereNotNull('p.sku')
                ->where('p.sku', '<>', '')
                ->whereExists(function (Builder $sub): void {
                    $sub->from('catalog_products as dup')
                        ->whereColumn('dup.sku', 'p.sku')
                        ->whereColumn('dup.id', '<>', 'p.id')
                        ->whereNull('dup.deleted_at')
                        ->selectRaw('1')
                        ->limit(1);
                }),
            /*
             * M1s. An EXISTS over the image rows, like the missing_data conditions beside it —
             * renditions_failed is nullable and null on the overwhelming majority of rows, so this
             * selects the handful that need somebody.
             */
            'image_problem' => $query->whereExists(function (Builder $sub): void {
                $sub->from('catalog_product_images as i3')
                    ->whereColumn('i3.product_id', 'p.id')
                    ->whereNotNull('i3.renditions_failed')
                    ->selectRaw('1')->limit(1);
            }),
            default => null,
        };

        return $query;
    }

    /**
     * What this product is missing, as the tokens the badge renders and the filter selects.
     *
     * The same six the importer marks (`ImportReport::MISSING_*`), because the import and the
     * screen must agree about what "not finished" means — the import writes nothing for this, so
     * agreement is the only thing that keeps them honest.
     *
     * @param  stdClass  $row  a list-query row, already cast
     * @param  array{nodes: int, primaries: int}|null  $placement  this storefront's placement counts
     * @param  int|null  $generic  the Generic brand's id, read once for the page
     * @param  bool  $onStorefront  is the product on this shop at all? (D-3)
     * @return list<string>
     */
    private static function missingFor(stdClass $row, ?string $cover, ?array $placement, ?int $generic, bool $imageProblem = false, bool $onStorefront = true): array
    {
        $out = [];

        if (Row::nstr($row, 'sku') === null) {
            $out[] = 'sku';
        }
        if ($cover === null) {
            $out[] = 'image';
        }
        /*
         * It HAS an image and something is wrong with it (M1s) — a rendition the encoder wrote as
         * zero bytes. Separate from image because the action differs: image means find a
         * photo, image_problem means the photo we have did not process and wants re-uploading.
         */
        if ($imageProblem) {
            $out[] = 'image_problem';
        }
        if (trim(Row::nstr($row, 'title_ar') ?? '') === '') {
            $out[] = 'arabic';
        }
        /*
         * `nodes === 0` as well as null: "on the root and nowhere else" is the same problem for a
         * customer as no placement at all.
         *
         * …but only for a product that IS on this shop (D-3). A product with no
         * `storefront_product` row here is not missing a category — it is not sold here, and
         * "missing: category" on 7,087 rows was the badge that told a junior to file the whole
         * Brand Fashion catalogue into the Watchizer tree.
         */
        if ($onStorefront && ($placement === null || $placement['nodes'] === 0)) {
            $out[] = 'category';
        }
        if ($generic !== null && Row::int($row, 'brand_id') === $generic) {
            $out[] = 'brand';
        }
        if ((float) Row::str($row, 'selling_price') <= 0) {
            $out[] = 'price';
        }

        return $out;
    }

    /**
     * The `Generic` brand, or null when the importer has never run.
     *
     * Read once per page and passed down — not memoised in a static. A static would be read once
     * per PROCESS, which is right for a web request and wrong for a test suite, where the brand
     * may not exist yet when the first list renders and does by the time the tenth does.
     */
    private static function genericBrandId(): ?int
    {
        return Coerce::nint(DB::table('catalog_brands')->where('slug', 'generic')->value('id'));
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
     * @return array{covers: array<int, string>, variants: array<int, int>, storefronts: array<int, array<int, bool>>, placement: array<int, array{nodes: int, primaries: int}>, image_problem: array<int, bool>, shared_sku: array<string, int>}
     */
    private static function pageExtras(array $rows, ?int $storefrontId = null): array
    {
        $ids = [];
        foreach ($rows as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }
        if ($ids === []) {
            return ['covers' => [], 'variants' => [], 'storefronts' => [], 'placement' => [], 'image_problem' => [], 'shared_sku' => []];
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

        /*
         * ── Supplier codes this page shares with another product (2026-10-05) ───────────────
         *
         * One grouped read for the page's codes, not one per row. It answers the question the
         * dropped UNIQUE index used to answer by refusing: *is anybody else carrying this?*
         *
         * Keyed LOWERCASE on both sides, and that is load-bearing: the column's collation is
         * case-insensitive, so MariaDB groups `Ap0012` with `AP0012` and hands back whichever
         * casing it met first — while PHP's array lookup is case-sensitive and would miss the
         * other row. The real pair in this catalogue differs by exactly one letter's case.
         */
        $sharedSku = [];
        $codes = [];
        foreach ($rows as $raw) {
            $code = Row::nstr(Row::cast($raw), 'sku');
            if ($code !== null && $code !== '') {
                $codes[mb_strtolower($code)] = $code;
            }
        }
        /*
         * Run unconditionally, even when the page holds no codes at all.
         *
         * Skipping it on an empty list looks like a free optimisation and is not: it makes the
         * number of queries a page costs depend on its CONTENT, and `ProductListTest` asserts the
         * opposite — that a page of 100 costs exactly what a page of 25 costs, which is the
         * property that keeps this list free of N+1 reads. An empty `whereIn` is one round trip
         * that returns nothing; a conditional read is a count that moves for reasons nobody can
         * see from the outside.
         */
        foreach (
            DB::table('catalog_products')
                ->whereIn('sku', array_values($codes))
                ->whereNull('deleted_at')
                ->groupBy('sku')
                ->havingRaw('COUNT(*) > 1')
                ->get(['sku', DB::raw('COUNT(*) as shared')]) as $raw
        ) {
            $row = Row::cast($raw);
            $sharedSku[mb_strtolower(Row::str($row, 'sku'))] = Row::int($row, 'shared');
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
        /*
         * …and the image PROBLEM comes off the same read (M1s), because it is a fact about a row
         * this query is already fetching. Any image of the product counts, not only the cover: a
         * broken gallery rendition is still a broken image on the product page, and asking the team
         * to re-upload "the one that is wrong" is better served by them opening the product than by
         * this list guessing which.
         *
         * `renditions_failed` is the one STORED image marker, and it has to be. The others are
         * derived per render (see `missingFor()`), which works because each is a question SQL can
         * ask. "Does a file on disk have zero bytes" is not — answering it per row would mean
         * stat()-ing several files per product on every page of the list.
         */
        $imageProblem = [];
        foreach (
            DB::table('catalog_product_images')
                ->whereIn('product_id', $ids)
                ->orderBy('product_id')->orderByDesc('is_cover')->orderBy('sort')->orderBy('id')
                ->get(['product_id', 'path', 'renditions_failed']) as $raw
        ) {
            $row = Row::cast($raw);
            $productId = Row::int($row, 'product_id');
            if (! array_key_exists($productId, $covers)) {
                $covers[$productId] = Row::str($row, 'path');
            }
            if (Row::nstr($row, 'renditions_failed') !== null) {
                $imageProblem[$productId] = true;
            }
        }

        return ['covers' => $covers, 'variants' => $variants, 'storefronts' => $visibility,
            'placement' => $placement, 'image_problem' => $imageProblem, 'shared_sku' => $sharedSku];
    }

    /**
     * Search: delegated to {@see ProductSearch}, which is the ONE implementation.
     *
     * It used to live here as a private method, which is why the stock screen — a second list of
     * the same products — could not use it and searched codes only (D-8). Moving it out was the
     * fix; this wrapper stays because the table builder wants a closure and the alias pair this
     * screen joins under is its own business.
     *
     * @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|Builder  $query
     */
    private static function applySearch(EloquentBuilder|Builder $query, string $term): void
    {
        ProductSearch::apply($query, $term);
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
            /*
             * Who may type a slug (item 6, 2026-09-18) — a different rule from `slug_lock`, which
             * is about the calendar. The product form writes a slug PER STOREFRONT, through the
             * same `PlacementWriter` the placement screen uses, so it is refused in the same place
             * and has to say so in the same way. A field that looks live and fails on save is how
             * an operator learns to distrust the screen.
             */
            'slug_role' => [
                'allowed' => Gate::allows(Role::EDIT_PRODUCT_SLUG),
                'message' => Gate::allows(Role::EDIT_PRODUCT_SLUG) ? null : ManageText::t(
                    'placement.slug_admin_only',
                    'تغيير رابط المنتج متاح للمدير فقط. الرابط هو عنوان الصفحة عند العملاء وفي جوجل، وتغييره ينقل الصفحة ويعتمد على تحويل 301 لكي لا تصبح 404. لو الرابط يحتاج تعديلًا اطلب ذلك من المدير.',
                ),
            ],
            // Everything that keeps the product off the storefront, not just the Arabic half.
            'missing_arabic' => $productId === null ? [] : $this->placements->missingForVisibility($productId),
            // The storefront whose primary category decides the family and therefore the spec
            // block, named so the screen can say it out loud.
            'family_storefront' => self::familyStorefront(),
            // The family and the REASON, so the screen can say why this block is on screen — plus
            // the family the product is SAVED with, which is what lets the form warn that a
            // category change will discard the block it is showing.
            'family' => $this->families->explain($primaryNode, $product === null ? null : Row::str($product, 'family')),
            'blocks' => SpecBlocks::all(),
            // Which colour questions each family is asked (J-6). Every family at once, because
            // the form re-derives the family in the browser when the primary category changes.
            'color_roles' => SpecBlocks::colorRoles(),
            'lookups' => self::allLookupOptions(),
            // EVERY option carries the family the SERVER resolves for it (task 4.1). The browser
            // looks it up when the category changes instead of mirroring the rule in TypeScript —
            // the mirror is what was broken, and a second implementation of one rule always is.
            'categories' => $this->categoryOptionsWithFamily($storefront->id),
            'brands' => self::brandOptions(),
            /*
             * Brand and category names in BOTH locales, keyed by id (item 8, 2026-09-18).
             *
             * The SEO generator writes an Arabic sentence and an English one, so it needs each name
             * in each language. The pickers above carry a single combined label — Arabic first with
             * the English in the hint position — which is right for a dropdown and useless for
             * composing a sentence in one language.
             *
             * Sent as maps rather than resolved on the server because the generator runs on what is
             * ON SCREEN: the brand the operator has just selected, on a form that may not be saved
             * yet, or a product that does not exist at all. 78 brands and 61 nodes — the payload is
             * a rounding error next to the spec blocks already here.
             */
            'brand_names' => self::namePairs('catalog_brands', 'catalog_brand_translations', 'brand_id'),
            'category_names' => self::categoryNamePairs(),
            /*
             * The same, for the three lookups the rebuilt generator describes the product WITH
             * (item 5, 2026-09-19): the audience, the material and the colour.
             *
             * The brief asked for keywords "drawn from what the product IS (brand, family,
             * material, colour, gender, category)". The form already holds those as ids — it draws
             * pickers with them — but a picker's label is in the ACTIVE locale only, and a
             * generator that writes an Arabic sentence and an English one in the same click needs
             * both names at once. So the three lists travel as name pairs, exactly as the brands
             * and the category nodes do.
             *
             * Three lists and not eleven: the rest (shapes, closures, movements, units) do not
             * appear in a sentence anybody would click.
             */
            'lookup_names' => [
                'genders' => self::namePairs('catalog_genders', 'catalog_gender_translations', 'gender_id'),
                'colors' => self::namePairs('catalog_colors', 'catalog_color_translations', 'color_id'),
                'materials' => self::namePairs('catalog_materials', 'catalog_material_translations', 'material_id'),
            ],
            'families' => self::familyOptions(),
            'variants' => [
                'rows' => $productId === null ? [] : $this->variants->rows($productId),
                'state' => $productId === null
                    ? ['has_variants' => false, 'may_convert' => false, 'reason' => ManageText::t('variants.save_product_first_reason', 'احفظ المنتج أولًا ثم أضف المقاسات.'), 'write_switch_completed' => ConversionGuard::writeSwitchCompleted(), 'legacy_backed' => false]
                    : $this->conversion->state($productId),
            ],
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
            /*
             * NOT unique, since 2026-10-05.
             *
             * `sku` is the manufacturer's code: it comes from outside, it may be empty, and two of
             * our products may legitimately carry one — the developer's own definition, and the
             * merge migration hit a real pair (413 and 414, `Ap0012`/`AP0012`). The UNIQUE index
             * that contradicted it has been dropped, and this rule went with it: a form that
             * refuses a true value is the same mistake one layer up.
             *
             * Duplicates are not unnoticed, they are SHOWN — the list marks a shared code beside
             * it and `?filters[flag]=shared_sku` selects every row carrying one, so somebody can
             * judge whether it is a supplier's habit or a typo. `max:64` is the column.
             */
            'sku' => ['nullable', 'string', 'max:64'],

            'hs_code' => ['nullable', 'string', 'max:32'],
            'brand_id' => ['required', 'integer', Rule::exists('catalog_brands', 'id')],
            'grade_id' => ['nullable', 'integer', Rule::exists('catalog_grades', 'id')],
            'purchase_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'selling_price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'sale_price' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'currency' => ['required', 'string', 'size:3'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'warranty_years' => ['nullable', 'integer', 'min:0', 'max:255'],
            'is_active' => ['required', 'boolean'],
            'search_keywords' => ['nullable', 'string', 'max:65535'],

            'title.ar' => ['required', 'string', 'min:2', 'max:255'],
            'title.en' => ['required', 'string', 'min:2', 'max:255'],
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
                $errors[$field] = ManageText::t(
                    'products.primary_category_required',
                    'اختر التصنيف الأساسي من بين التصنيفات المحددة: هو الذي يحدد مسار المنتج على هذا المتجر، ومنه تُشتق مواصفاته.',
                );

                continue;
            }
            if ($primary !== null && ! in_array($primary, $ids, true)) {
                $errors[$field] = ManageText::t(
                    'products.primary_category_must_be_chosen',
                    'التصنيف الأساسي يجب أن يكون واحدًا من التصنيفات المحددة.',
                );

                continue;
            }
            if ($creating && (int) $key === $decider && $ids === []) {
                $errors["storefronts.{$key}.category_ids"] = ManageText::t(
                    'products.category_required_to_create',
                    'اختر تصنيفًا واحدًا على الأقل: التصنيف هو ما يحدد نوع المنتج وقائمة مواصفاته، ولا يمكن إنشاء منتج بلا تصنيف.',
                );
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Say it out loud when a save took a product off sale.
     *
     * ── Why this exists ─────────────────────────────────────────────────────────────────────
     *
     * The completeness gate demotes rather than refuses, which means a product can go from visible
     * to hidden as a side effect of the save the operator just made — they blank a description,
     * press save, and the product leaves the shop. The developer's instruction was that this must
     * never be quiet: *"Make sure the operator is told clearly at the moment it happens ('this
     * product is no longer visible — it needs X'), so it is never a silent disappearance."*
     *
     * It rides the ERROR channel, beside the green "saved". The save genuinely succeeded, so the
     * success message stays; the demotion is the part that needs acting on, and a warning the
     * colour of success is a warning nobody reads.
     */
    private function withDemotions(RedirectResponse $response): RedirectResponse
    {
        $demotions = $this->placements->demotions();
        if ($demotions === []) {
            return $response;
        }

        $names = DB::table('storefronts')->whereIn('id', array_keys($demotions))->pluck('name', 'id');
        $separator = ManageText::t('common.list_separator', '، ');

        $lines = [];
        foreach ($demotions as $storefrontId => $missing) {
            $lines[] = ManageText::t(
                'products.demoted_from_storefront',
                'المنتج لم يعد ظاهرًا على :storefront — ينقصه: :fields.',
                [
                    'storefront' => Coerce::str($names[$storefrontId] ?? (string) $storefrontId),
                    'fields' => implode($separator, $missing),
                ],
            );
        }

        return $response->with('error', implode(' ', $lines));
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

    /**
     * One product's placement shape on one storefront: placed / root_only / none.
     *
     * `absent` never comes back from here, and that is not an omission. This feeds the product
     * FORM, where each storefront has its own section and being on a shop is expressed by that
     * section's own "add to this shop" control — so the fourth state (D-3) would be a second way
     * of saying something the section already says with a switch. The LIST has no such control,
     * which is exactly why it needs the word.
     */
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

        return self::placementState(
            ['nodes' => Row::int($cast, 'nodes'), 'primaries' => Row::int($cast, 'primaries')],
            onStorefront: true,
        );
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

    /**
     * `id => {ar, en}` for a lookup table and its translations (item 8).
     *
     * @return array<int, array{ar: string, en: string}>
     */
    private static function namePairs(string $master, string $translations, string $fk): array
    {
        $out = [];
        foreach (
            DB::table($master.' as m')
                ->leftJoin($translations.' as ar', function (JoinClause $join) use ($fk): void {
                    $join->on('ar.'.$fk, '=', 'm.id')->where('ar.locale', '=', 'ar');
                })
                ->leftJoin($translations.' as en', function (JoinClause $join) use ($fk): void {
                    $join->on('en.'.$fk, '=', 'm.id')->where('en.locale', '=', 'en');
                })
                ->get(['m.id', 'ar.name as name_ar', 'en.name as name_en']) as $raw
        ) {
            $row = Row::cast($raw);
            $out[Row::int($row, 'id')] = [
                'ar' => Row::nstr($row, 'name_ar') ?? '',
                'en' => Row::nstr($row, 'name_en') ?? '',
            ];
        }

        return $out;
    }

    /**
     * The same for category nodes, across EVERY storefront.
     *
     * Not narrowed to one storefront on purpose: the form renders a section per storefront and the
     * generator may be asked about the primary category of any of them.
     *
     * @return array<int, array{ar: string, en: string}>
     */
    private static function categoryNamePairs(): array
    {
        $out = [];
        foreach (
            DB::table('storefront_categories as c')
                ->leftJoin('storefront_category_translations as ar', function (JoinClause $join): void {
                    $join->on('ar.storefront_category_id', '=', 'c.id')->where('ar.locale', '=', 'ar');
                })
                ->leftJoin('storefront_category_translations as en', function (JoinClause $join): void {
                    $join->on('en.storefront_category_id', '=', 'c.id')->where('en.locale', '=', 'en');
                })
                ->get(['c.id', 'ar.name as name_ar', 'en.name as name_en']) as $raw
        ) {
            $row = Row::cast($raw);
            $out[Row::int($row, 'id')] = [
                'ar' => Row::nstr($row, 'name_ar') ?? '',
                'en' => Row::nstr($row, 'name_en') ?? '',
            ];
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function familyOptions(): array
    {
        // One label per family in Product::FAMILIES — the list is exhaustive by construction,
        // so a family added there without a label here is a PHPStan error rather than a screen
        // showing the raw key.
        // The very keys the product form's family picker renders, so the filter and the field
        // cannot end up calling one family two things.
        $labels = [
            'watch' => ManageText::t('products.family_watch', 'ساعات'),
            'fashion' => ManageText::t('products.family_fashion', 'أزياء'),
            'bag' => ManageText::t('products.family_bag', 'حقائب'),
            'wallet' => ManageText::t('products.family_wallet', 'محافظ'),
            'perfume' => ManageText::t('products.family_perfume', 'عطور'),
            'electronics' => ManageText::t('products.family_electronics', 'إلكترونيات'),
            'other' => ManageText::t('products.family_other', 'أخرى'),
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
     * @return list<array{value: string, label: string, name: string, en: string, depth: int, path: string, selectable: bool, family: string, family_reason: string}>
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
                'name' => $option['name'],
                'en' => $option['en'],
                'depth' => $option['depth'],
                'path' => $option['path'],
                'selectable' => $option['selectable'],
                // A node with a malformed `path` has no family here; the SAVE refuses it loudly
                // rather than the dropdown guessing one.
                'family' => $resolved === null ? '' : $resolved['family'],
                'family_reason' => $resolved === null
                    ? ManageText::t(
                        'products.family_reason_bad_path',
                        'مسار هذا التصنيف غير سليم في قاعدة البيانات — لا يمكن اشتقاق العائلة منه.',
                    )
                    : $resolved['reason'],
            ];
        }

        return $out;
    }

    /**
     * Every category of one storefront, deepest-path order, labelled for a dropdown.
     *
     * `label` carries the `— ` depth prefix because a native `<select>` has no other way to show
     * nesting; `name` is the same text without it, for the tree picker, which indents properly.
     * `en` is carried too so the picker can be SEARCHED in either language — the team is bilingual
     * and half of them will type "watches" into a tree labelled «ساعات».
     *
     * @return list<array{value: string, label: string, name: string, en: string, depth: int, path: string, selectable: bool}>
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
            ->get(['c.id', 'c.depth', 'c.path', 'c.slug', 'c.legacy_source', 'ar.name as name_ar', 'en.name as name_en']);

        /*
         * ── The dead taxonomy, and why it is offered but not pickable (J-9) ─────────────────
         *
         * `شجرة التصنيفات القديمة` is a parked copy of the pre-migration tree. Nothing on either
         * storefront lists from it — measured: 7 nodes per shop, **0 products** — and it was
         * offered in both category pickers exactly like a live section. A product filed there is
         * filed into a taxonomy the storefront will never read, and it looks completely normal on
         * the form while it happens.
         *
         * Marked rather than removed. Removing it would hide a placement that already exists from
         * the one screen that could take it off — and the picker re-enables any node the product is
         * ALREADY in, precisely so a mistake made before today can still be undone.
         */
        $legacyRoots = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            if (Row::nstr($row, 'legacy_source') === 'category_root') {
                $legacyRoots[] = Row::str($row, 'path');
            }
        }

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $depth = Row::int($row, 'depth');
            $path = Row::str($row, 'path');
            /*
             * The READER's language, not Arabic-always (2026-10-05).
             *
             * This one line built four things — the form's category picker, its primary-category
             * select, the products list's category filter and the placement tree — and every one
             * of them showed an English operator sixty-one Arabic names. The client could not have
             * corrected it: by the time the option reached the browser it was a single string.
             */
            $en = trim(Row::nstr($row, 'name_en') ?? '');
            $label = LocalisedName::pick(
                Row::nstr($row, 'name_ar'),
                $en,
                Row::nstr($row, 'slug') ?? ('#'.$id),
            );

            $inDeadTree = false;
            foreach ($legacyRoots as $root) {
                if (str_starts_with($path, $root)) {
                    $inDeadTree = true;

                    break;
                }
            }

            $out[] = [
                'value' => (string) $id,
                'label' => str_repeat('— ', max(0, $depth - 1)).$label,
                'name' => $label,
                'en' => $en,
                'depth' => $depth,
                'path' => $path,
                'selectable' => ! $inDeadTree,
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
            // Interpolated rather than a suffix concatenated onto the name: in English the marker
            // belongs after the name, in another language it may not, and a bare ' (…)' fragment
            // is not a string anybody can translate.
            $out[] = ['value' => (string) $id, 'label' => Row::bool($row, 'is_active')
                ? $name
                : ManageText::t('products.storefront_option_inactive', ':name (معطّل)', ['name' => $name])];
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

    private static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
