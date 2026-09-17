import { Link, router } from "@inertiajs/react";
import { Lock, Pencil, Plus } from "lucide-react";

import { DataTable, type Column } from "@/components/table/DataTable";
import ManageLayout from "@/layouts/ManageLayout";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Select } from "@/components/ui/input";
import type { PreSwitchState, TablePayload } from "@/types";
import { Ltr, Num } from "@/components/ui/bidi";
import { useT } from "@/lib/i18n";

interface ProductRow {
    id: number;
    wa_code: string;
    sku: string | null;
    title: { ar: string; en: string };
    family: string;
    brand: { ar: string; en: string };
    selling_price: string;
    sale_price: string | null;
    currency: string;
    stock_express: number;
    stock_market: number;
    in_stock: boolean;
    is_active: boolean;
    archived: boolean;
    variants: number;
    is_visible: boolean | null;
    is_featured: boolean | null;
    slug: string | null;
    /** The four at-a-glance states (task 4.3). */
    has_arabic: boolean;
    has_image: boolean;
    /** Wave 4D: everything this row is missing, as tokens — derived by the server on every render. */
    missing: string[];
    /** Its Arabic title was written by the importer's machine translation and nobody has edited it. */
    machine_ar: boolean;
    has_stock: boolean;
    /**
     * Placement shape on THIS storefront (rehearsal #3, 2026-09-12):
     *  - `placed`    — has a primary category, the ordinary state;
     *  - `root_only` — sits on the top-level section and nowhere else, with NO primary category.
     *                  A legacy product with no sub-type lands here: the site serves it, but it
     *                  appears in no sub-category listing;
     *  - `none`      — no category at all, so it appears in no listing whatsoever.
     */
    placement: "placed" | "root_only" | "none";
    /** One entry per active storefront: visible, hidden, or absent (no row at all). */
    visibility: Array<{ id: number; state: "visible" | "hidden" | "absent" }>;
    cover: string | null;
    updated_at: string | null;
    edit_url: string;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Option[];
    /** Every ACTIVE storefront, for the per-storefront visibility column. */
    all_storefronts: Array<{ id: number; code: string; name: string }>;
    brands: Option[];
    categories: Option[];
    families: Option[];
    table: TablePayload<ProductRow>;
    pre_switch_notice: { pre_switch: boolean; message: string } | null;
    pre_switch: PreSwitchState;
}

/**
 * /manage/storefronts/{id}/products — the list the team opens first every morning.
 *
 * Everything is the server's: the filters, the sort, the search and the page all live in the query
 * string, so "the out-of-stock bags with no Arabic" is a URL a team member can send to another
 * (see App\Support\Table\TableQuery for why that matters at 7 447 products).
 *
 * The columns are chosen for the job, not for completeness: a team member scanning this list is
 * looking for what is WRONG — a product with no Arabic cannot be published, one with no category
 * shows nowhere, one whose stock is at zero should not be featured. So those three states are
 * badges, not hidden behind a filter.
 */
export default function ProductsIndex({
    storefront,
    storefronts,
    all_storefronts,
    brands,
    categories,
    families,
    table,
    pre_switch_notice,
    pre_switch,
}: Props) {
    const t = useT();

    /**
     * The family names, in the operator's language. Built inside the component because every one
     * of them goes through `t()`, and a hook cannot run at module level.
     */
    const familyLabels: Record<string, string> = {
        watch: t("products.family_watch", "ساعات"),
        fashion: t("products.family_fashion", "أزياء"),
        bag: t("products.family_bag", "حقائب"),
        wallet: t("products.family_wallet", "محافظ"),
        perfume: t("products.family_perfume", "عطور"),
        electronics: t("products.family_electronics", "إلكترونيات"),
        other: t("products.family_other", "أخرى"),
    };

    /**
     * The seven missing-data tokens, in the operator's language.
     *
     * The tokens themselves are the server's (`ImportReport::MISSING_*`) and are deliberately short
     * and stable — they are also what the filter selects on — so the translation lives here, next to
     * the only place that shows them to a person.
     *
     * `image_problem` is the odd one out and reads as a whole phrase rather than a field name, on
     * purpose. Every other token names something ABSENT, and the chip is read as "missing: …". This
     * one is present and broken, so "صورة" in that list would say the opposite of what is true.
     */
    const missingLabels: Record<string, string> = {
        sku: t("products.supplier_code", "كود المورّد"),
        image: t("products.image", "صورة"),
        arabic: t("common.name_ar", "الاسم (عربي)"),
        category: t("common.category", "تصنيف"),
        brand: t("products.brand", "الماركة"),
        price: t("common.price", "السعر"),
        image_problem: t("products.image_problem", "صورة تالفة — تحتاج رفعًا جديدًا"),
    };

    const missingLabel = (token: string): string =>
        missingLabels[token] ?? token;

    /** The joined list a missing-data chip shows. The separator is punctuation, so it translates too. */
    const missingList = (tokens: string[]): string =>
        tokens.map(missingLabel).join(t("common.list_separator", "، "));

    /** A short label per storefront id, for the visibility chips. */
    const storefrontLabel = (id: number): string => {
        const match = all_storefronts.find((option) => option.id === id);

        return match === undefined ? `#${id}` : match.name;
    };
    const columns: Array<Column<ProductRow>> = [
        {
            key: "cover",
            header: t("products.image", "صورة"),
            sortable: false,
            className: "w-14",
            cell: (row) => (
                <div className="h-10 w-10 overflow-hidden rounded border bg-muted/40">
                    {row.cover === null ? null : (
                        <img
                            src={row.cover}
                            alt=""
                            className="h-full w-full object-contain"
                            loading="lazy"
                        />
                    )}
                </div>
            ),
        },
        {
            key: "p.wa_code",
            header: t("products.code", "الكود"),
            cell: (row) => (
                <Num className="font-mono text-xs">{row.wa_code}</Num>
            ),
        },
        {
            key: "title",
            header: t("common.name", "الاسم"),
            sortable: false,
            cell: (row) => (
                <div className="min-w-[12rem] space-y-0.5">
                    <div className="font-medium">
                        {row.title.ar === "" ? (
                            <span className="text-muted-foreground">
                                {t("common.no_arabic_name", "— بلا اسم عربي —")}
                            </span>
                        ) : (
                            row.title.ar
                        )}
                    </div>
                    {row.title.en === "" ? null : (
                        <div className="text-xs text-muted-foreground">
                            <Ltr>{row.title.en}</Ltr>
                        </div>
                    )}
                    <div className="flex flex-wrap gap-1 pt-0.5">
                        {/* Everything that stops a product from selling, on the row itself — no
                            opening, no filtering, no guessing (task 4.3). Each of these is a hard
                            gate on the server too, so a badge here is a refusal there. */}
                        {/* The importer's badge. It is NOT one chip per missing field — a row
                            with five gaps would be a wall of red — but one chip that SAYS what is
                            missing, so the operator reads a sentence instead of decoding colours.
                            The Arabic-missing and image-missing chips below stay as they are: they
                            are hard gates on the server, and they predate the import. */}
                        {row.missing.length > 0 ? (
                            <Badge
                                variant="warning"
                                title={t(
                                    "products.missing_title",
                                    "ناقص: :list",
                                    { list: missingList(row.missing) },
                                )}
                            >
                                {t(
                                    "products.missing_data",
                                    "بيانات ناقصة: :list",
                                    { list: missingList(row.missing) },
                                )}
                            </Badge>
                        ) : null}
                        {row.machine_ar ? (
                            <Badge
                                variant="outline"
                                title={t(
                                    "products.machine_ar_hint",
                                    "العنوان العربي مكتوب آليًا أثناء الاستيراد ولم يراجعه أحد بعد. افتح المنتج وصحّح الاسم — بمجرد حفظك للترجمة تختفي هذه العلامة.",
                                )}
                            >
                                {t(
                                    "products.machine_ar_badge",
                                    "ترجمة آلية — تحتاج مراجعة",
                                )}
                            </Badge>
                        ) : null}
                        {!row.has_arabic ? (
                            <Badge variant="destructive">
                                {t("products.missing_arabic", "عربي ناقص")}
                            </Badge>
                        ) : null}
                        {!row.has_image ? (
                            <Badge variant="destructive">
                                {t("products.no_image", "بلا صورة")}
                            </Badge>
                        ) : null}
                        {!row.has_stock ? (
                            <Badge variant="warning">
                                {t("common.out_of_stock", "نفد المخزون")}
                            </Badge>
                        ) : null}
                        {/* Rehearsal #3: a product on the top-level section with no sub-category is
                            served by the site but listed under no sub-section, and it used to look
                            exactly like an ordinary placed product here. */}
                        {row.placement === "root_only" ? (
                            <Badge
                                variant="warning"
                                title={t(
                                    "products.root_only_hint",
                                    "هذا المنتج موضوع في القسم الرئيسي فقط وبدون تصنيف فرعي: يظهر في صفحة القسم وفي البحث، ولا يظهر في أي قائمة تصنيف فرعي، ومسار التصفّح له خطوة واحدة. العائلة تُشتق من اسم القسم الرئيسي. افتح المنتج واختر تصنيفًا فرعيًا ليظهر في قوائمه.",
                                )}
                            >
                                {t("products.no_sub_type", "بدون تصنيف فرعي")}
                            </Badge>
                        ) : null}
                        {row.placement === "none" ? (
                            <Badge
                                variant="destructive"
                                title={t(
                                    "products.unplaced_hint",
                                    "هذا المنتج غير موضوع في أي تصنيف على هذا المتجر، فلا يظهر في أي قائمة. اختر له تصنيفًا من شاشة المنتج أو من شاشة التوزيع.",
                                )}
                            >
                                {t("products.unplaced", "بلا تصنيف")}
                            </Badge>
                        ) : null}
                        {row.variants > 0 ? (
                            <Badge variant="outline">
                                {t(
                                    "products.variant_count",
                                    ":count مقاس/لون",
                                    { count: row.variants },
                                )}
                            </Badge>
                        ) : null}
                        {row.archived ? (
                            <Badge variant="neutral">
                                {t("products.archived", "مؤرشف")}
                            </Badge>
                        ) : null}
                    </div>
                </div>
            ),
        },
        {
            key: "brand",
            header: t("products.brand", "الماركة"),
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-sm">
                    {row.brand.ar === "" ? row.brand.en : row.brand.ar}
                </span>
            ),
        },
        {
            key: "p.family",
            header: t("products.family", "العائلة"),
            hideOnMobile: true,
            cell: (row) => (
                <Badge variant="neutral">
                    {familyLabels[row.family] ?? row.family}
                </Badge>
            ),
        },
        {
            key: "p.selling_price",
            header: t("common.price", "السعر"),
            cell: (row) => (
                <span className="whitespace-nowrap text-sm" dir="ltr">
                    {row.sale_price === null ? (
                        <>{row.selling_price}</>
                    ) : (
                        <>
                            <s className="text-muted-foreground">
                                {row.selling_price}
                            </s>{" "}
                            <strong>{row.sale_price}</strong>
                        </>
                    )}{" "}
                    {row.currency}
                </span>
            ),
        },
        {
            key: "p.stock_express",
            header: t("common.inventory", "المخزون"),
            cell: (row) => (
                <span className="whitespace-nowrap text-xs" dir="ltr">
                    <span title="Express">{row.stock_express}</span> /{" "}
                    <span title="Market">{row.stock_market}</span>
                    {row.in_stock ? null : (
                        <Badge variant="warning" className="ms-1">
                            {t("common.out_short", "نفد")}
                        </Badge>
                    )}
                </span>
            ),
        },
        {
            key: "p.is_active",
            header: t("common.active", "مفعّل"),
            cell: (row) =>
                row.is_active ? (
                    <Badge variant="success">{t("common.yes", "نعم")}</Badge>
                ) : (
                    <Badge variant="neutral">{t("common.no", "لا")}</Badge>
                ),
        },
        {
            // Sorting still uses THIS storefront's column (the one in the URL); the cell shows
            // every storefront, because "where is this product live?" is the question the team
            // actually asks and it used to need two browser tabs to answer.
            key: "sp.is_visible",
            header: t(
                "products.visibility_per_storefront",
                "الظهور على المتاجر",
            ),
            cell: (row) => (
                <div className="flex flex-wrap gap-1">
                    {row.visibility.map((entry) => (
                        <Badge
                            key={entry.id}
                            variant={
                                entry.state === "visible"
                                    ? "success"
                                    : entry.state === "hidden"
                                      ? "neutral"
                                      : "outline"
                            }
                            title={
                                entry.state === "visible"
                                    ? t(
                                          "products.visible_on",
                                          "ظاهر على :storefront",
                                          {
                                              storefront: storefrontLabel(
                                                  entry.id,
                                              ),
                                          },
                                      )
                                    : entry.state === "hidden"
                                      ? t(
                                            "products.hidden_on",
                                            "مخفي على :storefront",
                                            {
                                                storefront: storefrontLabel(
                                                    entry.id,
                                                ),
                                            },
                                        )
                                      : t(
                                            "products.absent_on",
                                            "غير مضاف إلى :storefront",
                                            {
                                                storefront: storefrontLabel(
                                                    entry.id,
                                                ),
                                            },
                                        )
                            }
                        >
                            {storefrontLabel(entry.id)}
                            {entry.state === "visible"
                                ? entry.id === storefront.id && row.is_featured
                                    ? " ★"
                                    : " ✓"
                                : entry.state === "hidden"
                                  ? " ✕"
                                  : " —"}
                        </Badge>
                    ))}
                </div>
            ),
        },
    ];

    const setStorefront = (id: string) => {
        router.get(
            `/manage/storefronts/${id}/products`,
            {},
            { preserveState: false },
        );
    };

    return (
        <ManageLayout
            title={t("products.title", "المنتجات")}
            crumbs={[
                { label: t("common.home", "الرئيسية"), href: "/manage" },
                { label: t("products.title", "المنتجات") },
            ]}
            actions={
                <div className="flex items-center gap-2">
                    {storefronts.length > 1 ? (
                        <Select
                            aria-label={t("common.storefront", "المتجر")}
                            className="w-40"
                            value={String(storefront.id)}
                            onChange={(event) =>
                                setStorefront(event.target.value)
                            }
                        >
                            {storefronts.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                    ) : null}
                    {/* Disabled until the write-switch, with the reason in the tooltip. The
                        server refuses the POST as well — this is so nobody fills in a form for
                        twenty minutes to be told at the end. */}
                    {pre_switch.blocked ? (
                        <Button
                            type="button"
                            disabled
                            title={pre_switch.message ?? undefined}
                            className="gap-1.5"
                        >
                            <Lock className="h-4 w-4" />
                            {t("products.new", "منتج جديد")}
                        </Button>
                    ) : (
                        <Button asChild className="gap-1.5">
                            <Link
                                href={`/manage/storefronts/${storefront.id}/products/create`}
                            >
                                <Plus className="h-4 w-4" />
                                {t("products.new", "منتج جديد")}
                            </Link>
                        </Button>
                    )}
                </div>
            }
        >
            {pre_switch.blocked ? (
                <Alert
                    tone="warning"
                    title={t(
                        "products.pre_switch_paused_title",
                        "قبل ليلة التحويل: الإضافة موقوفة، والتعديل مفتوح",
                    )}
                >
                    {pre_switch.message}
                </Alert>
            ) : pre_switch_notice === null ? null : (
                <Alert
                    tone="warning"
                    title={t(
                        "products.pre_switch_notice_title",
                        "قبل ليلة التحويل — اقرأ هذا أولًا",
                    )}
                >
                    {pre_switch_notice.message}
                </Alert>
            )}

            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.id}
                searchPlaceholder={t(
                    "products.search_placeholder",
                    "ابحث بالاسم أو الكود أو الموديل…",
                )}
                filters={(setFilter, current) => (
                    <>
                        <Select
                            aria-label={t(
                                "products.filter_by_category",
                                "تصفية بالتصنيف",
                            )}
                            className="w-44"
                            value={current.category ?? ""}
                            onChange={(event) =>
                                setFilter(
                                    "category",
                                    event.target.value === ""
                                        ? null
                                        : event.target.value,
                                )
                            }
                        >
                            <option value="">
                                {t("products.all_categories", "كل التصنيفات")}
                            </option>
                            {categories.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            aria-label={t(
                                "products.filter_by_brand",
                                "تصفية بالماركة",
                            )}
                            className="w-40"
                            value={current["p.brand_id"] ?? ""}
                            onChange={(event) =>
                                setFilter(
                                    "p.brand_id",
                                    event.target.value === ""
                                        ? null
                                        : event.target.value,
                                )
                            }
                        >
                            <option value="">
                                {t("products.all_brands", "كل الماركات")}
                            </option>
                            {brands.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            aria-label={t(
                                "products.filter_by_family",
                                "تصفية بالعائلة",
                            )}
                            className="w-32"
                            value={current["p.family"] ?? ""}
                            onChange={(event) =>
                                setFilter(
                                    "p.family",
                                    event.target.value === ""
                                        ? null
                                        : event.target.value,
                                )
                            }
                        >
                            <option value="">
                                {t("products.all_families", "كل العائلات")}
                            </option>
                            {families.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            aria-label={t(
                                "products.filter_by_status",
                                "تصفية بالحالة",
                            )}
                            className="w-32"
                            value={current["p.is_active"] ?? ""}
                            onChange={(event) =>
                                setFilter(
                                    "p.is_active",
                                    event.target.value === ""
                                        ? null
                                        : event.target.value,
                                )
                            }
                        >
                            <option value="">
                                {t(
                                    "products.active_and_inactive",
                                    "مفعّل ومعطّل",
                                )}
                            </option>
                            <option value="1">
                                {t("common.active", "مفعّل")}
                            </option>
                            <option value="0">
                                {t("products.inactive", "معطّل")}
                            </option>
                        </Select>
                        <Select
                            aria-label={t(
                                "products.filter_by_stock",
                                "تصفية بالمخزون",
                            )}
                            className="w-32"
                            value={current["p.in_stock"] ?? ""}
                            onChange={(event) =>
                                setFilter(
                                    "p.in_stock",
                                    event.target.value === ""
                                        ? null
                                        : event.target.value,
                                )
                            }
                        >
                            <option value="">
                                {t("products.all_stock", "كل المخزون")}
                            </option>
                            <option value="1">
                                {t("products.in_stock", "متوفر")}
                            </option>
                            <option value="0">
                                {t("common.out_short", "نفد")}
                            </option>
                        </Select>
                        <Select
                            aria-label={t(
                                "products.quick_filter",
                                "تصفية سريعة",
                            )}
                            className="w-44"
                            value={current.flag ?? ""}
                            onChange={(event) =>
                                setFilter(
                                    "flag",
                                    event.target.value === ""
                                        ? null
                                        : event.target.value,
                                )
                            }
                        >
                            <option value="">
                                {t(
                                    "products.no_quick_filter",
                                    "بلا تصفية سريعة",
                                )}
                            </option>
                            <option value="low_stock">
                                {t("products.low_stock", "مخزون منخفض")}
                            </option>
                            <option value="no_arabic">
                                {t("products.missing_arabic", "عربي ناقص")}
                            </option>
                            <option value="unplaced">
                                {t("products.unplaced", "بلا تصنيف")}
                            </option>
                            <option value="has_variants">
                                {t("products.has_variants", "به مقاسات/ألوان")}
                            </option>
                            <option value="archived">
                                {t("products.filter_archived", "المؤرشف")}
                            </option>
                            {/* Wave 4D. Both select exactly the set their badge marks. */}
                            <option value="missing_data">
                                {t(
                                    "products.missing_data_filter",
                                    "بيانات ناقصة",
                                )}
                            </option>
                            <option value="machine_ar">
                                {t(
                                    "products.machine_ar_filter",
                                    "ترجمة آلية تحتاج مراجعة",
                                )}
                            </option>
                            <option value="image_problem">
                                {t(
                                    "products.image_problem_filter",
                                    "صور تالفة تحتاج رفعًا جديدًا",
                                )}
                            </option>
                        </Select>
                    </>
                )}
                bulkActions={(selected, clear) => (
                    <>
                        {/* Visibility is deliberately NOT here: it needs the Arabic gate per
                            product, and a bulk action that silently skipped half a selection
                            would be worse than no bulk action. It lives on the placement screen,
                            which reports what it skipped. */}
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/manage/storefronts/${storefront.id}/products/bulk`,
                                    { action: "activate", ids: selected },
                                    { preserveScroll: true, onSuccess: clear },
                                )
                            }
                        >
                            {t("products.activate", "تفعيل")}
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/manage/storefronts/${storefront.id}/products/bulk`,
                                    { action: "deactivate", ids: selected },
                                    { preserveScroll: true, onSuccess: clear },
                                )
                            }
                        >
                            {t("products.deactivate", "تعطيل")}
                        </Button>
                    </>
                )}
                rowActions={(row) => (
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        aria-label={t("products.edit_row", "تعديل :name", {
                            name: row.title.ar || row.wa_code,
                        })}
                    >
                        <Link href={row.edit_url}>
                            <Pencil className="h-4 w-4" />
                        </Link>
                    </Button>
                )}
                emptyTitle={t("common.no_products", "لا توجد منتجات")}
                emptyDescription={t(
                    "products.empty_description",
                    "جرِّب تعديل البحث أو عوامل التصفية، أو أضف منتجًا جديدًا.",
                )}
            />
        </ManageLayout>
    );
}
