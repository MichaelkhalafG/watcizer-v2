import { Link } from '@inertiajs/react';

import { DataTable, type Column } from '@/components/table/DataTable';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input, Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import type { TablePayload } from '@/types';
import { Ltr } from '@/components/ui/bidi';

/**
 * The movement ledger (wave 4C) — READ-ONLY, for everybody including an administrator.
 *
 * There is no edit control on this page and no route behind one. A ledger you can edit reconciles
 * nothing: the whole value of `inventory:verify` is that the sum of the movements must equal the
 * column, and an edited history makes that equality meaningless. A mistake is corrected by posting
 * the OPPOSITE movement with a note — which is what the `adjustment` reason exists for, and what
 * an accountant would do with a wrong entry.
 *
 * `InventoryAuthorizationTest` asserts the absence of a write route rather than trusting this
 * paragraph: the screen not having a button is not the control.
 */

interface LedgerRow {
    id: number;
    product_id: number | null;
    wa_code: string | null;
    variant: string | null;
    bucket: string;
    delta: number;
    after: number;
    reason: string;
    reference: string | null;
    reference_id: number | null;
    actor: string | null;
    actor_id: number | null;
    storefront_id: number | null;
    note: string | null;
    external_ref: string | null;
    created_at: string | null;
}

interface Option {
    value: string;
    label: string;
}

interface Props {
    table: TablePayload<LedgerRow>;
    filters: { reasons: Option[]; buckets: Option[]; references: Option[] };
}

type Translate = ReturnType<typeof useT>;

/**
 * The ledger reasons in Arabic — the same vocabulary the other inventory screens use.
 *
 * A function of `t` rather than a constant map: a hook cannot run at module level, and these have
 * to be translated. `adjustment` keeps its own key because the ledger names it a stock-COUNT
 * correction, which is a longer phrase than the order screen's, and one key cannot hold both.
 */
const reasonLabels = (t: Translate): Record<string, string> => ({
    order: t('common.reason_order', 'حجز لطلب'),
    order_cancel: t('common.reason_order_cancel', 'إرجاع بعد إلغاء'),
    payment_failed: t('common.reason_payment_failed', 'إرجاع بعد فشل دفع'),
    restock: t('common.reason_restock', 'توريد'),
    manual: t('common.reason_manual', 'تعديل يدوي'),
    import: t('common.reason_import', 'استيراد'),
    adjustment: t('inventory.ledger_reason_adjustment', 'تسوية جرد'),
    erp_sync: t('common.reason_erp_sync', 'مزامنة ERP'),
    transform: t('common.reason_transform', 'بناء أولي'),
});

/** A release is the movement that proves a cancellation gave the stock back. */
const RELEASE_REASONS = ['order_cancel', 'payment_failed'];

const bucketLabels = (t: Translate): Record<string, string> => ({
    express: t('common.stock_express', 'إكسبريس'),
    market: t('common.stock_market', 'ماركت'),
});

export default function InventoryLedger({ table, filters }: Props) {
    const t = useT();

    const REASON_LABEL = reasonLabels(t);
    const BUCKET_LABEL = bucketLabels(t);

    const columns: Array<Column<LedgerRow>> = [
        {
            key: 'im.created_at',
            header: t('common.date', 'التاريخ'),
            sortable: true,
            cell: (row) => (
                <span className="text-xs" dir="ltr">
                    {row.created_at ?? '—'}
                </span>
            ),
        },
        {
            key: 'product',
            header: t('common.product', 'المنتج'),
            sortable: false,
            cell: (row) => (
                <div className="space-y-0.5">
                    <div className="text-xs font-medium">
                        <Ltr>{row.wa_code ?? `#${row.product_id ?? '—'}`}</Ltr>
                    </div>
                    {row.variant !== null ? <div className="text-xs text-muted-foreground">{row.variant}</div> : null}
                </div>
            ),
        },
        {
            key: 'im.bucket',
            header: t('inventory.bucket', 'المخزن'),
            sortable: false,
            cell: (row) => <span className="text-sm">{BUCKET_LABEL[row.bucket] ?? row.bucket}</span>,
        },
        {
            key: 'im.quantity_delta',
            header: t('inventory.ledger_delta', 'التغيير'),
            sortable: true,
            cell: (row) => (
                <span
                    className={row.delta > 0 ? 'font-medium text-emerald-700 dark:text-emerald-400' : 'font-medium text-destructive'}
                    dir="ltr"
                >
                    {row.delta > 0 ? `+${row.delta}` : row.delta}
                </span>
            ),
        },
        {
            key: 'after',
            header: t('inventory.ledger_balance_after', 'الرصيد بعدها'),
            sortable: false,
            cell: (row) => (
                <span dir="ltr">{row.after}</span>
            ),
        },
        {
            key: 'reason',
            header: t('common.reason', 'السبب'),
            sortable: false,
            cell: (row) => (
                <div className="space-y-0.5">
                    <Badge variant={RELEASE_REASONS.includes(row.reason) ? 'success' : 'outline'}>
                        {REASON_LABEL[row.reason] ?? row.reason}
                    </Badge>
                    {row.note !== null ? <div className="text-xs text-muted-foreground">{row.note}</div> : null}
                </div>
            ),
        },
        {
            key: 'reference',
            header: t('inventory.ledger_source', 'المصدر'),
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <div className="space-y-0.5 text-xs">
                    {row.reference === null ? (
                        <span className="text-muted-foreground">—</span>
                    ) : row.reference === 'orders' && row.reference_id !== null ? (
                        // The one link on the page: a movement caused by an order leads to that
                        // order, which is the question a surprising number actually raises.
                        <Link href={`/manage/orders/${row.reference_id}`} className="text-brand-strong hover:underline" dir="ltr">
                            order #{row.reference_id}
                        </Link>
                    ) : (
                        <span dir="ltr">
                            {row.reference}
                            {row.reference_id !== null ? ` #${row.reference_id}` : ''}
                        </span>
                    )}
                    {row.external_ref !== null ? (
                        <div className="text-muted-foreground">
                            <Ltr>{row.external_ref}</Ltr>
                        </div>
                    ) : null}
                </div>
            ),
        },
        {
            key: 'actor',
            header: t('inventory.ledger_actor', 'مَن'),
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <span className="text-xs text-muted-foreground" dir="ltr">
                    {row.actor ?? '—'}
                    {row.actor_id !== null ? ` #${row.actor_id}` : ''}
                </span>
            ),
        },
    ];

    return (
        <ManageLayout
            title={t('inventory.ledger_title', 'سجل حركات المخزون')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('common.inventory', 'المخزون'), href: '/manage/inventory' },
                { label: t('inventory.ledger_crumb', 'السجل') },
            ]}
            actions={
                <Button asChild variant="outline" size="sm">
                    <Link href="/manage/inventory/reconciliation">{t('common.reconciliation', 'فحص المطابقة')}</Link>
                </Button>
            }
        >
            <div className="space-y-4">
                <Alert tone="info" title={t('inventory.ledger_read_only_title', 'السجل للقراءة فقط')}>
                    {t(
                        'inventory.ledger_read_only_body',
                        'لا توجد شاشة ولا مسار يعدّل سطرًا هنا أو يحذفه. الخطأ يُصحَّح بتسجيل الحركة المضادة مع ملاحظة — وهكذا يظل مجموع الحركات مساويًا للرصيد، وهو أساس فحص المطابقة.',
                    )}
                </Alert>

                <DataTable
                    table={table}
                    columns={columns}
                    rowId={(row) => row.id}
                    emptyTitle={t('inventory.ledger_empty_title', 'لا توجد حركات')}
                    emptyDescription={t('inventory.ledger_empty_description', 'جرِّب تعديل التصفية أو نطاق التاريخ.')}
                    filters={(setFilter, current) => (
                        <>
                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('common.reason', 'السبب')}
                                value={current.reason ?? ''}
                                onChange={(event) => setFilter('reason', event.target.value || null)}
                            >
                                <option value="">{t('inventory.ledger_all_reasons', 'كل الأسباب')}</option>
                                {filters.reasons.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {REASON_LABEL[option.value] ?? option.label}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('inventory.bucket', 'المخزن')}
                                value={current.bucket ?? ''}
                                onChange={(event) => setFilter('bucket', event.target.value || null)}
                            >
                                {filters.buckets.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <Select
                                className="w-full sm:w-48"
                                aria-label={t('inventory.ledger_source', 'المصدر')}
                                value={current.reference_type ?? ''}
                                onChange={(event) => setFilter('reference_type', event.target.value || null)}
                            >
                                {filters.references.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                            <Input
                                type="number"
                                aria-label={t('common.product_number', 'رقم المنتج')}
                                className="w-[9rem]"
                                dir="ltr"
                                placeholder="product id"
                                value={current.product_id ?? ''}
                                onChange={(event) => setFilter('product_id', event.target.value || null)}
                            />
                            <Input
                                type="date"
                                aria-label={t('common.from_date', 'من تاريخ')}
                                className="w-[10rem]"
                                value={current.from ?? ''}
                                onChange={(event) => setFilter('from', event.target.value || null)}
                            />
                            <Input
                                type="date"
                                aria-label={t('common.to_date', 'إلى تاريخ')}
                                className="w-[10rem]"
                                value={current.to ?? ''}
                                onChange={(event) => setFilter('to', event.target.value || null)}
                            />
                        </>
                    )}
                />
            </div>
        </ManageLayout>
    );
}
