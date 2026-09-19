/**
 * The SEO fields, written from the product's own data (item 8, 2026-09-18).
 *
 * ── Why this exists ──────────────────────────────────────────────────────────────────────────
 *
 * The old dashboard had a button that filled these, and the developer asked for it back in those
 * words: *"It exists to prevent human error — the team will not write 7,700 meta descriptions."*
 * That is the whole argument. A field nobody fills is empty; a field 7,700 people fill in a hurry
 * is worse than empty, because it is wrong in ways nobody audits.
 *
 * ── Why it runs in the BROWSER and not on the server ─────────────────────────────────────────
 *
 * It writes from what is ON SCREEN, not from what is stored. An operator who has just corrected the
 * English title and then asks for the SEO fields expects the corrected title — and on a CREATE form
 * there is nothing stored at all. A server round trip would also need a route, an authorisation
 * rule, a loading state and an error state, to compose four strings the page already holds.
 *
 * ── What it deliberately does not write ──────────────────────────────────────────────────────
 *
 * No price, no stock, no "original", no "best". A meta description is served for months: a price in
 * it goes stale the first time somebody runs a sale, and a superlative is a claim this file is not
 * in a position to make. Everything below is a fact already on the product.
 */

export interface SeoInput {
    /** The product's own name, per locale. */
    title: { ar: string; en: string };
    /** The brand's name as the picker shows it, per locale — empty when none is chosen. */
    brand: { ar: string; en: string };
    /** The family in words ("ساعات" / "Watches"), never the stored token. */
    family: { ar: string; en: string };
    /** The primary category's name, per locale — the most specific true thing about the product. */
    category: { ar: string; en: string };
    modelNumber: string;
}

export interface SeoOutput {
    meta_title: { ar: string; en: string };
    meta_description: { ar: string; en: string };
    search_keywords: string;
}

/**
 * What search engines actually show. Both are the long-standing rules of thumb, and both matter for
 * the same reason: past them the text is cut mid-sentence, and a description that ends in "…" reads
 * as a page nobody finished.
 */
export const META_TITLE_MAX = 60;
export const META_DESCRIPTION_MAX = 160;

/** Collapse whitespace and trim — source data arrives with newlines and double spaces in it. */
function tidy(value: string): string {
    return value.replace(/\s+/g, ' ').trim();
}

/**
 * Cut to `max` characters WITHOUT breaking a word.
 *
 * Returns the text unchanged when it already fits, which is most of the time. When it does not, it
 * cuts at the last space before the limit — never mid-word, and never with an ellipsis, because an
 * ellipsis spends three of the characters that were the problem.
 */
export function clip(value: string, max: number): string {
    const text = tidy(value);
    if (text.length <= max) {
        return text;
    }

    const cut = text.slice(0, max);
    const lastSpace = cut.lastIndexOf(' ');

    // A single word longer than the limit — a 200-character imported title — has no space to cut
    // at. Hard-cut it: something is better than nothing, and the operator can see and fix it.
    return (lastSpace > max * 0.5 ? cut.slice(0, lastSpace) : cut).trim();
}

/** Join the parts that have something in them, with a separator, skipping the empty ones. */
function join(parts: string[], separator: string): string {
    return parts.map(tidy).filter((part) => part !== '').join(separator);
}

/**
 * One locale's title: the product, then the brand, but only when the brand is not already in it.
 *
 * "Michael Kors Watch for Women MK6268" does not become "Michael Kors Watch for Women MK6268 |
 * Michael Kors". Most of this catalogue's titles already carry the brand, which is exactly the case
 * a naive template gets wrong 7,000 times.
 */
function metaTitle(title: string, brand: string): string {
    const name = tidy(title);
    const maker = tidy(brand);

    if (name === '') {
        return clip(maker, META_TITLE_MAX);
    }
    if (maker === '' || name.toLowerCase().includes(maker.toLowerCase())) {
        return clip(name, META_TITLE_MAX);
    }

    return clip(`${name} | ${maker}`, META_TITLE_MAX);
}

/** One locale's description: what the thing IS, in one sentence of facts. */
function metaDescription(
    title: string,
    brand: string,
    family: string,
    category: string,
    modelNumber: string,
    locale: 'ar' | 'en',
): string {
    const name = tidy(title);
    if (name === '') {
        return '';
    }

    const maker = tidy(brand);
    const kind = tidy(category) === '' ? tidy(family) : tidy(category);
    const model = tidy(modelNumber);

    if (locale === 'ar') {
        /*
         * The Arabic below is PRODUCT CONTENT, not interface copy, and is exempt from the seam for
         * the same reason `Support\ArabicSearch`'s letter tables are: it is written INTO the
         * product's Arabic meta description, which is served to Arabic-speaking customers. It must
         * be Arabic whatever language the operator happens to be reading the dashboard in — routing
         * it through `t()` would make an English operator generate an English Arabic description.
         */
        return clip(
            join(
                [
                    name,
                    maker === '' ? '' : `من ${maker}`, // i18n-exempt: written into the Arabic meta description, not shown to the operator
                    kind === '' ? '' : `ضمن ${kind}`, // i18n-exempt: product content, not interface text
                    model === '' ? '' : `موديل ${model}`, // i18n-exempt: product content, not interface text
                ],
                '، ', // i18n-exempt: the Arabic comma, punctuation for the generated sentence
            ),
            META_DESCRIPTION_MAX,
        );
    }

    return clip(
        join(
            [
                name,
                maker === '' ? '' : `by ${maker}`,
                kind === '' ? '' : `in ${kind}`,
                model === '' ? '' : `model ${model}`,
            ],
            ', ',
        ),
        META_DESCRIPTION_MAX,
    );
}

/**
 * The search keywords — both languages in one field, because that is how the field is stored and
 * because a customer types Arabic or English into one box.
 *
 * Deduplicated case-insensitively, and the ORDER is kept: the most specific term first, so a
 * truncation downstream loses the vaguest word rather than the model number.
 */
function keywords(input: SeoInput): string {
    const parts = [
        input.modelNumber,
        input.brand.ar,
        input.brand.en,
        input.category.ar,
        input.category.en,
        input.family.ar,
        input.family.en,
        input.title.ar,
        input.title.en,
    ];

    const seen = new Set<string>();
    const out: string[] = [];
    for (const part of parts) {
        const term = tidy(part);
        const key = term.toLowerCase();
        if (term !== '' && !seen.has(key)) {
            seen.add(key);
            out.push(term);
        }
    }

    return out.join('، '); // i18n-exempt: the Arabic comma — stored in search_keywords, which holds both languages
}

/** Everything, for both locales. Empty inputs give empty outputs rather than a template with holes. */
export function generateSeo(input: SeoInput): SeoOutput {
    return {
        meta_title: {
            ar: metaTitle(input.title.ar, input.brand.ar),
            en: metaTitle(input.title.en, input.brand.en),
        },
        meta_description: {
            ar: metaDescription(input.title.ar, input.brand.ar, input.family.ar, input.category.ar, input.modelNumber, 'ar'),
            en: metaDescription(input.title.en, input.brand.en, input.family.en, input.category.en, input.modelNumber, 'en'),
        },
        search_keywords: keywords(input),
    };
}
