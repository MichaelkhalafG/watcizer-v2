<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * The PARTIAL-update path for a product — the thing AGENTS §3 says does not exist yet (wave 4D).
 *
 * ── Why this class had to be written before any importer ─────────────────────────────────────
 *
 * `PUT /manage/storefronts/{s}/products/{p}` is a **full-REPLACE** contract (§2.9.7): the payload
 * IS the row, so a key it omits is CLEARED. That is deliberate and stays — it is how the form
 * empties a field. It is also exactly wrong for a spreadsheet, and the cost is measured: a
 * five-field payload PUT at a live product took with it `grade_id`, the watch specs, both
 * descriptions, the sale price and every storefront-1 placement. Nothing on any screen showed it.
 *
 * `App\Support\FullReplace` now refuses a caller that has not declared `_complete=1`, but the
 * declaration is a DECLARATION, not a verification: a marked five-field payload still destroys
 * what it omits. So an importer must not send it, and until this class existed an importer had no
 * supported way to change a column at all.
 *
 * ── The contract ─────────────────────────────────────────────────────────────────────────────
 *
 *  1. **Only named fields are touched.** Every column and locale not named keeps its stored value
 *     — not "is re-written with the same value", not written at all.
 *  2. **The addressable set is DECLARED** ({@see self::SCALARS}, {@see self::TRANSLATED}). An
 *     unknown field is a RuntimeException naming it, never a silent no-op — a typo'd column in a
 *     sheet header would otherwise import as "nothing happened, all good".
 *  3. **`_complete` is refused.** A caller that sends it thinks this is the full-replace endpoint,
 *     and the two have opposite semantics; guessing which one they meant is how the damage above
 *     happens.
 *  4. **The result is a DIFF against the stored values**, so the same patch applied twice reports
 *     zero changes the second time. That is what makes an import idempotent, and it is measured
 *     rather than asserted.
 *  5. **A derived change is reported as one.** See the sale-price rule below: it is the single
 *     case where this class writes a column the caller did not name.
 *  6. **No stock column is addressable.** `stock_express`, `stock_market` and `in_stock` are
 *     absent from the declared set, so naming one is refused by rule 2 — and `StockWriteGuard`
 *     would refuse the statement anyway. Stock moves through `InventoryService`, always (D-21).
 *  7. **No creation.** This class only ever UPDATEs. A row that does not exist is a
 *     RuntimeException, so a sheet naming an unknown product cannot quietly insert one — which
 *     also means `PreSwitch` does not gate it: an EDIT is allowed before the write-switch
 *     (§2.23). **The caveat that matters to an importer:** switch night rebuilds the clean tables
 *     from legacy, so a pre-switch import is REVERTED and has to be run again afterwards.
 *  8. **Family and `specs` are NOT addressable.** Changing family rewrites the spec block and can
 *     delete a `catalog_product_watch_specs` row; that is a form decision a human makes on a
 *     screen, not a column a spreadsheet flips.
 *
 * ── The one derived change, and why it is not optional ───────────────────────────────────────
 *
 * The price contract both the storefront and the frontend enforce: a sale price is a sale price
 * only when `0 < sale < selling`. The full-replace path gets this for free because it always has
 * both numbers. A patch may carry one, the other, or neither — so the rule is evaluated against
 * the RESULTING row. A patch that lowers `selling_price` under a stored `sale_price` therefore
 * nulls that sale, and says so in `PatchResult::$derived`. Leaving it would make the storefront
 * quote a sale above the list price and fail the checkout total check with "Order total mismatch".
 */
final class ProductPatcher
{
    /**
     * Columns of `catalog_products` a patch may name, each with the coercion the full-replace path
     * uses for it, so the two never store a value in two shapes.
     *
     * `family`, `specs`, the stock columns, `created_by`/`created_at` and the id are deliberately
     * absent — see the class docblock, rules 6 and 8.
     *
     * @var array<string, string>
     */
    public const SCALARS = [
        'brand_id' => 'nint',
        'grade_id' => 'nint',
        'wa_code' => 'str',
        'sku' => 'nstr',

        'hs_code' => 'nstr',
        'purchase_price' => 'decimal',
        'selling_price' => 'decimal',
        'sale_price' => 'ndecimal',
        'currency' => 'currency',
        'low_stock_threshold' => 'int',
        'warranty_years' => 'nint',
        'is_active' => 'bool',
        'search_keywords' => 'nstr',
    ];

    /** Translated columns, addressed as `title.ar`, `short_description.en`, … */
    public const TRANSLATED = ProductWriter::TRANSLATED;

    /** Locales the patch may address. Arabic is required for a product to be displayable (§2.17). */
    public const LOCALES = ['ar', 'en'];

    /** A change to any of these has to reach `storefront_product.effective_price`. */
    private const PRICE_FIELDS = ['selling_price', 'sale_price'];

    /**
     * A change to any of these changes what the product can be FOUND by.
     *
     * Taken from what `ProductIndexer::reindex()` actually reads — the title, the BRAND's name,
     * `model_name`, `model_number`, `search_keywords` and the category names — not from what looks
     * searchable. The first cut guessed `wa_code`/`sku` (which the indexer ignores) and omitted
     * `brand_id` (which it does not): a brand change would have left the index naming the old
     * brand, and a test asserting the wrong column is what surfaced it.
     */
    // `sku` since the merge (item 4): one code column, and it is the one the index reads.
    private const SEARCHABLE = ['brand_id', 'sku', 'search_keywords'];

    /** Translated columns whose change also has to reach the index. */
    private const SEARCHABLE_TRANSLATED = ['title', 'model_name'];

    public function __construct(
        private readonly ProductWriter $writer,
        private readonly ProductIndexer $indexer,
    ) {}

    /**
     * Apply only what `$changes` names. Returns the diff that actually landed.
     *
     * @param  array<string, mixed>  $changes  scalar column => value, and/or `title.ar` => value
     *
     * @throws RuntimeException on an unknown field, a missing product, a `_complete` declaration,
     *                          or a translation that would leave a locale without a title
     */
    public function patch(int $productId, array $changes, ?int $actorId = null): PatchResult
    {
        self::refuseCompletenessDeclaration($changes);

        if ($changes === []) {
            // An empty patch is a caller bug, not a no-op: a sheet row that resolved to nothing
            // means the mapping is wrong, and reporting "0 changed" would hide it.
            throw new RuntimeException('Partial update called with no fields. Name at least one.');
        }

        [$scalars, $translations] = self::split($changes);

        return DB::transaction(function () use ($productId, $scalars, $translations, $actorId): PatchResult {
            $current = DB::table('catalog_products')->where('id', $productId)
                ->first(array_merge(['id'], array_keys(self::SCALARS)));

            if (! is_object($current)) {
                throw new RuntimeException("Product {$productId} does not exist. A partial update never creates.");
            }
            $row = Row::cast($current);

            [$write, $diff] = self::scalarDiff($row, $scalars);

            [$contractWrite, $contractDiff, $derived, $ignored] = self::salePriceContract($row, $scalars, $write);
            $write = array_merge($write, $contractWrite);
            $diff = array_merge($diff, $contractDiff);

            /*
             * Drop anything whose before and after turned out equal. The contract above can put a
             * column back to the value it already held — a rejected `sale_price` on a product that
             * had none — and a diff entry of `null → null` would be a change this class reports
             * without making, which is exactly what the importer's idempotency claim rests on NOT
             * happening.
             */
            [$write, $diff] = self::dropNoops($write, $diff);

            $translationDiff = $this->applyTranslations($productId, $translations);

            $changed = array_merge($diff, $translationDiff);

            if ($changed === [] && $ignored === []) {
                /*
                 * Nothing to write, and nothing derived either: the second run of the same import.
                 * `updated_at` is deliberately NOT bumped — a no-op that touches the timestamp
                 * would move the compat payload's `updated_at`, and the harness has no rule for
                 * that outside the commerce paths (D-17). An import that changed nothing must be
                 * invisible to the harness.
                 */
                return new PatchResult;
            }

            if ($changed === []) {
                // Nothing landed, but something was REFUSED — a sale price the contract would not
                // accept. Reporting that as a clean no-op is how a price import looks successful
                // and changes nothing.
                return new PatchResult(ignored: $ignored);
            }

            if ($write !== []) {
                $write['updated_by'] = $actorId;
                $write['updated_at'] = now();
                DB::table('catalog_products')->where('id', $productId)->update($write);
            }

            $this->afterWrite($productId, array_keys($changed));

            return new PatchResult(changes: $changed, derived: $derived, ignored: $ignored);
        });
    }

    /**
     * Which fields exist — so an importer can validate a sheet's HEADER before it reads a row,
     * and refuse the file instead of refusing three hundred rows one at a time.
     *
     * @return list<string>
     */
    public static function addressableFields(): array
    {
        $out = array_keys(self::SCALARS);
        foreach (self::TRANSLATED as $column) {
            foreach (self::LOCALES as $locale) {
                $out[] = $column.'.'.$locale;
            }
        }

        return $out;
    }

    // ── pieces ───────────────────────────────────────────────────────────────────────────────

    /**
     * `_complete=1` is the FULL-REPLACE declaration. Seeing it here means the caller has the two
     * endpoints confused, and the two have opposite semantics — so this refuses rather than
     * picking one.
     *
     * @param  array<string, mixed>  $changes
     */
    private static function refuseCompletenessDeclaration(array $changes): void
    {
        if (array_key_exists('_complete', $changes)) {
            throw new RuntimeException(
                'A partial update must not declare `_complete`: that marker belongs to the full-REPLACE '
                .'endpoints (§2.9.7), where an omitted key is CLEARED. Here an omitted key is left alone. '
                .'Remove the marker, or use the form endpoint deliberately.'
            );
        }
    }

    /**
     * Split the patch into scalar columns and `column.locale` translations, refusing anything not
     * declared.
     *
     * @param  array<string, mixed>  $changes
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    private static function split(array $changes): array
    {
        $scalars = [];
        $translations = [];

        foreach ($changes as $field => $value) {
            if (array_key_exists($field, self::SCALARS)) {
                $scalars[$field] = $value;

                continue;
            }

            if (str_contains($field, '.')) {
                [$column, $locale] = explode('.', $field, 2);
                if (in_array($column, self::TRANSLATED, true) && in_array($locale, self::LOCALES, true)) {
                    $translations[$locale][$column] = $value;

                    continue;
                }
            }

            /*
             * Rule 2. A column name that is not in the declared set is an error naming itself —
             * a mistyped sheet header must not import as "nothing happened".
             */
            throw new RuntimeException(
                "[{$field}] is not a field a partial update may change. Addressable: "
                .implode(', ', self::addressableFields()).'.'
            );
        }

        return [$scalars, $translations];
    }

    /**
     * The scalar columns that would actually change, and the diff.
     *
     * Compared as the DATABASE stores them — decimals normalised to two places, booleans to
     * `0`/`1` — because `'100'` versus `'100.00'` is not a change and reporting it as one would
     * make every import look like it did something.
     *
     * @param  array<string, mixed>  $scalars
     * @return array{0: array<string, mixed>, 1: array<string, array{from: string|null, to: string|null}>}
     */
    private static function scalarDiff(stdClass $row, array $scalars): array
    {
        $write = [];
        $diff = [];

        foreach ($scalars as $column => $raw) {
            $to = self::coerce(self::SCALARS[$column], $raw);
            $from = self::stored($row, $column);

            if (self::same($from, $to)) {
                continue;
            }

            $write[$column] = $to;
            $diff[$column] = ['from' => $from, 'to' => self::asString($to)];
        }

        return [$write, $diff];
    }

    /**
     * The price contract, evaluated against the RESULTING row (see the class docblock).
     *
     * Three outcomes, and telling them apart is the whole point:
     *
     *  - **it holds** — nothing to do.
     *  - **the caller NAMED `sale_price` and their value breaks it** — their value is refused and
     *    reported in `$ignored` with the reason. The column ends at NULL, which is a real change
     *    only if something was stored there before.
     *  - **the caller did NOT name it and lowering `selling_price` invalidated the stored sale** —
     *    the sale is nulled and reported in `$derived`, because this class just changed a column
     *    nobody asked about and that must never be silent.
     *
     * @param  array<string, mixed>  $named  the fields the caller named
     * @param  array<string, mixed>  $write  what is already going to be written
     * @return array{0: array<string, mixed>, 1: array<string, array{from: string|null, to: string|null}>, 2: list<string>, 3: array<string, string>}
     */
    private static function salePriceContract(stdClass $row, array $named, array $write): array
    {
        // Nothing to re-check unless a price moved.
        if (! array_key_exists('selling_price', $named) && ! array_key_exists('sale_price', $named)) {
            return [[], [], [], []];
        }

        $sellingAfter = array_key_exists('selling_price', $write)
            ? (float) Coerce::str($write['selling_price'])
            : (float) (self::stored($row, 'selling_price') ?? '0');

        $saleAfterRaw = array_key_exists('sale_price', $write)
            ? $write['sale_price']
            : self::stored($row, 'sale_price');
        $saleAfter = $saleAfterRaw === null ? null : (float) Coerce::str($saleAfterRaw);

        if ($saleAfter === null || ($saleAfter > 0 && $saleAfter < $sellingAfter)) {
            return [[], [], [], []];                    // the contract holds
        }

        $from = self::stored($row, 'sale_price');
        $callerNamedIt = array_key_exists('sale_price', $named);

        $ignored = $callerNamedIt
            ? ['sale_price' => sprintf(
                'refused: a sale price must be greater than 0 and less than the selling price (%s). Stored as none.',
                number_format($sellingAfter, 2, '.', '')
            )]
            : [];

        return [
            ['sale_price' => null],
            ['sale_price' => ['from' => $from, 'to' => null]],
            // Derived ONLY when the caller never mentioned the column.
            $callerNamedIt ? [] : ['sale_price'],
            $ignored,
        ];
    }

    /**
     * Remove entries whose before and after are the same value.
     *
     * @param  array<string, mixed>  $write
     * @param  array<string, array{from: string|null, to: string|null}>  $diff
     * @return array{0: array<string, mixed>, 1: array<string, array{from: string|null, to: string|null}>}
     */
    private static function dropNoops(array $write, array $diff): array
    {
        foreach ($diff as $field => $change) {
            if ($change['from'] === $change['to']) {
                unset($diff[$field], $write[$field]);
            }
        }

        return [$write, $diff];
    }

    /**
     * Translations, per locale, per column — only the ones named.
     *
     * A locale row is `updateOrInsert`ed, which means a patch CAN bring a locale into existence
     * (an English title for a product that had none). That is an edit to an existing product, not
     * a creation, so `PreSwitch` does not apply — but it does mean the NOT NULL `title` has to be
     * satisfied, so a patch that gives a new locale anything but a title is refused with the
     * reason rather than a database error.
     *
     * @param  array<string, array<string, mixed>>  $byLocale
     * @return array<string, array{from: string|null, to: string|null}>
     */
    private function applyTranslations(int $productId, array $byLocale): array
    {
        $diff = [];

        foreach ($byLocale as $locale => $columns) {
            $existing = DB::table('catalog_product_translations')
                ->where('product_id', $productId)->where('locale', $locale)
                ->first(array_merge(['id'], self::TRANSLATED));

            $write = [];
            foreach ($columns as $column => $raw) {
                $to = Coerce::nstr($raw);
                $to = $to === null || trim($to) === '' ? null : $to;
                $from = is_object($existing) ? Row::nstr(Row::cast($existing), $column) : null;

                if ($from === $to) {
                    continue;
                }
                $write[$column] = $to;
                $diff[$column.'.'.$locale] = ['from' => $from, 'to' => $to];
            }

            if ($write === []) {
                continue;
            }

            if (! is_object($existing)) {
                if (($write['title'] ?? null) === null) {
                    throw new RuntimeException(ManageText::t(
                        'products.locale_needs_title_to_create',
                        'اللغة [:locale] غير موجودة لهذا المنتج، ولا يمكن إنشاؤها بدون عنوان. أضف [title.:locale] مع بقية الحقول.',
                        ['locale' => $locale],
                    ));
                }
                DB::table('catalog_product_translations')->insert(
                    $write + ['product_id' => $productId, 'locale' => $locale]
                );

                continue;
            }

            /*
             * Emptying the ARABIC title would leave the product undisplayable: fallback is off
             * (§2.17), so a blank Arabic row is indistinguishable from a real one on the
             * storefront. The form path refuses this too; refusing it here keeps a spreadsheet
             * from doing what a human cannot.
             */
            if ($locale === 'ar' && array_key_exists('title', $write) && $write['title'] === null) {
                throw new RuntimeException(ManageText::t(
                    'products.arabic_title_not_emptiable',
                    'لا يمكن إفراغ العنوان العربي: الترجمة الاحتياطية مُعطّلة والمنتج بدونه لا يصلح للعرض.',
                ));
            }

            DB::table('catalog_product_translations')
                ->where('product_id', $productId)->where('locale', $locale)
                ->update($write);
        }

        return $diff;
    }

    /**
     * The derived work a change implies. Skipping any of it is how a price change fails to reach
     * the storefront, or a renamed product stops being findable.
     *
     * @param  list<string>  $changedFields
     */
    private function afterWrite(int $productId, array $changedFields): void
    {
        $touchedPrice = false;
        $touchedSearchable = false;

        foreach ($changedFields as $field) {
            if (in_array($field, self::PRICE_FIELDS, true)) {
                $touchedPrice = true;
            }
            if (in_array($field, self::SEARCHABLE, true)) {
                $touchedSearchable = true;
            }
            foreach (self::SEARCHABLE_TRANSLATED as $translated) {
                if (str_starts_with($field, $translated.'.')) {
                    $touchedSearchable = true;
                }
            }
        }

        if ($touchedPrice) {
            // `effective_price` is a maintained mirror of the catalog price while the override gate
            // is off (§2.4, D3). Forgetting this is how a storefront sells at yesterday's price.
            $this->writer->refreshEffectivePrices($productId);
        }
        if ($touchedSearchable) {
            $this->indexer->reindex($productId);
        }

        /*
         * Any change at all invalidates the cached payloads that carry this product — through the
         * WRITER's own `flush()`, not a second copy of the rule. A patch that changed a price and
         * left a cached listing quoting the old one is the same defect as not refreshing
         * `effective_price`, one layer out.
         */
        $this->writer->flush($productId);
    }

    // ── value helpers ────────────────────────────────────────────────────────────────────────

    /**
     * `is_active` answers 1/0 rather than a PHP bool: the column is `tinyint(1)`, and the diff
     * compares against what the column STORES, so the coercion speaks the database's terms.
     */
    private static function coerce(string $type, mixed $value): string|int|null
    {
        return match ($type) {
            'nint' => Coerce::nint($value),
            'int' => Coerce::int($value),
            'str' => Coerce::str($value),
            'nstr' => Coerce::nstr($value),
            'bool' => Coerce::bool($value) ? 1 : 0,
            'currency' => strtoupper(Coerce::str($value, 'EGP')),
            'decimal' => number_format(Coerce::float($value), 2, '.', ''),
            'ndecimal' => ($n = Coerce::nfloat($value)) === null ? null : number_format($n, 2, '.', ''),
            default => throw new RuntimeException("Unknown coercion [{$type}]."),
        };
    }

    /** The stored value as a string, so the comparison is against what the column really holds. */
    private static function stored(stdClass $row, string $column): ?string
    {
        $value = property_exists($row, $column) ? $row->{$column} : null;

        return $value === null ? null : (is_bool($value) ? ($value ? '1' : '0') : Coerce::str($value));
    }

    private static function asString(string|int|null $value): ?string
    {
        // No bool branch: `coerce()` already answers 1/0 for the one boolean column, so the
        // value reaching here is only ever a string, an int or null.
        return $value === null ? null : (string) $value;
    }

    /**
     * Is the stored value already what the patch wants?
     *
     * Numeric comparison for anything that looks like a number, so `100` and `100.00` are the same
     * value and an import does not report a change it did not make.
     */
    private static function same(?string $from, string|int|null $to): bool
    {
        $toString = self::asString($to);

        if ($from === null || $toString === null) {
            return $from === $toString;
        }
        if (is_numeric($from) && is_numeric($toString)) {
            return abs((float) $from - (float) $toString) < 0.000001;
        }

        return $from === $toString;
    }
}
