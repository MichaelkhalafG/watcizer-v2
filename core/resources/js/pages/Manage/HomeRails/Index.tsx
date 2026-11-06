import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import ProductPicker from '@/components/manage/ProductPicker';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';

/**
 * Home-page rails (C-1 stage 4, slice C).
 *
 * The product rails under the hero, in the order the home page shows them. A rail is a KIND —
 * offers, featured, newest, or one grade / brand / top-level category — with an optional title in
 * each language (empty = the target's own name), a card count, and an on/off switch.
 *
 * Order is edited with up/down buttons, and every move sends the WHOLE order: the server refuses a
 * list that does not name every rail once, so two tabs cannot silently interleave their moves.
 * A rail with nothing to show (a grade with no products) is simply not rendered on the home page.
 *
 * `custom` (M2e, 2026-10-02): products picked by hand with the shared ProductPicker — search by name
 * or code with picture and price, drag to reorder. It has no target to be named after, so both
 * titles are required; it shows exactly the products picked, in that order.
 */

interface Rail {
    id: number;
    kind: string;
    target_id: number | null;
    product_ids: number[];
    target_name: string | null;
    /** How many products this rail renders TODAY — resolved by the storefront's own rule (item 14). */
    product_count: number;
    title_en: string | null;
    title_ar: string | null;
    position: number;
    is_active: boolean;
    card_count: number;
    /** Part 3.2 (2026-11-05): this slider's section colour (`#RRGGBB` or null) and whether its photos blend into it. */
    background: string | null;
    blend: boolean;
    /** Part 3.3: a brand banner's three colours and its sentence. Empty / null on every other kind. */
    colors: string[];
    description_ar: string | null;
    description_en: string | null;
    /** An under-a-price rail's cap in EGP; null on every other kind. */
    max_price: number | null;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Option[];
    rails: Rail[];
    kinds: string[];
    targeted: string[];
    targets: Record<string, Option[]>;
    max_cards: number;
    /** Below this the storefront does not draw the rail at all. */
    min_products: number;
    /** A brand banner's fixed counts, and the brands that have no logo on file (Part 3.3). */
    background_presets: { hex: string; name: string }[];
    banner_products: number;
    banner_colors: number;
    brands_without_logo: string[];
    /** What leads this storefront's home (three_d | campaign | banner): the Banners block row counts only under a hero. */
    hero: string;
    /** How many banners the Banners block would draw right now. */
    block_banners: number;
    /** The kinds that are a SECTION of the page (one row each, nothing to fill in): the Banners block, the campaign sliders, the category tiles, the brand strip. */
    sections: string[];
}

interface Draft {
    id: number | null;
    kind: string;
    target_id: string;
    product_ids: number[];
    title_en: string;
    title_ar: string;
    card_count: string;
    is_active: boolean;
    /** '' = no colour. */
    background: string;
    blend: boolean;
    colors: string[];
    description_ar: string;
    description_en: string;
    /** '' = not set. */
    max_price: string;
}

/** A brand banner starts on ink, a red accent and white — three colours to change, never three blanks. */
const BANNER_DEFAULT_COLORS = ['#14213D', '#C1121F', '#FFFFFF'];

const EMPTY: Draft = {
    id: null,
    kind: 'grade',
    target_id: '',
    product_ids: [],
    title_en: '',
    title_ar: '',
    card_count: '8',
    is_active: true,
    background: '',
    blend: false,
    colors: BANNER_DEFAULT_COLORS,
    description_ar: '',
    description_en: '',
    max_price: '',
};

const HEX = /^#[0-9A-Fa-f]{6}$/;

/** The kinds, named for the operator. A function because `useT()` only runs inside the component. */
function kindLabels(t: ReturnType<typeof useT>): Record<string, string> {
    return {
        offers: t('home_rails.kind_offers', 'العروض'),
        featured: t('home_rails.kind_featured', 'منتجات مميّزة'),
        newest: t('home_rails.kind_newest', 'وصل حديثًا'),
        grade: t('products.grade', 'الدرجة'),
        brand: t('home_rails.kind_brand', 'ماركة'),
        category_type: t('home_rails.kind_category_type', 'تصنيف رئيسي'),
        custom: t('home_rails.kind_custom', 'اختيار يدوي'),
        brand_banner: t('home_rails.kind_brand_banner', 'بانر ماركة'),
        banners: t('home_rails.kind_banners', 'قسم البانرات'),
        hot_deals: t('home_rails.kind_hot_deals', 'قسم العروض الساخنة'),
        flash_sale: t('home_rails.kind_flash_sale', 'قسم التخفيضات الخاطفة'),
        categories: t('home_rails.kind_categories', 'قسم الفئات بالصور'),
        brands: t('home_rails.kind_brands', 'شريط الماركات'),
        best_value: t('home_rails.kind_best_value', 'أفضل قيمة (أعلى خصم)'),
        price: t('home_rails.kind_price', 'تحت سعر معيّن'),
    };
}

export default function HomeRailsIndex({
    storefront,
    storefronts,
    rails,
    kinds,
    targeted,
    targets,
    max_cards,
    min_products,
    background_presets,
    banner_products,
    banner_colors,
    brands_without_logo,
    hero,
    block_banners,
    sections,
}: Props) {
    const t = useT();
    const errors = (usePage().props.errors ?? {}) as Record<string, string>;
    const [draft, setDraft] = useState<Draft | null>(null);
    const KIND = kindLabels(t);
    const base = `/manage/storefronts/${storefront.id}/home-rails`;
    const needsTarget = (kind: string) => targeted.includes(kind);

    const payloadOf = (rail: Rail, patch: Partial<Rail> = {}) => {
        const next = { ...rail, ...patch };

        return {
            kind: next.kind,
            target_id: next.target_id,
            product_ids: next.kind === 'custom' || next.kind === 'brand_banner' ? next.product_ids : null,
            title_en: next.title_en,
            title_ar: next.title_ar,
            card_count: next.card_count,
            is_active: next.is_active,
            // the whole rail travels on every save (the endpoint stores what it is sent), so its look goes too
            background: next.background,
            blend: next.blend,
            colors: next.kind === 'brand_banner' ? next.colors : null,
            description_ar: next.description_ar,
            description_en: next.description_en,
            max_price: next.max_price,
        };
    };

    const edit = (rail: Rail) =>
        setDraft({
            id: rail.id,
            kind: rail.kind,
            target_id: rail.target_id === null ? '' : String(rail.target_id),
            product_ids: rail.product_ids,
            title_en: rail.title_en ?? '',
            title_ar: rail.title_ar ?? '',
            card_count: String(rail.card_count),
            is_active: rail.is_active,
            background: rail.background ?? '',
            blend: rail.blend,
            colors: rail.colors.length === banner_colors ? rail.colors : BANNER_DEFAULT_COLORS,
            description_ar: rail.description_ar ?? '',
            description_en: rail.description_en ?? '',
            max_price: rail.max_price === null ? '' : String(rail.max_price),
        });

    const submit = () => {
        if (draft === null) {
            return;
        }
        const picked = draft.kind === 'custom' || draft.kind === 'brand_banner';
        const payload = {
            kind: draft.kind,
            target_id: needsTarget(draft.kind) && draft.target_id !== '' ? Number(draft.target_id) : null,
            product_ids: picked ? draft.product_ids : null,
            title_en: draft.title_en.trim() === '' ? null : draft.title_en,
            title_ar: draft.title_ar.trim() === '' ? null : draft.title_ar,
            // A custom rail shows exactly what was picked (the server counts them too).
            card_count: picked ? Math.max(1, draft.product_ids.length) : Number(draft.card_count || 0),
            is_active: draft.is_active,
            background: draft.background === '' ? null : draft.background,
            blend: draft.background !== '' && draft.blend,
            colors: draft.kind === 'brand_banner' ? draft.colors : null,
            description_ar: draft.kind === 'brand_banner' ? draft.description_ar : null,
            description_en: draft.kind === 'brand_banner' ? draft.description_en : null,
            max_price: draft.kind === 'price' && draft.max_price !== '' ? Number(draft.max_price) : null,
        };
        const done = { preserveScroll: true, onSuccess: () => setDraft(null) };
        draft.id === null ? router.post(base, payload, done) : router.put(`${base}/${draft.id}`, payload, done);
    };

    /*
     * ONE MOVE IN FLIGHT AT A TIME, AND A REFUSAL THAT SAYS SO (2026-10-21).
     *
     * Two faults lived here, and both looked identical to the operator — the arrow does nothing:
     *
     *  1. The order is built from `rails`, which is this page's props. A second click before the first
     *     response landed posted the OLD arrangement again and overwrote the move that had just been made.
     *     Moving a rail two places, which is two clicks, was the ordinary way to hit it.
     *  2. The server REFUSES a list that no longer matches the storefront's rails — someone else added or
     *     removed one, or this tab has been open since before a deploy — by failing validation on `order`.
     *     Nothing on this screen rendered that error and `move` passed no `onError`, so the refusal was
     *     silent and the screen went on showing the old order because the server never changed it.
     *
     * `pending` serialises the clicks; `onError` surfaces the refusal where the operator is looking.
     */
    const [pending, setPending] = useState(false);
    const [orderError, setOrderError] = useState<string | null>(null);
    const move = (index: number, by: -1 | 1) => {
        if (pending) {
            return;
        }
        const order = rails.map((rail) => rail.id);
        const other = index + by;
        if (other < 0 || other >= order.length) {
            return;
        }
        [order[index], order[other]] = [order[other], order[index]];
        setPending(true);
        setOrderError(null);
        router.post(`${base}/order`, { order }, {
            preserveScroll: true,
            onError: (bag) => setOrderError(bag.order ?? t('home_rails.order_failed', 'تعذّر حفظ الترتيب.')),
            onFinish: () => setPending(false),
        });
    };

    const toggle = (rail: Rail) =>
        router.put(`${base}/${rail.id}`, payloadOf(rail, { is_active: !rail.is_active }), { preserveScroll: true });

    /*
     * A BRAND BANNER'S SUGGESTED PRODUCTS (the review of 2026-11-05): the brand's range — a watch, a bag, an
     * accessory, in stock — asked of core when a brand is chosen (and again from the button). It only fills the
     * picker: the team may replace any of the three by hand.
     */
    const suggestPicks = async (brandId: string, current: Draft) => {
        if (brandId === '') {
            return;
        }
        try {
            const response = await fetch(`${base}/banner-picks?brand=${encodeURIComponent(brandId)}`, {
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return;
            }
            const body = (await response.json()) as { ids?: number[] };
            setDraft({ ...current, target_id: brandId, product_ids: Array.isArray(body.ids) ? body.ids : [] });
        } catch {
            // the picker simply stays empty: the team picks by hand
        }
    };

    const draftTargetMissing = draft !== null && needsTarget(draft.kind) && draft.target_id === '';
    const isCustom = draft !== null && draft.kind === 'custom';
    const isBanner = draft !== null && draft.kind === 'brand_banner';
    // The Banners block (the design pass): a row with a place and a switch, and nothing else to fill in.
    const isBlock = draft !== null && sections.includes(draft.kind);
    // What each SECTION is, said once in the form and once in its row.
    const SECTION_NOTE: Record<string, string> = {
        hot_deals: t('home_rails.section_hot_deals', 'شريط العروض الساخنة في هذا المكان. يظهر فقط أثناء وجود حملة عروض ساخنة جارية، ويختفي عند انتهائها.'),
        flash_sale: t('home_rails.section_flash_sale', 'شريط التخفيضات الخاطفة في هذا المكان. يظهر فقط أثناء وجود تخفيض خاطف جارٍ، ويختفي عند انتهائه.'),
        categories: t('home_rails.section_categories', 'فئات المتجر بصورها في هذا المكان. الصور تُدار من شاشة الفئات.'),
        brands: t('home_rails.section_brands', 'شريط شعارات الماركات التي يعرضها المتجر، في هذا المكان.'),
    };
    const bannersUrl = `/manage/storefronts/${storefront.id}/banners`;
    // A brand banner is aimed at a brand: the same list a brand slider offers.
    const targetList = (kind: string) => targets[kind === 'brand_banner' ? 'brand' : kind] ?? [];
    // Why Save is disabled, said next to it (AGENTS §2.27: the rule is in the form, not a surprise after).
    const blocked =
        draft === null
            ? null
            : draftTargetMissing
              ? t('home_rails.need_target', 'اختر ما يعرضه الشريط أولًا.')
              : isBanner && draft.product_ids.length !== banner_products
                ? t('home_rails.banner_need_products', 'اختر :count منتجات بالضبط من الماركة.', { count: banner_products })
                : isBanner &&
                    (draft.title_ar.trim() === '' || draft.title_en.trim() === '' || draft.description_ar.trim() === '' || draft.description_en.trim() === '')
                  ? t('home_rails.banner_need_text', 'اكتب العنوان والوصف بالعربية والإنجليزية.')
                  : draft.kind === 'price' && !(Number(draft.max_price) >= 1)
                    ? t('home_rails.price_required', 'اكتب السعر الأقصى لهذا الشريط.')
                  : isBanner && draft.colors.some((color) => !HEX.test(color))
                    ? t('home_rails.color_shape', 'اللون يُكتب بهذا الشكل: ‎#1A2B3C.')
                    : draft.background !== '' && !HEX.test(draft.background)
                      ? t('home_rails.color_shape', 'اللون يُكتب بهذا الشكل: ‎#1A2B3C.')
                      : isCustom && draft.product_ids.length === 0
                        ? t('home_rails.need_picks', 'اختر منتجًا واحدًا على الأقل.')
                : isCustom && (draft.title_ar.trim() === '' || draft.title_en.trim() === '')
                  ? t('home_rails.need_titles', 'اكتب العنوانين بالعربية والإنجليزية: الشريط المختار يدويًا ليس له اسم تلقائي.')
                  : null;

    return (
        <ManageLayout
            title={t('home_rails.title', 'أشرطة الصفحة الرئيسية')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('home_rails.title', 'أشرطة الصفحة الرئيسية') },
            ]}
            actions={<Button onClick={() => setDraft({ ...EMPTY })}>{t('home_rails.new', 'شريط جديد')}</Button>}
        >
            <div className="space-y-4">
                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        aria-label={t('common.storefront', 'المتجر')}
                        className="w-56"
                        value={String(storefront.id)}
                        onChange={(event) => router.visit(`/manage/storefronts/${event.target.value}/home-rails`)}
                    >
                        {storefronts.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'home_rails.intro',
                            'أشرطة المنتجات تحت الصورة الرئيسية لهذا المتجر، بنفس ترتيبها هنا. الشريط الذي لا منتجات فيه لا يظهر. التغيير يظهر في المتجر خلال ٥ دقائق.',
                        )}
                    </p>
                </div>

                {draft !== null ? (
                    <div className="rounded-lg border bg-card p-4">
                        <h2 className="font-medium">
                            {draft.id === null
                                ? t('home_rails.new', 'شريط جديد')
                                : t('home_rails.edit_heading', 'تعديل الشريط #:id', { id: draft.id })}
                        </h2>

                        <div className="mt-3 grid gap-4 sm:grid-cols-2">
                            <label className="space-y-1 text-sm">
                                <span>{t('home_rails.kind', 'النوع')}</span>
                                <Select
                                    value={draft.kind}
                                    onChange={(event) => setDraft({ ...draft, kind: event.target.value, target_id: '' })}
                                >
                                    {kinds.map((kind) => (
                                        <option key={kind} value={kind}>
                                            {KIND[kind] ?? kind}
                                        </option>
                                    ))}
                                </Select>
                            </label>

                            {needsTarget(draft.kind) ? (
                                <label className="space-y-1 text-sm">
                                    <span>{t('home_rails.target', 'يعرض')}</span>
                                    <Select
                                        value={draft.target_id}
                                        // a banner's products belong to its brand, so another brand starts from that brand's
                                        // suggested three (its range), which the team may then change
                                        onChange={(event) => {
                                            const next = { ...draft, target_id: event.target.value, product_ids: isBanner ? [] : draft.product_ids };
                                            setDraft(next);
                                            if (isBanner) {
                                                void suggestPicks(event.target.value, next);
                                            }
                                        }}
                                    >
                                        <option value="">{t('common.choose', 'اختر…')}</option>
                                        {targetList(draft.kind).map((option) => (
                                            <option key={option.value} value={option.value}>
                                                {option.label}
                                            </option>
                                        ))}
                                    </Select>
                                    {isBanner && draft.target_id !== '' && brands_without_logo.includes(draft.target_id) ? (
                                        <span className="block text-xs font-medium text-amber-700">
                                            {t(
                                                'home_rails.banner_no_logo',
                                                'هذه الماركة بلا شعار محفوظ: سيظهر اسمها مكتوبًا مكان الشعار. ارفع الشعار من «الماركات والقوائم».',
                                            )}
                                        </span>
                                    ) : null}
                                    {errors.target_id ? <span className="text-xs text-destructive">{errors.target_id}</span> : null}
                                </label>
                            ) : isBlock && draft.kind !== 'banners' ? (
                                <p className="self-end text-xs text-muted-foreground">{SECTION_NOTE[draft.kind] ?? ''}</p>
                            ) : isBlock ? (
                                <p className="self-end text-xs text-muted-foreground">
                                    {t(
                                        'home_rails.block_hint',
                                        'البانر الكبير ومعه الخانتان الجانبيتان، في هذا المكان بين الأشرطة. صوره تُدار من شاشة «البانرات». يظهر هنا ما دام أعلى الصفحة هيرو (الحملة أو الساعة ثلاثية الأبعاد)؛ وإذا كان أعلى الصفحة «البانرات» فهو أول ما في الصفحة تلقائيًا.',
                                    )}{' '}
                                    <a className="underline" href={bannersUrl}>
                                        {t('home_rails.block_open_banners', 'فتح شاشة البانرات')}
                                    </a>
                                </p>
                            ) : isCustom ? (
                                <div className="space-y-2 text-sm sm:col-span-2">
                                    <span>{t('home_rails.picks', 'المنتجات، بالترتيب الذي تظهر به')}</span>
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'home_rails.custom_hint',
                                            'يعرض الشريط هذه المنتجات فقط، بهذا الترتيب. المنتج المخفي في المتجر أو المحذوف لاحقًا لا يظهر. حتى :max منتجًا.',
                                            { max: max_cards },
                                        )}
                                    </p>
                                    <ProductPicker
                                        id="home-rail-picks"
                                        searchUrl={`${base}/products`}
                                        value={draft.product_ids}
                                        onChange={(ids) => setDraft({ ...draft, product_ids: ids })}
                                        max={max_cards}
                                    />
                                    {Object.entries(errors)
                                        .filter(([key]) => key === 'product_ids' || key.startsWith('product_ids.'))
                                        .map(([key, message]) => (
                                            <p key={key} className="text-sm text-destructive">
                                                {message}
                                            </p>
                                        ))}
                                </div>
                            ) : (
                                <p className="self-end text-xs text-muted-foreground">
                                    {draft.kind === 'best_value'
                                        ? t('home_rails.best_value_hint', 'المنتجات المتوفرة ذات أعلى خصم حقيقي، الأعلى خصمًا أولًا.')
                                        : draft.kind === 'price'
                                          ? t('home_rails.price_hint', 'المنتجات المتوفرة التي يدفع فيها العميل السعر الأقصى أو أقل، الأحدث أولًا.')
                                          : draft.kind === 'featured'
                                        ? t(
                                              'home_rails.featured_hint',
                                              'المنتجات المعلَّمة «مميّز» في شاشة العرض والترتيب، بترتيب عشوائي. ما دام لا شيء معلَّمًا: منتجات مخفّضة لها صورة.',
                                          )
                                        : draft.kind === 'newest'
                                          ? t('home_rails.newest_hint', 'أحدث المنتجات المتوفرة، الأحدث أولًا.')
                                          : t('home_rails.offers_hint', 'المنتجات التي عليها خصم.')}
                                </p>
                            )}

                            {isBanner ? (
                                <div className="space-y-2 text-sm sm:col-span-2">
                                    <span>{t('home_rails.banner_products', 'منتجات البانر — :count من هذه الماركة', { count: banner_products })}</span>
                                    <Button variant="outline" size="sm" className="ms-2" disabled={draft.target_id === ''} onClick={() => void suggestPicks(draft.target_id, draft)}>
                                        {t('home_rails.banner_suggest', 'اقتراح منتجات تُظهر تنوّع الماركة')}
                                    </Button>
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'home_rails.banner_products_hint',
                                            'تظهر بطاقاتها الثلاث على البانر بأسعارها الحالية، والبانر كله يفتح صفحة الماركة. اختر منتجات لها صور واضحة.',
                                        )}
                                    </p>
                                    <ProductPicker
                                        id="home-rail-banner-picks"
                                        searchUrl={`${base}/products`}
                                        value={draft.product_ids}
                                        onChange={(ids) => setDraft({ ...draft, product_ids: ids })}
                                        max={banner_products}
                                    />
                                    {Object.entries(errors)
                                        .filter(([key]) => key === 'product_ids' || key.startsWith('product_ids.'))
                                        .map(([key, message]) => (
                                            <p key={key} className="text-sm text-destructive">
                                                {message}
                                            </p>
                                        ))}
                                </div>
                            ) : null}

                            {isBlock ? null : (
                            <>
                            <label className="space-y-1 text-sm">
                                <span>{t('home_rails.title_ar', 'العنوان (عربي)')}</span>
                                <Input
                                    value={draft.title_ar}
                                    placeholder={isCustom ? t('home_rails.title_required', 'مطلوب') : t('home_rails.title_placeholder', 'فارغ = الاسم التلقائي')}
                                    onChange={(event) => setDraft({ ...draft, title_ar: event.target.value })}
                                />
                                {errors.title_ar ? <span className="text-xs text-destructive">{errors.title_ar}</span> : null}
                            </label>

                            <label className="space-y-1 text-sm">
                                <span>{t('home_rails.title_en', 'العنوان (إنجليزي)')}</span>
                                <Input
                                    dir="ltr"
                                    value={draft.title_en}
                                    placeholder={isCustom ? t('home_rails.title_required', 'مطلوب') : t('home_rails.title_placeholder', 'فارغ = الاسم التلقائي')}
                                    onChange={(event) => setDraft({ ...draft, title_en: event.target.value })}
                                />
                                {errors.title_en ? <span className="text-xs text-destructive">{errors.title_en}</span> : null}
                            </label>
                            </>
                            )}

                            {draft.kind === 'price' ? (
                                <label className="space-y-1 text-sm">
                                    <span>{t('home_rails.max_price', 'السعر الأقصى (ج.م)')}</span>
                                    <Input
                                        dir="ltr"
                                        type="number"
                                        min={1}
                                        value={draft.max_price}
                                        placeholder={t('home_rails.title_required', 'مطلوب')}
                                        onChange={(event) => setDraft({ ...draft, max_price: event.target.value })}
                                    />
                                    {errors.max_price ? <span className="text-xs text-destructive">{errors.max_price}</span> : null}
                                </label>
                            ) : null}

                            {isBlock ? null : isBanner ? (
                                <>
                                    <label className="space-y-1 text-sm">
                                        <span>{t('home_rails.description_ar', 'الوصف (عربي)')}</span>
                                        <Input
                                            value={draft.description_ar}
                                            maxLength={240}
                                            placeholder={t('home_rails.title_required', 'مطلوب')}
                                            onChange={(event) => setDraft({ ...draft, description_ar: event.target.value })}
                                        />
                                        {errors.description_ar ? <span className="text-xs text-destructive">{errors.description_ar}</span> : null}
                                    </label>
                                    <label className="space-y-1 text-sm">
                                        <span>{t('home_rails.description_en', 'الوصف (إنجليزي)')}</span>
                                        <Input
                                            dir="ltr"
                                            value={draft.description_en}
                                            maxLength={240}
                                            placeholder={t('home_rails.title_required', 'مطلوب')}
                                            onChange={(event) => setDraft({ ...draft, description_en: event.target.value })}
                                        />
                                        {errors.description_en ? <span className="text-xs text-destructive">{errors.description_en}</span> : null}
                                    </label>
                                    <div className="space-y-2 text-sm sm:col-span-2">
                                        <span>{t('home_rails.banner_colors', 'ألوان البانر الثلاثة')}</span>
                                        <div className="flex flex-wrap items-end gap-4">
                                            {[
                                                t('home_rails.banner_color_1', 'الخلفية الرئيسية (خلف النص)'),
                                                t('home_rails.banner_color_2', 'الشريط المائل'),
                                                t('home_rails.banner_color_3', 'خلف المنتجات'),
                                            ].map((label, index) => (
                                                <label key={label} className="space-y-1 text-xs">
                                                    <span className="block text-muted-foreground">{label}</span>
                                                    <span className="flex items-center gap-2">
                                                        <input
                                                            type="color"
                                                            className="h-9 w-12 cursor-pointer rounded border bg-transparent p-0.5"
                                                            value={HEX.test(draft.colors[index] ?? '') ? draft.colors[index] : '#000000'}
                                                            onChange={(event) =>
                                                                setDraft({ ...draft, colors: draft.colors.map((color, i) => (i === index ? event.target.value.toUpperCase() : color)) })
                                                            }
                                                        />
                                                        <Input
                                                            dir="ltr"
                                                            className="w-28"
                                                            value={draft.colors[index] ?? ''}
                                                            maxLength={7}
                                                            onChange={(event) =>
                                                                setDraft({ ...draft, colors: draft.colors.map((color, i) => (i === index ? event.target.value : color)) })
                                                            }
                                                        />
                                                    </span>
                                                </label>
                                            ))}
                                            {/* What the three colours make, before saving: the banner's own geometry in miniature. */}
                                            <span
                                                aria-hidden="true"
                                                className="relative block h-14 w-56 overflow-hidden rounded-md border"
                                                style={{ background: HEX.test(draft.colors[2] ?? '') ? draft.colors[2] : '#FFFFFF' }}
                                            >
                                                <span
                                                    className="absolute inset-y-0 start-0 w-[62%]"
                                                    style={{
                                                        background: HEX.test(draft.colors[1] ?? '') ? draft.colors[1] : '#C1121F',
                                                        clipPath: 'polygon(0 0, 100% 0, 84% 100%, 0 100%)',
                                                    }}
                                                />
                                                <span
                                                    className="absolute inset-y-0 start-0 w-[55%]"
                                                    style={{
                                                        background: HEX.test(draft.colors[0] ?? '') ? draft.colors[0] : '#14213D',
                                                        clipPath: 'polygon(0 0, 100% 0, 82% 100%, 0 100%)',
                                                    }}
                                                />
                                            </span>
                                        </div>
                                        {errors.colors ? <p className="text-sm text-destructive">{errors.colors}</p> : null}
                                    </div>
                                </>
                            ) : isCustom ? (
                                <p className="self-end text-sm text-muted-foreground">
                                    {t('home_rails.custom_count', 'يعرض :count منتجات — بالترتيب المختار.', { count: draft.product_ids.length })}
                                </p>
                            ) : (
                                <label className="space-y-1 text-sm">
                                    <span>{t('home_rails.card_count', 'عدد المنتجات')}</span>
                                    <Input
                                        dir="ltr"
                                        type="number"
                                        min={1}
                                        max={max_cards}
                                        value={draft.card_count}
                                        onChange={(event) => setDraft({ ...draft, card_count: event.target.value })}
                                    />
                                </label>
                            )}

                            <label className="flex items-center gap-2 self-end text-sm">
                                <input
                                    type="checkbox"
                                    checked={draft.is_active}
                                    onChange={(event) => setDraft({ ...draft, is_active: event.target.checked })}
                                />
                                <span>{t('common.active', 'مفعّل')}</span>
                            </label>

                            {/*
                              THE SLIDER'S LOOK (Part 3.2, 2026-11-05): a colour behind this slider's section, and a
                              toggle that dissolves the white studio background of its product photos into that colour.
                              Both belong to THIS slider only. No colour and the toggle off = the slider as it always was.
                              A brand banner brings its own three colours, so it has neither.
                            */}
                            {errors.kind ? <p className="text-sm text-destructive sm:col-span-2">{errors.kind}</p> : null}

                            {isBanner || isBlock ? null : (
                                <div className="space-y-2 rounded-md border p-3 text-sm sm:col-span-2">
                                    <span className="font-medium">{t('home_rails.look', 'شكل هذا الشريط')}</span>
                                    {/*
                                      THE SHOP'S PRESET COLOURS (the review of 2026-11-05): a few colours chosen to flatter
                                      product photos on this shop. A preset only fills the colour field below — any colour
                                      may still be picked or typed there.
                                    */}
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="text-xs text-muted-foreground">{t('home_rails.presets', 'ألوان جاهزة لهذا المتجر')}</span>
                                        {background_presets.map((preset) => {
                                            const chosen = draft.background.toUpperCase() === preset.hex.toUpperCase();
                                            return (
                                                <button
                                                    key={preset.hex}
                                                    type="button"
                                                    title={`${preset.name} ${preset.hex}`}
                                                    aria-label={preset.name}
                                                    aria-pressed={chosen}
                                                    className={`size-8 rounded-full border shadow-sm outline-none ring-offset-2 ring-offset-background focus-visible:ring-2 focus-visible:ring-ring ${chosen ? 'ring-2 ring-primary' : ''}`}
                                                    style={{ background: preset.hex }}
                                                    onClick={() => setDraft({ ...draft, background: preset.hex })}
                                                />
                                            );
                                        })}
                                    </div>
                                    <div className="flex flex-wrap items-end gap-4">
                                        <label className="space-y-1 text-xs">
                                            <span className="block text-muted-foreground">{t('home_rails.background', 'لون خلفية القسم')}</span>
                                            <span className="flex items-center gap-2">
                                                <input
                                                    type="color"
                                                    className="h-9 w-12 cursor-pointer rounded border bg-transparent p-0.5"
                                                    value={HEX.test(draft.background) ? draft.background : '#FFFFFF'}
                                                    onChange={(event) => setDraft({ ...draft, background: event.target.value.toUpperCase() })}
                                                />
                                                <Input
                                                    dir="ltr"
                                                    className="w-28"
                                                    value={draft.background}
                                                    maxLength={7}
                                                    placeholder={t('home_rails.background_none', 'بلا لون')}
                                                    onChange={(event) => setDraft({ ...draft, background: event.target.value, blend: event.target.value === '' ? false : draft.blend })}
                                                />
                                                {draft.background !== '' ? (
                                                    <Button variant="outline" size="sm" onClick={() => setDraft({ ...draft, background: '', blend: false })}>
                                                        {t('home_rails.background_clear', 'إزالة اللون')}
                                                    </Button>
                                                ) : null}
                                            </span>
                                        </label>
                                        <label className="flex items-center gap-2 text-sm">
                                            <input
                                                type="checkbox"
                                                checked={draft.blend}
                                                disabled={draft.background === ''}
                                                onChange={(event) => setDraft({ ...draft, blend: event.target.checked })}
                                            />
                                            <span>{t('home_rails.blend', 'دمج خلفية صور المنتجات في اللون')}</span>
                                        </label>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {draft.background === ''
                                            ? t('home_rails.blend_needs_color_hint', 'اختر لونًا أولًا ليصبح الدمج متاحًا: الدمج يستبدل الخلفية البيضاء للصور بلون القسم.')
                                            : t(
                                                  'home_rails.blend_hint',
                                                  'مع الدمج يحلّ لون القسم محل الخلفية البيضاء فقط في صورة كل منتج؛ المنتج نفسه يبقى بألوانه كما هي تمامًا. الصورة التي ليست لها خلفية بيضاء تُعرض كما هي، والفراغ الأبيض المحاط بالمنتج (داخل يد حقيبة مثلًا) يبقى أبيض.',
                                              )}
                                    </p>
                                    {errors.background ? <p className="text-sm text-destructive">{errors.background}</p> : null}
                                    {errors.blend ? <p className="text-sm text-destructive">{errors.blend}</p> : null}
                                </div>
                            )}
                        </div>

                        <div className="mt-4 flex flex-wrap gap-2">
                            <Button onClick={submit} disabled={blocked !== null}>
                                {t('common.save', 'حفظ')}
                            </Button>
                            <Button variant="outline" onClick={() => setDraft(null)}>
                                {t('common.cancel', 'إلغاء')}
                            </Button>
                            {blocked !== null ? <span className="self-center text-sm text-muted-foreground">{blocked}</span> : null}
                            {draft.id !== null ? (
                                <Button
                                    variant="destructive"
                                    className="ms-auto"
                                    onClick={() =>
                                        router.delete(`${base}/${draft.id}`, {
                                            preserveScroll: true,
                                            onSuccess: () => setDraft(null),
                                        })
                                    }
                                >
                                    {t('common.delete', 'حذف')}
                                </Button>
                            ) : null}
                        </div>
                    </div>
                ) : null}

                {rails.length === 0 ? (
                    <div className="rounded-lg border border-dashed p-8 text-center">
                        <p className="font-medium">{t('home_rails.empty_title', 'لا توجد أشرطة')}</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                            {t('home_rails.empty_description', 'الصفحة الرئيسية لهذا المتجر لا تعرض أي شريط منتجات. أضف شريطًا.')}
                        </p>
                    </div>
                ) : (
                    <>
                        {/* A refused reorder is said out loud here — it used to be swallowed entirely. */}
                        {orderError ? (
                            <p className="mb-3 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm text-destructive">
                                {orderError}
                            </p>
                        ) : null}
                        <ol className="divide-y rounded-lg border bg-card">
                        {rails.map((rail, index) => (
                            <li
                                key={rail.id}
                                className={`flex flex-wrap items-center gap-3 p-3 ${rail.is_active ? '' : 'opacity-60'}`}
                            >
                                <span className="w-6 text-center text-sm text-muted-foreground tabular-nums" dir="ltr">
                                    {index + 1}
                                </span>
                                <div className="flex flex-col gap-1">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-label={t('home_rails.move_up', 'تحريك لأعلى')}
                                        disabled={index === 0}
                                        onClick={() => move(index, -1)}
                                    >
                                        ↑
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        aria-label={t('home_rails.move_down', 'تحريك لأسفل')}
                                        disabled={index === rails.length - 1}
                                        onClick={() => move(index, 1)}
                                    >
                                        ↓
                                    </Button>
                                </div>
                                <div className="min-w-0 flex-1 space-y-0.5">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">{KIND[rail.kind] ?? rail.kind}</span>
                                        {rail.target_name !== null ? <span className="truncate">· {rail.target_name}</span> : null}
                                        {/*
                                          * The live product count, and a warning when the storefront would not
                                          * draw this rail at all (item 14, 2026-10-23). It is shown for every
                                          * rail, not only the short ones, because the number is the point: it
                                          * moves with stock and visibility without anyone editing the rail, so
                                          * a rail that was fine last week can be short today. Saving is not
                                          * blocked — the page's own rule is the guard, and a refusal here would
                                          * promise something this screen cannot keep.
                                          */}
                                        {rail.background !== null ? (
                                            <span
                                                className="inline-flex shrink-0 items-center gap-1 text-xs text-muted-foreground"
                                                title={rail.blend ? t('home_rails.blend_on', 'الدمج مفعّل') : undefined}
                                            >
                                                <span className="inline-block size-3.5 rounded-sm border" style={{ background: rail.background }} />
                                                <span dir="ltr">{rail.background}</span>
                                                {rail.blend ? <span>· {t('home_rails.blend_short', 'دمج')}</span> : null}
                                            </span>
                                        ) : null}
                                        {sections.includes(rail.kind) && rail.kind !== 'banners' ? null : rail.kind === 'banners' ? (
                                            <span
                                                className={
                                                    hero === 'banner' || block_banners === 0
                                                        ? 'shrink-0 rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800'
                                                        : 'shrink-0 text-slate-500'
                                                }
                                            >
                                                ·{' '}
                                                {hero === 'banner'
                                                    ? t('home_rails.block_leads', 'يتصدّر الصفحة الآن (أعلى الصفحة: البانرات) — ترتيبه هنا يُطبَّق عند اختيار هيرو')
                                                    : block_banners === 0
                                                      ? t('home_rails.block_empty', 'لا بانرات مفعّلة — لا يظهر')
                                                      : t('home_rails.block_count', ':count بانر', { count: block_banners })}
                                            </span>
                                        ) : (
                                        <span
                                            className={
                                                rail.product_count < (rail.kind === 'brand_banner' ? 1 : min_products)
                                                    ? 'shrink-0 rounded bg-amber-100 px-1.5 py-0.5 font-medium text-amber-800'
                                                    : 'shrink-0 text-slate-500'
                                            }
                                            title={
                                                rail.product_count < (rail.kind === 'brand_banner' ? 1 : min_products)
                                                    ? t('home_rails.too_few_title', 'لا يظهر هذا الشريط في الصفحة الرئيسية لأن منتجاته أقل من :min', { min: min_products })
                                                    : undefined
                                            }
                                        >
                                            ·{' '}
                                            {rail.product_count < (rail.kind === 'brand_banner' ? 1 : min_products)
                                                ? t('home_rails.too_few', ':count منتج — لا يظهر (الحد :min)', { count: rail.product_count, min: rail.kind === 'brand_banner' ? 1 : min_products })
                                                : t('home_rails.count', ':count منتج', { count: rail.product_count })}
                                        </span>
                                        )}
                                    </div>
                                    {sections.includes(rail.kind) && rail.kind !== 'banners' ? (
                                        <div className="text-xs text-muted-foreground">{SECTION_NOTE[rail.kind] ?? ''}</div>
                                    ) : rail.kind === 'banners' ? (
                                        <div className="text-xs text-muted-foreground">
                                            {t('home_rails.block_row', 'البانر الكبير والخانتان الجانبيتان، في هذا المكان بين الأشرطة.')}{' '}
                                            <a className="underline" href={bannersUrl}>
                                                {t('home_rails.block_open_banners', 'فتح شاشة البانرات')}
                                            </a>
                                        </div>
                                    ) : (
                                    <div className="text-xs text-muted-foreground">
                                        {rail.title_ar !== null || rail.title_en !== null
                                            ? [rail.title_ar, rail.title_en].filter((title) => title !== null).join(' / ')
                                            : t('home_rails.default_title', 'العنوان: الاسم التلقائي')}
                                        {' · '}
                                        {t('home_rails.cards', ':count منتجات', { count: rail.card_count })}
                                    </div>
                                    )}
                                </div>
                                <Badge variant={rail.is_active ? 'success' : 'neutral'}>
                                    {rail.is_active ? t('common.active', 'مفعّل') : t('common.suspended', 'موقوف')}
                                </Badge>
                                <Button variant="outline" size="sm" onClick={() => toggle(rail)}>
                                    {rail.is_active ? t('home_rails.switch_off', 'إيقاف') : t('home_rails.switch_on', 'تفعيل')}
                                </Button>
                                <Button variant="outline" size="sm" onClick={() => edit(rail)}>
                                    {t('common.edit', 'تعديل')}
                                </Button>
                            </li>
                        ))}
                        </ol>
                    </>
                )}
            </div>
        </ManageLayout>
    );
}
