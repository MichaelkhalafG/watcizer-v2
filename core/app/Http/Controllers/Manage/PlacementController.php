<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Catalog\FieldRefusal;
use App\Domain\Catalog\PlacementWriter;
use App\Domain\Catalog\PreSwitch;
use App\Models\Storefront\Storefront;
use App\Storefront\ImageUrl;
use App\Support\Coerce;
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

    public function index(Request $request, Storefront $storefront): Response
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
            ->perPage(default: 25, max: 100);

        $filters = $table->resolvedFilters();
        $query = $this->listQuery($storefront->id, $filters);

        // Filled by the `prepare` callback below. Same reason as the product list: three
        // correlated sub-selects in a list query are three queries per MATCHING row, not per
        // shown row (`TableQuery::paginate()`).
        $extras = self::pageExtras([], $storefront->id);

        return Inertia::render('Manage/Placement/Index', [
            'storefront' => ['id' => $storefront->id, 'code' => $storefront->code, 'name' => $storefront->name],
            'storefronts' => self::storefrontOptions(),
            'categories' => $categories,
            'table' => $table->paginate($query, function (object $raw) use (&$extras): array {
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
            }, function (array $rows) use (&$extras, $storefront): void {
                $extras = self::pageExtras($rows, $storefront->id);
            }),
            /*
             * Two different sentences, and which one shows depends on the flag (review 🟠-3).
             *
             * Post-switch the 301 is real and the caveat is the honest one: the redirect works, but
             * published links start travelling through it. Pre-switch the field is LOCKED, so the
             * screen says why instead of promising a redirect the next rebuild deletes.
             */
            'slug_warning' => PreSwitch::mayEditSlug()
                ? 'تغيير الرابط ينشئ تحويلًا 301 من الرابط القديم تلقائيًا، لكن الروابط المنشورة والمشاركة ستمر عبر التحويل. لا تغيّره بلا سبب.'
                : PreSwitch::slugMessage(),
            'slug_lock' => PreSwitch::slugState(),
            // Worded for what THIS screen loses: placements, visibility, order, featured, and any
            // hand-typed slug together with the 301 written for it.
            'pre_switch_notice' => PreSwitch::noticeFor('placement'),
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

        try {
            if (array_key_exists('category_ids', $payload)) {
                $this->placements->place(
                    $storefront->id,
                    $product,
                    Coerce::intList($payload['category_ids']),
                    Coerce::nint($payload['primary_category_id'] ?? null),
                );
            }
            $this->placements->save($storefront->id, $product, $payload);
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

        return back()->with('status', 'تم حفظ العرض والترتيب.');
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

        return back()->with('status', "تم ترتيب {$moved} منتجًا في التصنيف.");
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
            'action' => ['required', 'string', 'in:show,hide,feature,unfeature'],
            'product_ids' => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['integer', 'min:1'],
        ]);

        $action = $request->string('action')->toString();
        $skipped = [];
        $done = 0;

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
                $this->placements->save($storefront->id, $productId, $payload);
                $done++;
            } catch (RuntimeException) {
                $skipped[] = $productId;
            }
        }

        $message = "تم التنفيذ على {$done} منتجًا.";
        if ($skipped !== []) {
            // The reasons, named. A bulk action that says only "3 skipped" sends the operator
            // hunting; these are exactly the three gates `PlacementWriter::save()` enforces.
            $message .= ' تم تخطّي '.count($skipped).' منتجًا (عربي ناقص، أو بلا صورة، أو بلا تصنيف في هذا المتجر): '
                .implode('، ', array_slice($skipped, 0, 20)).'.';
        }

        return $skipped === []
            ? back()->with('status', $message)
            : back()->with('status', $message)->withErrors(['bulk' => $message]);
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
            ->where('c.storefront_id', $storefrontId)
            ->orderBy('c.path')
            ->get(['c.id', 'c.depth', 'c.slug', 'ar.name as name_ar']);

        $out = [];
        foreach ($rows as $raw) {
            $row = Row::cast($raw);
            $id = Row::int($row, 'id');
            $ar = trim(Row::nstr($row, 'name_ar') ?? '');
            $name = $ar !== '' ? $ar : (Row::nstr($row, 'slug') ?? ('#'.$id));
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
