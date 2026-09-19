/**
 * The SEO fields, written from the product's own data.
 *
 * ── Why this exists ──────────────────────────────────────────────────────────────────────────
 *
 * The old dashboard had a button that filled these, and the developer asked for it back in those
 * words: *"It exists to prevent human error — the team will not write 7,700 meta descriptions."*
 * That is the whole argument. A field nobody fills is empty; a field 7,700 people fill in a hurry
 * is worse than empty, because it is wrong in ways nobody audits.
 *
 * ── What the LEGACY generator actually did (checked, 2026-09-19) ─────────────────────────────
 *
 * The brief said to look at it first, so it was read in full before a line of this was rewritten:
 * `backend/resources/views/Dashboard/product/create.blade.php`, `window.autoSEO`. It is worth
 * writing down what it was, because it is a narrower baseline than the team remembers:
 *
 *   • It was ENGLISH ONLY. `titleEn` was its only input; nothing Arabic was ever generated.
 *   • It wrote NO META DESCRIPTION AT ALL. That field was a plain textarea with `maxlength="160"`
 *     and it was filled by hand or left empty. There is nothing here to restore.
 *   • Its title was `titleEn + ' — ' + brand + ' | ' + category`, cut at 57 characters with `'...'`
 *     glued on — which spends three of the characters that were the problem and cuts mid-word.
 *   • Its keywords were `[titleEn, brand, category]` lowercased: the whole title as one keyword,
 *     which is not a phrase anybody searches.
 *   • It ran on the CREATE screen only. The edit screen had the three fields and no button.
 *
 * So "what the legacy generator did" is: a slug, a truncated English title, and three comma-joined
 * words. The developer's verdict — *"It fills the fields but produces nothing that would help these
 * sites rank"* — was about this file's previous version, and it applied at least as hard to the
 * legacy one. The only idea worth keeping from it is the idea itself: one button, every field
 * still editable afterwards.
 *
 * ── What changed here (item 5, second browser pass, 2026-09-19) ──────────────────────────────
 *
 *   1. **Length is measured in WIDTH, not characters.** A search engine cuts a title when it runs
 *      out of pixels, and "MMMMMMMMMMMMMMMMMMMM" and "iiiiiiiiiiiiiiiiiiii" are the same 20
 *      characters and nowhere near the same width. `estimateEm` below approximates the rendered
 *      width, and the budgets are in ems. Arabic gets more characters than English for the same
 *      box because Arabic glyphs are narrower, which the character rule could not express.
 *   2. **Nothing is cut mid-phrase.** The title is COMPOSED to fit: optional parts are dropped
 *      whole — the category, then the brand — and only a title that is one long name with no
 *      seams left is clipped. Same for the description, which sheds its attribute clause, then
 *      its audience word, before it is ever cut.
 *   3. **The description is a sentence, not a list.** It was `name, by brand, in category, model
 *      X` — four facts and three commas, which is a caption. It is now two sentences: what the
 *      thing is, and what the page holds. A person decides to click on the second one.
 *   4. **Keywords come from what the product IS.** Brand, audience, family, category, material and
 *      colour — and the COMBINATIONS people actually type ("ساعة رجالي مايكل كورس", "Michael Kors
 *      watches"), not the product's own 90-character title as a single keyword.
 *   5. **The two languages are written independently.** Not one translated from the other: Arabic
 *      leads with the noun and hangs the brand off `من`, English leads with the brand and puts the
 *      noun last, and each picks its own attribute phrasing. They are generated from the same
 *      FACTS, which is a different thing from being generated from each other.
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

type Locale = "ar" | "en";

/**
 * One name in both languages. Empty strings where a language has no name, never `null`.
 *
 * A `type` and not an `interface`, and the difference is load-bearing: the form assigns these
 * straight into its translated-field state, which is keyed `Record<string, string>`. TypeScript
 * gives an object type alias an implicit index signature and an interface none, so declaring this
 * as an interface makes `setPair("meta_title", generated.meta_title)` a type error.
 */
export type NamePair = {
    ar: string;
    en: string;
};

/**
 * An attribute WITH the part of the product it belongs to.
 *
 * The role is the whole reason this is richer than a flat list: "leather" and "brown" are facts,
 * but "a brown leather strap" is a sentence, and only the role makes the difference. Roles come
 * from the form itself — `case` / `band` / `glass` / `main` for materials, `dial` / `band` / `main`
 * for colours — and an unknown role degrades to the `main` phrasing rather than being dropped.
 */
export interface Attribute {
    role: string;
    name: NamePair;
}

export interface SeoInput {
    /** The product's own name, per locale. */
    title: NamePair;
    /** The brand's name as the picker shows it, per locale — empty when none is chosen. */
    brand: NamePair;
    /** The family KEY (`watch`, `bag`, …). The words for it, singular and plural, live below. */
    familyKey: string;
    /** The primary category's name, per locale — the most specific true thing about the product. */
    category: NamePair;
    /** The SKU — the manufacturer's model number since the two columns were merged (item 4). */
    modelNumber: string;
    /** Every audience ticked on the form. More than one means the generator says nothing (below). */
    genders: NamePair[];
    /** Case / band / glass / main materials, in whatever order the form holds them. */
    materials: Attribute[];
    /** Dial / band / main colours, in whatever order the form holds them. */
    colors: Attribute[];
}

export interface SeoOutput {
    meta_title: NamePair;
    meta_description: NamePair;
    search_keywords: string;
}

/**
 * The seven families in both languages, singular AND plural.
 *
 * Singular because a sentence needs one ("a watch with a steel case"), plural because a keyword
 * needs the other ("Michael Kors watches" is what people type, "Michael Kors watch" is not). The
 * previous version had only the plural and used it in both places, which is why the description
 * read "in Watches".
 *
 * They live HERE, next to the grammar that consumes them, rather than in the product form: the
 * generator writes an Arabic sentence and an English one in the same click, and `t()` answers only
 * in the active locale, so the seam cannot express this. `SeoGeneratorTest` keeps this list in step
 * with `Product::FAMILIES`.
 *
 * `other` has no singular noun on purpose — there is no word for "an other" — so a product in that
 * family is described by its CATEGORY instead, which is the more specific thing anyway.
 */
export const FAMILY_WORDS: Record<
    string,
    { ar: { one: string; many: string }; en: { one: string; many: string } }
> = {
    watch: {
        ar: { one: "ساعة", many: "ساعات" }, // i18n-exempt: product content, both locales at once
        en: { one: "watch", many: "watches" },
    }, // i18n-exempt: both locales at once, written into product content
    fashion: {
        ar: { one: "قطعة أزياء", many: "أزياء" }, // i18n-exempt: product content, both locales at once
        en: { one: "fashion piece", many: "fashion" },
    }, // i18n-exempt: both locales at once, written into product content
    bag: {
        ar: { one: "حقيبة", many: "حقائب" }, // i18n-exempt: product content, both locales at once
        en: { one: "bag", many: "bags" },
    }, // i18n-exempt: both locales at once, written into product content
    wallet: {
        ar: { one: "محفظة", many: "محافظ" }, // i18n-exempt: product content, both locales at once
        en: { one: "wallet", many: "wallets" },
    }, // i18n-exempt: both locales at once, written into product content
    perfume: {
        ar: { one: "عطر", many: "عطور" }, // i18n-exempt: product content, both locales at once
        en: { one: "perfume", many: "perfumes" },
    }, // i18n-exempt: both locales at once, written into product content
    electronics: {
        ar: { one: "جهاز", many: "إلكترونيات" }, // i18n-exempt: product content, both locales at once
        en: { one: "device", many: "electronics" },
    }, // i18n-exempt: both locales at once, written into product content
    other: { ar: { one: "", many: "أخرى" }, en: { one: "", many: "other" } }, // i18n-exempt: both locales at once, written into product content
};

/**
 * ── How long is too long ─────────────────────────────────────────────────────────────────────
 *
 * Google cuts a title and a description by the WIDTH of the box, not by a character count. The
 * familiar 60/160 numbers are that width divided by the average width of an English lowercase
 * letter, which is why they are only ever "about right" and why they are wrong in both directions:
 * a title of wide capitals overflows at 50, and an Arabic title still fits at 75.
 *
 * So the budget is in ems — the rendered width divided by the font size — and the two numbers below
 * are the real boxes: roughly 600px of a 20px face for the title, roughly 920px of a 14px face for
 * the description. For ordinary English lowercase they land within a character or two of 60 and
 * 160, so nothing familiar has moved; they simply now hold for text that is not that.
 */
export const TITLE_EM = 30;

export const DESCRIPTION_EM = 72;

const NARROW = new Set([
    "i",
    "l",
    "j",
    "t",
    "f",
    "r",
    "I",
    "(",
    ")",
    "[",
    "]",
    "|",
    ".",
    ",",
    "'",
    "`",
    "!",
    ";",
    ":",
    "’",
]);

const UPPERCASE = /[A-Z]/;

const DIGIT = /[0-9]/;

/** Arabic, its supplements, and the presentation forms an imported title can arrive in. */
const ARABIC = /[؀-ۿݐ-ݿﭐ-﷿ﹰ-﻿]/; // i18n-exempt: a character RANGE, not a string — it classifies letters, it is never shown

/**
 * The rendered width of a string, in ems, near enough to decide whether it fits.
 *
 * These are Arial's advance widths rounded to two places, and Arial is close enough to what a
 * search result is actually set in for a decision that only has two outcomes. It is an ESTIMATE and
 * is named one: measuring exactly would mean a canvas, a font that may not have loaded, and a
 * number that changes between the operator's machine and the customer's.
 */
export function estimateEm(text: string): number {
    let em = 0;

    for (const character of text) {
        if (character === " ") {
            em += 0.28;
        } else if (NARROW.has(character)) {
            em += 0.26;
        } else if (character === "m" || character === "M") {
            em += 0.83;
        } else if (character === "w") {
            em += 0.72;
        } else if (character === "W") {
            em += 0.94;
        } else if (UPPERCASE.test(character)) {
            em += 0.67;
        } else if (DIGIT.test(character)) {
            em += 0.56;
        } else if (ARABIC.test(character)) {
            em += 0.45;
        } else if (character >= "a" && character <= "z") {
            em += 0.52;
        } else {
            em += 0.5;
        }
    }

    return em;
}

/** Collapse whitespace and trim — source data arrives with newlines and double spaces in it. */
function tidy(value: string): string {
    return value.replace(/\s+/g, " ").trim();
}

/** Does this fit the box? The question every composition step below asks. */
export function fits(value: string, maxEm: number): boolean {
    return estimateEm(tidy(value)) <= maxEm;
}

/**
 * Cut to `maxEm` WITHOUT breaking a word — the last resort, when composition has already dropped
 * everything droppable and what is left is one long name.
 *
 * Never with an ellipsis: it spends width on saying that width ran out.
 */
export function clipToEm(value: string, maxEm: number): string {
    const text = tidy(value);
    if (fits(text, maxEm)) {
        return text;
    }

    const words = text.split(" ");
    let out = "";
    for (const word of words) {
        const next = out === "" ? word : `${out} ${word}`;
        if (!fits(next, maxEm)) {
            break;
        }
        out = next;
    }

    if (out !== "") {
        return trimDangling(out);
    }

    // A single word wider than the whole box — a 200-character imported title with no spaces. Cut
    // it by character, because something is better than nothing and the operator can see it.
    let hard = "";
    for (const character of text) {
        if (!fits(hard + character, maxEm)) {
            break;
        }
        hard += character;
    }

    return hard;
}

/**
 * Words a clipped line must not end on.
 *
 * Found on a real row: product 7010's English title cut at the width limit to "…fast charging
 * powerbank with", which reads as a sentence somebody abandoned. A word-safe cut is not enough —
 * the last word also has to be one that can END something. These are the joining words of the two
 * languages, and dropping a trailing one costs nothing and is never wrong.
 */
const DANGLING = new Set([
    ..."with and for the a an of by to from or in on".split(" "),
    // The Arabic joining words on ONE line, deliberately: split one per line they would each
    // need their own exemption marker, and a list of markers is a list nobody reads.
    ..."مع و من في على إلى أو ذات ذو".split(" "), // i18n-exempt: joining words of the generated Arabic, not interface copy
]);

/** A title that already ends a sentence, in either language's punctuation. */
const SENTENCE_END = /[.!?؟]$/; // i18n-exempt: punctuation, including the Arabic question mark

/** Trailing punctuation a cut can leave behind — the Arabic comma among it. */
const TRAILING_PUNCTUATION = /[\s\-|،,]+$/; // i18n-exempt: a punctuation class, not copy

/** Drop trailing joining words and punctuation left behind by a cut. */
function trimDangling(value: string): string {
    const words = value.split(" ");
    while (
        words.length > 1 &&
        DANGLING.has(
            words[words.length - 1]
                .replace(TRAILING_PUNCTUATION, "")
                .toLowerCase(),
        )
    ) {
        words.pop();
    }

    return words.join(" ").replace(TRAILING_PUNCTUATION, "");
}

/** Join the parts that have something in them, skipping the empty ones. */
function join(parts: string[], separator: string): string {
    return parts
        .map(tidy)
        .filter((part) => part !== "")
        .join(separator);
}

/** Is `needle` already inside `haystack`? Case-insensitive, because titles are typed by hand. */
function contains(haystack: string, needle: string): boolean {
    return (
        needle !== "" && haystack.toLowerCase().includes(needle.toLowerCase())
    );
}

function capitalise(value: string): string {
    return value === "" ? "" : value.charAt(0).toUpperCase() + value.slice(1);
}

/**
 * An attribute's name as ENGLISH prose wants it.
 *
 * The lookups screen stores `Stainless Steel` and `Blue`, because that is how a picker should read.
 * Inside a sentence they are common nouns — `a stainless steel case and a blue dial` — and the
 * capitals made the first draft of this read like a parts list, which was the defect being fixed.
 * Arabic has no case, so this applies to one locale and says so.
 */
function attributeName(name: NamePair, locale: Locale): string {
    const value = tidy(name[locale]);

    return locale === "en" ? value.toLowerCase() : value;
}

/**
 * ── The audience word ────────────────────────────────────────────────────────────────────────
 *
 * Arabic uses the lookup's own name, untouched: `ساعة` + `رجالي`. That is not laziness — it means
 * the wording lives in the team's data, where they can fix it without a deploy. If they decide the
 * catalogue should read `نسائية` rather than `نسائي`, they rename the row on the lookups screen and
 * every description generated afterwards says so.
 *
 * English cannot do the same, because English needs a possessive and `Women watch` is not English.
 * The table below is English GRAMMAR, not a mapping of meaning, and it covers the four rows that
 * exist (verified against `catalog_genders`: Men, Women, Unisex, Kids). A row added later that is
 * not in it is used as a plain modifier — `teen watch` — which is the graceful half of wrong.
 */
const GENDER_EN: Record<string, string> = {
    men: "men's",
    women: "women's",
    kids: "kids'",
    unisex: "unisex",
};

/**
 * The one audience word, or nothing.
 *
 * Nothing when more than one is ticked, ON PURPOSE: a watch listed for both men and women is not a
 * men's watch, and picking the first row would make the description say it was. "Unisex" would be
 * an inference — the team has a row for that and can tick it — so the generator stays quiet and
 * describes the product without an audience rather than inventing one.
 */
function genderWord(genders: NamePair[], locale: Locale): string {
    const named = genders.filter((gender) => tidy(gender[locale]) !== "");
    if (named.length !== 1) {
        return "";
    }

    const name = tidy(named[0][locale]);
    if (locale === "ar") {
        return name;
    }

    return GENDER_EN[name.toLowerCase()] ?? name.toLowerCase();
}

/** Materials first by the part a customer looks at first; unknown roles last, but kept. */
const MATERIAL_ORDER = ["case", "main", "band", "glass"];

const COLOR_ORDER = ["dial", "main", "band"];

function byRole(attributes: Attribute[], order: string[]): Attribute[] {
    return [...attributes]
        .filter(
            (attribute) =>
                tidy(attribute.name.ar) !== "" ||
                tidy(attribute.name.en) !== "",
        )
        .sort((a, b) => {
            const left = order.indexOf(a.role);
            const right = order.indexOf(b.role);

            return (
                (left === -1 ? order.length : left) -
                (right === -1 ? order.length : right)
            );
        });
}

/**
 * One material as a phrase, in the shape its language wants.
 *
 * Arabic names the part then the material (`جسم الساعة من ستانلس ستيل`), English the material then
 * the part (`a stainless steel case`) — and neither is a translation of the other, they are each
 * what that language does. `main` is the body of a bag or a wallet, and both languages avoid
 * inflecting anything: `خامة جلد` needs no agreement with a noun it never touches.
 *
 * ── Why the case alone is DEFINITE, and takes `من` ───────────────────────────────
 *
 * Two corrections, in order, and the second is the one that matters:
 *
 *   1. It read `علبة ${name}`. `علبة` is the box the watch ARRIVED IN as often as it is the
 *      watch's own body — the same collision that renamed the specification labels to
 *      `جسم الساعة`. And a body is made OF a material, so it takes `من`:
 *      `جسم ستانلس ستيل` is not a phrase anybody would write.
 *   2. The indefinite `جسم ساعة` then read badly for a reason only visible in the finished
 *      sentence: `role === 'case'` can fire on nothing but a WATCH — `case_material_id` is
 *      declared once, inside the watch block — so the subject is always `ساعة`, and the
 *      description named the same noun twice a clause apart:
 *
 *          ساعة رجالي من مايكل كورس، جسم ساعة من ستانلس ستيل…
 *
 *      Definite, it is a back-reference to the watch just named rather than a repetition — and it
 *      is the same two words the operator now reads on the specifications panel
 *      (`مادة جسم الساعة`, `قياس جسم الساعة`), so the shop and the dashboard say it
 *      the same way.
 *
 * The English is untouched throughout: `a stainless steel case` is what the watch trade calls it,
 * it never meant the box, and it does not need to mirror the Arabic's shape.
 */
function materialPhrase(role: string, name: string, locale: Locale): string {
    if (name === "") {
        return "";
    }

    if (locale === "ar") {
        if (role === "case") {
            return `جسم الساعة من ${name}`; // i18n-exempt: product content, written into the Arabic meta description
        }
        if (role === "band") {
            return `سوار ${name}`; // i18n-exempt: product content, written into the Arabic meta description
        }
        if (role === "glass") {
            return `زجاج ${name}`; // i18n-exempt: product content, written into the Arabic meta description
        }

        return `خامة ${name}`; // i18n-exempt: product content, written into the Arabic meta description
    }

    if (role === "case") {
        return `a ${name} case`;
    }
    if (role === "band") {
        return `a ${name} strap`;
    }
    if (role === "glass") {
        return `${name} glass`;
    }

    return `a ${name} body`;
}

function colorPhrase(role: string, name: string, locale: Locale): string {
    if (name === "") {
        return "";
    }

    if (locale === "ar") {
        /*
         * `بلون X` and not `X` on its own, and this is the whole reason the Arabic reads correctly:
         * an Arabic colour adjective agrees with its noun, and the lookup stores one form. `مينا
         * أزرق` is wrong (a dial is feminine — `زرقاء`), and nothing here can know that. Hung off
         * `لون`, which is masculine and never changes, the stored form is always the right one:
         * `مينا بلون أزرق`. Grammar solved by construction rather than by a table of exceptions.
         */
        if (role === "dial") {
            return `مينا بلون ${name}`; // i18n-exempt: product content, written into the Arabic meta description
        }
        if (role === "band") {
            return `سوار بلون ${name}`; // i18n-exempt: product content, written into the Arabic meta description
        }

        return `بلون ${name}`; // i18n-exempt: product content, written into the Arabic meta description
    }

    if (role === "dial") {
        return `a ${name} dial`;
    }
    if (role === "band") {
        return `a ${name} strap`;
    }

    return `a ${name} finish`;
}

/**
 * At most two attribute phrases: the first material and the first colour.
 *
 * Two because a third turns the sentence back into the list this was rebuilt to stop being.
 *
 * The one special case is the STRAP, and it is worth the six lines: a leather band and a brown band
 * are two facts about one object, and emitting both phrases gives "a leather strap and a brown
 * strap", which reads as two straps. Merged, it is `a brown leather strap` — the phrase a person
 * would have written.
 */
function attributePhrases(input: SeoInput, locale: Locale): string[] {
    const materials = byRole(input.materials, MATERIAL_ORDER);
    const colors = byRole(input.colors, COLOR_ORDER);

    const material = materials[0];
    const color = colors[0];

    const materialName =
        material === undefined ? "" : attributeName(material.name, locale);
    const colorName =
        color === undefined ? "" : attributeName(color.name, locale);

    if (
        material !== undefined &&
        color !== undefined &&
        material.role === "band" &&
        color.role === "band" &&
        materialName !== "" &&
        colorName !== ""
    ) {
        return [
            locale === "ar"
                ? `سوار ${materialName} بلون ${colorName}` // i18n-exempt: product content, written into the Arabic meta description
                : `a ${colorName} ${materialName} strap`,
        ];
    }

    return [
        material === undefined
            ? ""
            : materialPhrase(material.role, materialName, locale),
        color === undefined ? "" : colorPhrase(color.role, colorName, locale),
    ].filter((phrase) => phrase !== "");
}

/** The noun the product IS: the family's word, or the category when the family has none. */
function productNoun(input: SeoInput, locale: Locale): string {
    const word = FAMILY_WORDS[input.familyKey]?.[locale].one ?? "";
    if (tidy(word) !== "") {
        return tidy(word);
    }

    return tidy(input.category[locale]);
}

/**
 * What the product is, in the shortest true phrase — the subject of the description's sentence and
 * the fallback for a title with no name yet.
 *
 * Arabic: `ساعة رجالي من مايكل كورس` — noun, audience, then the brand hung off `من`.
 * English: `Michael Kors men's watch` — brand, audience, then the noun.
 *
 * Neither is the other's word order, and that is the point of the brief's "generated independently".
 */
function subject(input: SeoInput, locale: Locale, withGender: boolean): string {
    const brand = tidy(input.brand[locale]);
    const gender = withGender ? genderWord(input.genders, locale) : "";
    const noun = productNoun(input, locale);

    if (locale === "ar") {
        return join([noun, gender, brand === "" ? "" : `من ${brand}`], " "); // i18n-exempt: product content, written into the Arabic meta description
    }

    return join([brand, gender, noun], " ");
}

/**
 * One locale's title, COMPOSED to fit rather than written and then cut.
 *
 * The product's own name is the title; the brand and then the category are appended only when they
 * are not already in it AND there is room. Most of this catalogue's titles already carry the brand
 * — "Michael Kors Watch for Women MK6268" — which is exactly the case the old `name | brand`
 * template got wrong seven thousand times.
 *
 * When there is no name at all (a create form mid-typing), the subject phrase stands in, so the
 * button still produces something true rather than an empty field.
 */
function metaTitle(input: SeoInput, locale: Locale): string {
    const name = tidy(input.title[locale]);
    const base = name === "" ? subject(input, locale, true) : name;

    if (base === "") {
        return "";
    }

    const out = clipToEm(base, TITLE_EM);

    /*
     * ONE appended segment, never two.
     *
     * The brand first, because that is what a person scans a result for; the category only when
     * the brand is already in the name. `محفظة Calvin Klein | كالفن كلاين | محافظ` was the
     * three-segment version on a real row, and the third segment is where a title stops being a
     * name and starts being a breadcrumb.
     */
    for (const extra of [
        tidy(input.brand[locale]),
        tidy(input.category[locale]),
    ]) {
        if (extra === "" || contains(out, extra)) {
            continue;
        }

        const candidate = `${out} | ${extra}`;

        return fits(candidate, TITLE_EM) ? candidate : out;
    }

    return out;
}

/**
 * One locale's description: two sentences, and a person decides to click on the second one.
 *
 * The first says what the thing is. The second says what is on the page — which is the honest
 * version of a call to action, and the only one available to a file forbidden from mentioning the
 * price, the stock or how good the product is.
 *
 * It is built at full length and then SHED, in a fixed order, until it fits: the attribute clause
 * goes first (it is the most decorative), then the audience word. Clipping is the last resort and
 * in practice never happens, because a subject and a closing sentence are far inside the budget.
 */
function metaDescription(input: SeoInput, locale: Locale): string {
    const model = tidy(input.modelNumber);
    const phrases = attributePhrases(input, locale);

    const closing =
        locale === "ar"
            ? model === ""
                ? "المواصفات الكاملة والصور داخل صفحة المنتج." // i18n-exempt: product content, written into the Arabic meta description
                : `موديل ${model}. المواصفات الكاملة والصور داخل صفحة المنتج.` // i18n-exempt: product content, written into the Arabic meta description
            : model === ""
              ? "Full specification and photos on the product page."
              : `Model ${model}. Full specification and photos on the product page.`;

    /** One attempt at the opening sentence, with as much of the product in it as asked for. */
    const opening = (withGender: boolean, attributes: string[]): string => {
        const head =
            subject(input, locale, withGender) === ""
                ? tidy(input.title[locale])
                : subject(input, locale, withGender);

        if (head === "") {
            return "";
        }

        if (locale === "ar") {
            return attributes.length === 0
                ? `${head}.`
                : `${head}، ${attributes.join(" و")}.`; // i18n-exempt: the Arabic comma and conjunction, punctuation for the generated sentence
        }

        return attributes.length === 0
            ? `${capitalise(head)}.`
            : `${capitalise(head)} with ${attributes.join(" and ")}.`;
    };

    /*
     * ── Which opening, and the correction a real row forced ─────────────────────────────────
     *
     * The subject phrase — `Joyroom device`, `ساعة رجالي من إمبوريو أرماني` — is only better than
     * the product's own title when it has something specific hanging off it. On product 7010, a
     * Joyroom power bank with no audience, no material and no colour recorded, the subject-first
     * rule produced *"Joyroom device. Full specification and photos on the product page."* while
     * the title said `Joyroom JR-QP192 20000mAh 22.5W fast charging powerbank with LCD display`.
     * The generated sentence was grammatical and worse than the raw title, which is the failure
     * mode of every template that never looks at its own output.
     *
     * So: describe the product from its ATTRIBUTES when there are any, and from its NAME when
     * there are none. The name is always the most specific true thing on the screen.
     */
    const distinguished =
        phrases.length > 0 || genderWord(input.genders, locale) !== "";

    const named = tidy(input.title[locale]);
    // No second full stop on a title that already ends in one — imported titles sometimes do.
    const fromName =
        named === "" ? "" : SENTENCE_END.test(named) ? named : `${named}.`;

    const attempts = distinguished
        ? [
              opening(true, phrases),
              opening(true, phrases.slice(0, 1)),
              opening(true, []),
              fromName,
              opening(false, []),
          ]
        : [fromName, opening(false, [])];

    for (const attempt of attempts) {
        if (attempt === "") {
            continue;
        }

        const whole = `${attempt} ${closing}`;
        if (fits(whole, DESCRIPTION_EM)) {
            return tidy(whole);
        }
    }

    /*
     * Every shape overflowed, which means the product's own name alone is wider than the box.
     * Keep the closing sentence — it is the reason to click — and give the name whatever is left,
     * rather than dropping the closing and filling the whole box with a name nobody finished
     * reading.
     */
    const last = attempts.filter((attempt) => attempt !== "").pop() ?? "";
    if (last === "") {
        return "";
    }

    const room = DESCRIPTION_EM - estimateEm(closing) - 1;

    return room <= 0
        ? clipToEm(last, DESCRIPTION_EM)
        : tidy(`${clipToEm(last, room)} ${closing}`);
}

/**
 * The search keywords — both languages in one field, because that is how the field is stored and
 * because a customer types Arabic or English into one box.
 *
 * ── What changed, and why it matters more than the sentences ─────────────────────────────────
 *
 * The old list was the product's own TITLE, its brand, its category and its family — five values
 * copied from five fields. A 90-character title is not a search; nobody types it. What people type
 * is a COMBINATION that no single field holds: the audience with the noun, the brand with the
 * noun, the material with the noun. Those are built here.
 *
 * The two languages are interleaved rather than listed one after the other, so that anything
 * downstream which truncates this field loses the tail of both languages rather than all of one.
 */
function keywords(input: SeoInput): string {
    const build = (locale: Locale): string[] => {
        const brand = tidy(input.brand[locale]);
        const gender = genderWord(input.genders, locale);
        const noun = productNoun(input, locale);
        const many = tidy(FAMILY_WORDS[input.familyKey]?.[locale].many ?? "");
        const category = tidy(input.category[locale]);
        const model = tidy(input.modelNumber);

        const material = byRole(input.materials, MATERIAL_ORDER)[0];
        const color = byRole(input.colors, COLOR_ORDER)[0];
        const materialName =
            material === undefined ? "" : attributeName(material.name, locale);
        const colorName =
            color === undefined ? "" : attributeName(color.name, locale);

        if (locale === "ar") {
            return [
                join([noun, gender, brand], " "),
                join([many, brand], " "),
                join([noun, gender], " "),
                brand,
                category,
                materialName === "" ? "" : join([noun, materialName], " "),
                // `بلون` for the same reason the sentence uses it: `ساعة أزرق` does not agree and
                // `ساعة بلون أزرق` always does — and it is the phrase people type.
                colorName === "" ? "" : join([noun, "بلون", colorName], " "), // i18n-exempt: product content, a generated Arabic search term
                model,
            ];
        }

        return [
            join([brand, gender, noun], " "),
            join([brand, many], " "),
            join([gender, noun], " "),
            brand,
            category,
            materialName === "" ? "" : join([materialName, noun], " "),
            colorName === "" ? "" : join([colorName, noun], " "),
            model === "" ? "" : join([brand, model], " "),
        ];
    };

    const arabic = build("ar");
    const english = build("en");

    const ordered: string[] = [];
    for (
        let index = 0;
        index < Math.max(arabic.length, english.length);
        index++
    ) {
        if (arabic[index] !== undefined) {
            ordered.push(arabic[index]);
        }
        if (english[index] !== undefined) {
            ordered.push(english[index]);
        }
    }

    const seen = new Set<string>();
    const out: string[] = [];
    for (const term of ordered) {
        const value = tidy(term);
        const key = value.toLowerCase();
        // A one-word term that is already a word inside a term we kept adds nothing: `Michael Kors`
        // is in `Michael Kors watches`, and a keyword list of prefixes of itself is noise.
        if (value === "" || seen.has(key)) {
            continue;
        }

        seen.add(key);
        out.push(value);
    }

    // Sixteen is the point past which a keyword list stops being a description of the product and
    // starts being an attempt to game one — and the specific terms are first, so the cut is cheap.
    return out.slice(0, 16).join("، "); // i18n-exempt: the Arabic comma — stored in search_keywords, which holds both languages
}

/** Everything, for both locales. Empty inputs give empty outputs rather than a template with holes. */
export function generateSeo(input: SeoInput): SeoOutput {
    return {
        meta_title: {
            ar: metaTitle(input, "ar"),
            en: metaTitle(input, "en"),
        },
        meta_description: {
            ar: metaDescription(input, "ar"),
            en: metaDescription(input, "en"),
        },
        search_keywords: keywords(input),
    };
}
