/**
 * Which of a product's two names an operator should be shown (item 1b, 2026-09-17).
 *
 * ── The defect ───────────────────────────────────────────────────────────────────────────────
 *
 * Every product carries an Arabic name and an English one, and every screen rendered the Arabic —
 * whatever language the operator had chosen. An English-speaking operator got an English interface
 * wrapped around Arabic product names, which is the dashboard showing what the system STORES rather
 * than what the reader can use.
 *
 * ── The rule ─────────────────────────────────────────────────────────────────────────────────
 *
 * Show the name in the operator's own language. Where it is missing, show the other one and MARK it
 * — never an empty cell.
 *
 * The marking is the part worth being careful about. A silent fallback would hide a real gap: 750
 * imported products have no supplier code and a smaller number have no English name, and those are
 * exactly the rows somebody is meant to go and fill in. The same reasoning the bilingual CSV export
 * already follows — *"an empty cell is information; a duplicated one hides the gap"* — except that a
 * screen has somewhere to put the mark, so it shows the text AND says it is standing in.
 *
 * `secondary` is the other language, for the small line beneath. It is null when there is nothing to
 * put there, and null in the fallback case too: repeating the same string twice under itself tells
 * the reader nothing.
 */

/**
 * Both locales of a name, as every screen already holds it.
 *
 * Both sides are OPTIONAL on purpose. The product list types the pair exactly (`{ar, en}`), but the
 * edit form holds its translated fields as `Record<string, string>` — the shape the form components
 * share — and a required `ar` would reject it at the call site over a difference that does not
 * exist in the data. The function treats absent and empty the same way regardless.
 */
export interface TitlePair {
    ar?: string | null;
    en?: string | null;
}

export interface LocalisedTitle {
    /** The name to render. Empty only when the product has no name in either language. */
    text: string;
    /** The other language, for the muted line underneath — null when there is nothing to show. */
    secondary: string | null;
    /** True when `text` is NOT in the operator's language, so the screen can say so. */
    fallback: boolean;
    /** True when neither language has a name at all. */
    missing: boolean;
}

/** `locale` is the operator's chosen dashboard language, as shared by Inertia. */
export function localisedTitle(pair: TitlePair | null | undefined, locale: string): LocalisedTitle {
    const ar = (pair?.ar ?? '').trim();
    const en = (pair?.en ?? '').trim();

    const wanted = locale === 'ar' ? ar : en;
    const other = locale === 'ar' ? en : ar;

    if (wanted !== '') {
        return { text: wanted, secondary: other === '' ? null : other, fallback: false, missing: false };
    }
    if (other !== '') {
        return { text: other, secondary: null, fallback: true, missing: false };
    }

    return { text: '', secondary: null, fallback: false, missing: true };
}

/**
 * The one-line form, for an `aria-label` or a confirmation sentence.
 *
 * Falls back to the product code rather than to an empty string: a dialog that says
 * "hide «»" names nothing, and the code is what the operator would search for anyway.
 */
export function titleOrCode(pair: TitlePair | null | undefined, locale: string, code: string): string {
    const { text, missing } = localisedTitle(pair, locale);

    return missing ? code : text;
}
