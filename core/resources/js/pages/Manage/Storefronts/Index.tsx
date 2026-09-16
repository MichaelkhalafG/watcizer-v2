import { Link } from '@inertiajs/react';
import { Pencil } from 'lucide-react';

import ManageLayout from '@/layouts/ManageLayout';
import { DataTable, type Column } from '@/components/table/DataTable';
import { Alert } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
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
    const t = useT();
    const columns: Array<Column<StorefrontRow>> = [
        {
            key: 'code',
            header: t('storefronts.code', 'الرمز'),
            cell: (row) => (
                <span className="font-mono text-xs" dir="ltr">
                    {row.code}
                </span>
            ),
        },
        { key: 'name', header: t('common.name', 'الاسم'), cell: (row) => <span className="font-medium">{row.name}</span> },
        {
            key: 'domain',
            header: t('common.domain', 'النطاق'),
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
            header: t('storefronts.locales', 'اللغات'),
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
        { key: 'currency', header: t('common.currency', 'العملة'), sortable: false, cell: (row) => <span dir="ltr">{row.currency}</span> },
        {
            key: 'is_active',
            header: t('common.status', 'الحالة'),
            cell: (row) =>
                row.is_active ? (
                    <Badge variant="success">{t('common.active', 'مفعّل')}</Badge>
                ) : (
                    <Badge variant="neutral">{t('storefronts.inactive', 'معطّل')}</Badge>
                ),
        },
    ];

    return (
        <ManageLayout
            title={t('common.storefronts', 'المتاجر')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: t('common.storefronts', 'المتاجر') },
            ]}
        >
            {/* The switch-night finding, on the screen rather than only in the report. */}
            <Alert tone="warning" title={t('storefronts.rebuild_warning_title', 'تنبيه قبل الاعتماد على هذه الإعدادات')}>
                {rebuild_warning}
            </Alert>

            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.id}
                searchPlaceholder={t('storefronts.search_placeholder', 'ابحث بالرمز أو الاسم أو النطاق…')}
                filters={(setFilter, current) => (
                    <Select
                        aria-label={t('storefronts.filter_by_status', 'تصفية بالحالة')}
                        className="w-40"
                        value={current.is_active ?? ''}
                        onChange={(event) => setFilter('is_active', event.target.value === '' ? null : event.target.value)}
                    >
                        <option value="">{t('common.all_statuses', 'كل الحالات')}</option>
                        <option value="1">{t('common.active', 'مفعّل')}</option>
                        <option value="0">{t('storefronts.inactive', 'معطّل')}</option>
                    </Select>
                )}
                rowActions={(row) => (
                    <Button asChild variant="ghost" size="icon" aria-label={t('storefronts.row_edit', 'تعديل :name', { name: row.name })}>
                        <Link href={`/manage/storefronts/${row.id}/edit`}>
                            <Pencil className="h-4 w-4" />
                        </Link>
                    </Button>
                )}
                emptyTitle={t('storefronts.empty_title', 'لا توجد متاجر')}
                emptyDescription={t('storefronts.empty_description', 'يُنشئ المتجر الأول عن طريق التهيئة (StorefrontSeeder).')}
            />
        </ManageLayout>
    );
}
