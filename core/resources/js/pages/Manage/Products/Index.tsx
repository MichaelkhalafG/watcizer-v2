import { Link, router } from "@inertiajs/react";
import { ImageOff, Lock, Pencil, Plus } from "lucide-react";
import { useState } from "react";

import { DataTable, type Column } from "@/components/table/DataTable";
import ManageLayout from "@/layouts/ManageLayout";
import { Alert } from "@/components/ui/alert";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input, Select } from "@/components/ui/input";
import type { PreSwitchState, TablePayload } from "@/types";
import { Name, Num } from "@/components/ui/bidi";
import { ProductName } from "@/components/manage/ProductName";
import { useLocale, useT } from "@/lib/i18n";
import { localisedTitle, titleOrCode } from "@/lib/title";
import { bucketLabel } from "@/lib/labels";

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
    // `absent` = no `storefront_product` row on the shop being looked at. Not a fault (D-3).
    placement: "placed" | "root_only" | "none" | "absent";
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
    pre_switch,
}: Props) {
    const t = useT();
    const locale = useLocale();

    // The bulk reorder threshold (W-2). `'0'` as the starting value on purpose: turning the alert
    // OFF for products nobody has set a reorder point for is what this control is mostly for.
    const [threshold, setThreshold] = useState("0");

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

    /*
     * ── ONE indicator for everything wrong with a row (§2.2) ────────────────────────────────
     *
     * Almost every row carried the same three badges — `بيانات ناقصة: تصنيف، الماركة`,
     * `ترجمة آلية — تحتاج مراجعة` and `بلا تصنيف` — and **7,087 of 7,713 rows carried that exact
     * set**. A warning that fires on 92% of the catalogue is wallpaper: it costs a third of every
     * row's height and tells the reader nothing, because the one row that is different looks the
     * same as the 7,086 that are not.
     *
     * So the marks are counted rather than listed. The chip says HOW MANY things are wrong, the
     * tooltip says what they are, and the difference between a row with one problem and a row with
     * five is finally visible from across the screen — which is the thing the three badges could
     * never show.
     *
     * `تصنيف` also stopped being named twice. It appeared inside `بيانات ناقصة` AND as its own
     * `بلا تصنيف` badge, on the same row, meaning the same thing.
     */
    const needsWork = (row: ProductRow): string[] => {
        const out = row.missing.map(missingLabel);

        if (row.machine_ar) {
            out.push(t("products.machine_ar_badge", "ترجمة آلية — تحتاج مراجعة"));
        }
        // Only for a product that IS on this shop: `absent` is a fact, not work (D-3).
        if (row.placement === "root_only") {
            out.push(t("products.no_sub_type", "بدون تصنيف فرعي"));
        }

        return out;
    };


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
                <div className="flex h-10 w-10 items-center justify-center overflow-hidden rounded border bg-muted/40">
                    {row.cover === null ? (
                        // Not an empty bordered box (§2.2). A blank frame cannot tell the reader
                        // whether the picture is missing or failed to load, and one of those is
                        // something to go and fix.
                        <ImageOff
                            className="h-4 w-4 text-muted-foreground"
                            aria-label={t("products.no_image", "بلا صورة")}
                        />
                    ) : (
                        <img
                            src={row.cover}
                            alt=""
                            className="h-full w-full object-cover"
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
            /*
             * ── ONE LINE (§2.2) ────────────────────────────────────────────────────────────
             *
             * Measured before: rows 118-190 px tall, five or six to a screen, 7,713 products at 25
             * per page = 309 pages. The height went on two things, and both were repetition:
             *
             *  • the title printed TWICE — Arabic, then English underneath in grey. When the
             *    Arabic field holds the English string, which is common across the imported Brand
             *    Fashion catalogue, the SAME 200-character sentence printed twice, ten lines, in
             *    one cell.
             *  • three badges that 7,087 of 7,713 rows carried identically.
             *
             * Now: the reader's own language only, truncated, with the other language on hover —
             * and one counted indicator instead of three badges. `title` on the cell rather than a
             * second line, because a name is something you check occasionally and read constantly.
             */
            cell: (row) => {
                const problems = needsWork(row);
                const other = localisedTitle(row.title, locale).secondary;

                return (
                    <div
                        className="flex min-w-[14rem] max-w-[28rem] items-center gap-2"
                        title={other ?? undefined}
                    >
                        <span className="min-w-0 flex-1 truncate">
                            <ProductName title={row.title} secondary={false} />
                        </span>

                        {problems.length > 0 ? (
                            <Badge
                                variant="warning"
                                className="shrink-0"
                                title={t("products.needs_work_list", "يحتاج: :list", {
                                    list: problems.join(
                                        t("common.list_separator", "، "),
                                    ),
                                })}
                            >
                                {t("products.needs_work", "يحتاج مراجعة (:count)", {
                                    count: problems.length,
                                })}
                            </Badge>
                        ) : null}

                        {/* Kept as its own badge, and only these two. `absent` is the D-3
                            distinction — a fact about where the product is sold, not work — and
                            `archived` changes what the row IS. Everything else that used to sit
                            here is inside the count above. */}
                        {row.placement === "absent" ? (
                            <Badge
                                variant="neutral"
                                className="shrink-0"
                                title={t(
                                    "products.absent_hint",
                                    "هذا المنتج غير معروض على هذا المتجر أصلًا، فلا ينقصه تصنيف ولا يحتاج أي إجراء. أضِفه من شاشة التوزيع فقط إذا قررت بيعه هنا.",
                                )}
                            >
                                {t("products.absent", "غير مضاف لهذا المتجر")}
                            </Badge>
                        ) : null}
                        {row.archived ? (
                            <Badge variant="neutral" className="shrink-0">
                                {t("products.archived", "مؤرشف")}
                            </Badge>
                        ) : null}
                    </div>
                );
            },
        },
        {
            key: "brand",
            header: t("products.brand", "الماركة"),
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-sm">
                    {/*
                     * Same rule as the name, WITHOUT the marker — deliberately. The badge on a
                     * product title points at a row somebody is meant to go and fill in; a brand
                     * name is shared by hundreds of rows, so the same badge would repeat down the
                     * whole page and say nothing new. The brand screen is where a missing brand
                     * name is actionable, and that is where it is shown.
                     */}
                    <Name>{localisedTitle(row.brand, locale).text}</Name>
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
                    <span title={bucketLabel(t, 'express')}>{row.stock_express}</span> /{" "}
                    <span title={bucketLabel(t, 'market')}>{row.stock_market}</span>
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
                        "الإضافة موقوفة حاليًا، والتعديل مفتوح",
                    )}
                >
                    {pre_switch.message}
                </Alert>
            ) : null}

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
                            {/* TWO entries, because they were two different things wearing one
                                name (D-3). `بلا تصنيف` is a fault on a product this shop sells;
                                `غير مضاف` is the ordinary state of a product it does not. */}
                            <option value="unplaced">
                                {t("products.unplaced", "بلا تصنيف")}
                            </option>
                            <option value="absent">
                                {t("products.absent", "غير مضاف لهذا المتجر")}
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
                bulkActions={(selected, clear, scope) => {
                    /*
                     * Every action posts either the ids on screen or the SCOPE (W-3). The server
                     * re-resolves "matching" through the same whitelists the list rendered from,
                     * so a selection of 7,578 costs one small request rather than 7,578 ids.
                     */
                    const post = (payload: Record<string, unknown>) =>
                        router.post(
                            `/manage/storefronts/${storefront.id}/products/bulk`,
                            scope.matching
                                ? { ...payload, scope: "matching", query: scope.query }
                                : { ...payload, ids: selected },
                            { preserveScroll: true, onSuccess: clear },
                        );

                    return (
                        <>
                            {/* Visibility is deliberately NOT here: it needs the Arabic gate per
                                product, and a bulk action that silently skipped half a selection
                                would be worse than no bulk action. It lives on the placement screen,
                                which reports what it skipped. */}
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => post({ action: "activate" })}
                            >
                                {t("products.activate", "تفعيل")}
                            </Button>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => post({ action: "deactivate" })}
                            >
                                {t("products.deactivate", "تعطيل")}
                            </Button>

                            {/* ── The reorder threshold, in bulk (W-2) ──────────────────────
                                7,578 of 7,713 products carry the old default of 5 while their
                                stock sits at 0–3, which is why the low-stock alert covered 97.5%
                                of the shop. Fixing that one product at a time was 7,578 form
                                saves; this is the control that makes the alert mean something. */}
                            <form
                                className="flex items-center gap-1.5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    post({
                                        action: "set_threshold",
                                        threshold: Number(threshold),
                                    });
                                }}
                            >
                                <label
                                    className="text-xs"
                                    htmlFor="bulk-threshold"
                                >
                                    {t(
                                        "products.low_stock_threshold",
                                        "حد التنبيه للمخزون",
                                    )}
                                </label>
                                <Input
                                    id="bulk-threshold"
                                    type="number"
                                    min={0}
                                    dir="ltr"
                                    className="h-8 w-20"
                                    value={threshold}
                                    onChange={(event) =>
                                        setThreshold(event.target.value)
                                    }
                                />
                                <Button type="submit" size="sm" variant="outline">
                                    {t("common.apply", "طبِّق")}
                                </Button>
                            </form>
                        </>
                    );
                }}
                rowActions={(row) => (
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        aria-label={t("products.edit_row", "تعديل :name", {
                            name: titleOrCode(row.title, locale, row.wa_code),
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
