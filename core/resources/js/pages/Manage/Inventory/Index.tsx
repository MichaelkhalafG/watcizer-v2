import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { SelectField, TextField, TextareaField } from '@/components/form/TextField';
import { DataTable, type Column } from '@/components/table/DataTable';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import type { SharedProps, TablePayload } from '@/types';

/**
 * Stock (wave 4C).
 *
 * ── Both buckets, always ─────────────────────────────────────────────────────────────────────
 *
 * `express` and `market` are two real shelves, and the storefront prices and promises delivery
 * differently from each. A screen that showed one total would hide the case that actually happens:
 * 0 in express and 6 in market, which is "in stock" but not "next-day". So every row shows both
 * and the sum, and an adjustment must NAME its bucket — there is no default.
 *
 * ── A product with variants is adjusted per variant ─────────────────────────────────────────
 *
 * `InventoryService` refuses a product-level movement on a product that has variants (wave 3.5),
 * because the product's number is derived from theirs. Rather than let an operator discover that
 * as a red error, the row expands to its variants and the product-level button is disabled with
 * the reason on it.
 *
 * ── Low stock is a FILTER, not a screen ─────────────────────────────────────────────────────
 *
 * The brief asks for a low-stock view. It is `?filters[view]=low` on this same list, so the
 * low-stock count and the list can never disagree — two screens with two queries eventually do.
 */

interface Variant {
    id: number;
    label: string | null;
    sku: string | null;
    express: number;
    market: number;
    is_active: boolean;
}

interface StockRow {
    id: number;
    wa_code: string;
    sku: string | null;
    title: string | null;
    family: string | null;
    express: number;
    market: number;
    total: number;
    threshold: number;
    in_stock: boolean;
    is_low: boolean;
    variants: Variant[];
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    table: TablePayload<StockRow>;
    filters: { views: Option[]; buckets: Option[] };
    reasons: Option[];
    low_count: number;
}

/** The form the dialog posts. `variant_id` empty means the product itself. */
interface AdjustTarget {
    product_id: number;
    wa_code: string;
    title: string | null;
    variant_id: number | null;
    variant_label: string | null;
    express: number;
    market: number;
}

const BUCKET_LABEL: Record<string, string> = { express: 'إكسبريس', market: 'ماركت' };

function StockCell({ value, low }: { value: number; low: boolean }) {
    return (
        <span className={low && value === 0 ? 'font-medium text-destructive' : 'font-medium'} dir="ltr">
            {value}
        </span>
    );
}

export default function InventoryIndex({ table, filters, reasons, low_count }: Props) {
    const { errors } = usePage<SharedProps>().props;
    const [target, setTarget] = useState<AdjustTarget | null>(null);
    const [expanded, setExpanded] = useState<number[]>([]);
    const [mode, setMode] = useState<'set' | 'adjust'>('adjust');
    const [bucket, setBucket] = useState<'express' | 'market'>('express');
    const [quantity, setQuantity] = useState('');
    const [reason, setReason] = useState(reasons[0]?.value ?? 'adjustment');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);

    const open = (next: AdjustTarget) => {
        setTarget(next);
        setMode('adjust');
        setBucket('express');
        setQuantity('');
        setReason(reasons[0]?.value ?? 'adjustment');
        setNote('');
    };

    const submit = () => {
        if (target === null) {
            return;
        }
        setBusy(true);
        router.post(
            '/manage/inventory/adjust',
            {
                product_id: target.product_id,
                variant_id: target.variant_id,
                bucket,
                mode,
                quantity: quantity === '' ? 0 : Number(quantity),
                reason,
                note,
            },
            {
                preserveScroll: true,
                onSuccess: () => setTarget(null),
                onFinish: () => setBusy(false),
            },
        );
    };

    const columns: Array<Column<StockRow>> = [
        {
            key: 'p.wa_code',
            header: 'المنتج',
            sortable: true,
            cell: (row) => (
                <div className="space-y-0.5">
                    <div className="font-medium">{row.title ?? '—'}</div>
                    <div className="text-xs text-muted-foreground" dir="ltr">
                        {row.wa_code}
                        {row.sku !== null ? ` · ${row.sku}` : ''}
                    </div>
                </div>
            ),
        },
        {
            key: 'p.stock_express',
            header: 'إكسبريس',
            sortable: true,
            cell: (row) => <StockCell value={row.express} low={row.is_low} />,
        },
        {
            key: 'p.stock_market',
            header: 'ماركت',
            sortable: true,
            cell: (row) => <StockCell value={row.market} low={row.is_low} />,
        },
        {
            key: 'total',
            header: 'الإجمالي',
            sortable: false,
            cell: (row) => (
                <div className="flex items-center gap-2">
                    <span dir="ltr">{row.total}</span>
                    {row.is_low ? (
                        <Badge variant="warning" title={`الحد: ${row.threshold}`}>
                            منخفض
                        </Badge>
                    ) : null}
                    {!row.in_stock ? <Badge variant="destructive">نفد</Badge> : null}
                </div>
            ),
        },
        {
            key: 'p.low_stock_threshold',
            header: 'حد التنبيه',
            sortable: true,
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-sm text-muted-foreground" dir="ltr">
                    {row.threshold}
                </span>
            ),
        },
        {
            key: 'variants',
            header: 'المقاسات/الألوان',
            sortable: false,
            cell: (row) =>
                row.variants.length === 0 ? (
                    <span className="text-xs text-muted-foreground">لا يوجد</span>
                ) : (
                    <div className="space-y-1">
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() =>
                                setExpanded((current) =>
                                    current.includes(row.id) ? current.filter((id) => id !== row.id) : [...current, row.id],
                                )
                            }
                        >
                            {row.variants.length} {expanded.includes(row.id) ? '▲' : '▼'}
                        </Button>

                        {expanded.includes(row.id) ? (
                            <div className="space-y-1 rounded-md border border-border bg-muted/40 p-2">
                                {row.variants.map((variant) => (
                                    <div key={variant.id} className="flex items-center justify-between gap-3 text-xs">
                                        <span className="font-medium">
                                            {variant.label ?? `#${variant.id}`}
                                            {!variant.is_active ? <span className="text-muted-foreground"> (موقوف)</span> : null}
                                        </span>
                                        <span className="text-muted-foreground" dir="ltr">
                                            {variant.express} / {variant.market}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                open({
                                                    product_id: row.id,
                                                    wa_code: row.wa_code,
                                                    title: row.title,
                                                    variant_id: variant.id,
                                                    variant_label: variant.label,
                                                    express: variant.express,
                                                    market: variant.market,
                                                })
                                            }
                                        >
                                            تعديل
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        ) : null}
                    </div>
                ),
        },
    ];

    return (
        <ManageLayout
            title="المخزون"
            crumbs={[{ label: 'الرئيسية', href: '/manage' }, { label: 'المخزون' }]}
            actions={
                <div className="flex flex-wrap items-center gap-2">
                    <Button asChild variant="outline" size="sm">
                        <Link href="/manage/inventory/ledger">سجل الحركات</Link>
                    </Button>
                    <Button asChild variant="outline" size="sm">
                        <Link href="/manage/inventory/reconciliation">فحص المطابقة</Link>
                    </Button>
                </div>
            }
        >
            <div className="space-y-4">
                {low_count > 0 ? (
                    <Alert tone="warning" title={`${low_count} منتجًا تحت حد التنبيه`}>
                        كل منتج له حدّه الخاص، فالقائمة تحسب «منخفض» من عمود المنتج نفسه لا من رقم عام.
                    </Alert>
                ) : null}

                <DataTable
                    table={table}
                    columns={columns}
                    rowId={(row) => row.id}
                    searchPlaceholder="كود المنتج أو SKU…"
                    emptyTitle="لا توجد منتجات"
                    emptyDescription="جرِّب تعديل البحث أو التصفية."
                    rowActions={(row) =>
                        row.variants.length > 0 ? (
                            // The service would refuse this write, so the button refuses first and
                            // says why — a disabled control with a reason beats a red error.
                            <Button
                                variant="outline"
                                size="sm"
                                disabled
                                title="هذا المنتج له مقاسات/ألوان: يُعدَّل المخزون على مستوى المتغيّر، ورقم المنتج مشتقّ منها."
                            >
                                تعديل
                            </Button>
                        ) : (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    open({
                                        product_id: row.id,
                                        wa_code: row.wa_code,
                                        title: row.title,
                                        variant_id: null,
                                        variant_label: null,
                                        express: row.express,
                                        market: row.market,
                                    })
                                }
                            >
                                تعديل
                            </Button>
                        )
                    }
                    filters={(setFilter, current) => (
                        <>
                            <Select
                                aria-label="العرض"
                                value={current.view ?? ''}
                                onChange={(event) => setFilter('view', event.target.value || null)}
                            >
                                {filters.views.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                aria-label="المخزن"
                                value={current.bucket ?? ''}
                                onChange={(event) => setFilter('bucket', event.target.value || null)}
                            >
                                {filters.buckets.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        </>
                    )}
                />
            </div>

            <Dialog open={target !== null} onOpenChange={(open) => (open ? undefined : setTarget(null))}>
                <DialogContent title="تعديل المخزون" className="space-y-4">
                    {target === null ? null : (
                        <>
                            <p className="text-sm text-muted-foreground">
                                {target.title ?? target.wa_code}
                                {target.variant_label !== null ? ` — ${target.variant_label}` : ''}
                                <span className="mx-1">·</span>
                                <span dir="ltr">
                                    إكسبريس {target.express} / ماركت {target.market}
                                </span>
                            </p>

                            <div className="grid gap-4 sm:grid-cols-2">
                                <SelectField
                                    label="نوع التعديل"
                                    required
                                    value={mode}
                                    onChange={(value) => setMode(value === 'set' ? 'set' : 'adjust')}
                                    options={[
                                        { value: 'adjust', label: 'تغيير نسبي (+/‑)' },
                                        { value: 'set', label: 'تعيين رقم مطلق' },
                                    ]}
                                    hint={
                                        mode === 'adjust'
                                            ? 'مثال: 3 لوصول ثلاث قطع، أو ‑2 لخصم قطعتين.'
                                            : 'الرقم الذي على الرف الآن. تُحسب الحركة كفرق.'
                                    }
                                />
                                <SelectField
                                    label="المخزن"
                                    required
                                    value={bucket}
                                    onChange={(value) => setBucket(value === 'market' ? 'market' : 'express')}
                                    options={[
                                        { value: 'express', label: BUCKET_LABEL.express },
                                        { value: 'market', label: BUCKET_LABEL.market },
                                    ]}
                                    hint="لا يوجد افتراضي في الخادم: المخزنان رفّان حقيقيان."
                                />
                                <TextField
                                    label="الكمية"
                                    required
                                    type="number"
                                    dir="ltr"
                                    value={quantity}
                                    onChange={setQuantity}
                                    min={mode === 'set' ? 0 : undefined}
                                    step={1}
                                    error={errors.quantity ?? null}
                                />
                                <SelectField
                                    label="السبب"
                                    required
                                    value={reason}
                                    onChange={setReason}
                                    options={reasons}
                                    error={errors.reason ?? null}
                                    // `order`, `order_cancel`, `payment_failed` and `transform` are
                                    // machinery reasons and are deliberately absent from this list.
                                    hint="أسباب الطلبات والبناء الأولي تكتبها المنظومة وحدها."
                                />
                            </div>

                            <TextareaField
                                label="ملاحظة"
                                rows={2}
                                value={note}
                                onChange={setNote}
                                error={errors.note ?? null}
                                hint="تُحفظ مع الحركة في السجل. اكتب ما يفسّر الرقم لمن يقرأه بعد شهر."
                            />

                            {errors.variant_id ? <Alert tone="error">{errors.variant_id}</Alert> : null}

                            <div className="flex flex-wrap justify-end gap-2">
                                <Button type="button" variant="outline" onClick={() => setTarget(null)}>
                                    إلغاء
                                </Button>
                                <Button type="button" disabled={busy} onClick={submit}>
                                    سجِّل الحركة
                                </Button>
                            </div>

                            <p className="text-xs text-muted-foreground">
                                كل تعديل يمرّ عبر خدمة المخزون ويُسجَّل في السجل باسمك. لا توجد طريقة لتغيير رقم بدون سطر
                                في السجل.
                            </p>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </ManageLayout>
    );
}
