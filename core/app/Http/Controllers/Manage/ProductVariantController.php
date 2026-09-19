<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Activity\ActivityLog;
use App\Domain\Catalog\ConversionGuard;
use App\Domain\Catalog\VariantWriter;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The variants panel's endpoints (wave 4B scope item 3), inside the product form.
 *
 * Separate routes rather than fields of the product form, for one reason: **a stock change is a
 * LEDGER EVENT, not a form field.** Folding "quantity 12" into the product save would mean a
 * failed validation on the title could roll back a movement, or worse, a re-submitted form could
 * replay one. Each variant row posts on its own, `InventoryService` writes its own movement, and
 * the product form never owns a quantity.
 *
 * Authorisation is `can:manage-catalog` for the row's attributes and `can:manage-inventory` for
 * the quantity — data-entry holds both (AGENTS §2.7), and naming them separately is what lets a
 * future role hold one without the other.
 *
 * Every refusal from {@see VariantWriter} and {@see ConversionGuard} comes back as a validation
 * error on the field that caused it, so the panel shows the sentence next to the control instead
 * of a 500 page the team cannot act on.
 *
 * ── Why this screen writes to the activity log, when the ledger already covers it ────────────
 *
 * It only LOOKED covered. A variant's QUANTITY has been audited since wave 3.5 — every movement
 * carries an actor, and {@see App\Domain\Inventory\InventoryService} writes a second
 * `catalog_product_variants` entry into the activity log for human adjustments — so "who changed
 * this number" always had an answer. What had no answer at all was the ROW: who added this size,
 * who renamed it, who re-priced it, who deleted it, who moved it up the panel. Four write actions
 * here, and none of them recorded anything.
 *
 * So the snapshot in {@see self::logFields()} is the row's OWN fields and deliberately excludes
 * `stock_express` and `stock_market`. That is not a shortcut: those two are the ledger's, they are
 * already in this table under `ActivityLog::ADJUSTED`, and copying them here would put two writers
 * on one fact — with this one unable to say which movement it came from. The two never overlap:
 * the inventory entries carry the `express`/`market` keys and the `adjusted` action, these carry
 * the attribute keys and `created`/`updated`/`deleted`.
 */
final class ProductVariantController
{
    public function __construct(private readonly VariantWriter $variants) {}

    public function store(Request $request, int $product): RedirectResponse
    {
        self::assertProduct($product);
        $data = $this->validated($request, null);

        try {
            $variant = $this->variants->create($product, $data, self::actorId($request));
        } catch (RuntimeException $e) {
            // The conversion refusal lands here. It is not a field error on `label` — it is about
            // the PRODUCT — so it is reported under a name the panel renders at its top.
            throw ValidationException::withMessages(['variants' => $e->getMessage()]);
        }

        // AFTER the create, and only on the path that reached it: a refusal above leaves by
        // exception, so a conversion that was turned away writes no entry saying a size was added.
        ActivityLog::record(
            'catalog_product_variants',
            $variant,
            ActivityLog::CREATED,
            [],
            self::logFields($variant),
            label: self::logLabel($product, $variant),
        );

        return back()->with('status', ManageText::t('variants.added', 'تمت إضافة الصف.'));
    }

    public function update(Request $request, int $product, int $variant): RedirectResponse
    {
        self::assertProduct($product);
        $data = $this->validated($request, $variant);

        // BEFORE the write, or the diff is a row compared with itself. A save that changed nothing
        // produces an empty diff and `ActivityLog::record()` drops it, so the panel's per-row save
        // does not fill the log with entries saying so.
        $before = self::logFields($variant);

        try {
            $this->variants->update($product, $variant, $data, self::actorId($request));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['variants' => $e->getMessage()]);
        }

        ActivityLog::record(
            'catalog_product_variants',
            $variant,
            ActivityLog::UPDATED,
            $before,
            self::logFields($variant),
            // Captured AFTER: a rename's most useful label is the new name, and the old one is in
            // `changes`, which is where a diff belongs.
            label: self::logLabel($product, $variant),
        );

        return back()->with('status', ManageText::t('variants.saved', 'تم حفظ الصف.'));
    }

    /**
     * Delete — or explain why not.
     *
     * A refusal is a 302 back with an error rather than a 403/409, because the panel is a form and
     * the team needs the sentence ("مرتبط بـ 3 سطر طلب") on the row they clicked.
     */
    public function destroy(Request $request, int $product, int $variant): RedirectResponse
    {
        self::assertProduct($product);

        // Both captured while the row still exists — after the delete there is nothing left to
        // read, and "what did it look like before?" is the question this log answers.
        $before = self::logFields($variant);
        $label = self::logLabel($product, $variant);

        try {
            $result = $this->variants->delete($product, $variant);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['variants' => $e->getMessage()]);
        }

        // Only a delete that HAPPENED. The three refusals return `deleted: false` rather than
        // throwing, and a log that recorded them would report deletions that never took place.
        if ($result['deleted']) {
            ActivityLog::record(
                'catalog_product_variants',
                $variant,
                ActivityLog::DELETED,
                $before,
                [],
                label: $label,
            );
        }

        return $result['deleted']
            ? back()->with('status', $result['reason'])
            : back()->withErrors(['variants' => $result['reason']]);
    }

    public function reorder(Request $request, int $product): RedirectResponse
    {
        self::assertProduct($product);
        $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'min:1'],
        ]);

        $moved = $this->variants->reorder($product, Coerce::orderedIntList($request->input('ids')));

        /*
         * ONE entry for the whole reorder, not one per moved row — the same call the category
         * tree's reorder makes, and for the same reason.
         *
         * A per-row log answers "why is THIS record the way it is", which is a real question for a
         * price or a hidden flag: the answer has to name the record. `sort` is not like that.
         * Nobody asks why a size sits third; they ask who rearranged the panel, and eight entries
         * each saying `sort: 2 ← 3` bury that answer instead of giving it. A log nobody reads is
         * worth no more than no log.
         *
         * So the SUBJECT is the panel, and the panel's only identifier is its product — which is
         * why `subject_id` here is a PRODUCT id under the variants type, exactly as the category
         * reorder records the parent whose level was reordered. Nothing dereferences this id
         * (`ActivityController::currentNames()` resolves products and categories only), and the
         * label carries the identity a reader actually uses: the product's name.
         *
         * `rows_reordered` is a COUNT, never a sentence — a number needs no translating, and an
         * English sentence stored in an Arabic operator's audit trail is the one thing this table
         * can never take back, because the log is forward-only and is never rewritten.
         */
        if ($moved > 0) {
            ActivityLog::record(
                'catalog_product_variants',
                $product,
                ActivityLog::UPDATED,
                ['rows_reordered' => null],
                ['rows_reordered' => $moved],
                label: self::logLabel($product),
            );
        }

        return back()->with('status', ManageText::t('variants.reordered', 'تم ترتيب :count صفًا.', ['count' => $moved]));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $variantId): array
    {
        // Written for the person holding the keyboard: what to do, not what is invalid. ONE key
        // said on whichever of the two fields the operator is looking at — two keys would be two
        // Englishes for one sentence.
        $colourOrSize = ManageText::t(
            'variants.colour_or_size_required',
            'اختر لونًا أو مقاسًا على الأقل: الصف الذي لا يحدد أيًّا منهما ليس مقاسًا ولا لونًا، وسيظهر على المتجر كأنه نسخة مكرّرة من المنتج.',
        );

        return Coerce::arr($request->validate([
            'label' => ['required', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('catalog_product_variants', 'sku')->ignore($variantId)],
            /*
             * AT LEAST ONE OF colour / size (task 4.2). A variant is "this product, in this
             * colour, in this size" — a row with neither is not a variant, it is a duplicate of
             * the product with its own stock bucket, and the two are indistinguishable on the
             * storefront. `required_without` each way says it once per field and produces an error
             * on the field the operator is looking at.
             */
            'color_id' => ['nullable', 'required_without:size_id', 'integer', Rule::exists('catalog_colors', 'id')],
            'size_id' => ['nullable', 'required_without:color_id', 'integer', Rule::exists('catalog_sizes', 'id')],
            // A DELTA, not a price: the variant's price is the product's plus this. Negative is
            // legal (a smaller size costing less) and is exactly why the rule is not `min:0`.
            'price_delta' => ['nullable', 'numeric', 'min:-99999999', 'max:99999999'],
            'is_active' => ['required', 'boolean'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
            // Absolute quantities. Optional: a save that does not send them moves no units and
            // writes no ledger row.
            'stock_express' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'stock_market' => ['nullable', 'integer', 'min:0', 'max:1000000'],
        ], [
            'color_id.required_without' => $colourOrSize,
            'size_id.required_without' => $colourOrSize,
        ]));
    }

    /** A product that exists and is not archived, or 404. */
    private static function assertProduct(int $productId): void
    {
        $exists = DB::table('catalog_products')->where('id', $productId)->whereNull('deleted_at')->exists();
        if (! $exists) {
            abort(404);
        }
    }

    private static function actorId(Request $request): ?int
    {
        $user = $request->user();

        return $user === null ? null : Coerce::nint($user->getAuthIdentifier());
    }

    /**
     * What a variant ROW is, for the activity log.
     *
     * Seven fields, and they are exactly {@see VariantWriter::COLUMNS} minus `product_id` — the
     * columns this screen may write. `product_id` is left out because it never changes: a variant
     * belongs to the product it was created under, and every endpoint here is scoped to it.
     *
     * `stock_express` and `stock_market` are NOT here, and that omission is the point. Quantities
     * are the ledger's, `InventoryService` already records the human ones against this same
     * subject type, and a second writer copying them in would produce two entries for one fact —
     * the second of them unable to name the movement it came from. What is audited here is the
     * row: its identity (`label`, `sku`), what it IS (`color_id`, `size_id`), what it costs
     * (`price_delta`), whether it can be sold (`is_active`) and where it sits (`sort`).
     *
     * @return array<string, mixed>
     */
    private static function logFields(int $variantId): array
    {
        $row = DB::table('catalog_product_variants')->where('id', $variantId)
            ->first(['label', 'sku', 'color_id', 'size_id', 'price_delta', 'is_active', 'sort']);
        if (! is_object($row)) {
            return [];
        }
        $variant = Row::cast($row);

        return [
            'label' => Row::str($variant, 'label'),
            'sku' => Row::nstr($variant, 'sku'),
            'color_id' => Row::nint($variant, 'color_id'),
            'size_id' => Row::nint($variant, 'size_id'),
            'price_delta' => Row::money($variant, 'price_delta'),
            'is_active' => Row::bool($variant, 'is_active'),
            'sort' => Row::int($variant, 'sort'),
        ];
    }

    /**
     * `ساعة رولكس: أسود / 42مم` — the product, then the row, which is how somebody would say it.
     *
     * A variant's own label is `42mm` or `أسود`, which identifies nothing on its own, and the
     * activity screen resolves live names for products and categories ONLY — so for a variant the
     * captured label is the whole of what a reader gets. Naming the product is also what makes the
     * entry findable: `subject_label` is one of the two columns that screen searches.
     *
     * Both halves are DATA — a product title and an operator-typed row name — so neither belongs
     * on the translation seam, and an entry written today still reads the same in a year.
     */
    private static function logLabel(int $productId, ?int $variantId = null): string
    {
        /*
         * `FIELD(locale, 'ar', 'en')` — Arabic first, English if there is no Arabic — which is the
         * ordering `ProductWriter::labelFor()` and `PlacementController::productLabel()` already
         * use for exactly this job.
         *
         * Written pinned to `locale = 'ar'`, which is safe TODAY only because `ProductWriter`
         * refuses to save a product without an Arabic title. That is a guarantee held somewhere
         * else, and an entry reading `#412` would be the cost of it moving — so this asks the same
         * question the other two label helpers ask, and does not depend on the answer.
         *
         * The label is a SNAPSHOT either way: it is stored, never re-rendered, so unlike the names
         * on the screens it does not go through the reader's-language seam.
         */
        $product = Coerce::nstr(DB::table('catalog_product_translations')
            ->where('product_id', $productId)
            ->orderByRaw("FIELD(locale, 'ar', 'en')")
            ->value('title')) ?? ('#'.$productId);

        if ($variantId === null) {
            return $product;
        }

        $variant = Coerce::nstr(DB::table('catalog_product_variants')->where('id', $variantId)->value('label'));

        return $variant === null || $variant === '' ? $product : $product.': '.$variant;
    }
}
