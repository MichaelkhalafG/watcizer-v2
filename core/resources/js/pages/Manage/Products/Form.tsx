import { router, useForm, usePage } from "@inertiajs/react";
import { Trash2 } from "lucide-react";
import { useEffect, useMemo, useState } from "react";

import { CategoryPicker } from "@/components/manage/CategoryPicker";
import { ErrorCount, ErrorSummary } from "@/components/form/ErrorSummary";
import {
    FormTabs,
    SaveScopeNote,
    StickySaveBar,
    TabPanel,
    firstTabWithError,
    type FormTab,
} from "@/components/form/FormTabs";
import { FormActions } from "@/components/form/FormActions";
import {
    SelectField,
    TextField,
    TextareaField,
} from "@/components/form/TextField";
import { SwitchField } from "@/components/form/SwitchField";
import {
    TranslatedField,
    type Translations,
} from "@/components/form/TranslatedField";
import { useDirtyGuard } from "@/components/form/useDirtyGuard";
import {
    ImageGallery,
    type GalleryImage,
} from "@/components/manage/ImageGallery";
import {
    SpecBlock,
    type FamilyExplanation,
    type SpecBlockDef,
    type SpecValues,
} from "@/components/manage/SpecBlock";
import {
    VariantsPanel,
    type VariantRow,
    type VariantState,
} from "@/components/manage/VariantsPanel";
import ManageLayout from "@/layouts/ManageLayout";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ConfirmAction } from "@/components/manage/ConfirmAction";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { useLocale, useT } from "@/lib/i18n";
import { cn } from "@/lib/utils";
import { titleOrCode } from "@/lib/title";
import {
    DESCRIPTION_EM,
    estimateEm,
    generateSeo,
    TITLE_EM,
    type Attribute as SeoAttribute,
    type NamePair,
    type SeoInput,
} from "@/lib/seo";
import type { PreSwitchState, SharedProps } from "@/types";

type Option = { value: string; label: string };

/** Both locales of one translated field. `Translations` is the form components' own shape. */
type Pair = Translations;

/** The translated fields this form edits — the keys App\Domain\Catalog\ProductWriter::TRANSLATED names. */
type TranslationKey =
    | "title"
    | "short_description"
    | "long_description"
    | "model_name"
    | "country"
    | "stone"
    | "meta_title"
    | "meta_description";

/**
 * The form's shape, written out rather than inferred.
 *
 * `useForm` needs a concrete type for `setData` to be checked at all, and this form has four
 * kinds of value (scalars, ar/en pairs, a spec bag, and three lists) — an inferred type would
 * make every dynamic `setData` an `any` and lose exactly the checking that matters here.
 */
interface ProductFormData {
    /**
     * The full-replace declaration (`App\Support\FullReplace`). Always `1` from this screen: the
     * save replaces the whole product, and the server refuses a payload that has not said so.
     */
    _complete: 1;
    wa_code: string;
    sku: string;
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
    /** With the `— ` depth prefix, for the native `<select>` that picks the primary. */
    label: string;
    /** Without it, for the tree picker, which indents properly. */
    name: string;
    /** The English name — search only (the team is bilingual; the tree is labelled in Arabic). */
    en: string;
    depth: number;
    path: string;
    /** False for the parked legacy tree: shown, explained, not pickable (J-9). */
    selectable: boolean;
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
    /**
     * What the placement IS today (rehearsal #3, 2026-09-12), not what this form will submit:
     *  - `root_only` — on the top-level section with NO primary category, the shape a legacy
     *                  product with no sub-type has. The select below is prefilled with that root,
     *                  so an ordinary save GIVES it a primary it does not have — said out loud
     *                  rather than done quietly;
     *  - `none`      — no category at all;
     *  - `placed`    — the ordinary state.
     */
    placement_state: "placed" | "root_only" | "none";
}

interface ProductPayload {
    id: number;
    wa_code: string;
    sku: string;
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
    /** The server's view of what blocks visibility. The form recomputes it live, so this
     *  documents the payload (and `PreventionTest` pins it) rather than being read here. */
    missing_arabic: string[];
    /** The storefront whose primary category decides the shared family (and the spec block). */
    family_storefront: number;
    /** Whether slug editing is open yet, and the reason when it is not (review 🟠-3). */
    slug_lock: PreSwitchState;
    /** Whether THIS operator may type a slug at all (item 6) — the person, not the calendar. */
    slug_role: { allowed: boolean; message: string | null };
    family: FamilyExplanation;
    blocks: Record<string, SpecBlockDef>;
    /** Family => the colour questions it is asked. `default` answers for the rest (J-6). */
    color_roles: Record<string, Array<{ key: string; label: string }>>;
    lookups: Record<string, Option[]>;
    brands: Option[];
    /** `id => {ar, en}` for the SEO generator, which writes a sentence in each language. */
    brand_names: Record<string, { ar: string; en: string }>;
    category_names: Record<string, { ar: string; en: string }>;
    /** `list => id => {ar, en}` for genders, colours and materials — the SEO generator's other
     *  facts. Only the three lists that appear in a sentence somebody would click (item 5). */
    lookup_names: Record<string, Record<string, NamePair>>;
    variants: { rows: VariantRow[]; state: VariantState };
    pre_switch: PreSwitchState;
    pre_switch_variant: PreSwitchState;
}

const EMPTY_PAIR: Pair = { ar: "", en: "" };

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
    slug_lock,
    slug_role,
    family,
    blocks,
    color_roles,
    lookups,
    brands,
    brand_names,
    category_names,
    lookup_names,
    variants,
    pre_switch,
    pre_switch_variant,
}: Props) {
    const t = useT();
    const locale = useLocale();
    const { errors } = usePage<SharedProps>().props;

    /*
     * ── The seven tabs (§2.1) ───────────────────────────────────────────────────────────────
     *
     * `fields` is what makes them honest. Only the ACTIVE panel is mounted (see `FormTabs`), so a
     * field the server refused on another tab has nothing on screen to point at — the count on the
     * tab is the only thing that can say so, and a failed save switches to the first tab holding
     * one. The prefixes are matched against Laravel's own keys, which is why `title` covers
     * `title.ar` and `storefronts` covers `storefronts.1.slug`.
     */
    const TABS: FormTab[] = [
        {
            key: "identity",
            label: t("products.identity", "التعريف"),
            fields: [
                "wa_code",
                "sku",
                "brand_id",
                "grade_id",
                "is_active",
                "hs_code",
            ],
        },
        {
            key: "content",
            label: t("products.content", "الاسم والوصف"),
            fields: ["title", "short_description", "long_description"],
        },
        {
            key: "price",
            label: t("common.price", "السعر"),
            fields: [
                "selling_price",
                "purchase_price",
                "sale_price",
                "currency",
                "low_stock_threshold",
            ],
        },
        {
            key: "visibility",
            label: t("products.visibility", "الظهور"),
            fields: [
                "storefronts",
                "category_ids",
                "primary_category_id",
                "is_visible",
                "slug",
            ],
        },
        {
            key: "specs",
            label: t("products.specs", "المواصفات"),
            // `warranty_years` is asked in the specification block now, not on the price tab, so
            // the tab that counts its refusal has to be this one — otherwise a 422 on the warranty
            // sends the operator to a tab where the field is no longer mounted.
            fields: [
                "specs",
                "warranty_years",
                "feature_ids",
                "gender_ids",
                "colors",
            ],
        },
        {
            key: "images",
            label: t("gallery.title", "الصور"),
            fields: ["images"],
        },
        {
            key: "variants",
            label: t("variants.tab", "المقاسات والألوان"),
            // The panel posts on its own and never through the product form, so the product's
            // error bag has nothing of its own to count here.
            fields: ["variants"],
        },
        {
            key: "seo",
            label: t("products.seo_short", "SEO"),
            fields: ["meta_title", "meta_description", "search_keywords"],
        },
    ];

    const [tab, setTab] = useState("identity");
    /*
     * Which shop's placement the visibility tab is showing (item 1a). It starts at the shop in
     * the URL — the one they came from — and switching it is local: every shop's placement is in
     * `form.data.storefronts` already, and Save submits all of them, so this moves the view and
     * never the data.
     */
    const [placementShop, setPlacementShop] = useState(storefront.id);

    /*
     * A failed save lands the operator on the tab that refused (§2.1, D-19).
     *
     * Without this the sequence is: press Save on the Images tab, the server refuses `title.ar`,
     * and the screen shows… the Images tab, unchanged. The error summary above would say so, but
     * the field itself is on a panel that is not mounted, so "go and fix it" has nowhere to go.
     *
     * Keyed on the error SET rather than on every render, so it does not drag somebody back the
     * moment they navigate away to look at something else.
     */
    const errorSignature = Object.keys(errors).sort().join("|");
    useEffect(() => {
        const target = firstTabWithError(TABS, errors);
        if (target !== null) {
            setTab(target);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errorSignature]);

    /*
     * The error summary's vocabulary: the server's field NAMES, mapped to the words printed on the
     * labels the operator is looking at.
     *
     * Deliberately the same strings as the labels below rather than new ones — a summary that
     * calls a field something the field does not call itself sends the reader hunting. Anything
     * not listed falls back to the raw name, which is honest and rare.
     */
    // A language is named in its own language, whichever locale the dashboard is in — the same
    // rule and the same pair `TranslatedField` uses for the boxes these errors belong to.
    const LANG: Record<string, string> = { ar: "العربية", en: "English" }; // i18n-exempt: language names are data, named in their own language

    const fieldLabels: Record<string, string> = {
        wa_code: t("products.wa_code", "الكود الداخلي"),
        sku: "SKU",
        brand_id: t("products.brand", "الماركة"),
        "title.ar": `${t("products.field_title", "العنوان")} — ${LANG.ar}`,
        "title.en": `${t("products.field_title", "العنوان")} — ${LANG.en}`,
        "short_description.ar": `${t("products.field_short_description", "وصف مختصر")} — ${LANG.ar}`,
        "short_description.en": `${t("products.field_short_description", "وصف مختصر")} — ${LANG.en}`,
        "long_description.ar": `${t("products.field_long_description", "الوصف الكامل")} — ${LANG.ar}`,
        "long_description.en": `${t("products.field_long_description", "الوصف الكامل")} — ${LANG.en}`,
        selling_price: t("products.selling_price", "سعر البيع"),
        purchase_price: t("products.purchase_price", "سعر الشراء"),
        sale_price: t("products.sale_price", "سعر التخفيض"),
        currency: t("products.currency", "العملة"),
        grade_id: t("products.grade", "الدرجة"),
        is_active: t("products.is_active", "مفعّل في الكتالوج"),
        is_visible: t("common.visible", "ظاهر"),
        gender_ids: t("products.genders", "الفئة (رجالي/حريمي…)"),
        images: t("gallery.title", "الصور"),
    };
    const isNew = product === null;

    const form = useForm<ProductFormData>({
        /*
         * The save REPLACES the product: a key absent from the payload is cleared server-side,
         * which is how this form empties a field. `_complete` is the declaration that the payload
         * IS the whole record — the server refuses a full-replace PUT without it, because a
         * partial payload from a script or an importer silently deletes everything it omits.
         */
        _complete: 1,
        wa_code: product?.wa_code ?? "",
        sku: product?.sku ?? "",
        hs_code: product?.hs_code ?? "",
        /*
         * ── NO default brand (J-1, 2026-09-19) ──────────────────────────────────────────────
         *
         * It defaulted to `brands[0]`, which is `value="1"` — the first row of `catalog_brands`,
         * which is **Rolex**. The field is required, so there was no "choose one" state: a form
         * left untouched WAS a Rolex, silently, because the box was filled.
         *
         * A Coach handbag published as a Rolex gets the wrong brand page, the wrong sitemap entry
         * and the wrong filter facet, on a luxury storefront — and nothing looks wrong on the form
         * while it happens. `غير محدد — Generic` is the 78th and last option, so it was not even a
         * near miss.
         *
         * Empty, with a `— اختر ماركة —` placeholder that must be chosen. `required` now reaches
         * the control (D-19), so the browser refuses the submit rather than letting the server
         * explain it six screens later.
         */
        brand_id: product?.brand_id ?? "",
        grade_id: product?.grade_id ?? "",
        purchase_price: product?.purchase_price ?? "0",
        selling_price: product?.selling_price ?? "",
        sale_price: product?.sale_price ?? "",
        currency: product?.currency ?? "EGP",
        // 0 = no alert, and it is the default for a NEW product (W-2). A reorder point is a
        // decision about one product; 5 was a guess applied to 7,578 of them, which is what made
        // the low-stock alert cover 97.5% of the shop.
        low_stock_threshold: product?.low_stock_threshold ?? 0,
        warranty_years: product?.warranty_years ?? "",
        is_active: product?.is_active ?? true,
        search_keywords: product?.search_keywords ?? "",

        title: product?.translations.title ?? EMPTY_PAIR,
        short_description:
            product?.translations.short_description ?? EMPTY_PAIR,
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
                        section.placement.primary_category_id === null
                            ? ""
                            : String(section.placement.primary_category_id),
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
    const deciding =
        sections.find((section) => section.decides_family) ?? sections[0];
    const decidingKey =
        deciding === undefined ? "" : String(deciding.storefront.id);

    // Resolved OUTSIDE the memo: `t` is a fresh closure every render, so depending on it would
    // defeat the memo, while the resolved sentence is a stable string.
    const familyRuleNote = t(
        "products.family_rule_note",
        "القاعدة نفسها المطبَّقة في المتجر",
    );

    const shownFamily: FamilyExplanation = useMemo(() => {
        if (deciding === undefined) {
            return family;
        }
        const chosen =
            form.data.storefronts[decidingKey]?.primary_category_id ?? "";
        if (chosen === "") {
            return family;
        }
        const node = deciding.categories.find(
            (option) => option.value === chosen,
        );
        if (node === undefined || node.family === "") {
            return family;
        }

        return {
            family: node.family,
            node_id: Number(chosen),
            node_en: family.node_en,
            root_en: family.root_en,
            reason: `${node.family_reason} — ${familyRuleNote}`,
            saved_family: family.saved_family,
        };
    }, [deciding, decidingKey, family, familyRuleNote, form.data.storefronts]);

    /*
     * ── The SEO generator (item 8, 2026-09-18) ─────────────────────────────────────
     *
     * Reads what is ON SCREEN, never what is stored: the brand just picked, the title just
     * corrected, on a product that may not exist yet. The composition itself is in `lib/seo`, away
     * from React, so what it writes can be reasoned about without a form around it.
     *
     * The FAMILY it reports is `shownFamily` — the one the chosen primary category resolves to
     * right now, which is the same value the specification block above is drawn from. Using the
     * saved `family` here would describe the product as whatever it was before this edit.
     */
    const seoInput = (): SeoInput => {
        const brandId = String(form.data.brand_id ?? "");
        const primaryId =
            deciding === undefined
                ? ""
                : (form.data.storefronts[decidingKey]?.primary_category_id ??
                  "");

        const named = (list: string, id: unknown): NamePair | null => {
            const key = String(id ?? "");
            if (key === "" || key === "0") {
                return null;
            }

            return lookup_names[list]?.[key] ?? null;
        };

        /*
         * The materials are read from the BLOCK DEFINITION rather than from a list of spec keys
         * written out here. `config/catalog.php` is what decides that a watch has three material
         * fields and a bag has one, and a second copy of that decision in this file would be a
         * copy that goes stale the first time a family gains a field.
         *
         * The role is taken from the key's own prefix — `case_material_id` is the case's — which
         * is the same convention the config already follows. Anything else is the `main` material,
         * which is what a bag's single `material_id` is.
         */
        const materials: SeoAttribute[] = [];
        for (const field of blocks[shownFamily.family]?.fields ?? []) {
            if (field.lookup !== "materials") {
                continue;
            }

            const name = named("materials", form.data.specs[field.key]);
            if (name === null) {
                continue;
            }

            const role = field.key.startsWith("case_")
                ? "case"
                : field.key.startsWith("band_")
                  ? "band"
                  : field.key.startsWith("glass_")
                    ? "glass"
                    : "main";

            materials.push({ role, name });
        }

        const colors: SeoAttribute[] = [];
        for (const row of form.data.colors) {
            const name = named("colors", row.color_id);
            if (name !== null) {
                colors.push({ role: row.role, name });
            }
        }

        const genders: NamePair[] = [];
        for (const id of form.data.gender_ids) {
            const name = named("genders", id);
            if (name !== null) {
                genders.push(name);
            }
        }

        return {
            title: {
                ar: pair("title").ar ?? "",
                en: pair("title").en ?? "",
            },
            brand: brand_names[brandId] ?? { ar: "", en: "" },
            // The KEY, not a label: `lib/seo` owns the words for it, singular and plural, in both
            // languages, because a sentence needs the singular and a keyword needs the plural.
            familyKey: shownFamily.family,
            category: category_names[primaryId] ?? { ar: "", en: "" },
            // `sku` since the merge (item 4): one code column, and the SEO generator reads it.
            modelNumber: String(form.data.sku ?? ""),
            genders,
            materials,
            colors,
        };
    };

    /** Which of the three SEO fields already have something in them. */
    const seoFilled = () =>
        [
            pair("meta_title").ar,
            pair("meta_title").en,
            pair("meta_description").ar,
            pair("meta_description").en,
            String(form.data.search_keywords ?? ""),
        ].some((value) => value.trim() !== "");

    /*
     * ── Does what is in the boxes actually fit a search result? (item 5) ────────────────────
     *
     * The generator composes to fit, so its own output always does. These fields stay EDITABLE
     * afterwards, which is the point of them — and an operator who adds half a sentence has no way
     * to know they have pushed the title past what Google renders. The measure is the same one the
     * generator uses, so the screen and the generator can never disagree about what "too long"
     * means.
     *
     * Named, not counted: "43 / 60" invites the wrong question. The only question is whether the
     * customer sees the whole thing.
     */
    const seoOverLength = (): string[] => {
        const over: string[] = [];
        const check = (value: string, budget: number, label: string) => {
            if (
                value.trim() !== "" &&
                estimateEm(value.replace(/\s+/g, " ").trim()) > budget
            ) {
                over.push(label);
            }
        };

        check(
            pair("meta_title").ar ?? "",
            TITLE_EM,
            t("products.seo_fit_title_ar", "عنوان SEO بالعربية"),
        );
        check(
            pair("meta_title").en ?? "",
            TITLE_EM,
            t("products.seo_fit_title_en", "عنوان SEO بالإنجليزية"),
        );
        check(
            pair("meta_description").ar ?? "",
            DESCRIPTION_EM,
            t("products.seo_fit_desc_ar", "وصف SEO بالعربية"),
        );
        check(
            pair("meta_description").en ?? "",
            DESCRIPTION_EM,
            t("products.seo_fit_desc_en", "وصف SEO بالإنجليزية"),
        );

        return over;
    };

    const writeSeo = () => {
        const next = generateSeo(seoInput());
        setPair("meta_title", next.meta_title);
        setPair("meta_description", next.meta_description);
        form.setData("search_keywords", next.search_keywords);
    };

    /*
     * The sale-price rule, checked as it is typed (J-4).
     *
     * The same condition the DOMAIN applies — `0 < sale < selling`, the one the storefront and the
     * cart both re-check and the one that makes a checkout answer "Order total mismatch" when they
     * disagree. Stated here as a refusal rather than as prose, and returned as the field's own
     * error so it renders exactly where a server refusal would.
     *
     * A blank sale price is not a refusal: "no discount" is the ordinary state of 7,000 products.
     */
    const saleRefusal: string | null = (() => {
        const sale = Number(form.data.sale_price);
        const selling = Number(form.data.selling_price);

        if (String(form.data.sale_price ?? "").trim() === "") {
            return null;
        }
        if (
            !Number.isFinite(sale) ||
            !Number.isFinite(selling) ||
            selling <= 0
        ) {
            return null;
        }
        if (sale <= 0) {
            return t(
                "products.sale_price_not_positive",
                "سعر التخفيض لا بد أن يكون أكبر من صفر. اتركه فارغًا لو لا يوجد تخفيض.",
            );
        }
        if (sale >= selling) {
            return t(
                "products.sale_price_not_below",
                "سعر التخفيض (:sale) لا بد أن يكون أقل من سعر البيع (:selling). الأرجح أن الرقمين مقلوبان — وبهذه القيمة لن يُحفظ أي تخفيض ولن يرى العميل أي خصم.",
                {
                    sale: String(form.data.sale_price),
                    selling: String(form.data.selling_price),
                },
            );
        }

        return null;
    })();

    const pair = (name: TranslationKey): Pair => form.data[name] ?? EMPTY_PAIR;
    const setPair = (name: TranslationKey, value: Pair) =>
        form.setData(name, value);

    const submit = () => {
        /*
         * `form.post`/`form.put` rather than `router.*`: they carry the form's own typed data and
         * keep `processing`/`errors` wired to this form instead of the page.
         *
         * ── No `preserveScroll` (D-20, 2026-09-19) ──────────────────────────────────────────
         *
         * This form is 4,868 px tall against a 662 px viewport — 7.4 screens — with Save at the
         * bottom and the flash at the top. `preserveScroll: true` meant that pressing Save left
         * the viewport exactly where it was and NOTHING on screen changed: the success message
         * rendered six screens above and was only found by pressing Ctrl+Home. Measured; the
         * operator's honest reading is that the button does nothing.
         *
         * `preserveScroll` is the right default for a small form where the flash is already in
         * view. It is the wrong one here, and the height is the reason — so it goes, and a failed
         * save is handled separately by the error summary, which scrolls itself into view.
         */
        if (isNew) {
            form.post(`/manage/storefronts/${storefront.id}/products`);

            return;
        }
        form.put(`/manage/storefronts/${storefront.id}/products/${product.id}`);
    };

    /** Patch one storefront's section, leaving every other storefront alone. */
    const setSection = (key: string, patch: Partial<StorefrontSectionData>) => {
        const current = form.data.storefronts[key];
        if (current === undefined) {
            return;
        }
        form.setData("storefronts", {
            ...form.data.storefronts,
            [key]: { ...current, ...patch },
        });
    };

    const toggleCategory = (key: string, id: number, on: boolean) => {
        const current = form.data.storefronts[key];
        if (current === undefined) {
            return;
        }
        const next = on
            ? [...current.category_ids, id]
            : current.category_ids.filter((item) => item !== id);
        const patch: Partial<StorefrontSectionData> = { category_ids: next };
        // The primary must be one of the chosen categories, or the one-primary invariant has
        // nothing to hold — and the product would have no family on this storefront.
        if (!on && current.primary_category_id === String(id)) {
            patch.primary_category_id = next.length > 0 ? String(next[0]) : "";
        }
        if (on && current.primary_category_id === "") {
            patch.primary_category_id = String(id);
        }
        setSection(key, patch);
    };

    /*
     * ── What still keeps this product off the storefront ──────────────────────────────────────
     *
     * Computed from the LIVE form state rather than the server's list, so the panel answers as the
     * operator types: fill the Arabic short description and the line naming it disappears without a
     * round trip. The server decides the same thing again on save — this is the explanation, not
     * the rule ({@see PlacementWriter::missingForVisibility()}).
     *
     * The six requirements are the developer's (2026-09-18): both titles, both short descriptions,
     * both long descriptions, a gender and an image. They do not block the save — they decide
     * whether the product may be SEEN.
     */
    const arabic = t("common.arabic", "عربي");
    const english = t("common.english", "إنجليزي");
    const missingForVisibility: string[] = [
        ...(
            [
                ["title", t("products.field_title", "العنوان")],
                [
                    "short_description",
                    t("products.field_short_description", "وصف مختصر"),
                ],
                [
                    "long_description",
                    t("products.field_long_description", "الوصف الكامل"),
                ],
            ] as const
        ).flatMap(([field, label]) => {
            const value = pair(field);
            return [
                value.ar.trim() === "" ? `${label} — ${arabic}` : null,
                value.en.trim() === "" ? `${label} — ${english}` : null,
            ].filter((line): line is string => line !== null);
        }),
        form.data.gender_ids.length === 0
            ? t("products.field_gender", "الفئة (رجالي/حريمي)")
            : null,
        form.data.images.length === 0
            ? t("products.field_image", "صورة المنتج")
            : null,
    ].filter((line): line is string => line !== null);

    const canBeVisible = missingForVisibility.length === 0;
    const newProductTitle = t("products.new_title", "منتج جديد");

    return (
        <ManageLayout
            title={
                isNew
                    ? newProductTitle
                    : t("products.edit_title", "تعديل: :name", {
                          // The heading names the product in the reader's own language; the two
                          // title fields below are where BOTH are edited, so nothing is hidden.
                          name: titleOrCode(
                              pair("title"),
                              locale,
                              product.wa_code,
                          ),
                      })
            }
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                {
                    label: t("products.title", "المنتجات"),
                    href: `/manage/storefronts/${storefront.id}/products`,
                },
                {
                    label: isNew
                        ? newProductTitle
                        : titleOrCode(pair("title"), locale, product.wa_code),
                },
            ]}
        >
            {/* On a CREATE screen the block is the headline; on an EDIT screen it is a footnote,
                because editing is exactly what the team is meant to be doing before the switch. */}
            {isNew && pre_switch.blocked ? (
                <Alert
                    tone="error"
                    title={t(
                        "products.pre_switch_blocked_title",
                        "إضافة المنتجات موقوفة حاليًا",
                    )}
                >
                    {pre_switch.message}
                </Alert>
            ) : null}

            {errors.pre_switch ? (
                <Alert
                    tone="error"
                    title={t("products.create_failed", "تعذّر الإنشاء")}
                >
                    {errors.pre_switch}
                </Alert>
            ) : null}

            {/* ── The save bar is OUTSIDE the form, and that is deliberate (2026-10-05) ────

                A `position: sticky` box only sticks inside its own parent. The bar used to be the
                first child of `<form>`, so it stuck beautifully on seven tabs and vanished on the
                eighth — the variants panel is rendered after `</form>` (pressing Enter in a
                quantity box must not submit the PRODUCT), and once the page scrolled into it, the
                form's box had ended and the bar went with it.

                Out here its parent is the page, so it sticks across every tab. The button still
                submits the form through HTML's own `form="product-form"` association: no click
                handler, no change to validation, and Enter in a field still saves. */}
            <StickySaveBar>
                <FormActions
                    formId="product-form"
                    processing={form.processing}
                    // A create form whose server will refuse the POST must not offer a live save.
                    dirty={form.isDirty && !(isNew && pre_switch.blocked)}
                    submitLabel={
                        isNew
                            ? t("products.create_submit", "إنشاء المنتج")
                            : t("products.save_changes", "حفظ التغييرات")
                    }
                    onCancel={() =>
                        router.get(
                            `/manage/storefronts/${storefront.id}/products`,
                        )
                    }
                    extra={
                        <span className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                            {/* The error count travels with the button (§2.1). It is what tells
                            somebody standing on the Images tab that the Price tab is the
                            reason nothing saved. */}
                            <ErrorCount errors={errors} />
                            <SaveScopeNote />
                            {/* ── Archiving, from the form that created it (W-6) ─────────────

                            There was no DELETE route for a product at all: archiving existed
                            only as a bulk action on the LIST, so a junior who had just created
                            a duplicate had to leave the form, find the row again among 7,713,
                            tick it and use the bulk bar.

                            It keeps the same confirmation discipline as everything else that
                            removes something: the dialog names what archiving does and what it
                            does NOT do, because "delete" is the word people expect and this is
                            not that. */}
                            {product === null ? null : (
                                <>
                                    <ConfirmAction
                                        title={t(
                                            "products.archive_title",
                                            "أرشفة المنتج",
                                        )}
                                        confirmLabel={t(
                                            "products.archive_confirm",
                                            "أرشف المنتج",
                                        )}
                                        consequence={
                                            <div className="space-y-2">
                                                <p>
                                                    {t(
                                                        "products.archive_consequence",
                                                        "سيختفي المنتج من كل المتاجر ومن البحث فورًا، ويخرج من قوائم اللوحة إلا قائمة «المؤرشف».",
                                                    )}
                                                </p>
                                                <p>
                                                    {t(
                                                        "products.archive_keeps",
                                                        "لا يُحذف شيء: الطلبات القديمة وسجل المخزون تبقى كما هي، ويمكن إرجاع المنتج من قائمة «المؤرشف» في أي وقت.",
                                                    )}
                                                </p>
                                            </div>
                                        }
                                        onConfirm={() =>
                                            router.delete(
                                                `/manage/storefronts/${storefront.id}/products/${product.id}`,
                                            )
                                        }
                                        trigger={
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="gap-1.5 text-destructive"
                                            >
                                                <Trash2 className="h-3.5 w-3.5" />
                                                {t(
                                                    "products.archive_action",
                                                    "أرشفة",
                                                )}
                                            </Button>
                                        }
                                    />
                                    <Badge variant="neutral">
                                        #{product.id}
                                    </Badge>
                                    {/* Read-only on purpose: a quantity is a ledger event, so the
                                place to change it is the variants panel (or 4C's stock
                                screen for a product with no variants). */}
                                    <span dir="ltr">
                                        Express {product.stock_express} /
                                        Market {product.stock_market}
                                    </span>
                                    {product.in_stock ? (
                                        <Badge variant="success">
                                            {t(
                                                "products.in_stock",
                                                "متوفر",
                                            )}
                                        </Badge>
                                    ) : (
                                        <Badge variant="warning">
                                            {t("common.out_short", "نفد")}
                                        </Badge>
                                    )}
                                </>
                            )}
                        </span>
                    }
                />
            </StickySaveBar>
            <form
                id="product-form"
                className="space-y-6"
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
            >
                {/* Everything the server refused, in one place, with the page scrolled to it
                    (D-19). The messages still render beside their own fields — this is the map,
                    not a replacement for them. */}
                <ErrorSummary errors={errors} labels={fieldLabels} />

                {/* ── The save bar is at the TOP (item 3, second pass, 2026-09-19) ──────────

                    It was at the bottom — an improvement on the 4,868 px scroll it replaced, and
                    still the wrong end: the operator opens the form, reads the tabs, and the thing
                    they came to do is below the fold on first paint.

                    It carries the sentence a tabbed form owes its reader. A form split into tabs
                    invites the belief that each tab saves separately — that is the reasonable
                    reading of the shape, and nothing contradicted it. Somebody who believes it
                    fills one tab, saves, and leaves thinking the other six are still waiting. */}
                <FormTabs
                    tabs={TABS}
                    active={tab}
                    onChange={setTab}
                    errors={errors}
                />

                <TabPanel when="identity" active={tab}>
                    {/* ── identity ─────────────────────────────────────────────────────────── */}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t("products.identity", "التعريف")}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {/* ── TWO codes, not three (item 4, second browser pass, 2026-09-19) ────

                            `model_number` and `sku` held the same thing, so the form asked for it
                            twice and the team had no way to know which box to use. They are one
                            column now (`sku` survived, 6,799 values against 295), and each of the
                            two survivors carries a line saying whose code it is — which is the
                            actual question somebody stares at these boxes asking. */}
                            <TextField
                                label={t("products.wa_code", "الكود الداخلي")}
                                required
                                dir="ltr"
                                hint={t(
                                    "products.wa_code_hint",
                                    "كودنا نحن لهذا المنتج. نحن من يضعه، ولا يتكرر أبدًا بين منتجين.",
                                )}
                                error={errors.wa_code ?? null}
                                value={String(form.data.wa_code ?? "")}
                                onChange={(value) =>
                                    form.setData("wa_code", value)
                                }
                            />
                            <TextField
                                label={t("products.sku", "رقم الموديل (SKU)")}
                                dir="ltr"
                                hint={t(
                                    "products.sku_hint",
                                    "كود المصنّع أو المورّد. يأتي منهم، ويمكن أن يكون فارغًا، ويمكن أن يشترك فيه منتجان.",
                                )}
                                error={errors.sku ?? null}
                                value={String(form.data.sku ?? "")}
                                onChange={(value) => form.setData("sku", value)}
                            />
                            <SelectField
                                label={t("products.brand", "الماركة")}
                                required
                                // An explicit empty option (J-1). Without it the select shows the
                                // first BRAND as though somebody had chosen it.
                                placeholder={t(
                                    "products.brand_choose",
                                    "— اختر ماركة —",
                                )}
                                error={errors.brand_id ?? null}
                                value={String(form.data.brand_id ?? "")}
                                options={brands}
                                onChange={(value) =>
                                    form.setData("brand_id", value)
                                }
                            />
                            <SelectField
                                label={t("products.grade", "الدرجة")}
                                placeholder="—"
                                error={errors.grade_id ?? null}
                                value={String(form.data.grade_id ?? "")}
                                options={lookups.grades ?? []}
                                onChange={(value) =>
                                    form.setData("grade_id", value)
                                }
                            />
                            <SwitchField
                                label={t(
                                    "products.is_active",
                                    "مفعّل في الكتالوج",
                                )}
                                checked={form.data.is_active === true}
                                onChange={(checked) =>
                                    form.setData("is_active", checked)
                                }
                            />
                        </CardContent>
                    </Card>
                </TabPanel>

                <TabPanel when="content" active={tab}>
                    {/* ── names and copy, both locales at once ─────────────────────────────── */}
                    <Card>
                        <CardHeader className="gap-1">
                            <CardTitle>
                                {t("products.names", "الاسم والوصف")}
                            </CardTitle>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    "products.names_hint",
                                    "الترجمة الاحتياطية مُعطّلة: ما يغيب بالعربية يظهر ناقصًا على المتجر، ولا يمكن إظهار منتج بلا عنوان عربي.",
                                )}
                            </p>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <TranslatedField
                                label={t("products.field_title", "العنوان")}
                                name="title"
                                required
                                value={pair("title")}
                                onChange={(value) => setPair("title", value)}
                                errors={errors}
                            />
                            <TranslatedField
                                label={t(
                                    "products.field_short_description",
                                    "وصف مختصر",
                                )}
                                name="short_description"
                                multiline
                                value={pair("short_description")}
                                onChange={(value) =>
                                    setPair("short_description", value)
                                }
                                errors={errors}
                            />
                            <TranslatedField
                                label={t(
                                    "products.field_long_description",
                                    "الوصف الكامل",
                                )}
                                name="long_description"
                                multiline
                                value={pair("long_description")}
                                onChange={(value) =>
                                    setPair("long_description", value)
                                }
                                errors={errors}
                            />
                            <div className="grid gap-5 lg:grid-cols-3">
                                <TranslatedField
                                    label={t(
                                        "products.field_model_name",
                                        "اسم الموديل",
                                    )}
                                    name="model_name"
                                    value={pair("model_name")}
                                    onChange={(value) =>
                                        setPair("model_name", value)
                                    }
                                    errors={errors}
                                />
                                <TranslatedField
                                    label={t(
                                        "products.field_country",
                                        "بلد الصنع",
                                    )}
                                    name="country"
                                    value={pair("country")}
                                    onChange={(value) =>
                                        setPair("country", value)
                                    }
                                    errors={errors}
                                />
                                <TranslatedField
                                    label={t("products.field_stone", "الحجر")}
                                    name="stone"
                                    value={pair("stone")}
                                    onChange={(value) =>
                                        setPair("stone", value)
                                    }
                                    errors={errors}
                                />
                            </div>
                        </CardContent>
                    </Card>
                </TabPanel>

                <TabPanel when="price" active={tab}>
                    {/* ── price ────────────────────────────────────────────────────────────── */}
                    <Card>
                        <CardHeader className="gap-1">
                            <CardTitle>{t("common.price", "السعر")}</CardTitle>
                            {/* The rule used to be narrated here, in prose, above the fields it was
                            about — which is the exact shape J-4 was reported for. It is now
                            enforced ON the sale-price field, live, and says what is wrong with the
                            number in front of it. A paragraph that repeats a guard is a paragraph
                            the reader learns to skip (item 8, 2026-09-19). */}
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <TextField
                                label={t("products.selling_price", "سعر البيع")}
                                required
                                dir="ltr"
                                type="number"
                                error={errors.selling_price ?? null}
                                value={String(form.data.selling_price ?? "")}
                                onChange={(value) =>
                                    form.setData("selling_price", value)
                                }
                            />
                            {/* ── The rule is ON the field now (J-4, 2026-09-19) ───────────────

                            Entering selling 1500 / sale 2000 produced no warning, no refusal and
                            no highlight. The form saved cleanly and the sale price was stored
                            EMPTY — so somebody who transposed the two fields watched a successful
                            save and the discount they had promised a customer simply did not
                            exist.

                            The rule was narrated in prose above the fields instead. AGENTS §2.27
                            says a rule in the domain is enforced IN the form; this one was
                            explained next to it, which is the failure mode the rule names. */}
                            <TextField
                                label={t("products.sale_price", "سعر التخفيض")}
                                dir="ltr"
                                type="number"
                                min={0}
                                error={errors.sale_price ?? saleRefusal}
                                value={String(form.data.sale_price ?? "")}
                                onChange={(value) =>
                                    form.setData("sale_price", value)
                                }
                            />
                            <TextField
                                label={t(
                                    "products.purchase_price",
                                    "سعر الشراء",
                                )}
                                dir="ltr"
                                type="number"
                                hint={t(
                                    "products.purchase_price_hint",
                                    "داخلي — لا يظهر على المتجر.",
                                )}
                                error={errors.purchase_price ?? null}
                                value={String(form.data.purchase_price ?? "")}
                                onChange={(value) =>
                                    form.setData("purchase_price", value)
                                }
                            />
                            <TextField
                                label={t("common.currency", "العملة")}
                                dir="ltr"
                                error={errors.currency ?? null}
                                value={String(form.data.currency ?? "")}
                                onChange={(value) =>
                                    form.setData("currency", value)
                                }
                            />
                            <TextField
                                label={t(
                                    "products.low_stock_threshold",
                                    "حد التنبيه للمخزون",
                                )}
                                hint={t(
                                    "products.low_stock_threshold_hint",
                                    "الكمية التي يبدأ عندها التنبيه. اتركه صفرًا لو لا تريد تنبيهًا لهذا المنتج.",
                                )}
                                dir="ltr"
                                type="number"
                                min={0}
                                error={errors.low_stock_threshold ?? null}
                                value={String(
                                    form.data.low_stock_threshold ?? "",
                                )}
                                onChange={(value) =>
                                    form.setData("low_stock_threshold", value)
                                }
                            />
                        </CardContent>
                    </Card>
                </TabPanel>

                <TabPanel when="visibility" active={tab}>
                    {/* ── one section per storefront: its categories, its single primary category,
                    its visibility, order and slug. The product's CONTENT above is shared; these
                    are the only columns `storefront_product` keeps per storefront (AGENTS §2.4),
                    and the form now shows all of them for every storefront at once instead of
                    making the team visit one URL per site. ────────────────────────────────── */}
                    {/* ── Only the shop being edited is EXPANDED (§2.1) ─────────────────────────

                    Both storefronts' complete trees used to render here at once — about 98
                    checkboxes, 37 nodes for Watchizer and 61 for Brand Fashion — on a form that
                    was already seven screens long. Two trees side by side is also the shape that
                    invites a mis-tick: they look identical and both open with the same section
                    names.

                    The others collapse to one line each that STATES what is true there, with a
                    link to edit inside that shop. Every field for every shop still SUBMITS — the
                    payload is unchanged. This is about what is on screen, not about what is
                    saved. ────────────────────────────────────────────────────────────────── */}
                    {/* ── An explicit shop switch (item 1a, second browser pass, 2026-09-19) ────────

                    The tab showed ONE tree with a shop name above it, and read as though the
                    product had one taxonomy. Nothing said a second shop existed, and nothing
                    offered a way to it — the only route was the "edit inside that shop" link,
                    which NAVIGATES, and navigating away from a half-filled form loses the rest of
                    it.

                    A segmented control instead. Both shops are on screen at once, each carrying
                    its own current state, so "there are two shops" and "this is the one I am
                    editing" are the same glance. Switching is local: `form.data.storefronts` has
                    always carried every shop's placement and Save has always submitted all of
                    them, so this changes what is SHOWN and nothing about what is saved.

                    "Impossible to edit the wrong shop without noticing" is why the selection is
                    repeated three times and never subtly: the pressed segment, the brand-coloured
                    rail down the expanded card, and the shop's own name as the card's title. */}
                    {sections.length > 1 ? (
                        <div
                            role="group"
                            aria-label={t(
                                "products.which_storefront",
                                "أي متجر تعدّل الآن؟",
                            )}
                            className="flex flex-wrap items-center gap-2"
                        >
                            <span className="text-sm text-muted-foreground">
                                {t(
                                    "products.which_storefront",
                                    "أي متجر تعدّل الآن؟",
                                )}
                            </span>
                            {sections.map((section) => {
                                const id = section.storefront.id;
                                const data = form.data.storefronts[String(id)];
                                const selected = id === placementShop;

                                return (
                                    <button
                                        key={id}
                                        type="button"
                                        aria-pressed={selected}
                                        onClick={() => setPlacementShop(id)}
                                        className={cn(
                                            "flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm transition-colors",
                                            "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring",
                                            selected
                                                ? "border-brand bg-brand-muted font-medium text-brand-strong"
                                                : "text-muted-foreground hover:text-foreground",
                                        )}
                                    >
                                        {section.storefront.name}
                                        {/* Each shop's own state, on its own button — so the choice
                                        is made with the answer already visible. */}
                                        <Badge
                                            variant={
                                                data === undefined
                                                    ? "outline"
                                                    : data.is_visible
                                                      ? "success"
                                                      : "neutral"
                                            }
                                        >
                                            {data === undefined
                                                ? t(
                                                      "products.absent",
                                                      "غير مضاف لهذا المتجر",
                                                  )
                                                : data.is_visible
                                                  ? t("common.visible", "ظاهر")
                                                  : t("common.hidden", "مخفي")}
                                        </Badge>
                                    </button>
                                );
                            })}
                        </div>
                    ) : null}

                    {sections
                        .filter(
                            (section) =>
                                section.storefront.id === placementShop,
                        )
                        .map((section) => (
                            <div
                                key={section.storefront.id}
                                className="border-s-4 border-brand ps-3"
                            >
                                <StorefrontFields
                                    section={section}
                                    data={
                                        form.data.storefronts[
                                            String(section.storefront.id)
                                        ]
                                    }
                                    errors={errors}
                                    canBeVisible={canBeVisible}
                                    slugLock={slug_lock}
                                    slugRole={slug_role}
                                    missingArabic={missingForVisibility}
                                    onChange={(patch) =>
                                        setSection(
                                            String(section.storefront.id),
                                            patch,
                                        )
                                    }
                                    onToggleCategory={(id, on) =>
                                        toggleCategory(
                                            String(section.storefront.id),
                                            id,
                                            on,
                                        )
                                    }
                                />
                            </div>
                        ))}

                    {sections.length > 1 ? (
                        <p className="text-xs text-muted-foreground">
                            {t(
                                "products.also_on_hint",
                                "اسم المنتج ووصفه وصوره مشتركة بين كل المتاجر. الظهور والتصنيفات والترتيب والرابط تخص كل متجر على حدة، وتُعدَّل من هنا بالتبديل بين المتجرين — والحفظ يحفظ الاثنين معًا.",
                            )}
                        </p>
                    ) : null}
                </TabPanel>

                <TabPanel when="specs" active={tab}>
                    {/* ── the family-aware block ───────────────────────────────────────────── */}
                    {/* ── the warranty is asked HERE now (2026-09-19) ───────────────────────

                    `warranty_years` is a column of `catalog_products` and it stays there: this is
                    a question of WHERE IT IS ASKED, not of where it is stored. It used to sit on
                    the price tab among the four money fields, which is where it landed rather than
                    where it belongs — a buyer asks about the guarantee in the same breath as the
                    water resistance, and whoever is filling in a watch's specifications had to
                    leave the tab to answer it.

                    It goes through `extra` rather than into `config/catalog.php`, because that
                    config is what the SERVER validates and writes into
                    `catalog_product_watch_specs`. Declaring the warranty there would make the
                    writer look for a column of that table that does not exist. */}
                    <SpecBlock
                        explanation={shownFamily}
                        blocks={blocks}
                        lookups={lookups}
                        values={form.data.specs}
                        onChange={(values) => form.setData("specs", values)}
                        errors={errors}
                        extra={
                            <TextField
                                label={t(
                                    "products.warranty_years",
                                    "سنوات الضمان",
                                )}
                                dir="ltr"
                                type="number"
                                error={errors.warranty_years ?? null}
                                value={String(form.data.warranty_years ?? "")}
                                onChange={(value) =>
                                    form.setData("warranty_years", value)
                                }
                            />
                        }
                    />

                    {/* ── attributes ──────────────────────────────────────────────────────── */}
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                {t(
                                    "products.attributes",
                                    "الخصائص والفئات والألوان",
                                )}
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-5 lg:grid-cols-3">
                            <CheckList
                                label={t("products.features", "الخصائص")}
                                options={lookups.features ?? []}
                                selected={form.data.feature_ids}
                                onChange={(ids) =>
                                    form.setData("feature_ids", ids)
                                }
                            />
                            <CheckList
                                // `حرمي` was a misspelling of `حريمي`, and the correct spelling was a
                                // few hundred pixels above it at `products.field_gender` — so one
                                // product form showed the word both ways (D-14).
                                label={t(
                                    "products.genders",
                                    "الفئة (رجالي/حريمي…)",
                                )}
                                options={lookups.genders ?? []}
                                selected={form.data.gender_ids}
                                onChange={(ids) =>
                                    form.setData("gender_ids", ids)
                                }
                            />
                            {/* Family-scoped since 2026-09-19 (J-6): a watch is asked for its dial and
                            band, everything else for its primary colour, and a family with no
                            colour question renders nothing at all. */}
                            <ColorRoles
                                options={lookups.colors ?? []}
                                roles={
                                    color_roles[shownFamily.family] ??
                                    color_roles.default ??
                                    []
                                }
                                value={form.data.colors}
                                onChange={(rows) =>
                                    form.setData("colors", rows)
                                }
                            />
                        </CardContent>
                    </Card>
                </TabPanel>

                <TabPanel when="images" active={tab}>
                    {/* ── images ──────────────────────────────────────────────────────────── */}
                    <ImageGallery
                        images={form.data.images}
                        onChange={(images) => form.setData("images", images)}
                    />
                </TabPanel>

                <TabPanel when="seo" active={tab}>
                    {/* ── SEO and search keywords: SHARED, like the rest of the product's content.
                    Per-storefront visibility, order, featured and slug live in each storefront's
                    own section above (AGENTS §2.4 — there are no per-storefront overrides of
                    title, description or media, on purpose). ──────────────────────── */}
                    <Card>
                        <CardHeader className="gap-1">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <CardTitle>
                                    {t(
                                        "products.seo",
                                        "بيانات SEO وكلمات البحث",
                                    )}
                                </CardTitle>
                                {/*
                                 * The generator (item 8). Two shapes for one button, and the difference
                                 * is whether anything would be overwritten:
                                 *
                                 *   • nothing written yet → fills, immediately. Nothing is at risk.
                                 *   • something written → asks first, because somebody may have written
                                 *     those two sentences by hand and a click that silently replaces
                                 *     them is the kind of help nobody asks for twice.
                                 *
                                 * Neither shape SAVES: the fields become dirty and the operator reads
                                 * them, edits them and presses Save like any other change. That is what
                                 * "editable afterwards" has to mean to be worth anything.
                                 */}
                                {seoFilled() ? (
                                    <ConfirmAction
                                        title={t(
                                            "products.seo_regenerate_title",
                                            "إعادة كتابة حقول SEO",
                                        )}
                                        consequence={
                                            <p>
                                                {t(
                                                    "products.seo_regenerate_body",
                                                    "سيُستبدل عنوان SEO ووصفه وكلمات البحث باللغتين بما يُشتق من بيانات المنتج المعروضة الآن. لن يُحفظ شيء إلا بعد ضغط زر الحفظ.",
                                                )}
                                            </p>
                                        }
                                        confirmLabel={t(
                                            "products.seo_regenerate_confirm",
                                            "أعد الكتابة",
                                        )}
                                        onConfirm={writeSeo}
                                        trigger={
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                            >
                                                {t(
                                                    "products.seo_generate",
                                                    "اكتب حقول SEO تلقائيًا",
                                                )}
                                            </Button>
                                        }
                                    />
                                ) : (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={writeSeo}
                                    >
                                        {t(
                                            "products.seo_generate",
                                            "اكتب حقول SEO تلقائيًا",
                                        )}
                                    </Button>
                                )}
                            </div>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    "products.seo_hint",
                                    "مشتركة بين كل المتاجر. الظهور والترتيب والتمييز والرابط والتصنيفات تخص كل متجر على حدة.",
                                )}{" "}
                                {t(
                                    "products.seo_generate_hint",
                                    "زر «اكتب حقول SEO تلقائيًا» يكتب جملة بالعربية وأخرى بالإنجليزية من بيانات المنتج نفسه: الماركة والفئة والتصنيف والخامة واللون والموديل. يبقى كل حقل قابلاً للتعديل بعدها. لا يُكتب السعر ولا التوفر: وصف SEO يُقدّم لشهور والسعر يتغير.",
                                )}
                            </p>
                            {/* ── Does it fit a search result? (item 5) ────────────────────────
                            The generator's own output always does; what somebody types afterwards
                            may not, and nothing on the screen used to say so. */}
                            {seoOverLength().length > 0 ? (
                                <p className="text-xs font-medium text-amber-700 dark:text-amber-500">
                                    {t(
                                        "products.seo_too_long",
                                        "أطول مما يعرضه محرك البحث، وسيُقصّ عند النتيجة: :fields",
                                        { fields: seoOverLength().join("، ") }, // i18n-exempt: the Arabic comma joining the field names
                                    )}
                                </p>
                            ) : null}
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <div className="grid gap-5 lg:grid-cols-2">
                                <TranslatedField
                                    label={t(
                                        "products.field_meta_title",
                                        "عنوان SEO",
                                    )}
                                    name="meta_title"
                                    value={pair("meta_title")}
                                    onChange={(value) =>
                                        setPair("meta_title", value)
                                    }
                                    errors={errors}
                                />
                                <TranslatedField
                                    label={t(
                                        "products.field_meta_description",
                                        "وصف SEO",
                                    )}
                                    name="meta_description"
                                    multiline
                                    value={pair("meta_description")}
                                    onChange={(value) =>
                                        setPair("meta_description", value)
                                    }
                                    errors={errors}
                                />
                            </div>

                            <TextareaField
                                label={t(
                                    "products.search_keywords",
                                    "كلمات البحث",
                                )}
                                hint={t(
                                    "products.search_keywords_hint",
                                    "تُضاف إلى فهرس البحث مع الاسم والماركة والتصنيف.",
                                )}
                                rows={2}
                                error={errors.search_keywords ?? null}
                                value={String(form.data.search_keywords ?? "")}
                                onChange={(value) =>
                                    form.setData("search_keywords", value)
                                }
                            />
                        </CardContent>
                    </Card>
                </TabPanel>
            </form>

            {/* ── Its own TAB, rendered outside the <form> (item 2, second pass, 2026-09-19) ────

                It used to hang below everything, outside the tab structure — an orphan at the foot
                of the page that read as an afterthought, which is exactly how it was reported.

                It is switched by the same `tab` state as every other panel, so to the operator it
                is simply the eighth tab. It is rendered AFTER `</form>` rather than inside a
                `TabPanel`, and that is not tidiness: the panel posts each row on its own, because
                a quantity is a ledger event and must never ride on a title validation. Inside the
                product form, pressing Enter in a quantity box would submit the PRODUCT — a
                surprise that writes. */}
            {tab === "variants" ? (
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
            ) : null}

            {storefronts.length > 1 ? (
                <p className="mt-4 text-xs text-muted-foreground">
                    {t(
                        "products.shared_across_storefronts",
                        "بيانات المنتج مشتركة بين المتاجر؛ ما يخص هذا المتجر فقط هو الظهور والترتيب والتمييز والرابط والتصنيفات.",
                    )}
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
    slugRole,
    missingArabic,
    onChange,
    onToggleCategory,
}: {
    section: StorefrontSection;
    data: StorefrontSectionData | undefined;
    errors: Record<string, string>;
    canBeVisible: boolean;
    slugLock: PreSwitchState;
    slugRole: { allowed: boolean; message: string | null };
    missingArabic: string[];
    onChange: (patch: Partial<StorefrontSectionData>) => void;
    onToggleCategory: (id: number, on: boolean) => void;
}) {
    // Before the early return below: a hook may not sit behind a condition.
    const t = useT();

    if (data === undefined) {
        return null;
    }

    const key = String(section.storefront.id);
    const error = (field: string): string | null =>
        errors[`storefronts.${key}.${field}`] ?? null;
    const chosen = section.categories.filter((option) =>
        data.category_ids.includes(Number(option.value)),
    );
    const hasCategory = data.category_ids.length > 0;
    // Every chosen node is a TOP-LEVEL section: the product would be published with no sub-category
    // at all. Derived from the options' own depth, so it follows the tree rather than a name.
    const rootOnlyChoice =
        hasCategory && chosen.every((option) => option.depth <= 1);
    /*
     * The sentence names the fields rather than the rule, because the operator's next action is to
     * go and fill them. It says the consequence first — the product will not appear — so somebody
     * scanning the form knows why the visibility switch is off before reading the list.
     */
    const blockedReason = !canBeVisible
        ? t(
              "products.visibility_incomplete",
              "لن يظهر المنتج على المتجر حتى تُستكمل هذه الحقول: :fields.",
              {
                  fields: missingArabic.join(t("common.list_separator", "، ")),
              },
          )
        : !hasCategory
          ? t(
                "products.visibility_needs_category",
                "اختر تصنيفًا واحدًا على الأقل في هذا المتجر، وإلا لن يصل إليه أحد من أي صفحة.",
            )
          : null;

    return (
        <Card>
            <CardHeader className="gap-1">
                <div className="flex flex-wrap items-center gap-2">
                    <CardTitle>{section.storefront.name}</CardTitle>
                    <Badge variant="neutral">{section.storefront.code}</Badge>
                    {data.is_visible ? (
                        <Badge variant="success">
                            {t("common.visible", "ظاهر")}
                        </Badge>
                    ) : (
                        <Badge variant="neutral">
                            {t("common.hidden", "مخفي")}
                        </Badge>
                    )}
                    {section.decides_family ? (
                        <Badge variant="outline">
                            {t("products.decides_specs", "منه تُشتق المواصفات")}
                        </Badge>
                    ) : null}
                </div>
                {/*
                 * The whole rule, in one place (item 4, 2026-09-18).
                 *
                 * This paragraph already said the second half — one primary per storefront, and
                 * that it decides the family. The developer still had to ask, and re-reading it
                 * shows why: it never said a product may be in SEVERAL categories, which was the
                 * first half of the question, and it never said what the primary is FOR from the
                 * customer's side or what happens without one.
                 *
                 * Every clause below was verified before it was written on a screen:
                 *
                 *   • several categories, one primary — `PlacementWriter` clears any previous
                 *     primary before setting a new one, and across 8,211 placements on both
                 *     storefronts not one product has more than one;
                 *   • the primary decides the FAMILY — `FamilyForCategory`, the same resolver the
                 *     transform uses;
                 *   • the family decides which SPECIFICATION fields exist — `SpecBlocks::for()`;
                 *   • the primary IS the breadcrumb — `Storefront\ProductDetail` builds it from the
                 *     primary node and nothing else, so a product without one has none at all.
                 *     37 products per storefront are in that state today.
                 */}
                <p className="text-xs text-muted-foreground">
                    {t(
                        "products.primary_category_rule",
                        "يمكن أن يوجد المنتج في أكثر من تصنيف في هذا المتجر — علّم على كل ما ينطبق عليه — لكن واحدًا فقط منها يكون التصنيف الأساسي، وهو المسار الذي يراه العميل فوق صفحة المنتج. بلا تصنيف أساسي لا يكون للمنتج مسار تصفّح أصلاً.",
                    )}{" "}
                    {section.decides_family
                        ? t(
                              "products.primary_category_decides_family",
                              "ومنه تُشتق عائلة المنتج وقائمة مواصفاته. لو لم تختر واحدًا تُشتق العائلة من أول تصنيف مختار.",
                          )
                        : t(
                              "products.categories_independent",
                              "تصنيفات هذا المتجر مستقلة تمامًا عن المتاجر الأخرى، لكن عائلة المنتج ومواصفاته مشتركة، ويحدّدها التصنيف الأساسي في المتجر المُعلَّم أعلاه.",
                          )}
                </p>
            </CardHeader>
            <CardContent className="space-y-4">
                <fieldset className="space-y-2">
                    <legend className="text-sm font-medium">
                        {t(
                            "products.categories_on",
                            "التصنيفات على :storefront",
                            { storefront: section.storefront.name },
                        )}
                    </legend>
                    {/* One searchable, properly indented column (§2.1). The two-column grid this
                        replaces destroyed the indentation outright: a parent landed in one column
                        with an unrelated child beside it in the other. */}
                    <CategoryPicker
                        options={section.categories}
                        selected={data.category_ids}
                        onToggle={onToggleCategory}
                    />
                    {error("category_ids") ? (
                        <p
                            role="alert"
                            className="text-xs font-medium text-destructive"
                        >
                            {error("category_ids")}
                        </p>
                    ) : null}
                </fieldset>

                {/* What happens if this product is saved with only a top-level section chosen —
                    and what is already true of a product the transform left at the root. Both are
                    legitimate, served states; neither is obvious from the fields (rehearsal #3). */}
                {section.placement_state === "root_only" ? (
                    <Alert
                        tone="warning"
                        title={t(
                            "products.root_only_title",
                            "هذا المنتج بدون تصنيف فرعي حاليًا",
                        )}
                    >
                        {t(
                            "products.root_only_body",
                            "هو موضوع في القسم الرئيسي فقط، وبلا تصنيف أساسي: يظهر في صفحة القسم وفي البحث، ولا يظهر في أي قائمة تصنيف فرعي، ومسار التصفّح له خطوة واحدة. العائلة مشتقة من اسم القسم الرئيسي. إن حفظت من هذه الشاشة الآن، سيصبح القسم الرئيسي هو تصنيفه الأساسي — اختر تصنيفًا فرعيًا إن أردت أن يظهر داخله.",
                        )}
                    </Alert>
                ) : null}
                {section.placement_state !== "root_only" && rootOnlyChoice ? (
                    <Alert
                        tone="warning"
                        title={t(
                            "products.root_choice_title",
                            "لم تختر تصنيفًا فرعيًا",
                        )}
                    >
                        {t(
                            "products.root_choice_body",
                            "اخترت القسم الرئيسي فقط. سيُنشر المنتج في صفحة القسم وفي البحث، ولن يظهر في أي قائمة تصنيف فرعي، وسيكون مسار التصفّح خطوة واحدة. اختر تصنيفًا فرعيًا إن أردت أن يظهر داخله.",
                        )}
                    </Alert>
                ) : null}

                <SelectField
                    label={t("products.primary_category", "التصنيف الأساسي")}
                    hint={
                        section.decides_family
                            ? t(
                                  "products.primary_category_hint_family",
                                  "يحدد عائلة المنتج ومواصفاته، ويحدد التصنيف الذي يمثل المنتج في مسارات هذا المتجر.",
                              )
                            : t(
                                  "products.primary_category_hint",
                                  "التصنيف الذي يمثل المنتج في مسارات هذا المتجر.",
                              )
                    }
                    placeholder="—"
                    error={error("primary_category_id")}
                    value={data.primary_category_id}
                    // Only the CHOSEN categories can be primary: the invariant is about a row that
                    // exists, so offering an unchosen node would offer an error.
                    options={chosen.map((option) => ({
                        value: option.value,
                        label: option.label.replace(/^(— )+/, ""),
                    }))}
                    onChange={(value) =>
                        onChange({ primary_category_id: value })
                    }
                />

                <div className="grid gap-4 sm:grid-cols-2">
                    <SwitchField
                        label={t(
                            "products.visible_on",
                            "ظاهر على :storefront",
                            { storefront: section.storefront.name },
                        )}
                        hint={blockedReason ?? undefined}
                        error={error("is_visible")}
                        checked={data.is_visible && blockedReason === null}
                        disabled={blockedReason !== null}
                        onChange={(checkedState) =>
                            onChange({ is_visible: checkedState })
                        }
                    />
                    <SwitchField
                        label={t("products.featured", "مميّز")}
                        hint={t(
                            "products.featured_hint",
                            "يظهر في الأقسام المميزة على واجهة هذا المتجر.",
                        )}
                        checked={data.is_featured}
                        onChange={(checkedState) =>
                            onChange({ is_featured: checkedState })
                        }
                    />
                    <TextField
                        label={t("common.sort", "الترتيب")}
                        dir="ltr"
                        type="number"
                        min={0}
                        hint={t(
                            "products.sort_hint",
                            "الأقل يظهر أولًا داخل التصنيف.",
                        )}
                        error={error("sort_order")}
                        value={String(data.sort_order)}
                        onChange={(value) => onChange({ sort_order: value })}
                    />
                    <TextField
                        label={t("common.slug", "الرابط (slug)")}
                        dir="ltr"
                        // Locked until the write-switch, with the reason ON the field: the 301 a
                        // change would promise is deleted by the next rebuild together with the
                        // slug itself, so the field refuses instead of warning (review 🟠-3).
                        // Two rules; the role one is named first because it is the one that does
                        // not expire on switch night (item 6).
                        disabled={slugLock.blocked || !slugRole.allowed}
                        hint={
                            !slugRole.allowed
                                ? (slugRole.message ?? undefined)
                                : slugLock.blocked
                                  ? (slugLock.message ??
                                    slugLock.caveat ??
                                    undefined)
                                  : t(
                                        "products.slug_hint",
                                        "يُولّد من العنوان الإنجليزي إن تُرك فارغًا.",
                                    )
                        }
                        error={error("slug")}
                        value={data.slug}
                        onChange={(value) => onChange({ slug: value })}
                    />
                </div>

                {!slugLock.blocked &&
                data.slug.trim() !== "" &&
                data.slug !== section.placement.slug ? (
                    <Alert
                        tone="warning"
                        title={t(
                            "products.slug_change_title",
                            "ستُغيّر رابط المنتج على هذا المتجر",
                        )}
                    >
                        {t("products.slug_change_current", "الرابط الحالي")}{" "}
                        <code dir="ltr">/product/{section.placement.slug}</code>{" "}
                        {t("products.slug_change_becomes", "وسيصبح")}{" "}
                        <code dir="ltr">/product/{data.slug.trim()}</code>.{" "}
                        {t(
                            "products.slug_change_note",
                            "الرابط القديم لن يتوقف: يُنشأ تحويل 301 تلقائيًا، لكن الروابط المنشورة والمشاركة وإعلانات جوجل ستمر عبر التحويل وتفقد قليلًا من ترتيبها. لا تغيّره بلا سبب.",
                        )}
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
    const t = useT();

    return (
        <fieldset className="space-y-2">
            <legend className="text-sm font-medium">{label}</legend>
            <div className="grid max-h-56 gap-1.5 overflow-y-auto rounded-lg border p-3">
                {options.length === 0 ? (
                    <p className="text-xs text-muted-foreground">
                        {t("products.no_options", "لا توجد عناصر.")}
                    </p>
                ) : null}
                {options.map((option) => {
                    const id = Number(option.value);

                    return (
                        <label
                            key={option.value}
                            className="flex items-center gap-2 text-sm"
                        >
                            <Checkbox
                                checked={selected.includes(id)}
                                onCheckedChange={(checked) =>
                                    onChange(
                                        checked === true
                                            ? [...selected, id]
                                            : selected.filter(
                                                  (item) => item !== id,
                                              ),
                                    )
                                }
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
    roles,
    value,
    onChange,
}: {
    options: Option[];
    /** The colour questions THIS family is asked, from `config/catalog.php` (J-6). */
    roles: Array<{ key: string; label: string }>;
    value: Array<{ color_id: number; role: string }>;
    onChange: (rows: Array<{ color_id: number; role: string }>) => void;
}) {
    const t = useT();

    /*
     * The roles come from the SERVER, per family (J-6). They used to be this fixed array, so a hat
     * and a handbag were asked for their dial and strap colours — and whoever answered wrote into
     * the column the storefront renders as a watch band, with the form looking entirely correct
     * while it happened.
     *
     * `roles` is already narrowed to the family on screen by the caller, which re-derives it from
     * the primary category without a round trip (task 4.1).
     */
    const set = (role: string, colorId: string) => {
        const without = value.filter((row) => row.role !== role);
        onChange(
            colorId === ""
                ? without
                : [...without, { color_id: Number(colorId), role }],
        );
    };

    if (roles.length === 0) {
        return null;
    }

    return (
        <div className="space-y-3">
            <Label>{t("products.colors", "الألوان")}</Label>
            {roles.map((role) => {
                const current = value.find((row) => row.role === role.key);

                return (
                    <SelectField
                        key={role.key}
                        label={role.label}
                        placeholder="—"
                        value={
                            current === undefined
                                ? ""
                                : String(current.color_id)
                        }
                        options={options}
                        onChange={(colorId) => set(role.key, colorId)}
                    />
                );
            })}
        </div>
    );
}
