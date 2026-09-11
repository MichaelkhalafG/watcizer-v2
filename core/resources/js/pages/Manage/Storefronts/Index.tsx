import { Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';

import ManageLayout from '@/layouts/ManageLayout';
import { DataTable, type Column } from '@/components/table/DataTable';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/input';
import type { TablePayload } from '@/types';

interface StorefrontRow {
    id: number;
    code: string;
    name: string;
    domain: string | null;
    locales: string[];
    default_locale: string;
    currency: string;
    is_active: boolean;
    updated_at: string | null;
}

/**
 * The first screen built on the DataTable — and therefore the worked example of how every 4B/4C
 * screen will look: declare columns, hand over the server's `table` payload, done. No client-side
 * sorting, no local filtering, no page state.
 */
export default function StorefrontsIndex({ table, rebuild_warning }: { table: TablePayload<StorefrontRow>; rebuild_warning: string }) {
    const columns: Array<Column<StorefrontRow>> = [
        {
            key: 'code',
            header: 'الرمز',
            cell: (row) => (
                <span className="font-mono text-xs" dir="ltr">
                    {row.code}
                </span>
            ),
        },
        { key: 'name', header: 'الاسم', cell: (row) => <span className="font-medium">{row.name}</span> },
        {
            key: 'domain',
            header: 'النطاق',
            sortable: false,
            hideOnMobile: true,
            cell: (row) =>
                row.domain === null ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <span dir="ltr" className="text-xs">
                        {row.domain}
                    </span>
                ),
        },
        {
            key: 'locales',
            header: 'اللغات',
            sortable: false,
            hideOnMobile: true,
            cell: (row) => (
                <span className="flex flex-wrap gap-1">
                    {row.locales.map((locale) => (
                        <Badge key={locale} variant={locale === row.default_locale ? 'default' : 'neutral'}>
                            {locale}
                            {locale === row.default_locale ? ' ★' : ''}
                        </Badge>
                    ))}
                </span>
            ),
        },
        { key: 'currency', header: 'العملة', sortable: false, cell: (row) => <span dir="ltr">{row.currency}</span> },
        {
            key: 'is_active',
            header: 'الحالة',
            cell: (row) => (row.is_active ? <Badge variant="success">مفعّل</Badge> : <Badge variant="neutral">معطّل</Badge>),
        },
    ];

    return (
        <ManageLayout
            title="المتاجر"
            crumbs={[{ label: 'الرئيسية', href: '/manage' }, { label: 'المتاجر' }]}
        >
            {/* The switch-night finding, on the screen rather than only in the report. */}
            <Alert tone="warning" title="تنبيه قبل الاعتماد على هذه الإعدادات">
                {rebuild_warning}
            </Alert>

            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.id}
                searchPlaceholder="ابحث بالرمز أو الاسم أو النطاق…"
                filters={(setFilter, current) => (
                    <Select
                        aria-label="تصفية بالحالة"
                        className="w-40"
                        value={current.is_active ?? ''}
                        onChange={(event) => setFilter('is_active', event.target.value === '' ? null : event.target.value)}
                    >
                        <option value="">كل الحالات</option>
                        <option value="1">مفعّل</option>
                        <option value="0">معطّل</option>
                    </Select>
                )}
                rowActions={(row) => (
                    <Button asChild variant="ghost" size="icon" aria-label={`تعديل ${row.name}`}>
                        <Link href={`/manage/storefronts/${row.id}/edit`}>
                            <Pencil className="h-4 w-4" />
                        </Link>
                    </Button>
                )}
                emptyTitle="لا توجد متاجر"
                emptyDescription="يُنشئ المتجر الأول عن طريق التهيئة (StorefrontSeeder)."
            />
        </ManageLayout>
    );
}
