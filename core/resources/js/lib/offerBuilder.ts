/**
 * THE OFFER BUILDER'S LOGIC (Michael's approval of 2026-11-19) — pure, no React, tested by offerBuilder.test.ts.
 *
 * The builder edits the promotions engine's own rule (the form's data, saved through the one writer). This module reads
 * that data as a plain description — what, on what, on which condition, how many items, the limits, where it shows — and
 * says it as ONE sentence in English and in Arabic, the sentence the team reads under the form ("30% off one item from
 * Wallets for each item from Watches in the cart, the most expensive first, up to 1,500 EGP. Won on the wheel."). It
 * also holds the ready templates, each a patch to the form's data. Latin digits in both languages, as the shops print.
 */

export type OfferKind = 'percent' | 'amount' | 'gift' | 'shipping';
export type ItemsMode = 'all' | 'per_qualifier' | 'fixed' | 'cheapest';
export type Where = 'auto' | 'code' | 'wheel';

export interface Names {
    en: string[];
    ar: string[];
}

/** The offer as a person would say it. */
export interface OfferModel {
    kind: OfferKind;
    value: number | null;
    gift: Names | null;
    target: Names | null; // null = the whole cart
    qualify: Names | null;
    qualifyMin: number | null;
    minTotal: number | null;
    firstOrder: boolean;
    items: ItemsMode;
    maxUnits: number | null;
    cap: number | null;
    endsAt: string | null;
    uses: number | null;
    where: Where;
    code: string | null;
}

export interface ScopeRow {
    role: 'target' | 'qualify' | 'exclude';
    dimension: string;
    value_id: number | null;
    value: string | null;
}

export interface FormLike {
    rewards: Array<{ type: string; amount: string | null; product_id: number | null; quantity: number }>;
    conditions: Array<{ type: string; amount?: string | null; quantity?: number | null }>;
    scopes: ScopeRow[];
    apply_per: string;
    max_discount: string;
    max_units: string | number | null;
    qualify_min_quantity: string;
    min_order_amount: string;
    usage_limit: string;
    ends_at: string;
    code: string;
    for_wheel: boolean;
}

/** Names of the things a scope row names: a category, a brand or a product id → its words. */
export type NameOf = (dimension: string, id: number | null, value: string | null) => { en: string; ar: string } | null;

const num = (v: unknown): number | null => {
    const n = typeof v === 'number' ? v : Number(String(v ?? '').trim());
    return String(v ?? '').trim() === '' || !Number.isFinite(n) ? null : n;
};

const namesOf = (rows: ScopeRow[], nameOf: NameOf): Names | null => {
    if (rows.length === 0) return null;
    const out: Names = { en: [], ar: [] };
    for (const r of rows) {
        const n = nameOf(r.dimension, r.value_id, r.value);
        if (n) {
            out.en.push(n.en);
            out.ar.push(n.ar);
        }
    }
    return out.en.length ? out : null;
};

/** Read the form's data as the offer it describes. */
export function modelOf(data: FormLike, nameOf: NameOf, giftName: { en: string; ar: string } | null = null): OfferModel {
    const reward = data.rewards[0];
    const kind: OfferKind =
        reward?.type === 'percent_discount' ? 'percent' : reward?.type === 'fixed_discount' ? 'amount' : reward?.type === 'free_shipping' ? 'shipping' : 'gift';
    const per = data.apply_per;
    const maxUnits = num(data.max_units);
    return {
        kind,
        value: num(reward?.amount),
        gift: kind === 'gift' && giftName ? { en: [giftName.en], ar: [giftName.ar] } : null,
        target: namesOf(data.scopes.filter((s) => s.role === 'target'), nameOf),
        qualify: namesOf(data.scopes.filter((s) => s.role === 'qualify'), nameOf),
        qualifyMin: num(data.qualify_min_quantity),
        minTotal: num(data.min_order_amount),
        firstOrder: data.conditions.some((c) => c.type === 'first_order'),
        items: per === 'paired' ? 'per_qualifier' : per === 'cheapest' ? 'cheapest' : maxUnits !== null ? 'fixed' : 'all',
        maxUnits,
        cap: num(data.max_discount),
        endsAt: data.ends_at && !data.for_wheel ? data.ends_at : null,
        uses: num(data.usage_limit),
        where: data.for_wheel ? 'wheel' : data.code.trim() !== '' ? 'code' : 'auto',
        code: data.code.trim() || null,
    };
}

const money = (n: number): string => (Number.isInteger(n) ? n : Math.round(n * 100) / 100).toLocaleString('en-US');
const list = (names: string[], lang: 'en' | 'ar'): string => (lang === 'ar' ? names.join(' أو ') : names.join(' or ')); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
const MONTHS = {
    en: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
    ar: ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'], // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
};
const date = (iso: string, lang: 'en' | 'ar'): string => {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso);
    if (!m) return iso;
    return `${Number(m[3])} ${MONTHS[lang][Number(m[2]) - 1]}`;
};

/** The offer in one plain sentence, in the language asked. */
export function sentence(m: OfferModel, lang: 'en' | 'ar'): string {
    const ar = lang === 'ar';
    const v = m.value ?? 0;
    const on = (names: Names | null, one: boolean): string => {
        if (!names) return ar ? 'السلة كلها' : 'the whole cart'; // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        const l = list(names[lang], lang);
        if (one) return ar ? `منتج واحد من ${l}` : `one item from ${l}`; // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        return l;
    };
    const parts: string[] = [];

    // what, on what
    if (m.kind === 'shipping') {
        parts.push(ar ? 'توصيل مجاني' : 'Free delivery'); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    } else if (m.kind === 'gift') {
        const g = m.gift ? list(m.gift[lang], lang) : ar ? 'هدية' : 'a gift'; // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        parts.push(ar ? `${g} مجانًا` : `${g} free`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    } else if (m.items === 'cheapest' && m.qualifyMin && m.kind === 'percent' && v >= 100) {
        parts.push(ar ? `اشترِ ${m.qualifyMin} من ${on(m.target, false)}: الأرخص مجانًا` : `Buy ${m.qualifyMin} from ${on(m.target, false)}: the cheapest one free`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    } else {
        const off = m.kind === 'percent' ? (ar ? `خصم ${money(v)}%` : `${money(v)}% off`) : ar ? `خصم ${money(v)} جنيه` : `${money(v)} EGP off`; // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        if (m.items === 'per_qualifier') {
            const q = m.qualify ? list(m.qualify[lang], lang) : ar ? 'المنتج المطلوب' : 'the qualifying item'; // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
            parts.push(ar ? `${off} على ${on(m.target, true)} مع كل منتج من ${q} في السلة، الأغلى أولًا` : `${off} ${on(m.target, true)} for each item from ${q} in the cart, the most expensive first`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        } else if (m.items === 'cheapest') {
            parts.push(ar ? `${off} على الأرخص من ${on(m.target, false)}` : `${off} the cheapest item from ${on(m.target, false)}`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        } else {
            parts.push(ar ? `${off} على ${on(m.target, false)}` : `${off} ${on(m.target, false)}`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        }
    }

    // the condition
    if (m.qualify && m.items !== 'per_qualifier' && m.items !== 'cheapest') {
        const q = list(m.qualify[lang], lang);
        const n = m.qualifyMin && m.qualifyMin > 1 ? m.qualifyMin : null;
        parts.push(ar ? (n ? `عند وجود ${n} منتجات من ${q} في السلة` : `عند وجود منتج من ${q} في السلة`) : n ? `with ${n} items from ${q} in the cart` : `with an item from ${q} in the cart`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    }
    if (m.minTotal) parts.push(ar ? `للطلبات من ${money(m.minTotal)} جنيه فأكثر` : `on orders of ${money(m.minTotal)} EGP or more`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    if (m.firstOrder) parts.push(ar ? 'لأول طلب فقط' : 'on a first order only'); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language

    // the limits
    if (m.items === 'fixed' && m.maxUnits) parts.push(ar ? `على ${m.maxUnits} قطع كحد أقصى` : `on up to ${m.maxUnits} items`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    if (m.cap && m.kind !== 'shipping') parts.push(ar ? `حتى ${money(m.cap)} جنيه` : `up to ${money(m.cap)} EGP`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    if (m.uses) parts.push(ar ? `لأول ${m.uses} استخدام` : `for the first ${m.uses} uses`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    if (m.endsAt) parts.push(ar ? `حتى ${date(m.endsAt, 'ar')}` : `until ${date(m.endsAt, 'en')}`); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language

    const where =
        m.where === 'wheel' ? (ar ? 'جائزة في عجلة الحظ.' : 'Won on the wheel.') : m.where === 'code' ? (ar ? `بالكود ${m.code}.` : `With the code ${m.code}.`) : ar ? 'يُطبَّق تلقائيًا.' : 'Applied automatically.'; // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language

    return `${parts.join(ar ? '، ' : ', ')}. ${where}`; // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
}

/** The checks shown before saving (the refusals stay in the server's writer). */
export function warnings(m: OfferModel, lang: 'en' | 'ar'): string[] {
    const ar = lang === 'ar';
    const out: string[] = [];
    if ((m.kind === 'percent' || (m.kind === 'amount' && m.items !== 'all')) && m.cap === null) {
        out.push(ar ? 'لا يوجد حد أقصى للخصم بالجنيه. أضف حدًا حتى لا يكبر الخصم مع السلة.' : 'There is no cap in EGP. Add one so the discount cannot grow with the cart.'); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    }
    if (m.where !== 'wheel' && m.endsAt === null) {
        out.push(ar ? 'لا يوجد تاريخ انتهاء. العرض بلا نهاية هو عرض ينسى أحد إيقافه.' : 'There is no end date. An offer without one is an offer somebody forgets to stop.'); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    }
    if (m.items === 'per_qualifier' && !m.qualify) {
        out.push(ar ? '«واحد مع كل منتج مؤهِّل» يحتاج تحديد المنتج المؤهِّل في خطوة الشرط.' : '"One per qualifying item" needs the qualifying item, in the condition step.'); // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
    }
    return out;
}

// ── the templates ──────────────────────────────────────────────────────────────────────────────────────────────────

export interface TemplateContext {
    /** A category of the chosen shop by its slug, or null. */
    categoryBySlug: (slug: string) => number | null;
}

export type FormPatch = Partial<FormLike> & { label_en?: string; label_ar?: string; name?: string };

export interface OfferTemplate {
    key: string;
    label: { en: string; ar: string };
    hint: { en: string; ar: string };
    patch: (ctx: TemplateContext) => FormPatch;
}

const target = (dimension: string, id: number | null): ScopeRow[] => (id === null ? [] : [{ role: 'target', dimension, value_id: id, value: null }]);
const qualify = (dimension: string, id: number | null): ScopeRow[] => (id === null ? [] : [{ role: 'qualify', dimension, value_id: id, value: null }]);
const base: FormPatch = { conditions: [], max_units: '', qualify_min_quantity: '', min_order_amount: '', max_discount: '', apply_per: 'order' };

export const TEMPLATES: OfferTemplate[] = [
    {
        key: 'wallet_with_watch',
        label: { en: '30% off a wallet with any watch', ar: 'خصم 30% على محفظة مع أي ساعة' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        hint: { en: 'One discounted wallet for each watch in the cart, the most expensive first, up to 1,500 EGP.', ar: 'محفظة واحدة بالخصم مع كل ساعة في السلة، الأغلى أولًا، حتى 1,500 جنيه.' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        patch: (ctx) => ({
            ...base,
            name: '30% off a wallet with any watch',
            label_en: '30% off a wallet with any watch',
            label_ar: 'خصم 30% على محفظة مع أي ساعة', // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
            rewards: [{ type: 'percent_discount', amount: '30', product_id: null, quantity: 1 }],
            scopes: [...target('category', ctx.categoryBySlug('wallets')), ...qualify('category', ctx.categoryBySlug('watches'))],
            apply_per: 'paired',
            qualify_min_quantity: '1',
            max_discount: '1500',
        }),
    },
    {
        key: 'buy2_get3rd',
        label: { en: 'Buy 2, get the 3rd free', ar: 'اشترِ 2 والثالثة مجانًا' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        hint: { en: 'Three items from the chosen category: the cheapest one is free.', ar: 'ثلاث قطع من التصنيف المختار: الأرخص مجانًا.' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        patch: (ctx) => {
            const cat = ctx.categoryBySlug('watches');
            return {
                ...base,
                name: 'Buy 2, get the 3rd free',
                label_en: 'Buy 2, get the 3rd free',
                label_ar: 'اشترِ 2 والثالثة مجانًا', // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
                rewards: [{ type: 'percent_discount', amount: '100', product_id: null, quantity: 1 }],
                scopes: [...target('category', cat), ...qualify('category', cat)],
                apply_per: 'cheapest',
                qualify_min_quantity: '3',
            };
        },
    },
    {
        key: 'percent_brand',
        label: { en: 'X% off a brand', ar: 'خصم X% على ماركة' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        hint: { en: 'Choose the brand and the percentage.', ar: 'اختر الماركة والنسبة.' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        patch: () => ({ ...base, name: '% off a brand', rewards: [{ type: 'percent_discount', amount: '10', product_id: null, quantity: 1 }], scopes: [], apply_per: 'order' }),
    },
    {
        key: 'amount_over_total',
        label: { en: 'An amount off above a cart total', ar: 'خصم مبلغ فوق قيمة سلة' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        hint: { en: 'For example 300 EGP off orders of 5,000 EGP or more.', ar: 'مثال: خصم 300 جنيه للطلبات من 5,000 جنيه فأكثر.' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        patch: () => ({ ...base, name: 'Amount off above a total', rewards: [{ type: 'fixed_discount', amount: '300', product_id: null, quantity: 1 }], scopes: [], min_order_amount: '5000' }),
    },
    {
        key: 'free_shipping_over',
        label: { en: 'Free delivery above a total', ar: 'توصيل مجاني فوق قيمة' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        hint: { en: 'For example free delivery on orders of 3,000 EGP or more.', ar: 'مثال: توصيل مجاني للطلبات من 3,000 جنيه فأكثر.' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        patch: () => ({ ...base, name: 'Free delivery above a total', rewards: [{ type: 'free_shipping', amount: null, product_id: null, quantity: 1 }], scopes: [], min_order_amount: '3000' }),
    },
    {
        key: 'first_order',
        label: { en: 'A first-order discount', ar: 'خصم لأول طلب' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        hint: { en: '10% off a first order (by the account and by the phone number).', ar: 'خصم 10% لأول طلب (بالحساب وبرقم الهاتف).' }, // i18n-exempt: BOTH LANGUAGES: the offer said in Arabic and English side by side, whatever the dashboard language
        patch: () => ({ ...base, name: 'First order 10%', rewards: [{ type: 'percent_discount', amount: '10', product_id: null, quantity: 1 }], scopes: [], conditions: [{ type: 'first_order' }], max_discount: '1000' }),
    },
];
