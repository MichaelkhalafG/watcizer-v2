<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The gate on the two dashboard endpoints that REPLACE a whole record.
 *
 * ── The hazard, found on 2026-09-12 ──────────────────────────────────────────────────────────
 *
 * `PUT /manage/storefronts/{storefront}/products/{product}` and
 * `PUT /manage/lookups/{list}/{id}` store the submitted payload as the WHOLE row. That is
 * deliberate and the screens depend on it: clearing a field is done by submitting it empty, and a
 * merge-on-absence endpoint could never clear anything. But it means a caller that omits a field
 * DELETES its value, silently and with a 302 that looks like a successful save.
 *
 * It is not theoretical. While building `compat:edit-probe` a minimal product payload — wa_code,
 * brand, price, currency, title — was PUT at a live product. The save succeeded and took with it
 * `grade_id`, the watch specs, both descriptions, the sale price and every storefront-1 placement;
 * a later lookup PUT without `extra.hex` cleared a colour's hex so `catalog/meta` served
 * `color_value: null` where legacy had `#111111`. Nothing on any screen showed a problem. The
 * compat harness is what caught both, and a drop-and-rebuild is what repaired them.
 *
 * Wave 4D brings importers, which are exactly the kind of caller that assembles a payload by hand
 * from whatever a spreadsheet happens to carry. So the danger is made EXPLICIT rather than removed:
 *
 *  • the screens send `_complete=1`, a declaration that the payload is the entire record;
 *  • every other caller is refused, by name, with the reason and the alternative;
 *  • the semantics do not change — a marked payload still replaces, because that is the contract
 *    the screens need.
 *
 * The marker is a DECLARATION, not a verification: it cannot prove a payload is complete, and it
 * is not meant to. What it buys is that no caller can clear a record by accident — clearing now
 * requires saying "this is the whole record", in the request, on purpose. An importer that wants
 * to change three columns must use a partial-update path instead of asserting something false.
 */
final class FullReplace
{
    /** The field the dashboard forms send with every full-record save. */
    public const MARKER = '_complete';

    /**
     * Refuse a payload that has not declared itself complete.
     *
     * @param  string  $record  what gets replaced, in Arabic, for the one-in-a-million case where
     *                          this reaches a screen (a hand-built request, a stale cached bundle)
     * @param  string  $field  where to hang the error so the form shows it at all
     *
     * @throws ValidationException
     */
    public static function assert(Request $request, string $record, string $field): void
    {
        if (self::declared($request)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => 'هذا الطلب يستبدل '.$record.' بالكامل، ولم يُعلن أنه كامل، فأي خانة غائبة كانت ستُمسح. '
                .'شاشات اللوحة ترسل الإعلان تلقائيًا؛ إن جاء الطلب من سكربت أو استيراد فاستخدم مسار التحديث الجزئي. '
                .'[developer] This endpoint REPLACES the whole record: every key absent from the payload is CLEARED. '
                .'Send '.self::MARKER.'=1 to declare the payload is the complete record, or use an explicit '
                .'partial-update path (an importer must never assert completeness it does not have). '
                .'See CLEAN_CORE_STUDY §2.9.7 and AGENTS §3.',
        ]);
    }

    /** Whether this request declares itself a complete record. */
    public static function declared(Request $request): bool
    {
        $value = $request->input(self::MARKER);

        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
