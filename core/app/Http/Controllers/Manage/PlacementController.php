<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Access\Role;
use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\FieldRefusal;
use App\Domain\Catalog\PlacementWriter;
use App\Domain\Catalog\PreSwitch;
use App\Models\Storefront\Storefront;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
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
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * /manage/storefronts/{storefront}/placement — visibility, ordering, featured, slug and the
 * primary category, for one storefront (scope item 5).
 *
 * The product FORM edits one product's placement as part of saving it. This screen is the other
 * direction: one storefront, many products, the columns the team actually sweeps through before a
 * season — hide these twelve, feature those three, re-order a category.
 *
 * ── The three things it has to explain, not just enforce ─────────────────────────────────────
 *
 *  1. **One primary category per (storefront, product)** — a database invariant since M1d. The
 *     screen shows which category is primary and makes choosing another one a single action that
 *     demotes the old one ({@see PlacementWriter::place()}), because the alternative is a 1062
 *     the team reads as "the dashboard is broken".
 *  2. **Arabic before visible** (AGENTS §2.17). A product with no Arabic title cannot be made
 *     visible; the row says so and the toggle is refused on the server with the missing field
 *     named. Fallback is OFF, so publishing without Arabic ships a hole to an Arabic-first shop.
 *  3. **Changing a slug creates a redirect.** The screen warns before the save, and the save
 *     writes the 301 (`storefront_redirects`, source `slug_change`) — the same table the
 *     transform's A-17 twin rule writes into.
 *
 * `EnsureStorefrontScope` guards the group: the URL names the storefront, so a caller scoped
 * elsewhere gets 404.
 */
final class PlacementController
{
    private const SORTABLE = ['sp.sort_order', 'sp.is_visible', 'sp.is_featured', 'p.id', 'p.wa_code', 'p.selling_price'];

    public function __construct(private readonly PlacementWriter $placements) {}

    public function index(Request $request, Storefront $storefront): Response|StreamedResponse
    {
        $categories = self::categoryOptions($storefront->id);

        $table = TableQuery::for($request)
            ->sortable(self::SORTABLE, default: 'sp.sort_order')
            ->filterable([
                'sp.is_visible' => ['0', '1'],
                'sp.is_featured' => ['0', '1'],
                'category' => array_map(fn (array $o): string => $o['value'], $categories),
                'flag' => ['no_arabic', 'no_primary', 'unplaced'],
            ])
            ->virtual(['category', 'flag'])
            /** @param  EloquentBuilder<covariant \Illuminate\Database\Eloquent\Model>|Builder  $query */
            ->searchUsing(function (EloquentBuilder|Builder $query, string $term): void {
                $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

                $query->where(function (Builder $inner) use ($escaped): void {
                    $inner->where('p.wa_code', 'like', '%'.$escaped.'%')
                        ->orWhere('sp.slug', 'like', '%'.$escaped.'%')
                        ->orWhere('ar.title', 'like', '%'.$escaped.'%')
                        ->orWhere('en.title', 'like', '%'.$escaped.'%');
                });
            })
            ->perPage(default: 25, max: 100)
            // A placement worksheet: which products sit where, in what order, and which of them a
            // customer cannot reach. `in_carts` travels because it is the number that decides
            // whether hiding a row is safe — the export is what the team works from off-screen.
            ->exportable([
                'wa_code' => ManageText::t('products.wa_code', 'الكود الداخلي'),
                /*
                 * BOTH languages (2026-09-16). The map already carries the pair; the export was
                 * dropping the English half, which is exactly the record somebody opening this file
                 * to bulk-edit needs. A missing translation stays an EMPTY cell rather than falling
                 * back to the other language — an empty cell is information, a duplicated one hides
                 * the gap.
                 */
                'title' => [ManageText::t('common.name_ar', 'الاسم (عربي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['title'] ?? null)['ar'] ?? null)],
                'title_en' => [ManageText::t('common.name_en', 'الاسم (إنجليزي)'), fn (array $row): string => Coerce::str(Coerce::arr($row['title'] ?? null)['en'] ?? null)],
                'slug' => ManageText::t('placement.slug', 'الرابط'),
                'sort_order' => ManageText::t('common.sort', 'الترتيب'),
                'placements' => ManageText::t('placement.category_count', 'عدد التصنيفات'),
                'primary_category_id' => ManageText::t('products.primary_category', 'التصنيف الأساسي'),
                'is_visible' => ManageText::t('common.visible', 'ظاهر'),
                'is_featured' => ManageText::t('placement.featured', 'مميّز'),
                'has_arabic' => ManageText::t('placement.has_arabic_name', 'له اسم عربي'),
                'in_carts' => ManageText::t('placement.in_open_carts', 'في سلات مفتوحة'),
                'selling_price' => ManageText::t('common.price', 'السعر'),
            ], 'placement');

        $filters = $table->resolvedFilters();
        $query = $this->listQuery($storefront->id, $filters);

        // Filled by the `prepare` callback below. Same reason as the product list: three
        // correlated sub-selects in a list query are three queries per MATCHING row, not per
        // shown row (`TableQuery::paginate()`).
        $extras = self::pageExtras([], $storefront->id);

        $prepare = function (array $rows) use (&$extras, $storefront): void {
            $extras = self::pageExtras(Coerce::objectList($rows), $storefront->id);
        };

        $map = function (object $raw) use (&$extras): array {
            $row = Row::cast($raw);
            $productId = Row::int($row, 'id');
            $cover = $extras['covers'][$productId] ?? null;
            $titleAr = Row::nstr($row, 'title_ar');

            return [
                'product_id' => $productId,
                'wa_code' => Row::str($row, 'wa_code'),
                'title' => ['ar' => $titleAr ?? '', 'en' => Row::nstr($row, 'title_en') ?? ''],
                'has_arabic' => $titleAr !== null && trim($titleAr) !== '',
                'is_visible' => Row::bool($row, 'is_visible'),
                'is_featured' => Row::bool($row, 'is_featured'),
                'sort_order' => Row::int($row, 'sort_order'),
                'slug' => Row::str($row, 'slug'),
                'primary_category_id' => $extras['primary'][$productId] ?? null,
                'placements' => $extras['placements'][$productId] ?? 0,
                // How many live carts hold this product — the number the hide confirmation needs.
                'in_carts' => $extras['carts'][$productId] ?? 0,
                'selling_price' => Row::str($row, 'selling_price'),
                'cover' => $cover === null ? null : ImageUrl::src($cover),
            ];
        };

        if ($table->wantsExport()) {
            return $table->export($query, $map, $prepare);
        }

        return Inertia::render('Manage/Placement/Index', [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'categories' => $categories,
            'table' => $table->paginate($query, $map, $prepare),
            /*
             * Two different sentences, and which one shows depends on the flag (review 🟠-3).
             *
             * Post-switch the 301 is real and the caveat is the honest one: the redirect works, but
             * published links start travelling through it. Pre-switch the field is LOCKED, so the
             * screen says why instead of promising a redirect the next rebuild deletes.
             */
            'slug_warning' => PreSwitch::mayEditSlug()
                ? ManageText::t('placement.slug_warning', 'تغيير الرابط ينشئ تحويلًا 301 من الرابط القديم تلقائيًا، لكن الروابط المنشورة والمشاركة ستمر عبر التحويل. لا تغيّره بلا سبب.')
                : PreSwitch::slugMessage(),
            'slug_lock' => PreSwitch::slugState(),
            /*
             * Whether THIS operator may type a slug at all (item 6, 2026-09-18).
             *
             * Deliberately a second prop rather than folded into `slug_lock`. They are two
             * different rules with two different answers: `slug_lock` is about the CALENDAR and is
             * the same for everybody, this is about the PERSON and is the same on any day. Folding
             * them would give an operator one sentence that is half true for them, and would make
             * the screen unable to say which rule it is reporting.
             *
             * Hiding is never the control — `PlacementWriter::assertMayTypeSlug()` refuses on the
             * server and `RouteAuthorizationTest` proves it. This is so the field looks disabled
             * instead of looking broken.
             */
            'slug_role' => [
                'allowed' => Gate::allows(Role::EDIT_PRODUCT_SLUG),
                'message' => Gate::allows(Role::EDIT_PRODUCT_SLUG) ? null : ManageText::t(
                    'placement.slug_admin_only',
                    'تغيير رابط المنتج متاح للمدير فقط. الرابط هو عنوان الصفحة عند العملاء وفي جوجل، وتغييره ينقل الصفحة ويعتمد على تحويل 301 لكي لا تصبح 404. لو الرابط يحتاج تعديلًا اطلب ذلك من المدير.',
                ),
            ],
        ]);
    }

    /**
     * Save one product's placement row on this storefront.
     *
     * `{product}` is not implicitly bound: it is resolved here as a product that HAS a placement
     * row on this storefront (or is being given one), which means a product id from another
     * catalogue state is a 404 rather than a message that confirms it exists.
     */
    public function update(Request $request, Storefront $storefront, int $product): RedirectResponse
    {
        if (! DB::table('catalog_products')->where('id', $product)->whereNull('deleted_at')->exists()) {
            abort(404);
        }

        // Every category id must be a node OF THIS STOREFRONT (review 🟠-1). Without the scope a
        // crafted payload naming another storefront's node was accepted here too, and `place()`
        // then deleted this storefront's placements because the desired set came out empty.
        $ofThisStorefront = Rule::exists('storefront_categories', 'id')->where('storefront_id', $storefront->id);

        $data = $request->validate([
            'is_visible' => ['required', 'boolean'],
            'is_featured' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:-2147483648', 'max:2147483647'],
            'slug' => ['nullable', 'string', 'max:191'],
            'category_ids' => ['nullable', 'array', 'max:30'],
            'category_ids.*' => ['integer', 'min:1', $ofThisStorefront],
            'primary_category_id' => ['nullable', 'integer', 'min:1', $ofThisStorefront],
        ]);

        $payload = Coerce::arr($data);

        /*
         * The BEFORE snapshot for the activity log. Taken from `storefront_product` rather than the
         * payload, because the payload is what was ASKED for and the row is what was true — and a
         * refusal (missing Arabic, no image, no category) leaves the row untouched, so a log built
         * from the request would record a change that never happened.
         */
        $before = ActivityLog::fields(DB::table('storefront_product')
            ->where('storefront_id', $storefront->id)->where('product_id', $product)
            ->first(['is_visible', 'is_featured', 'sort_order', 'slug']));

        try {
            if (array_key_exists('category_ids', $payload)) {
                $this->placements->place(
                    $storefront->id,
                    $product,
                    Coerce::intList($payload['category_ids']),
                    Coerce::nint($payload['primary_category_id'] ?? null),
                );
            }
            $this->placements->save($storefront->id, $product, $payload, visibilityRequested: true);
        } catch (RuntimeException $e) {
            /*
             * Route the refusal to the FIELD it is about. Three different rules land here — the
             * Arabic/image/placement gates, a duplicate or unslugifiable slug, and the pre-switch
             * slug lock — and an operator who is told "cannot show this product" while the real
             * problem is the slug they typed will change the wrong thing.
             */
            // The field travels WITH the refusal ({@see FieldRefusal}) — matching on the wording
            // put review 🟡-4's new slug message on the visibility toggle, which told the operator
            // the wrong thing about their own save.
            throw ValidationException::withMessages([FieldRefusal::fieldOf($e) => $e->getMessage()]);
        }

        $after = ActivityLog::fields(DB::table('storefront_product')
            ->where('storefront_id', $storefront->id)->where('product_id', $product)
            ->first(['is_visible', 'is_featured', 'sort_order', 'slug']));

        ActivityLog::record(
            'storefront_product',
            $product,
            ActivityLog::UPDATED,
            $before,
            $after,
            label: self::productLabel($product),
            storefrontId: Coerce::nint($storefront->id),
        );

        return back()->with('status', ManageText::t('placement.saved', 'تم حفظ العرض والترتيب.'));
    }

    /**
     * What to call this product in the log — captured at write time.
     *
     * A log entry has to outlive its subject: "storefront_product 512" means nothing six months
     * later, and the Arabic title is what the reader recognises.
     */
    private static function productLabel(int $productId): ?string
    {
        $title = DB::table('catalog_product_translations')
            ->where('product_id', $productId)
            ->orderByRaw("FIELD(locale, 'ar', 'en')")
            ->value('title');

        return is_string($title) && $title !== '' ? $title : null;
    }

    /** Re-order the products inside one category. */
    public function sort(Request $request, Storefront $storefront, int $category): RedirectResponse
    {
        if (! DB::table('storefront_categories')->where('storefront_id', $storefront->id)->where('id', $category)->exists()) {
            abort(404);
        }

        $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['integer', 'min:1'],
        ]);

        $moved = $this->placements->sortInCategory(
            $storefront->id,
            $category,
            Coerce::orderedIntList($request->input('product_ids')),
        );

        return back()->with('status', ManageText::t('placement.sorted', 'تم ترتيب :count منتجًا في التصنيف.', ['count' => $moved]));
    }

    /**
     * Bulk visibility / featured from the selection bar.
     *
     * "Show" is applied product by product and REPORTS the ones it had to skip, because the Arabic
     * gate is per product: silently hiding half a selection's worth of failures is how a team
     * discovers in October that twelve products were never published.
     */
    public function bulk(Request $request, Storefront $storefront): RedirectResponse
    {
        $request->validate([
            'action' => ['required', 'string', 'in:show,hide,feature,unfeature,set_category,clear_category'],
            'product_ids' => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['integer', 'min:1'],
            /*
             * ── The bulk category actions (W-1, 2026-09-19) ─────────────────────────────────
             *
             * *"The single most expensive gap in the product."* Correcting a wrong category on
             * twenty products meant, twenty times: open a 7.4-screen form, scroll to the picker,
             * scroll inside a 200 px pane through 61 names, untick, tick, set the primary, scroll
             * five screens down, Save, scroll back up to find out whether it saved.
             *
             * The node is validated as belonging to THIS storefront by `Rule::exists` with the
             * storefront in the where — a node from another shop is not an error naming it, it
             * simply does not exist as far as this request is concerned (study §3.11.14).
             */
            'category_id' => [
                'required_if:action,set_category', 'required_if:action,clear_category',
                'integer',
                Rule::exists('storefront_categories', 'id')->where('storefront_id', $storefront->id),
            ],
            // Whether the added category also becomes the product's PRIMARY — the one that decides
            // its breadcrumb and its family. Off by default: promoting a primary is a bigger
            // decision than filing a product, and a bulk action should not make it by accident.
            'make_primary' => ['nullable', 'boolean'],
        ]);

        $action = $request->string('action')->toString();
        $skipped = [];
        $done = 0;

        if ($action === 'set_category' || $action === 'clear_category') {
            return $this->bulkCategory($request, $storefront, $action);
        }

        foreach ((array) $request->input('product_ids') as $raw) {
            if (! is_numeric($raw)) {
                continue;
            }
            $productId = (int) $raw;
            $raw = DB::table('storefront_product')
                ->where('storefront_id', $storefront->id)->where('product_id', $productId)
                ->first(['is_visible', 'is_featured', 'sort_order', 'slug']);
            if ($raw === null) {
                $skipped[] = $productId;

                continue;
            }
            $current = Row::cast($raw);

            $payload = [
                'is_visible' => match ($action) {
                    'show' => true,
                    'hide' => false,
                    default => Row::bool($current, 'is_visible'),
                },
                'is_featured' => match ($action) {
                    'feature' => true,
                    'unfeature' => false,
                    default => Row::bool($current, 'is_featured'),
                },
                'sort_order' => Row::int($current, 'sort_order'),
                'slug' => Row::str($current, 'slug'),
            ];

            try {
                $this->placements->save($storefront->id, $productId, $payload, visibilityRequested: true);
                $done++;

                /*
                 * One row per product, not one per bulk action. A bulk hide of 200 products is 200
                 * separate facts about 200 separate records, and the question this log answers —
                 * "why is THIS product hidden?" — is only answerable if the row names that product.
                 *
                 * `ActivityLog::record()` drops an update whose diff is empty, so products the
                 * action did not actually change cost nothing here.
                 */
                ActivityLog::record(
                    'storefront_product',
                    $productId,
                    ActivityLog::UPDATED,
                    ActivityLog::fields($current),
                    $payload,
                    label: self::productLabel($productId),
                    storefrontId: Coerce::nint($storefront->id),
                );
            } catch (RuntimeException) {
                $skipped[] = $productId;
            }
        }

        $message = ManageText::t('placement.bulk_done', 'تم التنفيذ على :count منتجًا.', ['count' => $done]);
        if ($skipped !== []) {
            // The reasons, named. A bulk action that says only "3 skipped" sends the operator
            // hunting; these are exactly the three gates `PlacementWriter::save()` enforces.
            //
            // TWO sentences on the seam rather than one glued to the other's tail: each is a whole
            // thought, and the second only exists when something was skipped.
            $message .= ' '.ManageText::t(
                'placement.bulk_skipped',
                'تم تخطّي :count منتجًا (عربي ناقص، أو بلا صورة، أو بلا تصنيف في هذا المتجر): :ids.',
                [
                    'count' => count($skipped),
                    'ids' => implode(ManageText::t('common.list_separator', '، '), array_slice($skipped, 0, 20)),
                ],
            );
        }

        return $skipped === []
            ? back()->with('status', $message)
            : back()->with('status', $message)->withErrors(['bulk' => $message]);
    }

    /**
     * Add a category to a batch of products, or take one away (W-1).
     *
     * ── ADDITIVE, never a replacement ───────────────────────────────────────────────────────
     *
     * `set_category` adds the chosen node to whatever each product already has; it does not
     * replace the set. That is the difference between a bulk action somebody can use without
     * reading the code and one that quietly strips categories the operator never saw — twenty
     * products selected from a filtered list have twenty different existing sets, and none of them
     * is on screen. Correcting a wrong category is therefore two deliberate passes: clear the
     * wrong one, add the right one. Two passes over twenty products is still two actions instead
     * of twenty seven-screen form edits.
     *
     * ── What it refuses, and why that is the whole point ────────────────────────────────────
     *
     * Taking a product's LAST category away while it is visible leaves it on sale and reachable
     * from no listing on the site — the `بلا تصنيف` state, created in bulk, silently. Those
     * products are skipped and named, which is the posture this screen already takes for its other
     * refusals: the placement screen REFUSES where the product form demotes, because on this
     * screen placement is the only thing being decided (see `PreventionTest`).
     */
    private function bulkCategory(Request $request, Storefront $storefront, string $action): RedirectResponse
    {
        $categoryId = Coerce::int($request->input('category_id'));
        $makePrimary = $request->boolean('make_primary');

        $skipped = [];
        $done = 0;
        $unchanged = 0;

        foreach (Coerce::intList($request->input('product_ids')) as $productId) {
            // Only products that are ON this storefront. One that is not is not a failure to
            // report — it was never in this screen's world (D-3: absent is not a fault).
            $onStorefront = DB::table('storefront_product')
                ->where('storefront_id', $storefront->id)->where('product_id', $productId)
                ->first(['is_visible']);
            if ($onStorefront === null) {
                $skipped[] = $productId;

                continue;
            }

            $before = self::categoryIdsOf($storefront->id, $productId);
            $primary = self::primaryIdOf($storefront->id, $productId);

            if ($action === 'set_category') {
                if (in_array($categoryId, $before, true) && (! $makePrimary || $primary === $categoryId)) {
                    $unchanged++;

                    continue;
                }
                $after = in_array($categoryId, $before, true) ? $before : [...$before, $categoryId];
                $nextPrimary = $makePrimary ? $categoryId : $primary;
            } else {
                if (! in_array($categoryId, $before, true)) {
                    $unchanged++;

                    continue;
                }
                $after = array_values(array_filter($before, fn (int $id): bool => $id !== $categoryId));

                // The refusal: a VISIBLE product with nothing left is on sale and in no listing.
                if ($after === [] && Row::bool(Row::cast($onStorefront), 'is_visible')) {
                    $skipped[] = $productId;

                    continue;
                }
                $nextPrimary = $primary === $categoryId ? ($after[0] ?? null) : $primary;
            }

            $this->placements->place($storefront->id, $productId, $after, $nextPrimary);
            $done++;

            // One row per product, for the same reason the visibility bulk above keeps one: the
            // question this log answers is "why is THIS product filed here?".
            ActivityLog::record(
                'storefront_product', $productId, ActivityLog::UPDATED,
                ['categories' => implode(',', $before)],
                ['categories' => implode(',', $after)],
                label: self::productLabel($productId),
                storefrontId: Coerce::nint($storefront->id),
            );
        }

        $message = $action === 'set_category'
            ? ManageText::t('placement.bulk_category_added', 'تمت إضافة التصنيف إلى :count منتجًا.', ['count' => $done])
            : ManageText::t('placement.bulk_category_removed', 'تم حذف التصنيف من :count منتجًا.', ['count' => $done]);

        if ($unchanged > 0) {
            $message .= ' '.ManageText::t(
                'placement.bulk_category_unchanged',
                'و:count منتجًا كان بالفعل على الحالة المطلوبة فلم يتغيّر.',
                ['count' => $unchanged],
            );
        }

        if ($skipped === []) {
            return back()->with('status', $message);
        }

        $message .= ' '.ManageText::t(
            'placement.bulk_category_skipped',
            'وتُخطّي :count منتجًا: إمّا غير معروض على هذا المتجر أصلًا، أو كان هذا تصنيفه الوحيد وهو ظاهر — وحذفه كان سيتركه معروضًا للبيع بلا أي قائمة تصل إليه: :ids.',
            [
                'count' => count($skipped),
                'ids' => implode(ManageText::t('common.list_separator', '، '), array_slice($skipped, 0, 20)),
            ],
        );

        return back()->with('status', $message)->withErrors(['bulk' => $message]);
    }

    /**
     * The category ids a product holds on one storefront.
     *
     * @return list<int>
     */
    private static function categoryIdsOf(int $storefrontId, int $productId): array
    {
        $out = [];
        foreach (
            DB::table('storefront_category_product')
                ->where('storefront_id', $storefrontId)->where('product_id', $productId)
                ->orderBy('id')->pluck('storefront_category_id') as $value
        ) {
            $out[] = Coerce::int($value);
        }

        return $out;
    }

    /** …and which of them is the primary, or null. */
    private static function primaryIdOf(int $storefrontId, int $productId): ?int
    {
        return Coerce::nint(
            DB::table('storefront_category_product')
                ->where('storefront_id', $storefrontId)->where('product_id', $productId)
                ->where('is_primary', true)
                ->value('storefront_category_id')
        );
    }

    // ── the query ────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, string|null>  $filters
     */
    private function listQuery(int $storefrontId, array $filters): Builder
    {
        $query = DB::table('storefront_product as sp')
            ->join('catalog_products as p', 'p.id', '=', 'sp.product_id')
            ->leftJoin('catalog_product_translations as ar', function (JoinClause $join): void {
                $join->on('ar.product_id', '=', 'p.id')->where('ar.locale', '=', 'ar');
            })
            ->leftJoin('catalog_product_translations as en', function (JoinClause $join): void {
                $join->on('en.product_id', '=', 'p.id')->where('en.locale', '=', 'en');
            })
            ->where('sp.storefront_id', $storefrontId)
            ->whereNull('p.deleted_at')
            ->select([
                'p.id', 'p.wa_code', 'p.selling_price',
                'sp.is_visible', 'sp.is_featured', 'sp.sort_order', 'sp.slug',
                'ar.title as title_ar', 'en.title as title_en',
                // The placement count, the primary category and the cover are NOT selected here
                // — they are read for the page in `index()`. See `TableQuery::paginate()`.
            ]);

        // This filter is ONE node, not a branch: the placement screen is about where a product
        // actually sits, so "Watches" here means the Watches node itself. That is also why it does
        // not need the product list's `straight_join` — a single-node EXISTS is selective enough
        // that the optimizer reduces it to a probe on its own. NOT measured at 7k scale: the
        // explain harness covers the product list, and this screen's plan is a known gap
        // (`docs/wave4b/EXPLAIN_2026-09-11.md`).
        $category = $filters['category'] ?? null;
        if ($category !== null && is_numeric($category)) {
            $query->whereExists(function (Builder $sub) use ($category, $storefrontId): void {
                $sub->from('storefront_category_product as scpf')
                    ->whereColumn('scpf.product_id', 'p.id')
                    ->where('scpf.storefront_id', $storefrontId)
                    ->where('scpf.storefront_category_id', (int) $category)
                    ->selectRaw('1')
                    ->limit(1);
            });
        }

        match ($filters['flag'] ?? null) {
            'no_arabic' => $query->where(function (Builder $inner): void {
                $inner->whereNull('ar.title')->orWhere('ar.title', '=', '');
            }),
            'no_primary' => $query->whereNotExists(function (Builder $sub) use ($storefrontId): void {
                $sub->from('storefront_category_product as scpn')
                    ->whereColumn('scpn.product_id', 'p.id')
                    ->where('scpn.storefront_id', $storefrontId)
                    ->where('scpn.is_primary', true)
                    ->selectRaw('1')
                    ->limit(1);
            }),
            'unplaced' => $query->whereNotExists(function (Builder $sub) use ($storefrontId): void {
                $sub->from('storefront_category_product as scpu')
                    ->whereColumn('scpu.product_id', 'p.id')
                    ->where('scpu.storefront_id', $storefrontId)
                    ->selectRaw('1')
                    ->limit(1);
            }),
            default => null,
        };

        return $query;
    }

    /**
     * The per-page extras: how many categories each product sits in, which one is primary, and its
     * cover. Two small reads over the page's ids instead of three sub-selects per matching row.
     *
     * `in_carts` is task 4.4: hiding a product that sits in somebody's cart is allowed — it is
     * how a sold-out line is withdrawn — but the operator has to be told, because the customer
     * holding that cart will find the line gone at checkout. `cart_items` is a SHARED table both
     * applications write (AGENTS §2.6); it is only ever read here.
     *
     * @param  list<object>  $rows
     * @return array{covers: array<int, string>, placements: array<int, int>, primary: array<int, int>, carts: array<int, int>}
     */
    private static function pageExtras(array $rows, int $storefrontId): array
    {
        $ids = [];
        foreach ($rows as $raw) {
            $ids[] = Row::int(Row::cast($raw), 'id');
        }
        if ($ids === []) {
            return ['covers' => [], 'placements' => [], 'primary' => [], 'carts' => []];
        }

        $carts = [];
        foreach (
            DB::table('cart_items')
                ->whereIn('product_id', $ids)
                ->select('product_id', DB::raw('COUNT(*) as cart_count'))
                ->groupBy('product_id')
                ->get() as $raw
        ) {
            $row = Row::cast($raw);
            $carts[Row::int($row, 'product_id')] = Row::int($row, 'cart_count');
        }

        $placements = [];
        $primary = [];
        foreach (
            DB::table('storefront_category_product')
                ->where('storefront_id', $storefrontId)
                ->whereIn('product_id', $ids)
                ->get(['product_id', 'storefront_category_id', 'is_primary']) as $raw
        ) {
            $row = Row::cast($raw);
            $productId = Row::int($row, 'product_id');
            $placements[$productId] = ($placements[$productId] ?? 0) + 1;
            if (Row::bool($row, 'is_primary')) {
                $primary[$productId] = Row::int($row, 'storefront_category_id');
            }
        }

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

        return ['covers' => $covers, 'placements' => $placements, 'primary' => $primary, 'carts' => $carts];
    }

    /** @return list<array{value: string, label: string}> */
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
            ->get(['c.id', 'c.depth', 'c.slug', 'ar.name as name_ar', 'en.name as name_en']);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            // Both names read, and the reader's chosen (2026-10-05): this query asked for the
            // Arabic column alone, so no amount of client-side care could have shown an English
            // operator an English category here.
            $name = LocalisedName::pick(
                Row::nstr($row, 'name_ar'),
                Row::nstr($row, 'name_en'),
                Row::nstr($row, 'slug') ?? ('#'.$id),
            );
            $out[] = [
                'value' => (string) $id,
                'label' => str_repeat('— ', max(0, Row::int($row, 'depth') - 1)).$name,
            ];
        }

        return $out;
    }

    /** @return list<array{value: string, label: string}> */
    private static function storefrontOptions(): array
    {
        $out = [];
        foreach (DB::table('storefronts')->orderBy('id')->get(['id', 'name']) as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $out[] = ['value' => (string) $id, 'label' => Row::nstr($row, 'name') ?? ('#'.$id)];
        }

        return $out;
    }
}
