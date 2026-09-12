import { Link, router } from '@inertiajs/react';
import { Lock, Pencil, Plus } from 'lucide-react';

import { DataTable, type Column } from '@/components/table/DataTable';
import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/input';
import type { PreSwitchState, TablePayload } from '@/types';

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
    has_stock: boolean;
    /**
     * Placement shape on THIS storefront (rehearsal #3, 2026-09-12):
     *  - `placed`    — has a primary category, the ordinary state;
     *  - `root_only` — sits on the top-level section and nowhere else, with NO primary category.
     *                  A legacy product with no sub-type lands here: the site serves it, but it
     *                  appears in no sub-category listing;
     *  - `none`      — no category at all, so it appears in no listing whatsoever.
     */
    placement: 'placed' | 'root_only' | 'none';
    /** One entry per active storefront: visible, hidden, or absent (no row at all). */
    visibility: Array<{ id: number; state: 'visible' | 'hidden' | 'absent' }>;
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

const FAMILY_LABELS: Record<string, string> = {
    watch: 'ساعات',
    fashion: 'أزياء',
    bag: 'حقائب',
    wallet: 'محافظ',
    perfume: 'عطور',
    electronics: 'إلكترونيات',
    other: 'أخرى',
};

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
    /** A short label per storefront id, for the visibility chips. */
    const storefrontLabel = (id: number): string => {
        const match = all_storefronts.find((option) => option.id === id);

        return match === undefined ? `#${id}` : match.name;
    };
    const columns: Array<Column<ProductRow>> = [
        {
            key: 'cover',
            header: 'صورة',
            sortable: false,
            className: 'w-14',
            cell: (row) => (
                <div className="h-10 w-10 overflow-hidden rounded border bg-muted/40">
                    {row.cover === null ? null : <img src={row.cover} alt="" className="h-full w-full object-contain" loading="lazy" />}
                </div>
            ),
        },
        {
            key: 'p.wa_code',
            header: 'الكود',
            cell: (row) => (
                <span className="font-mono text-xs" dir="ltr">
                    {row.wa_code}
                </span>
            ),
        },
        {
            key: 'title',
            header: 'الاسم',
            sortable: false,
            cell: (row) => (
                <div className="min-w-[12rem] space-y-0.5">
                    <div className="font-medium">{row.title.ar === '' ? <span className="text-muted-foreground">— بلا اسم عربي —</span> : row.title.ar}</div>
                    {row.title.en === '' ? null : (
                        <div className="text-xs text-muted-foreground" dir="ltr">
                            {row.title.en}
                        </div>
                    )}
                    <div className="flex flex-wrap gap-1 pt-0.5">
                        {/* Everything that stops a product from selling, on the row itself — no
                            opening, no filtering, no guessing (task 4.3). Each of these is a hard
                            gate on the server too, so a badge here is a refusal there. */}
                        {!row.has_arabic ? <Badge variant="destructive">عربي ناقص</Badge> : null}
                        {!row.has_image ? <Badge variant="destructive">بلا صورة</Badge> : null}
                        {!row.has_stock ? <Badge variant="warning">نفد المخزون</Badge> : null}
                        {/* Rehearsal #3: a product on the top-level section with no sub-category is
                            served by the site but listed under no sub-section, and it used to look
                            exactly like an ordinary placed product here. */}
                        {row.placement === 'root_only' ? (
                            <Badge
                                variant="warning"
                                title="هذا المنتج موضوع في القسم الرئيسي فقط وبدون تصنيف فرعي: يظهر في صفحة القسم وفي البحث، ولا يظهر في أي قائمة تصنيف فرعي، ومسار التصفّح له خطوة واحدة. العائلة تُشتق من اسم القسم الرئيسي. افتح المنتج واختر تصنيفًا فرعيًا ليظهر في قوائمه."
                            >
                                بدون تصنيف فرعي
                            </Badge>
                        ) : null}
                        {row.placement === 'none' ? (
                            <Badge
                                variant="destructive"
                                title="هذا المنتج غير موضوع في أي تصنيف على هذا المتجر، فلا يظهر في أي قائمة. اختر له تصنيفًا من شاشة المنتج أو من شاشة التوزيع."
                            >
                                بلا تصنيف
                            </Badge>
                        ) : null}
                        {row.variants > 0 ? <Badge variant="outline">{row.variants} مقاس/لون</Badge> : null}
                        {row.archived ? <Badge variant="neutral">مؤرشف</Badge> : null}
                    </div>
                </div>
            ),
        },
        {
            key: 'brand',
            header: 'الماركة',
            sortable: false,
            hideOnMobile: true,
            cell: (row) => <span className="text-sm">{row.brand.ar === '' ? row.brand.en : row.brand.ar}</span>,
        },
        {
            key: 'p.family',
            header: 'العائلة',
            hideOnMobile: true,
            cell: (row) => <Badge variant="neutral">{FAMILY_LABELS[row.family] ?? row.family}</Badge>,
        },
        {
            key: 'p.selling_price',
            header: 'السعر',
            cell: (row) => (
                <span className="whitespace-nowrap text-sm" dir="ltr">
                    {row.sale_price === null ? (
                        <>{row.selling_price}</>
                    ) : (
                        <>
                            <s className="text-muted-foreground">{row.selling_price}</s> <strong>{row.sale_price}</strong>
                        </>
                    )}{' '}
                    {row.currency}
                </span>
            ),
        },
        {
            key: 'p.stock_express',
            header: 'المخزون',
            cell: (row) => (
                <span className="whitespace-nowrap text-xs" dir="ltr">
                    <span title="Express">{row.stock_express}</span> / <span title="Market">{row.stock_market}</span>
                    {row.in_stock ? null : <Badge variant="warning" className="ms-1">نفد</Badge>}
                </span>
            ),
        },
        {
            key: 'p.is_active',
            header: 'مفعّل',
            cell: (row) => (row.is_active ? <Badge variant="success">نعم</Badge> : <Badge variant="neutral">لا</Badge>),
        },
        {
            // Sorting still uses THIS storefront's column (the one in the URL); the cell shows
            // every storefront, because "where is this product live?" is the question the team
            // actually asks and it used to need two browser tabs to answer.
            key: 'sp.is_visible',
            header: 'الظهور على المتاجر',
            cell: (row) => (
                <div className="flex flex-wrap gap-1">
                    {row.visibility.map((entry) => (
                        <Badge
                            key={entry.id}
                            variant={entry.state === 'visible' ? 'success' : entry.state === 'hidden' ? 'neutral' : 'outline'}
                            title={
                                entry.state === 'visible'
                                    ? `ظاهر على ${storefrontLabel(entry.id)}`
                                    : entry.state === 'hidden'
                                      ? `مخفي على ${storefrontLabel(entry.id)}`
                                      : `غير مضاف إلى ${storefrontLabel(entry.id)}`
                            }
                        >
                            {storefrontLabel(entry.id)}
                            {entry.state === 'visible' ? (entry.id === storefront.id && row.is_featured ? ' ★' : ' ✓') : entry.state === 'hidden' ? ' ✕' : ' —'}
                        </Badge>
                    ))}
                </div>
            ),
        },
    ];

    const setStorefront = (id: string) => {
        router.get(`/manage/storefronts/${id}/products`, {}, { preserveState: false });
    };

    return (
        <ManageLayout
            title="المنتجات"
            crumbs={[{ label: 'الرئيسية', href: '/manage' }, { label: 'المنتجات' }]}
            actions={
                <div className="flex items-center gap-2">
                    {storefronts.length > 1 ? (
                        <Select aria-label="المتجر" className="w-40" value={String(storefront.id)} onChange={(event) => setStorefront(event.target.value)}>
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
                        <Button type="button" disabled title={pre_switch.message ?? undefined} className="gap-1.5">
                            <Lock className="h-4 w-4" />
                            منتج جديد
                        </Button>
                    ) : (
                        <Button asChild className="gap-1.5">
                            <Link href={`/manage/storefronts/${storefront.id}/products/create`}>
                                <Plus className="h-4 w-4" />
                                منتج جديد
                            </Link>
                        </Button>
                    )}
                </div>
            }
        >
            {pre_switch.blocked ? (
                <Alert tone="warning" title="قبل ليلة التحويل: الإضافة موقوفة، والتعديل مفتوح">
                    {pre_switch.message}
                </Alert>
            ) : pre_switch_notice === null ? null : (
                <Alert tone="warning" title="قبل ليلة التحويل — اقرأ هذا أولًا">
                    {pre_switch_notice.message}
                </Alert>
            )}

            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.id}
                searchPlaceholder="ابحث بالاسم أو الكود أو الموديل…"
                filters={(setFilter, current) => (
                    <>
                        <Select
                            aria-label="تصفية بالتصنيف"
                            className="w-44"
                            value={current.category ?? ''}
                            onChange={(event) => setFilter('category', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">كل التصنيفات</option>
                            {categories.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            aria-label="تصفية بالماركة"
                            className="w-40"
                            value={current['p.brand_id'] ?? ''}
                            onChange={(event) => setFilter('p.brand_id', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">كل الماركات</option>
                            {brands.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            aria-label="تصفية بالعائلة"
                            className="w-32"
                            value={current['p.family'] ?? ''}
                            onChange={(event) => setFilter('p.family', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">كل العائلات</option>
                            {families.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </Select>
                        <Select
                            aria-label="تصفية بالحالة"
                            className="w-32"
                            value={current['p.is_active'] ?? ''}
                            onChange={(event) => setFilter('p.is_active', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">مفعّل ومعطّل</option>
                            <option value="1">مفعّل</option>
                            <option value="0">معطّل</option>
                        </Select>
                        <Select
                            aria-label="تصفية بالمخزون"
                            className="w-32"
                            value={current['p.in_stock'] ?? ''}
                            onChange={(event) => setFilter('p.in_stock', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">كل المخزون</option>
                            <option value="1">متوفر</option>
                            <option value="0">نفد</option>
                        </Select>
                        <Select
                            aria-label="تصفية سريعة"
                            className="w-44"
                            value={current.flag ?? ''}
                            onChange={(event) => setFilter('flag', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">بلا تصفية سريعة</option>
                            <option value="low_stock">مخزون منخفض</option>
                            <option value="no_arabic">عربي ناقص</option>
                            <option value="unplaced">بلا تصنيف</option>
                            <option value="has_variants">به مقاسات/ألوان</option>
                            <option value="archived">المؤرشف</option>
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
                                    { action: 'activate', ids: selected },
                                    { preserveScroll: true, onSuccess: clear },
                                )
                            }
                        >
                            تفعيل
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    `/manage/storefronts/${storefront.id}/products/bulk`,
                                    { action: 'deactivate', ids: selected },
                                    { preserveScroll: true, onSuccess: clear },
                                )
                            }
                        >
                            تعطيل
                        </Button>
                    </>
                )}
                rowActions={(row) => (
                    <Button asChild variant="ghost" size="icon" aria-label={`تعديل ${row.title.ar || row.wa_code}`}>
                        <Link href={row.edit_url}>
                            <Pencil className="h-4 w-4" />
                        </Link>
                    </Button>
                )}
                emptyTitle="لا توجد منتجات"
                emptyDescription="جرِّب تعديل البحث أو عوامل التصفية، أو أضف منتجًا جديدًا."
            />
        </ManageLayout>
    );
}
