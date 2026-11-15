import { router } from '@inertiajs/react';
import { Check, RotateCcw } from 'lucide-react';

import ManageLayout from '@/layouts/ManageLayout';
import { DataTable, type Column } from '@/components/table/DataTable';
import { Ltr } from '@/components/ui/bidi';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select } from '@/components/ui/input';
import { useT } from '@/lib/i18n';
import type { TablePayload } from '@/types';

/**
 * Pages not found (SEO Phase 1, 2026-11-14): every address the shop answered "page not found" for, reported by the
 * shop's server — most asked first. "Search engines" is how many of the asks were Google and the other crawlers: a
 * path they keep asking for is a link somewhere (often an old one) worth a redirect. The team fixes the link or the
 * page and marks the row fixed; a fixed address asked for again comes back here on its own. Times are Cairo time.
 */

interface Row {
    id: number;
    path: string;
    hits: number;
    bot_hits: number;
    last_referer: string | null;
    first_seen_at: string | null;
    last_seen_at: string | null;
    fixed: boolean;
}

interface Props {
    storefront: { id: number; code: string; name: string };
    /** The shop's own address, so a path opens on the shop. */
    site: string;
    state: 'open' | 'fixed';
    open: number;
    table: TablePayload<Row>;
}

export default function NotFoundIndex({ storefront, site, state, open, table }: Props) {
    const t = useT();
    const mark = (row: Row, fixed: boolean) =>
        router.put(`/manage/storefronts/${storefront.id}/not-found/${row.id}`, { fixed }, { preserveScroll: true });

    const columns: Array<Column<Row>> = [
        {
            key: 'path',
            header: t('not_found.path', 'العنوان'),
            cell: (row) => (
                <a href={site + row.path} target="_blank" rel="noreferrer" className="font-mono text-xs break-all hover:underline" dir="ltr">
                    {row.path}
                </a>
            ),
        },
        { key: 'hits', header: t('not_found.hits', 'مرات الطلب'), cell: (row) => <Ltr>{row.hits.toLocaleString('en')}</Ltr> },
        {
            key: 'bot_hits',
            header: t('not_found.bot_hits', 'منها محركات البحث'),
            hideOnMobile: true,
            cell: (row) => (row.bot_hits > 0 ? <Badge variant="warning">{row.bot_hits.toLocaleString('en')}</Badge> : <span className="text-muted-foreground">0</span>),
        },
        {
            key: 'last_referer',
            header: t('not_found.referer', 'الصفحة التي أشارت إليه'),
            sortable: false,
            hideBelow: 'lg',
            cell: (row) =>
                row.last_referer === null ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    <span className="text-xs break-all" dir="ltr">
                        {row.last_referer}
                    </span>
                ),
        },
        { key: 'first_seen_at', header: t('not_found.first_seen', 'أول مرة'), hideBelow: 'xl', cell: (row) => <Ltr>{row.first_seen_at ?? '—'}</Ltr> },
        { key: 'last_seen_at', header: t('not_found.last_seen', 'آخر مرة'), cell: (row) => <Ltr>{row.last_seen_at ?? '—'}</Ltr> },
    ];

    return (
        <ManageLayout
            title={t('not_found.title', 'صفحات غير موجودة')}
            crumbs={[
                { label: t('common.home', 'الرئيسية'), href: '/manage' },
                { label: storefront.name },
                { label: t('not_found.title', 'صفحات غير موجودة') },
            ]}
        >
            <div className="mb-4 space-y-1">
                <h1 className="text-lg font-semibold">{t('not_found.title', 'صفحات غير موجودة')}</h1>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'not_found.notice',
                        'عناوين طلبها زوار أو محركات بحث ولم يجدها المتجر (:count عنواناً مفتوحاً). أصلح الرابط أو الصفحة ثم علّم العنوان كمُصلَح؛ إذا طُلب مرة أخرى يعود إلى القائمة.',
                        { count: open },
                    )}
                </p>
            </div>
            <DataTable
                table={table}
                columns={columns}
                rowId={(row) => row.id}
                searchPlaceholder={t('not_found.search_placeholder', 'ابحث بالعنوان أو الصفحة التي أشارت إليه…')}
                filters={(setFilter) => (
                    <Select
                        aria-label={t('not_found.filter_state', 'الحالة')}
                        className="w-40"
                        value={state}
                        onChange={(event) => setFilter('state', event.target.value)}
                    >
                        <option value="open">{t('not_found.state_open', 'مفتوحة')}</option>
                        <option value="fixed">{t('not_found.state_fixed', 'تم إصلاحها')}</option>
                    </Select>
                )}
                rowActions={(row) =>
                    row.fixed ? (
                        <Button variant="ghost" size="sm" onClick={() => mark(row, false)}>
                            <RotateCcw className="h-4 w-4" />
                            {t('not_found.reopen', 'إعادة فتح')}
                        </Button>
                    ) : (
                        <Button variant="ghost" size="sm" onClick={() => mark(row, true)}>
                            <Check className="h-4 w-4" />
                            {t('not_found.mark_fixed', 'تم إصلاحه')}
                        </Button>
                    )
                }
                emptyTitle={state === 'fixed' ? t('not_found.empty_fixed', 'لا توجد عناوين مُصلَحة') : t('not_found.empty_title', 'لا توجد صفحات غير موجودة')}
                emptyDescription={t('not_found.empty_description', 'يسجّل المتجر هنا كل عنوان يجيب عنه بـ«الصفحة غير موجودة».')}
            />
        </ManageLayout>
    );
}
