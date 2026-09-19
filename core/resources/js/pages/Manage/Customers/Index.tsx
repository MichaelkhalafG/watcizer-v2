import { router } from '@inertiajs/react';

import { DataTable, type Column } from '@/components/table/DataTable';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Ltr, Num } from '@/components/ui/bidi';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/input';
import ManageLayout from '@/layouts/ManageLayout';
import { useT } from '@/lib/i18n';
import type { TablePayload } from '@/types';

/**
 * Customers (wave 4D) — the people who buy.
 *
 * ── What this screen is for ─────────────────────────────────────────────────────────────────
 *
 * Somebody telephones and says a number. This is the screen that turns that number into their
 * orders. So the two search fields that matter are phone and e-mail, the phone is the first thing
 * on the row, and everything else answers "is this the person, and are they a regular?".
 *
 * ── The two honest caveats, on the screen ──────────────────────────────────────────────────
 *
 * It is READ-ONLY, because every table underneath belongs to the storefront and core may not write
 * one. And a "guest customer" is a GROUP of orders that share a phone number, not a record — the
 * database has no customer table, so the screen says so instead of implying a certainty it cannot
 * have.
 */

interface Customer {
    ckey: string;
    kind: string;
    kind_label: string;
    name: string;
    email: string | null;
    phone: string | null;
    orders_count: number;
    spent: string;
    ordered: string;
    last_order_at: string | null;
    joined_at: string | null;
    storefronts: string[];
    url: string;
}

interface Props {
    table: TablePayload<Customer>;
    storefronts: Array<{ value: string; label: string }>;
    grouping_notice: string;
    read_only_notice: string;
}

const money = new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

export default function CustomersIndex({ table, storefronts, grouping_notice, read_only_notice }: Props) {
    const t = useT();
    const columns: Array<Column<Customer>> = [
        {
            key: 'c.name',
            header: t('common.customer', 'العميل'),
            sortable: true,
            cell: (row) => (
                <div className="min-w-[12rem] space-y-0.5">
                    <div className="font-medium">{row.name}</div>
                    <div className="flex flex-wrap items-center gap-1.5">
                        <Badge variant={row.kind === 'registered' ? 'default' : 'neutral'}>{row.kind_label}</Badge>
                        {row.storefronts.map((name) => (
                            <span key={name} className="text-xs text-muted-foreground">
                                {name}
                            </span>
                        ))}
                    </div>
                </div>
            ),
        },
        {
            key: 'contact',
            header: t('customers.contact', 'التواصل'),
            cell: (row) => (
                <div className="space-y-0.5 text-sm">
                    {/* The phone first: it is what the person on the telephone reads out. */}
                    <div className="font-medium">
                        <Num>{row.phone ?? '—'}</Num>
                    </div>
                    <div className="text-xs text-muted-foreground">
                        <Ltr>{row.email ?? '—'}</Ltr>
                    </div>
                </div>
            ),
        },
        {
            key: 'c.orders_count',
            header: t('common.orders', 'الطلبات'),
            sortable: true,
            cell: (row) => <Num>{row.orders_count}</Num>,
        },
        {
            key: 'c.ordered',
            header: t('customers.ordered_total', 'إجمالي ما طلبه'),
            sortable: false,
            // Everything not cancelled: what the customer has committed to (D-23).
            cell: (row) => <Num>{money.format(Number(row.ordered))}</Num>,
        },
        {
            key: 'c.spent',
            header: t('customers.delivered_total', 'إجمالي ما استلمه'),
            sortable: true,
            /*
             * Delivered and completed only: money that was actually taken, not money that was
             * asked for. Named for what it is since 2026-09-19 — as `إجمالي المشتريات` beside an
             * order count it read as broken data on every row, because nothing in this database
             * has reached those two states yet (D-23).
             */
            cell: (row) => <Num>{money.format(Number(row.spent))}</Num>,
        },
        {
            key: 'c.last_order_at',
            header: t('customers.last_order', 'آخر طلب'),
            sortable: true,
            hideOnMobile: true,
            cell: (row) => <Num className="text-xs text-muted-foreground">{row.last_order_at?.slice(0, 10) ?? '—'}</Num>,
        },
        {
            key: 'c.joined_at',
            header: t('customers.first_seen', 'أول ظهور'),
            sortable: true,
            hideOnMobile: true,
            cell: (row) => <Num className="text-xs text-muted-foreground">{row.joined_at?.slice(0, 10) ?? '—'}</Num>,
        },
    ];

    return (
        <ManageLayout
            title={t('common.customers', 'العملاء')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('common.customers', 'العملاء') },
            ]}
        >
            <div className="space-y-4">
                <Alert tone="info" title={t('common.read_only', 'للقراءة فقط')}>
                    {read_only_notice} {grouping_notice}
                </Alert>

                <DataTable
                    table={table}
                    columns={columns}
                    rowId={(row) => row.ckey}
                    searchPlaceholder={t('customers.search_placeholder', 'ابحث برقم الهاتف أو البريد أو الاسم…')}
                    emptyTitle={t('customers.empty_title', 'لا يوجد عملاء')}
                    emptyDescription={t('customers.empty_description', 'جرِّب تعديل البحث أو عوامل التصفية.')}
                    filters={(setFilter, current) => (
                        <>
                            <Select
                                aria-label={t('common.type', 'النوع')}
                                className="w-full sm:w-48"
                                value={current.kind ?? ''}
                                onChange={(event) => setFilter('kind', event.target.value || null)}
                            >
                                <option value="">{t('common.all', 'الكل')}</option>
                                <option value="registered">{t('customers.registered', 'حساب مسجَّل')}</option>
                                <option value="guest">{t('common.guest', 'ضيف')}</option>
                            </Select>

                            <Select
                                aria-label={t('common.orders', 'الطلبات')}
                                className="w-full sm:w-48"
                                value={current.has_orders ?? ''}
                                onChange={(event) => setFilter('has_orders', event.target.value || null)}
                            >
                                <option value="">{t('customers.orders_any', 'بطلبات أو بدون')}</option>
                                <option value="1">{t('customers.has_orders', 'لديه طلبات')}</option>
                                <option value="0">{t('customers.no_orders', 'بلا طلبات')}</option>
                            </Select>

                            <Select
                                aria-label={t('common.storefront', 'المتجر')}
                                className="w-full sm:w-48"
                                value={current.storefront_id ?? ''}
                                onChange={(event) => setFilter('storefront_id', event.target.value || null)}
                            >
                                <option value="">{t('common.all_storefronts', 'كل المتاجر')}</option>
                                {storefronts.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </Select>
                        </>
                    )}
                    rowActions={(row) => (
                        <Button variant="outline" size="sm" onClick={() => router.visit(row.url)}>
                            {t('customers.profile', 'الملف')}
                        </Button>
                    )}
                />
            </div>
        </ManageLayout>
    );
}
