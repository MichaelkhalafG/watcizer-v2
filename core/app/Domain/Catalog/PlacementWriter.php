<?php

namespace App\Domain\Catalog;

use App\Models\Storefront\StorefrontRedirect;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Support\LegacySlug;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Per-storefront presentation of a shared product: visibility, category placement, sort, featured
 * and slug — the `storefront_product` and `storefront_category_product` half of the catalogue
 * (D3: product CONTENT is single, only these columns are per storefront).
 *
 * ── Three invariants this class exists to hold ───────────────────────────────────────────────
 *
 *  1. **One primary category per (storefront, product)**, enforced by the database since M1d
 *     (`primary_guard` + `UNIQUE (storefront_id, primary_guard)`). The UI must respect AND
 *     explain it, so setting a new primary DEMOTES the old one first, in the same transaction,
 *     for the same reason the transform's step 19 does: with two unique keys on the table, an
 *     upsert that meets a stale primary matches the WRONG key and silently updates the wrong row.
 *
 *  2. **Arabic before visible.** Translation fallback is OFF (AGENTS §2.17), so a product without
 *     a complete Arabic row renders with holes on an Arabic-first storefront. The study spells out
 *     the consequence for wave 4: the dashboard must BLOCK enabling `is_visible` for such a
 *     product. This is that block, and it refuses with the missing fields named.
 *
 *  3. **A slug change leaves a 301 behind.** `storefront_product.slug` is the live product URL.
 *     Changing it without a redirect row 404s every indexed link and every share; the transform
 *     already writes `storefront_redirects` rows for its own twin collisions (A-17), and this
 *     writes one for a hand edit, tagged `slug_change`.
 */
final class PlacementWriter
{
    /** Columns the dashboard may write on `storefront_product`. `effective_*` are maintained, not typed. */
    public const COLUMNS = ['is_visible', 'is_featured', 'sort_order', 'slug', 'published_at'];

    /** Translated product columns that must be present in Arabic before a product may be visible. */
    public const REQUIRED_AR = ['title'];

    public function __construct(
        private readonly StorefrontCache $cache,
        private readonly ProductIndexer $indexer,
    ) {}

    /**
     * Create or update a product's row on one storefront.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(int $storefrontId, int $productId, array $data): void
    {
        DB::transaction(function () use ($storefrontId, $productId, $data): void {
            $existing = DB::table('storefront_product')
                ->where('storefront_id', $storefrontId)->where('product_id', $productId)
                ->first(['id', 'slug', 'is_visible', 'published_at']);

            $visible = (bool) ($data['is_visible'] ?? false);
            if ($visible) {
                // Two gates before a product may face a customer, both of them things the
                // storefront cannot render around (task 4.2).
                $this->assertArabicComplete($productId);
                $this->assertHasImage($productId);
                $this->assertPlacedSomewhere($storefrontId, $productId);
            }

            $slug = $this->resolveSlug($storefrontId, $productId, $data, $existing === null ? null : Row::str(Row::cast($existing), 'slug'));

            $product = DB::table('catalog_products')->where('id', $productId)->first(['selling_price', 'sale_price']);
            if ($product === null) {
                throw new RuntimeException("Product {$productId} does not exist.");
            }
            $selling = (float) (is_numeric($product->selling_price) ? $product->selling_price : 0);
            $sale = is_numeric($product->sale_price) ? (float) $product->sale_price : null;
            if ($sale !== null && ! ($sale > 0 && $sale < $selling)) {
                $sale = null;
            }

            $row = [
                'is_visible' => $visible,
                'is_featured' => (bool) ($data['is_featured'] ?? false),
                'sort_order' => is_numeric($data['sort_order'] ?? null) ? (int) $data['sort_order'] : 0,
                'slug' => $slug,
                // `effective_*` mirror the catalog price: the override gate is OFF (AGENTS §2.4),
                // so they are computed here and never taken from the request.
                'effective_price' => $selling,
                'effective_sale_price' => $sale,
                'updated_at' => now(),
            ];

            // `published_at` is the moment the product FIRST became visible on this storefront —
            // it drives the listing's default order (`sp_list_position_idx`). Re-hiding and
            // re-showing must not jump the product to the front of the catalogue, so it is set
            // once and never rewritten.
            if ($existing === null) {
                $row['published_at'] = $visible ? now() : null;
                $row['storefront_id'] = $storefrontId;
                $row['product_id'] = $productId;
                $row['price_override'] = null;
                $row['sale_price_override'] = null;
                $row['created_at'] = now();
                DB::table('storefront_product')->insert($row);
            } else {
                if ($visible && $existing->published_at === null) {
                    $row['published_at'] = now();
                }
                DB::table('storefront_product')->where('id', $existing->id)->update($row);

                $oldSlug = Row::str(Row::cast($existing), 'slug');
                if ($oldSlug !== $slug) {
                    $this->recordSlugRedirect($storefrontId, $oldSlug, $slug);
                }
            }

            $this->cache->forgetProduct($storefrontId, $productId);
            $this->cache->flush($storefrontId);
        });
    }

    /**
     * Place a product in a set of categories on one storefront, naming which one is primary.
     *
     * The full desired set, so adding, removing and re-primarying are one operation. Removing a
     * placement here DOES delete the pivot row — unlike the transform, which is additive by design
     * because it cannot tell "the team un-placed this" from "legacy dropped the row". A human
     * clicking a category off is unambiguous.
     *
     * @param  list<int>  $categoryIds
     */
    public function place(int $storefrontId, int $productId, array $categoryIds, ?int $primaryCategoryId): void
    {
        DB::transaction(function () use ($storefrontId, $productId, $categoryIds, $primaryCategoryId): void {
            // Every id must be a node of THIS storefront. A node from another storefront is not an
            // error message naming it — it is simply not in the set (study §3.11.14: do not
            // confirm that another storefront's row exists).
            $valid = DB::table('storefront_categories')
                ->where('storefront_id', $storefrontId)
                ->whereIn('id', $categoryIds === [] ? [-1] : $categoryIds)
                ->pluck('id')
                ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->all();

            $primary = $primaryCategoryId !== null && in_array($primaryCategoryId, $valid, true)
                ? $primaryCategoryId
                : ($valid[0] ?? null);

            // Phase 1 — demote every existing primary, BEFORE any insert. This is the ONLY thing
            // that clears an old primary (phase 3 deliberately does not touch the flag), so
            // without it a re-primary leaves TWO flagged rows and M1d's unique key
            // (`storefront_id`, `primary_guard`) refuses the write — which is the invariant, and
            // the reason the order matters. Step 19 hit the same hazard from the other side: with
            // a stale primary still flagged, its raw upsert matched the WRONG unique key and
            // updated a row the caller never named.
            DB::table('storefront_category_product')
                ->where('storefront_id', $storefrontId)
                ->where('product_id', $productId)
                ->where('is_primary', true)
                ->update(['is_primary' => false, 'updated_at' => now()]);

            // Phase 2 — drop the placements the payload no longer names.
            DB::table('storefront_category_product')
                ->where('storefront_id', $storefrontId)
                ->where('product_id', $productId)
                ->when($valid !== [], fn ($q) => $q->whereNotIn('storefront_category_id', $valid))
                ->delete();

            // Phase 3 — write the set, primary last so it is the only flagged row at any instant.
            //
            // `is_primary` is NOT in the values, and that is deliberate: M1 defaults it to 0, so a
            // new row needs nothing, and an existing row's flag belongs to phase 1's demotion.
            // The first version set it to `false` here, which made phase 1 redundant — and a
            // sensitivity pass proved it: deleting the demotion changed no test. A guard that can
            // be removed without a test noticing is not a guard.
            foreach ($valid as $categoryId) {
                DB::table('storefront_category_product')->updateOrInsert(
                    ['storefront_category_id' => $categoryId, 'product_id' => $productId],
                    [
                        'storefront_id' => $storefrontId,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ],
                );
            }
            if ($primary !== null) {
                DB::table('storefront_category_product')
                    ->where('storefront_category_id', $primary)
                    ->where('product_id', $productId)
                    ->update(['is_primary' => true, 'updated_at' => now()]);
            }

            // Category names are part of the searchable body (step 21 and
            // {@see ProductIndexer}), so a re-placement changes what finds this product.
            $this->indexer->reindex($productId);

            $this->cache->forgetProduct($storefrontId, $productId);
            $this->cache->flush($storefrontId);
        });
    }

    /**
     * Sort order inside one category (the category's own product ordering).
     *
     * @param  list<int>  $orderedProductIds
     */
    public function sortInCategory(int $storefrontId, int $categoryId, array $orderedProductIds): int
    {
        return DB::transaction(function () use ($storefrontId, $categoryId, $orderedProductIds): int {
            $sort = 0;
            $moved = 0;
            foreach ($orderedProductIds as $productId) {
                $updated = DB::table('storefront_category_product')
                    ->where('storefront_id', $storefrontId)
                    ->where('storefront_category_id', $categoryId)
                    ->where('product_id', $productId)
                    ->update(['sort_order' => $sort++, 'updated_at' => now()]);
                $moved += $updated;
            }
            $this->cache->flush($storefrontId);

            return $moved;
        });
    }

    /**
     * The Arabic completeness gate (AGENTS §2.17).
     *
     * @throws RuntimeException naming what is missing, because "cannot publish" without a reason
     *                          is a dead end for the person holding the keyboard
     */
    public function assertArabicComplete(int $productId): void
    {
        $missing = $this->missingArabic($productId);
        if ($missing !== []) {
            // The missing fields travel as a `:fields` replacement rather than being glued to the
            // end of the sentence: the seam's fallback is ONE literal, and a tail concatenated
            // after it would stay Arabic for an English operator.
            throw new RuntimeException(ManageText::t(
                'placement.needs_arabic',
                'لا يمكن إظهار المنتج على المتجر قبل استكمال العربية (الترجمة الاحتياطية مُعطّلة). الناقص: :fields',
                ['fields' => implode(ManageText::t('common.list_separator', '، '), $missing)],
            ));
        }
    }

    /**
     * Which required Arabic fields are missing. Empty list = publishable.
     *
     * @return list<string>
     */
    /**
     * The refusal for a name that yields no slug, with a suggestion when one can be offered.
     *
     * The rule being explained is the legacy one: a Watchizer URL is `[a-z0-9-]`, so Arabic,
     * emoji and punctuation contribute nothing. `Str::slug()` transliterates roughly
     * («ساعة رولكس» → `saaa-rolks`), which is not good enough to STORE but is a fine starting
     * point for a person who can then correct it — which is the difference between a dead end and
     * a next step.
     */
    private static function unslugifiable(string $requested): string
    {
        $suggestion = Str::slug($requested);

        /*
         * Two sentences, two keys, joined with a space — never one key spliced onto half of
         * another. The rule is the same sentence in both branches, and only the NEXT STEP differs;
         * splitting on the full stop keeps each half a whole thought for whoever writes the
         * English, and keeps the shared half written once.
         */
        $base = ManageText::t('placement.slug_not_latin', 'لا يمكن تحويل «:name» إلى رابط: روابط المتجر تُكتب بحروف إنجليزية وأرقام وشرطات فقط، والحروف العربية والرموز لا تدخل فيها.', ['name' => $requested]);

        if ($suggestion === '') {
            return $base.' '.ManageText::t('placement.slug_write_latin', 'اكتب رابطًا بالإنجليزية (مثل rolex-submariner)، أو اترك الخانة فارغة ليُولّد من العنوان الإنجليزي تلقائيًا.');
        }

        return $base.' '.ManageText::t('placement.slug_suggestion', 'اقتراح قريب من الاسم: «:suggestion» — عدّله كما يناسبك واكتبه في الخانة، أو اتركها فارغة ليُولّد من العنوان الإنجليزي تلقائيًا.', ['suggestion' => $suggestion]);
    }

    /**
     * A product with NO image may not be visible.
     *
     * Not a taste rule: a listing card is an image and a price, and an empty frame reads as a
     * broken site rather than as a product without a photo. Nothing in the catalogue needs an
     * exception today — all 464 live products have at least one image — so the gate costs the team
     * nothing and stops the one mistake it exists for.
     *
     * @throws RuntimeException
     */
    public function assertHasImage(int $productId): void
    {
        if (DB::table('catalog_product_images')->where('product_id', $productId)->exists()) {
            return;
        }

        throw new RuntimeException(ManageText::t('placement.needs_image', 'لا يمكن إظهار منتج بلا صورة: بطاقة المنتج على المتجر صورة وسعر، والإطار الفارغ يبدو عطلًا في الموقع. ارفع صورة واحدة على الأقل من قسم الصور ثم أظهره.'));
    }

    /**
     * A visible product must sit in at least one category ON THIS STOREFRONT.
     *
     * Otherwise "visible" is a claim no page can honour: the product appears in no listing, under
     * no menu item, and the only way to reach it is to already know its URL.
     *
     * @throws RuntimeException
     */
    public function assertPlacedSomewhere(int $storefrontId, int $productId): void
    {
        $placed = DB::table('storefront_category_product')
            ->where('storefront_id', $storefrontId)->where('product_id', $productId)->exists();
        if ($placed) {
            return;
        }

        throw new RuntimeException(ManageText::t('placement.needs_category', 'لا يمكن إظهار المنتج في هذا المتجر قبل اختيار تصنيف واحد على الأقل: منتج بلا تصنيف لا يظهر في أي قائمة ولا تحت أي قسم، ولا يصل إليه إلا من يعرف رابطه.'));
    }

    /**
     * The Arabic fields a product is missing, as labels the operator can act on.
     *
     * @return list<string>
     */
    public function missingArabic(int $productId): array
    {
        $row = DB::table('catalog_product_translations')
            ->where('product_id', $productId)->where('locale', 'ar')
            ->first(self::REQUIRED_AR);

        if ($row === null) {
            return [ManageText::t('placement.missing_arabic_row', 'صف الترجمة العربية بالكامل')];
        }

        // One label per required column. The map is exhaustive by construction, so adding a
        // column to REQUIRED_AR without a label is a PHPStan error and not a raw key on screen.
        // The labels are the product form's own field names, off the shared English file, so the
        // refusal names the box the operator has to go and fill.
        $labels = ['title' => ManageText::t('products.field_title', 'العنوان')];
        $translation = Row::cast($row);
        $missing = [];
        foreach (self::REQUIRED_AR as $column) {
            if (trim(Row::nstr($translation, $column) ?? '') === '') {
                $missing[] = $labels[$column];
            }
        }

        return $missing;
    }

    /**
     * The slug: the caller's if they typed one, else generated from the EN title exactly as the
     * transform does (`LegacySlug`, falling back to the product id), and made unique inside the
     * storefront.
     *
     * `LegacySlug` and not a "better" slugifier: the transform derives every live Watchizer URL
     * with it, and a second algorithm here would mean a product's URL depended on which code
     * created it.
     *
     * @param  array<string, mixed>  $data
     */
    /**
     * The slug to store.
     *
     * Three refusals and one derivation, and the three refusals are the point: a slug is a live URL,
     * so every way of getting it wrong has to stop rather than be corrected quietly.
     *
     *  1. **Taken inside this storefront** → refused by name (task 4.2). It used to be silently
     *     suffixed: the operator typed `rolex-daytona`, got `rolex-daytona-2`, and found out by
     *     reading the URL later — the same class of surprise as a warning nobody reads.
     *  2. **Unslugifiable** → refused, with a transliterated SUGGESTION where one exists (review
     *     🟡-4). This used to fall back to the numeric product id, so typing an Arabic name — the
     *     likeliest real input on an Arabic-first dashboard — silently produced `/product/1234`.
     *     `LegacySlug` keeps ASCII-only behaviour on purpose (its docblock forbids transliteration,
     *     because the transform derives every live URL with it), so the suggestion is built with
     *     Laravel's `Str::slug()` for the MESSAGE ONLY and is never stored.
     *  3. **Changing an existing slug before the write-switch** → refused (review 🟠-3). The 301
     *     this would promise does not survive a rebuild; see `PreSwitch::mayEditSlug()`.
     *
     * A DERIVED slug (the field left empty) is still suffixed on collision, because there the
     * suffix is the answer rather than a surprise, and it is what the transform does.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveSlug(int $storefrontId, int $productId, array $data, ?string $current): string
    {
        $requested = Coerce::str($data['slug'] ?? null);
        if ($requested !== '') {
            $slug = LegacySlug::make($requested);
            if ($slug === '') {
                throw new FieldRefusal('slug', self::unslugifiable($requested));
            }

            /*
             * A TYPED slug that does not fit is refused, not shortened — the opposite of the
             * generated path below, and deliberately.
             *
             * Nobody chose the generated one, so cutting it costs nothing. Somebody chose this one,
             * and silently handing them back a different URL from the one they typed is how a person
             * ends up linking to a page that does not exist. They are at a keyboard; the sentence
             * says the limit and what they have.
             */
            if (mb_strlen($slug) > self::SLUG_MAX) {
                throw new FieldRefusal('slug', ManageText::t(
                    'placement.slug_too_long',
                    'الرابط طويل جدًا: :length حرفًا والحد :max. اختصره ثم احفظ.',
                    ['length' => mb_strlen($slug), 'max' => self::SLUG_MAX],
                ));
            }

            /*
             * The pre-switch lock applies to a CHANGE, not to every save. The product form echoes
             * the stored slug back in its payload on an ordinary edit, so refusing any non-empty
             * slug here would refuse every save the screen makes — and a rule that blocks unrelated
             * work is a rule the team routes around. A brand-new `storefront_product` row is also
             * allowed to carry a typed slug: there is no old URL, so there is no 301 to promise.
             */
            if ($current !== null && $current !== '' && $slug !== $current) {
                PreSwitch::assertMayEditSlug();
            }

            $taken = DB::table('storefront_product')
                ->where('storefront_id', $storefrontId)
                ->where('slug', $slug)
                ->where('product_id', '!=', $productId)
                ->exists();
            if ($taken) {
                throw new FieldRefusal(
                    'slug',
                    ManageText::t('placement.slug_taken', 'الرابط «:slug» مستخدم بالفعل لمنتج آخر في هذا المتجر. الروابط لا تتكرر داخل المتجر الواحد. اكتب رابطًا مختلفًا، أو اترك الخانة فارغة ليُولّد من العنوان الإنجليزي تلقائيًا.', ['slug' => $slug]),
                );
            }

            return $slug;
        }
        if ($current !== null && $current !== '') {
            return $current;
        }

        $titleEn = DB::table('catalog_product_translations')
            ->where('product_id', $productId)->where('locale', 'en')->value('title');

        return $this->uniqueSlug(
            $storefrontId,
            LegacySlug::orId(is_string($titleEn) ? $titleEn : '', $productId),
            $productId,
        );
    }

    /**
     * `storefront_product.slug` is varchar(191), and a slug that does not fit is a LOST PRODUCT.
     *
     * Measured 2026-09-17: ten Brand Fashion rows failed with `1406 Data too long for column 'slug'`
     * — supplier titles that run to 211 characters ("Jacop&Philipp Elegant Silk Sleep Cap for Women,
     * Single Layer Satin Sleep Cap, High-Tech Care, …"). The whole product was refused over the URL.
     *
     * The transform answers this differently and correctly for its own case: X-05 is a BLOCKING audit
     * finding, because a legacy product already has a live URL and silently shortening it would break
     * a link somebody has bookmarked. An IMPORTED product has no URL yet — there is nothing to
     * preserve — so the honest answer here is to shorten it and keep the product.
     */
    public const SLUG_MAX = 191;

    /**
     * A slug base cut to fit, leaving room for the collision suffix that may follow it.
     *
     * Cut on a HYPHEN where there is one in the last quarter, so the URL ends on a whole word rather
     * than mid-syllable — `…-single-layer-satin` reads as something, `…-single-layer-sat` reads as a
     * mistake. Trailing hyphens are trimmed either way, because `foo-` and `foo` are different URLs
     * and only one of them looks deliberate.
     */
    public static function fitSlug(string $base, int $reserve = 0): string
    {
        $limit = max(1, self::SLUG_MAX - $reserve);
        if (mb_strlen($base) <= $limit) {
            return rtrim($base, '-');
        }

        $cut = mb_substr($base, 0, $limit);
        $lastHyphen = mb_strrpos($cut, '-');
        if ($lastHyphen !== false && $lastHyphen >= (int) ($limit * 0.75)) {
            $cut = mb_substr($cut, 0, $lastHyphen);
        }

        return rtrim($cut, '-');
    }

    private function uniqueSlug(int $storefrontId, string $base, int $productId): string
    {
        /*
         * Room reserved for the longest suffix the loop below can add — `-{id}-{n}` — so a slug that
         * collides after truncation still fits the column. Reserving up front rather than
         * re-truncating per attempt keeps the base stable across the loop, which is what makes the
         * eventual slug predictable from the title.
         */
        $base = self::fitSlug($base, mb_strlen('-'.$productId.'-99'));
        $base = $base === '' ? (string) $productId : $base;
        $slug = $base;
        $suffix = 2;

        while (
            DB::table('storefront_product')
                ->where('storefront_id', $storefrontId)
                ->where('slug', $slug)
                ->where('product_id', '!=', $productId)
                ->exists()
        ) {
            // The transform's own collision rule is "-{id}" (A-17); a hand edit that collides with
            // a THIRD row then walks -2, -3, … so the loop always terminates.
            $slug = $suffix === 2 ? $base.'-'.$productId : $base.'-'.$productId.'-'.$suffix;
            $suffix++;
            if ($suffix > 50) {
                throw new FieldRefusal('slug', ManageText::t('placement.slug_not_unique', 'تعذّر توليد رابط فريد من «:base».', ['base' => $base]));
            }
        }

        return $slug;
    }

    private function recordSlugRedirect(int $storefrontId, string $oldSlug, string $newSlug): void
    {
        if ($oldSlug === '' || $oldSlug === $newSlug) {
            return;
        }
        $from = '/product/'.$oldSlug;
        $to = '/product/'.$newSlug;

        StorefrontRedirect::query()->updateOrCreate(
            ['storefront_id' => $storefrontId, 'from_hash' => StorefrontRedirect::hashPath($from)],
            ['from_path' => $from, 'to_path' => $to, 'status' => 301, 'source' => 'slug_change'],
        );
    }
}
