import { router, usePage } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { useState } from 'react';

import { DataTable, type Column } from '@/components/table/DataTable';
import ManageLayout from '@/layouts/ManageLayout';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input, Select } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import type { SharedProps, TablePayload } from '@/types';

interface PlacementRow {
    product_id: number;
    wa_code: string;
    title: { ar: string; en: string };
    has_arabic: boolean;
    is_visible: boolean;
    is_featured: boolean;
    sort_order: number;
    slug: string;
    primary_category_id: number | null;
    placements: number;
    /** Live carts holding this product. Hiding it is allowed; doing it silently is not. */
    in_carts: number;
    selling_price: string;
    cover: string | null;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    storefronts: Array<{ value: string; label: string }>;
    categories: Array<{ value: string; label: string }>;
    table: TablePayload<PlacementRow>;
    slug_warning: string;
}

/**
 * /manage/storefronts/{id}/placement — one storefront, many products (scope item 5).
 *
 * The product form edits one product's placement while you are editing that product. This is the
 * other direction: the sweep the team does before a season — hide these, feature those, fix the
 * ones with no primary category, re-order a shelf.
 *
 * Three things it refuses to let the team get wrong, each with the reason ON the row:
 *
 *  • **No Arabic → cannot be visible.** Fallback is off (AGENTS §2.17), so publishing without
 *    Arabic ships a hole to an Arabic-first shop. The switch is disabled and the badge says why.
 *  • **No primary category.** The DB allows a product with placements and no primary; the read
 *    layer then picks one by "primary → deepest → lowest id", which is deterministic but not what
 *    anybody chose. The row flags it so somebody chooses.
 *  • **A slug edit is a URL change.** The warning is above the table, and the save writes the 301.
 *
 * Bulk show/hide REPORTS what it skipped. A bulk action that silently dropped half a selection is
 * how a team finds out in October that twelve products were never published.
 */
export default function PlacementIndex({ storefront, storefronts, categories, table, slug_warning }: Props) {
    const { errors } = usePage<SharedProps>().props;
    const [slugs, setSlugs] = useState<Record<number, string>>({});
    const [sorts, setSorts] = useState<Record<number, string>>({});
    /** The row whose hide is waiting for a confirmation, because customers hold it in a cart. */
    const [hiding, setHiding] = useState<PlacementRow | null>(null);

    const base = `/manage/storefronts/${storefront.id}/placement`;

    const save = (row: PlacementRow, overrides: Partial<Record<string, unknown>>) => {
        router.put(
            `${base}/${row.product_id}`,
            {
                is_visible: row.is_visible,
                is_featured: row.is_featured,
                sort_order: sorts[row.product_id] ?? row.sort_order,
                slug: slugs[row.product_id] ?? row.slug,
                ...overrides,
            },
            { preserveScroll: true },
        );
    };

    const columns: Array<Column<PlacementRow>> = [
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
            header: 'المنتج',
            cell: (row) => (
                <div className="min-w-[11rem] space-y-0.5">
                    <div className="font-medium">{row.title.ar === '' ? <span className="text-destructive">— بلا اسم عربي —</span> : row.title.ar}</div>
                    <div className="font-mono text-[11px] text-muted-foreground" dir="ltr">
                        {row.wa_code}
                    </div>
                    <div className="flex flex-wrap gap-1">
                        {row.has_arabic ? null : <Badge variant="destructive">عربي ناقص</Badge>}
                        {row.placements === 0 ? <Badge variant="warning">بلا تصنيف</Badge> : null}
                        {row.placements > 0 && row.primary_category_id === null ? <Badge variant="warning">بلا تصنيف أساسي</Badge> : null}
                    </div>
                </div>
            ),
        },
        {
            key: 'sp.is_visible',
            header: 'ظاهر',
            cell: (row) => (
                <Switch
                    aria-label={`إظهار ${row.title.ar || row.wa_code}`}
                    checked={row.is_visible}
                    disabled={!row.has_arabic && !row.is_visible}
                    onCheckedChange={(checked) => {
                        // Turning it OFF while customers hold it in a cart asks first, with the
                        // number; everything else applies straight away (task 4.4).
                        if (!checked && row.in_carts > 0) {
                            setHiding(row);

                            return;
                        }
                        save(row, { is_visible: checked });
                    }}
                />
            ),
        },
        {
            key: 'sp.is_featured',
            header: 'مميّز',
            cell: (row) => (
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    aria-label={`تمييز ${row.title.ar || row.wa_code}`}
                    onClick={() => save(row, { is_featured: !row.is_featured })}
                >
                    <Star className={row.is_featured ? 'h-4 w-4 fill-current text-amber-500' : 'h-4 w-4 text-muted-foreground'} />
                </Button>
            ),
        },
        {
            key: 'sp.sort_order',
            header: 'الترتيب',
            cell: (row) => (
                <Input
                    dir="ltr"
                    type="number"
                    className="w-20"
                    aria-label={`ترتيب ${row.title.ar || row.wa_code}`}
                    value={sorts[row.product_id] ?? String(row.sort_order)}
                    onChange={(event) => setSorts((current) => ({ ...current, [row.product_id]: event.target.value }))}
                    onBlur={() => {
                        if ((sorts[row.product_id] ?? String(row.sort_order)) !== String(row.sort_order)) {
                            save(row, {});
                        }
                    }}
                />
            ),
        },
        {
            key: 'slug',
            header: 'الرابط',
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <Input
                    dir="ltr"
                    className="min-w-[10rem]"
                    aria-label={`رابط ${row.title.ar || row.wa_code}`}
                    value={slugs[row.product_id] ?? row.slug}
                    onChange={(event) => setSlugs((current) => ({ ...current, [row.product_id]: event.target.value }))}
                    onBlur={() => {
                        if ((slugs[row.product_id] ?? row.slug) !== row.slug) {
                            save(row, {});
                        }
                    }}
                />
            ),
        },
        {
            key: 'p.selling_price',
            header: 'السعر',
            hideOnMobile: true,
            cell: (row) => (
                <span dir="ltr" className="text-sm">
                    {row.selling_price}
                </span>
            ),
        },
    ];

    return (
        <ManageLayout
            title="العرض والترتيب"
            crumbs={[{ label: 'الرئيسية', href: '/manage' }, { label: 'العرض والترتيب' }]}
            actions={
                storefronts.length > 1 ? (
                    <Select
                        aria-label="المتجر"
                        className="w-40"
                        value={String(storefront.id)}
                        onChange={(event) => router.get(`/manage/storefronts/${event.target.value}/placement`)}
                    >
                        {storefronts.map((option) => (
                            <option key={option.value} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </Select>
                ) : null
            }
        >
            <Alert tone="warning" title="قبل تغيير أي رابط">
                {slug_warning}
            </Alert>

            {errors.is_visible ? (
                <Alert tone="error" title="تعذّر الإظهار">
                    {errors.is_visible}
                </Alert>
            ) : null}
            {errors.bulk ? (
                <Alert tone="warning" title="تم التنفيذ جزئيًا">
                    {errors.bulk}
                </Alert>
            ) : null}

            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.product_id}
                searchPlaceholder="ابحث بالاسم أو الكود أو الرابط…"
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
                            aria-label="تصفية بالظهور"
                            className="w-32"
                            value={current['sp.is_visible'] ?? ''}
                            onChange={(event) => setFilter('sp.is_visible', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">ظاهر ومخفي</option>
                            <option value="1">ظاهر</option>
                            <option value="0">مخفي</option>
                        </Select>
                        <Select
                            aria-label="تصفية سريعة"
                            className="w-44"
                            value={current.flag ?? ''}
                            onChange={(event) => setFilter('flag', event.target.value === '' ? null : event.target.value)}
                        >
                            <option value="">بلا تصفية سريعة</option>
                            <option value="no_arabic">عربي ناقص</option>
                            <option value="no_primary">بلا تصنيف أساسي</option>
                            <option value="unplaced">بلا تصنيف</option>
                        </Select>
                    </>
                )}
                bulkActions={(selected, clear) => (
                    <>
                        {(
                            [
                                ['show', 'إظهار'],
                                ['hide', 'إخفاء'],
                                ['feature', 'تمييز'],
                                ['unfeature', 'إلغاء التمييز'],
                            ] as Array<[string, string]>
                        ).map(([action, label]) => (
                            <Button
                                key={action}
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    router.post(`${base}/bulk`, { action, product_ids: selected }, { preserveScroll: true, onSuccess: clear })
                                }
                            >
                                {label}
                            </Button>
                        ))}
                    </>
                )}
                emptyTitle="لا توجد منتجات على هذا المتجر"
                emptyDescription="أضف منتجات من شاشة المنتجات، أو عدّل التصفية."
            />
            {/* ── hiding a product that customers are holding (task 4.4) ────────────────── */}
            <Dialog open={hiding !== null} onOpenChange={(open) => (open ? null : setHiding(null))}>
                {hiding === null ? null : (
                    <DialogContent title={`إخفاء «${hiding.title.ar || hiding.wa_code}» عن ${storefront.name}`}>
                        <div className="space-y-3 text-sm text-muted-foreground">
                            <p>
                                هذا المنتج موجود الآن في <strong>{hiding.in_carts}</strong> سلة مفتوحة. بعد الإخفاء لن يظهر في القوائم ولا في
                                البحث، ومن يفتح سلته لن يتمكن من إتمام شرائه.
                            </p>
                            <p>
                                الإخفاء لا يحذف المنتج ولا يمس مخزونه ولا طلباته السابقة، ويمكن إرجاعه في أي وقت. لو كان
                                السبب نفاد الكمية، الأفضل تركه ظاهرًا — المتجر يكتب «نفد» وحده.
                            </p>
                        </div>
                        <div className="mt-4 flex flex-wrap justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setHiding(null)}>
                                إلغاء
                            </Button>
                            <Button
                                type="button"
                                variant="destructive"
                                onClick={() => {
                                    const row = hiding;
                                    setHiding(null);
                                    save(row, { is_visible: false });
                                }}
                            >
                                أخفِ المنتج
                            </Button>
                        </div>
                    </DialogContent>
                )}
            </Dialog>

        </ManageLayout>
    );
}
