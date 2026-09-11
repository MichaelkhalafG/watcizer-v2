import { router, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

import { FormActions } from '@/components/form/FormActions';
import { SelectField, TextField, TextareaField } from '@/components/form/TextField';
import { SwitchField } from '@/components/form/SwitchField';
import { TranslatedField, type Translations } from '@/components/form/TranslatedField';
import { useDirtyGuard } from '@/components/form/useDirtyGuard';
import { ImageGallery, type GalleryImage } from '@/components/manage/ImageGallery';
import { SpecBlock, type FamilyExplanation, type SpecBlockDef, type SpecValues } from '@/components/manage/SpecBlock';
import { VariantsPanel, type VariantRow, type VariantState } from '@/components/manage/VariantsPanel';
import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { PreSwitchState, SharedProps } from '@/types';

type Option = { value: string; label: string };

/** Both locales of one translated field. `Translations` is the form components' own shape. */
type Pair = Translations;

/** The translated fields this form edits — the keys App\Domain\Catalog\ProductWriter::TRANSLATED names. */
type TranslationKey =
    | 'title'
    | 'short_description'
    | 'long_description'
    | 'model_name'
    | 'country'
    | 'stone'
    | 'meta_title'
    | 'meta_description';

/**
 * The form's shape, written out rather than inferred.
 *
 * `useForm` needs a concrete type for `setData` to be checked at all, and this form has four
 * kinds of value (scalars, ar/en pairs, a spec bag, and three lists) — an inferred type would
 * make every dynamic `setData` an `any` and lose exactly the checking that matters here.
 */
interface ProductFormData {
    wa_code: string;
    sku: string;
    model_number: string;
    hs_code: string;
    brand_id: string;
    grade_id: string;
    purchase_price: string;
    selling_price: string;
    sale_price: string;
    currency: string;
    low_stock_threshold: number | string;
    warranty_years: string;
    is_active: boolean;
    search_keywords: string;

    title: Pair;
    short_description: Pair;
    long_description: Pair;
    model_name: Pair;
    country: Pair;
    stone: Pair;
    meta_title: Pair;
    meta_description: Pair;

    specs: SpecValues;
    images: GalleryImage[];
    feature_ids: number[];
    gender_ids: number[];
    colors: Array<{ color_id: number; role: string }>;

    /**
     * The per-storefront half, keyed by storefront id as a string.
     *
     * Keyed rather than a list because that is the shape the server validates
     * (`storefronts.*.is_visible`) and because a form field name has to name its storefront: the
     * whole point of this section is that Watchizer's visibility and Brand Fashion's are two
     * different decisions about one shared product (AGENTS §2.4).
     */
    storefronts: Record<string, StorefrontSectionData>;
}

interface StorefrontSectionData {
    category_ids: number[];
    primary_category_id: string;
    is_visible: boolean;
    is_featured: boolean;
    sort_order: number | string;
    slug: string;
}

interface CategoryOption {
    value: string;
    label: string;
    depth: number;
    path: string;
    /** The family the SERVER resolves for this node — '' when its path is malformed. */
    family: string;
    family_reason: string;
}

interface StorefrontSection {
    storefront: { id: number; code: string; name: string };
    /** Whether THIS storefront's primary category decides the shared `family` column. */
    decides_family: boolean;
    categories: CategoryOption[];
    placement: {
        is_visible: boolean;
        is_featured: boolean;
        sort_order: number;
        slug: string;
        category_ids: number[];
        primary_category_id: number | null;
    };
}

interface ProductPayload {
    id: number;
    wa_code: string;
    sku: string;
    model_number: string;
    hs_code: string;
    brand_id: string;
    grade_id: string;
    family: string;
    purchase_price: string;
    selling_price: string;
    sale_price: string;
    currency: string;
    low_stock_threshold: number;
    warranty_years: string;
    is_active: boolean;
    search_keywords: string;
    translations: Record<string, Pair>;
    specs: SpecValues;
    images: GalleryImage[];
    feature_ids: number[];
    gender_ids: number[];
    colors: Array<{ color_id: number; role: string }>;
    stock_express: number;
    stock_market: number;
    in_stock: boolean;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Option[];
    product: ProductPayload | null;
    /** One section per ACTIVE storefront, in id order. */
    sections: StorefrontSection[];
    /** What is missing in Arabic, which is what blocks visibility on EVERY storefront. */
    missing_arabic: string[];
    /** The storefront whose primary category decides the shared family (and the spec block). */
    family_storefront: number;
    /** Whether slug editing is open yet, and the reason when it is not (review 🟠-3). */
    slug_lock: PreSwitchState;
    family: FamilyExplanation;
    blocks: Record<string, SpecBlockDef>;
    lookups: Record<string, Option[]>;
    brands: Option[];
    variants: { rows: VariantRow[]; state: VariantState };
    pre_switch_notice: { pre_switch: boolean; message: string } | null;
    pre_switch: PreSwitchState;
    pre_switch_variant: PreSwitchState;
}

const EMPTY_PAIR: Pair = { ar: '', en: '' };

/**
 * The product form — wave 4B's centrepiece (scope items 2, 3, 5).
 *
 * ── The one idea that shapes the whole screen ────────────────────────────────────────────────
 *
 * **The category decides the family, and the family decides the block.** There is no "family"
 * field: choosing a primary category IS choosing which specifications the product has, resolved by
 * the same rule and the same configuration the transform uses
 * (App\Domain\Catalog\FamilyForCategory → App\Transform\FamilyResolver → config/transform.php).
 * The legacy dashboard asked both questions and answered the second from hard-coded category ids,
 * which is the bug that had to be hotfixed; asking once makes that impossible rather than unlikely.
 *
 * The derived family is shown WITH its reason, and it updates the moment the category changes —
 * every block is already in the browser, so there is no round trip and no flash of the wrong form.
 *
 * ── Arabic is not optional ───────────────────────────────────────────────────────────────────
 *
 * Translation fallback is OFF (AGENTS §2.17). Every pair shows both locales at once, a missing
 * Arabic value is visibly missing, and the server REFUSES to make a product visible without one —
 * the visibility switch says so before the save and the error names the missing field after it.
 *
 * ── Stock is not on this form ────────────────────────────────────────────────────────────────
 *
 * Deliberately. A quantity is a ledger event (`InventoryService`), so it lives in the variants
 * panel, which posts per row. For a product with no variants the current quantity is shown
 * read-only with a pointer to where it can be changed — a number that looks editable and is not is
 * worse than a number that does not.
 */
export default function ProductForm({
    storefront,
    storefronts,
    product,
    sections,
    missing_arabic,
    slug_lock,
    family,
    blocks,
    lookups,
    brands,
    variants,
    pre_switch_notice,
    pre_switch,
    pre_switch_variant,
}: Props) {
    const { errors } = usePage<SharedProps>().props;
    const isNew = product === null;

    const form = useForm<ProductFormData>({
        wa_code: product?.wa_code ?? '',
        sku: product?.sku ?? '',
        model_number: product?.model_number ?? '',
        hs_code: product?.hs_code ?? '',
        brand_id: product?.brand_id ?? (brands[0]?.value ?? ''),
        grade_id: product?.grade_id ?? '',
        purchase_price: product?.purchase_price ?? '0',
        selling_price: product?.selling_price ?? '',
        sale_price: product?.sale_price ?? '',
        currency: product?.currency ?? 'EGP',
        low_stock_threshold: product?.low_stock_threshold ?? 5,
        warranty_years: product?.warranty_years ?? '',
        is_active: product?.is_active ?? true,
        search_keywords: product?.search_keywords ?? '',

        title: product?.translations.title ?? EMPTY_PAIR,
        short_description: product?.translations.short_description ?? EMPTY_PAIR,
        long_description: product?.translations.long_description ?? EMPTY_PAIR,
        model_name: product?.translations.model_name ?? EMPTY_PAIR,
        country: product?.translations.country ?? EMPTY_PAIR,
        stone: product?.translations.stone ?? EMPTY_PAIR,
        meta_title: product?.translations.meta_title ?? EMPTY_PAIR,
        meta_description: product?.translations.meta_description ?? EMPTY_PAIR,

        specs: product?.specs ?? {},
        images: product?.images ?? [],
        feature_ids: product?.feature_ids ?? [],
        gender_ids: product?.gender_ids ?? [],
        colors: product?.colors ?? [],

        storefronts: Object.fromEntries(
            sections.map((section) => [
                String(section.storefront.id),
                {
                    category_ids: section.placement.category_ids,
                    primary_category_id:
                        section.placement.primary_category_id === null ? '' : String(section.placement.primary_category_id),
                    is_visible: section.placement.is_visible,
                    is_featured: section.placement.is_featured,
                    sort_order: section.placement.sort_order,
                    slug: section.placement.slug,
                },
            ]),
        ),
    });

    useDirtyGuard(form.isDirty);

    /**
     * The family of whatever primary category is selected RIGHT NOW — looked up, never derived.
     *
     * ── This is the task-4.1 fix, and the previous version is worth remembering ──────────────
     *
     * It used to return `{ ...family, node_id, reason }`: it rewrote the explanation SENTENCE and
     * left `family` untouched, so `SpecBlock` kept rendering `blocks[the old family]`. Changing a
     * watch's category to Bags updated the text under the heading and not one field — the screen
     * looked reactive and was not, and the required-field set stayed the old one on both the
     * create and the edit screen, with or without a reload.
     *
     * The comment that stood here promised a "shallow mirror (root name → 'watch')" of the
     * server's rule. There was no such mirror in the code, and writing one would have been the
     * wrong fix anyway: a second implementation of the derivation rule is precisely the shape of
     * the legacy dashboard bug AGENTS §2.21 exists to prevent. So the SERVER resolves the family
     * for every option it offers and this is a dictionary lookup.
     */
    const deciding = sections.find((section) => section.decides_family) ?? sections[0];
    const decidingKey = deciding === undefined ? '' : String(deciding.storefront.id);

    const shownFamily: FamilyExplanation = useMemo(() => {
        if (deciding === undefined) {
            return family;
        }
        const chosen = form.data.storefronts[decidingKey]?.primary_category_id ?? '';
        if (chosen === '') {
            return family;
        }
        const node = deciding.categories.find((option) => option.value === chosen);
        if (node === undefined || node.family === '') {
            return family;
        }

        return {
            family: node.family,
            node_id: Number(chosen),
            node_en: family.node_en,
            root_en: family.root_en,
            reason: `${node.family_reason} — القاعدة نفسها التي يستخدمها التحويل (config/transform.php)`,
            saved_family: family.saved_family,
        };
    }, [deciding, decidingKey, family, form.data.storefronts]);

    const pair = (name: TranslationKey): Pair => form.data[name] ?? EMPTY_PAIR;
    const setPair = (name: TranslationKey, value: Pair) => form.setData(name, value);

    const submit = () => {
        // `form.post`/`form.put` rather than `router.*`: they carry the form’s own typed data and
        // keep `processing`/`errors` wired to this form instead of the page.
        if (isNew) {
            form.post(`/manage/storefronts/${storefront.id}/products`, { preserveScroll: true });

            return;
        }
        form.put(`/manage/storefronts/${storefront.id}/products/${product.id}`, { preserveScroll: true });
    };

    /** Patch one storefront's section, leaving every other storefront alone. */
    const setSection = (key: string, patch: Partial<StorefrontSectionData>) => {
        const current = form.data.storefronts[key];
        if (current === undefined) {
            return;
        }
        form.setData('storefronts', { ...form.data.storefronts, [key]: { ...current, ...patch } });
    };

    const toggleCategory = (key: string, id: number, on: boolean) => {
        const current = form.data.storefronts[key];
        if (current === undefined) {
            return;
        }
        const next = on ? [...current.category_ids, id] : current.category_ids.filter((item) => item !== id);
        const patch: Partial<StorefrontSectionData> = { category_ids: next };
        // The primary must be one of the chosen categories, or the one-primary invariant has
        // nothing to hold — and the product would have no family on this storefront.
        if (!on && current.primary_category_id === String(id)) {
            patch.primary_category_id = next.length > 0 ? String(next[0]) : '';
        }
        if (on && current.primary_category_id === '') {
            patch.primary_category_id = String(id);
        }
        setSection(key, patch);
    };

    const hasArabicTitle = pair('title').ar.trim() !== '';

    return (
        <ManageLayout
            title={isNew ? 'منتج جديد' : `تعديل: ${pair('title').ar || product.wa_code}`}
            crumbs={[
                { label: 'الرئيسية', href: '/manage' },
                { label: 'المنتجات', href: `/manage/storefronts/${storefront.id}/products` },
                { label: isNew ? 'منتج جديد' : pair('title').ar || product.wa_code },
            ]}
        >
            {/* On a CREATE screen the block is the headline; on an EDIT screen it is a footnote,
                because editing is exactly what the team is meant to be doing before the switch. */}
            {isNew && pre_switch.blocked ? (
                <Alert tone="error" title="لا يمكن إنشاء منتج جديد قبل ليلة التحويل">
                    {pre_switch.message}
                </Alert>
            ) : pre_switch_notice === null ? null : (
                <Alert tone="warning" title="قبل ليلة التحويل — اقرأ هذا أولًا">
                    {pre_switch_notice.message}
                </Alert>
            )}

            {errors.pre_switch ? (
                <Alert tone="error" title="تعذّر الإنشاء">
                    {errors.pre_switch}
                </Alert>
            ) : null}

            <form
                className="space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                {/* ── identity ─────────────────────────────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>التعريف</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <TextField
                            label="كود واتشيزر"
                            required
                            dir="ltr"
                            hint="الكود الذي يعرفه المخزن والفواتير. لا يتكرر."
                            error={errors.wa_code ?? null}
                            value={String(form.data.wa_code ?? '')}
                            onChange={(value) => form.setData('wa_code', value)}
                        />
                        <TextField label="SKU" dir="ltr" error={errors.sku ?? null} value={String(form.data.sku ?? '')} onChange={(value) => form.setData('sku', value)} />
                        <TextField
                            label="رقم الموديل"
                            dir="ltr"
                            error={errors.model_number ?? null}
                            value={String(form.data.model_number ?? '')}
                            onChange={(value) => form.setData('model_number', value)}
                        />
                        <SelectField
                            label="الماركة"
                            required
                            error={errors.brand_id ?? null}
                            value={String(form.data.brand_id ?? '')}
                            options={brands}
                            onChange={(value) => form.setData('brand_id', value)}
                        />
                        <SelectField
                            label="الدرجة"
                            placeholder="—"
                            error={errors.grade_id ?? null}
                            value={String(form.data.grade_id ?? '')}
                            options={lookups.grades ?? []}
                            onChange={(value) => form.setData('grade_id', value)}
                        />
                        <SwitchField label="مفعّل في الكتالوج" checked={form.data.is_active === true} onChange={(checked) => form.setData('is_active', checked)} />
                    </CardContent>
                </Card>

                {/* ── names and copy, both locales at once ─────────────────────────────── */}
                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle>الاسم والوصف</CardTitle>
                        <p className="text-xs text-muted-foreground">
                            الترجمة الاحتياطية مُعطّلة: ما يغيب بالعربية يظهر ناقصًا على المتجر، ولا يمكن إظهار منتج بلا عنوان عربي.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <TranslatedField label="العنوان" name="title" required value={pair('title')} onChange={(value) => setPair('title', value)} errors={errors} />
                        <TranslatedField label="وصف مختصر" name="short_description" multiline value={pair('short_description')} onChange={(value) => setPair('short_description', value)} errors={errors} />
                        <TranslatedField label="الوصف الكامل" name="long_description" multiline value={pair('long_description')} onChange={(value) => setPair('long_description', value)} errors={errors} />
                        <div className="grid gap-5 lg:grid-cols-3">
                            <TranslatedField label="اسم الموديل" name="model_name" value={pair('model_name')} onChange={(value) => setPair('model_name', value)} errors={errors} />
                            <TranslatedField label="بلد الصنع" name="country" value={pair('country')} onChange={(value) => setPair('country', value)} errors={errors} />
                            <TranslatedField label="الحجر" name="stone" value={pair('stone')} onChange={(value) => setPair('stone', value)} errors={errors} />
                        </div>
                    </CardContent>
                </Card>

                {/* ── price ────────────────────────────────────────────────────────────── */}
                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle>السعر</CardTitle>
                        <p className="text-xs text-muted-foreground">
                            سعر التخفيض يُحتسب فقط إذا كان أكبر من صفر وأقل من سعر البيع — غير ذلك يُخزَّن فارغًا، لأن الواجهة والسلة تتحققان من الشرط
                            نفسه ويُرفض إجمالي الطلب لو اختلفا.
                        </p>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <TextField label="سعر البيع" required dir="ltr" type="number" error={errors.selling_price ?? null} value={String(form.data.selling_price ?? '')} onChange={(value) => form.setData('selling_price', value)} />
                        <TextField label="سعر التخفيض" dir="ltr" type="number" error={errors.sale_price ?? null} value={String(form.data.sale_price ?? '')} onChange={(value) => form.setData('sale_price', value)} />
                        <TextField label="سعر الشراء" dir="ltr" type="number" hint="داخلي — لا يظهر على المتجر." error={errors.purchase_price ?? null} value={String(form.data.purchase_price ?? '')} onChange={(value) => form.setData('purchase_price', value)} />
                        <TextField label="العملة" dir="ltr" error={errors.currency ?? null} value={String(form.data.currency ?? '')} onChange={(value) => form.setData('currency', value)} />
                        <TextField label="حد التنبيه للمخزون" dir="ltr" type="number" error={errors.low_stock_threshold ?? null} value={String(form.data.low_stock_threshold ?? '')} onChange={(value) => form.setData('low_stock_threshold', value)} />
                        <TextField label="سنوات الضمان" dir="ltr" type="number" error={errors.warranty_years ?? null} value={String(form.data.warranty_years ?? '')} onChange={(value) => form.setData('warranty_years', value)} />
                    </CardContent>
                </Card>

                {/* ── one section per storefront: its categories, its single primary category,
                    its visibility, order and slug. The product's CONTENT above is shared; these
                    are the only columns `storefront_product` keeps per storefront (AGENTS §2.4),
                    and the form now shows all of them for every storefront at once instead of
                    making the team visit one URL per site. ────────────────────────────────── */}
                {sections.map((section) => (
                    <StorefrontFields
                        key={section.storefront.id}
                        section={section}
                        data={form.data.storefronts[String(section.storefront.id)]}
                        errors={errors}
                        canBeVisible={hasArabicTitle}
                        slugLock={slug_lock}
                        missingArabic={missing_arabic}
                        onChange={(patch) => setSection(String(section.storefront.id), patch)}
                        onToggleCategory={(id, on) => toggleCategory(String(section.storefront.id), id, on)}
                    />
                ))}

                {/* ── the family-aware block ───────────────────────────────────────────── */}
                <SpecBlock
                    explanation={shownFamily}
                    blocks={blocks}
                    lookups={lookups}
                    values={form.data.specs}
                    onChange={(values) => form.setData('specs', values)}
                    errors={errors}
                />

                {/* ── attributes ──────────────────────────────────────────────────────── */}
                <Card>
                    <CardHeader>
                        <CardTitle>الخصائص والفئات والألوان</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-5 lg:grid-cols-3">
                        <CheckList
                            label="الخصائص"
                            options={lookups.features ?? []}
                            selected={form.data.feature_ids}
                            onChange={(ids) => form.setData('feature_ids', ids)}
                        />
                        <CheckList
                            label="الفئة (رجالي/حرمي…)"
                            options={lookups.genders ?? []}
                            selected={form.data.gender_ids}
                            onChange={(ids) => form.setData('gender_ids', ids)}
                        />
                        <ColorRoles
                            options={lookups.colors ?? []}
                            value={form.data.colors}
                            onChange={(rows) => form.setData('colors', rows)}
                        />
                    </CardContent>
                </Card>

                {/* ── images ──────────────────────────────────────────────────────────── */}
                <ImageGallery images={form.data.images} onChange={(images) => form.setData('images', images)} />

                {/* ── SEO and search keywords: SHARED, like the rest of the product's content.
                    Per-storefront visibility, order, featured and slug live in each storefront's
                    own section above (AGENTS §2.4 — there are no per-storefront overrides of
                    title, description or media, on purpose). ──────────────────────── */}
                <Card>
                    <CardHeader className="gap-1">
                        <CardTitle>بيانات SEO وكلمات البحث</CardTitle>
                        <p className="text-xs text-muted-foreground">
                            مشتركة بين كل المتاجر. الظهور والترتيب والتمييز والرابط والتصنيفات تخص كل متجر على حدة، وكلها في قسم ذلك المتجر بالأعلى.
                        </p>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <div className="grid gap-5 lg:grid-cols-2">
                            <TranslatedField label="عنوان SEO" name="meta_title" value={pair('meta_title')} onChange={(value) => setPair('meta_title', value)} errors={errors} />
                            <TranslatedField label="وصف SEO" name="meta_description" multiline value={pair('meta_description')} onChange={(value) => setPair('meta_description', value)} errors={errors} />
                        </div>

                        <TextareaField
                            label="كلمات البحث"
                            hint="تُضاف إلى فهرس البحث مع الاسم والماركة والتصنيف."
                            rows={2}
                            error={errors.search_keywords ?? null}
                            value={String(form.data.search_keywords ?? '')}
                            onChange={(value) => form.setData('search_keywords', value)}
                        />
                    </CardContent>
                </Card>

                <FormActions
                    processing={form.processing}
                    // A create form whose server will refuse the POST must not offer a live save.
                    dirty={form.isDirty && !(isNew && pre_switch.blocked)}
                    submitLabel={isNew ? 'إنشاء المنتج' : 'حفظ التغييرات'}
                    onCancel={() => router.get(`/manage/storefronts/${storefront.id}/products`)}
                    extra={
                        product === null ? null : (
                            <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                <Badge variant="neutral">#{product.id}</Badge>
                                {/* Read-only on purpose: a quantity is a ledger event, so the
                                    place to change it is the variants panel (or 4C's stock
                                    screen for a product with no variants). */}
                                <span dir="ltr">
                                    Express {product.stock_express} / Market {product.stock_market}
                                </span>
                                {product.in_stock ? <Badge variant="success">متوفر</Badge> : <Badge variant="warning">نفد</Badge>}
                            </span>
                        )
                    }
                />
            </form>

            {/* Outside the form element: the panel posts on its own, and nesting forms is invalid HTML. */}
            <div className="mt-6">
                <VariantsPanel
                    productId={product === null ? null : product.id}
                    rows={variants.rows}
                    // The panel already has a `may_convert` gate from wave 3.5; the pre-switch
                    // block is a second, broader one, so the panel is told about both and shows
                    // whichever applies.
                    preSwitch={pre_switch_variant}
                    state={variants.state}
                    colors={lookups.colors ?? []}
                    sizes={lookups.sizes ?? []}
                    error={errors.variants ?? null}
                />
            </div>

            {storefronts.length > 1 ? (
                <p className="mt-4 text-xs text-muted-foreground">
                    بيانات المنتج مشتركة بين المتاجر؛ ما يخص هذا المتجر فقط هو الظهور والترتيب والتمييز والرابط والتصنيفات.
                </p>
            ) : null}
        </ManageLayout>
    );
}

/**
 * ONE STOREFRONT'S SECTION of the product form.
 *
 * Everything in here is a column of `storefront_product` or a row of
 * `storefront_category_product` — the only per-storefront things there are (AGENTS §2.4). The
 * product's name, description, price, images and specs are shared and live above, once.
 *
 * ── Rules are enforced by the controls, not described next to them (task 4.2) ─────────────────
 *
 *  • **No Arabic title → the visibility switch is OFF and DISABLED**, with the reason on the
 *    control. Translation fallback is off (§2.17), so a product with no Arabic title would render
 *    an empty name on the storefront; the server refuses it too, and this is so nobody fills in a
 *    form for twenty minutes to be told at the end.
 *  • **No category chosen → the visibility switch is OFF and DISABLED.** A product in no category
 *    is reachable from nowhere on that site, so "visible" would be a lie.
 *  • **The primary category can only be one of the CHOSEN categories**, because that is what the
 *    database's one-primary-per-storefront invariant is about.
 *  • **The slug says what changing it does** at the moment of editing, not in a toast afterwards.
 */
function StorefrontFields({
    section,
    data,
    errors,
    canBeVisible,
    slugLock,
    missingArabic,
    onChange,
    onToggleCategory,
}: {
    section: StorefrontSection;
    data: StorefrontSectionData | undefined;
    errors: Record<string, string>;
    canBeVisible: boolean;
    slugLock: PreSwitchState;
    missingArabic: string[];
    onChange: (patch: Partial<StorefrontSectionData>) => void;
    onToggleCategory: (id: number, on: boolean) => void;
}) {
    if (data === undefined) {
        return null;
    }

    const key = String(section.storefront.id);
    const error = (field: string): string | null => errors[`storefronts.${key}.${field}`] ?? null;
    const chosen = section.categories.filter((option) => data.category_ids.includes(Number(option.value)));
    const hasCategory = data.category_ids.length > 0;
    const blockedReason = !canBeVisible
        ? `لإظهار المنتج لازم عنوان عربي أولًا${missingArabic.length > 0 ? ` (الناقص: ${missingArabic.join('، ')})` : ''}. اكتبه في خانة «العنوان — عربي» بالأعلى.`
        : !hasCategory
          ? 'اختر تصنيفًا واحدًا على الأقل في هذا المتجر، وإلا لن يصل إليه أحد من أي صفحة.'
          : null;

    return (
        <Card>
            <CardHeader className="gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <CardTitle>{section.storefront.name}</CardTitle>
                    <Badge variant="neutral">{section.storefront.code}</Badge>
                    {data.is_visible ? <Badge variant="success">ظاهر</Badge> : <Badge variant="neutral">مخفي</Badge>}
                    {section.decides_family ? <Badge variant="outline">منه تُشتق المواصفات</Badge> : null}
                </div>
                <p className="text-xs text-muted-foreground">
                    التصنيف الأساسي واحد فقط لكل متجر — قاعدة مفروضة في قاعدة البيانات.{' '}
                    {section.decides_family
                        ? 'ومنه تُشتق عائلة المنتج وقائمة مواصفاته، لأن المواصفات مشتركة بين المتاجر ولا يمكن أن تختلف.'
                        : 'تصنيفات هذا المتجر مستقلة تمامًا عن المتاجر الأخرى.'}
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">التصنيفات على {section.storefront.name}</legend>
                    <div className="grid max-h-64 gap-1.5 overflow-y-auto rounded-lg border p-3 sm:grid-cols-2">
                        {section.categories.length === 0 ? (
                            <p className="text-xs text-muted-foreground">لا توجد تصنيفات في هذا المتجر بعد.</p>
                        ) : null}
                        {section.categories.map((option) => {
                            const id = Number(option.value);

                            return (
                                <label key={option.value} className="flex items-center gap-2 text-sm">
                                    <Checkbox
                                        checked={data.category_ids.includes(id)}
                                        onCheckedChange={(checkedState) => onToggleCategory(id, checkedState === true)}
                                    />
                                    <span>{option.label}</span>
                                </label>
                            );
                        })}
                    </div>
                    {error('category_ids') ? (
                        <p role="alert" className="text-xs font-medium text-destructive">
                            {error('category_ids')}
                        </p>
                    ) : null}
                </fieldset>

                <SelectField
                    label="التصنيف الأساسي"
                    hint={
                        section.decides_family
                            ? 'يحدد عائلة المنتج ومواصفاته، ويحدد التصنيف الذي يمثل المنتج في مسارات هذا المتجر.'
                            : 'التصنيف الذي يمثل المنتج في مسارات هذا المتجر.'
                    }
                    placeholder="—"
                    error={error('primary_category_id')}
                    value={data.primary_category_id}
                    // Only the CHOSEN categories can be primary: the invariant is about a row that
                    // exists, so offering an unchosen node would offer an error.
                    options={chosen.map((option) => ({ value: option.value, label: option.label.replace(/^(— )+/, '') }))}
                    onChange={(value) => onChange({ primary_category_id: value })}
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <SwitchField
                        label={`ظاهر على ${section.storefront.name}`}
                        hint={blockedReason ?? undefined}
                        error={error('is_visible')}
                        checked={data.is_visible && blockedReason === null}
                        disabled={blockedReason !== null}
                        onChange={(checkedState) => onChange({ is_visible: checkedState })}
                    />
                    <SwitchField
                        label="مميّز"
                        hint="يظهر في الأقسام المميزة على واجهة هذا المتجر."
                        checked={data.is_featured}
                        onChange={(checkedState) => onChange({ is_featured: checkedState })}
                    />
                    <TextField
                        label="الترتيب"
                        dir="ltr"
                        type="number"
                        min={0}
                        hint="الأقل يظهر أولًا داخل التصنيف."
                        error={error('sort_order')}
                        value={String(data.sort_order)}
                        onChange={(value) => onChange({ sort_order: value })}
                    />
                    <TextField
                        label="الرابط (slug)"
                        dir="ltr"
                        // Locked until the write-switch, with the reason ON the field: the 301 a
                        // change would promise is deleted by the next rebuild together with the
                        // slug itself, so the field refuses instead of warning (review 🟠-3).
                        disabled={slugLock.blocked}
                        hint={slugLock.blocked ? (slugLock.message ?? undefined) : 'يُولّد من العنوان الإنجليزي إن تُرك فارغًا.'}
                        error={error('slug')}
                        value={data.slug}
                        onChange={(value) => onChange({ slug: value })}
                    />
                </div>

                {!slugLock.blocked && data.slug.trim() !== '' && data.slug !== section.placement.slug ? (
                    <Alert tone="warning" title="ستُغيّر رابط المنتج على هذا المتجر">
                        الرابط الحالي <code dir="ltr">/product/{section.placement.slug}</code> وسيصبح{' '}
                        <code dir="ltr">/product/{data.slug.trim()}</code>. الرابط القديم لن يتوقف: يُنشأ تحويل 301 تلقائيًا،
                        لكن الروابط المنشورة والمشاركة وإعلانات جوجل ستمر عبر التحويل وتفقد قليلًا من ترتيبها. لا تغيّره بلا سبب.
                    </Alert>
                ) : null}
            </CardContent>
        </Card>
    );
}

/** A checkbox list over a lookup — features, genders. */
function CheckList({
    label,
    options,
    selected,
    onChange,
}: {
    label: string;
    options: Option[];
    selected: number[];
    onChange: (ids: number[]) => void;
}) {
    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-medium">{label}</legend>
            <div className="grid max-h-56 gap-1.5 overflow-y-auto rounded-lg border p-3">
                {options.length === 0 ? <p className="text-xs text-muted-foreground">لا توجد عناصر.</p> : null}
                {options.map((option) => {
                    const id = Number(option.value);

                    return (
                        <label key={option.value} className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={selected.includes(id)}
                                onCheckedChange={(checked) => onChange(checked === true ? [...selected, id] : selected.filter((item) => item !== id))}
                            />
                            <span>{option.label}</span>
                        </label>
                    );
                })}
            </div>
        </fieldset>
    );
}

/**
 * Colours with their ROLE (dial / band / main).
 *
 * The pivot's primary key is (product, colour, role), so the same colour can legitimately be both
 * the dial and the band colour — which is why this is a grid of roles rather than a flat list.
 */
function ColorRoles({
    options,
    value,
    onChange,
}: {
    options: Option[];
    value: Array<{ color_id: number; role: string }>;
    onChange: (rows: Array<{ color_id: number; role: string }>) => void;
}) {
    const ROLES: Array<{ key: string; label: string }> = [
        { key: 'main', label: 'اللون الأساسي' },
        { key: 'dial', label: 'لون القرص' },
        { key: 'band', label: 'لون السوار' },
    ];

    const set = (role: string, colorId: string) => {
        const without = value.filter((row) => row.role !== role);
        onChange(colorId === '' ? without : [...without, { color_id: Number(colorId), role }]);
    };

    return (
        <div className="space-y-3">
            <Label>الألوان</Label>
            {ROLES.map((role) => {
                const current = value.find((row) => row.role === role.key);

                return (
                    <SelectField
                        key={role.key}
                        label={role.label}
                        placeholder="—"
                        value={current === undefined ? '' : String(current.color_id)}
                        options={options}
                        onChange={(colorId) => set(role.key, colorId)}
                    />
                );
            })}
        </div>
    );
}
