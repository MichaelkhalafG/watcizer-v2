import { usePage } from '@inertiajs/react';
import { Download } from 'lucide-react';

import { Button } from '@/components/ui/button';
import type { SharedProps } from '@/types';
import { useT } from '@/lib/i18n';

/**
 * The export button for a screen that is NOT on `DataTable`.
 *
 * Four screens ship a prepared list rather than a paginated query — the category tree, the lookup
 * lists, the unit cleanup, the dashboard grants. They get the identical file from the identical
 * writer (`App\Support\TableExport`), so they get the identical control; `DataTable` renders this
 * same thing itself from `meta.exportable`.
 *
 * A plain link, not a router visit: the response is a file, and Inertia expects a page. Taking the
 * current address verbatim is also what keeps the file matching the screen — whatever is in the
 * query string (which storefront, which list) is already the server's view of it.
 */
export function ExportLink({ count, label: labelProp }: { count?: number; label?: string }) {
    /*
     * Hidden for anybody who may not export (review 🔴-2). This is presentation only — the route
     * refuses server-side whether the button is drawn or not — but an affordance that always leads
     * to a refusal is worse than no affordance.
     *
     * `DataTable` does the same thing from `meta.exportable`, which the server computes the same
     * way; these four screens are the ones that are not on a paginated table.
     */
    const t = useT();
    /*
     * Resolved HERE and not as a default parameter: a default is evaluated before the component
     * runs, where a hook cannot be called — the Arabic would have been frozen beyond any locale.
     */
    const label = labelProp ?? t('common.export_csv', 'تصدير CSV');

    const { abilities } = usePage<SharedProps>().props;
    if (abilities['export-data'] !== true) {
        return null;
    }

    const href = (() => {
        if (typeof window === 'undefined') {
            return '';
        }
        const url = new URL(window.location.href);
        url.searchParams.set('export', 'csv');
        return `${url.pathname}${url.search}`;
    })();

    return (
        <Button asChild variant="outline" size="sm" className="gap-1.5">
            <a href={href} download title={count === undefined ? label : t('table.download_count', 'تنزيل :count سجل', { count })}>
                <Download className="h-3.5 w-3.5" />
                {label}
            </a>
        </Button>
    );
}
