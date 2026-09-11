<?php

namespace App\Http\Controllers\Manage;

use App\Domain\Catalog\ConversionGuard;
use App\Domain\Catalog\VariantWriter;
use App\Support\Coerce;
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
 */
final class ProductVariantController
{
    public function __construct(private readonly VariantWriter $variants) {}

    public function store(Request $request, int $product): RedirectResponse
    {
        self::assertProduct($product);
        $data = $this->validated($request, null);

        try {
            $this->variants->create($product, $data, self::actorId($request));
        } catch (RuntimeException $e) {
            // The conversion refusal lands here. It is not a field error on `label` — it is about
            // the PRODUCT — so it is reported under a name the panel renders at its top.
            throw ValidationException::withMessages(['variants' => $e->getMessage()]);
        }

        return back()->with('status', 'تمت إضافة الصف.');
    }

    public function update(Request $request, int $product, int $variant): RedirectResponse
    {
        self::assertProduct($product);
        $data = $this->validated($request, $variant);

        try {
            $this->variants->update($product, $variant, $data, self::actorId($request));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['variants' => $e->getMessage()]);
        }

        return back()->with('status', 'تم حفظ الصف.');
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

        try {
            $result = $this->variants->delete($product, $variant);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['variants' => $e->getMessage()]);
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

        return back()->with('status', "تم ترتيب {$moved} صفًا.");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?int $variantId): array
    {
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
            // Written for the person holding the keyboard: what to do, not what is invalid.
            'color_id.required_without' => 'اختر لونًا أو مقاسًا على الأقل: الصف الذي لا يحدد أيًّا منهما ليس مقاسًا ولا لونًا، وسيظهر على المتجر كأنه نسخة مكرّرة من المنتج.',
            'size_id.required_without' => 'اختر لونًا أو مقاسًا على الأقل: الصف الذي لا يحدد أيًّا منهما ليس مقاسًا ولا لونًا، وسيظهر على المتجر كأنه نسخة مكرّرة من المنتج.',
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
}
