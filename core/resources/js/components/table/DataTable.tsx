import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
    Download,
    Inbox,
    RotateCcw,
    Search,
} from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { TablePayload } from '@/types';

/**
 * `p.wa_code` → `wa_code` — the same strip the server does in `TableQuery::publicSortName()`.
 *
 * A screen declares its columns in SQL, because that is what the query orders by; the URL should
 * not. `?sort=p.id` published this application's join aliases as a public interface — meaningless
 * to whoever pastes the link, and impossible to rename later (D-18). One function on each side,
 * agreeing on one rule, so no screen had to be edited.
 */
function sortName(key: string): string {
    const at = key.lastIndexOf('.');

    return at === -1 ? key : key.slice(at + 1);
}

/**
 * What a bulk action is being asked to act on.
 *
 * Two shapes, and the caller must handle both: the ids it can see, or "everything matching what is
 * on screen", which is a QUERY rather than a list. 7,578 ids do not fit in a request, would be
 * stale by the time they arrived, and would blow every id cap on the server.
 */
export interface BulkScope {
    /** True when the operator chose "select all N matching" rather than ticking rows. */
    matching: boolean;
    /** How many rows the action will touch — the page's selection, or the whole matching set. */
    count: number;
    /** The screen's query string, for the server to re-resolve. Empty unless `matching`. */
    query: string;
}

export interface Column<Row> {
    /**
     * The column, as the screen's query names it — `p.wa_code` or plain `wa_code`. Sorting uses
     * the part after the last dot, which is what the server whitelists and what the URL carries.
     */
    key: string;
    header: string;
    cell: (row: Row) => ReactNode;
    /** Sortable only when the SERVER said so; this flag just asks. */
    sortable?: boolean;
    className?: string;
    headerClassName?: string;
    /** Hide below `sm`, for the tablet layout. */
    hideOnMobile?: boolean;
}

export interface DataTableProps<Row> {
    table: TablePayload<Row>;
    columns: Array<Column<Row>>;
    /** Stable identity per row — needed by selection and by React keys. */
    rowId: (row: Row) => string | number;
    /** Right-hand cell of each row: buttons, a dropdown, whatever the screen needs. */
    rowActions?: (row: Row) => ReactNode;
    /**
     * Rendered above the table, next to the search box. A render function rather than a node,
     * because a filter control needs the setter and the current value — and must not have to know
     * that the state lives in the query string.
     */
    filters?: (setFilter: (key: string, value: string | null) => void, current: Record<string, string | null>) => ReactNode;
    /**
     * Rendered when a selection exists — the bulk bar.
     *
     * `scope` is W-3: the header checkbox only ever selected the rows ON SCREEN, so every bulk
     * action was capped at one page per round trip. When the operator asks for "all N matching",
     * `scope.matching` is true and `selected` is NOT the answer — the caller must post
     * `scope.query` and let the server re-resolve the set. See `ProductController::idsMatching()`.
     */
    bulkActions?: (selected: Array<string | number>, clear: () => void, scope: BulkScope) => ReactNode;
    searchPlaceholder?: string;
    emptyTitle?: string;
    emptyDescription?: string;
    /** Extra props to preserve across table navigations (a parent tab, say). */
    only?: string[];
    /**
     * A load failure the SCREEN caught (a failed partial reload, a rejected filter). The table
     * keeps rendering the rows it already has underneath, because a stale list the team can still
     * read beats an empty page.
     */
    error?: string | null;
}

/**
 * The dashboard's one table.
 *
 * It owns the URL, not local state: sorting, searching, filtering and paging all issue an Inertia
 * `router.get` with `preserveState`, so the address bar is always the table's state. That is what
 * makes a screen shareable ("here is the out-of-stock list"), reloadable and back-button-correct —
 * and it is why the server does the work (see App\Support\Table\TableQuery).
 *
 * Every future screen composes this; nothing about products, orders or offers is known here.
 */
export function DataTable<Row>({
    table,
    columns,
    rowId,
    rowActions,
    filters,
    bulkActions,
    searchPlaceholder,
    emptyTitle,
    emptyDescription,
    only,
    error = null,
}: DataTableProps<Row>) {
    const t = useT();
    /*
     * Resolved in the body, never as default parameters: a default is evaluated before the
     * component runs, where `useT()` is not a legal call.
     */
    const searchText = searchPlaceholder ?? t('table.search_placeholder', 'بحث…');
    const emptyHeading = emptyTitle ?? t('table.empty_title', 'لا توجد نتائج');
    const emptyBody = emptyDescription ?? t('table.empty_description', 'جرِّب تعديل البحث أو عوامل التصفية.');

    const { data, meta } = table;
    const [term, setTerm] = useState(meta.search ?? '');
    const [busy, setBusy] = useState(false);
    const [selected, setSelected] = useState<Array<string | number>>([]);
    /*
     * "Every row matching the filters", not "the 25 on this page" (W-3).
     *
     * Kept as a separate flag rather than by stuffing 7,578 ids into `selected`: the ids are not
     * knowable on the client, they would be stale the moment anybody else saved, and the server
     * re-resolving the screen's own query string is both smaller and more truthful.
     */
    const [allMatching, setAllMatching] = useState(false);
    const debounce = useRef<number | undefined>(undefined);
    // The page-jump box. Local until submitted, so typing `20` on the way to `200` does not
    // navigate twice — and reset from the server below, so Back and Forward move it too.
    const [jump, setJump] = useState(String(meta.page));

    // The server is the source of truth for the search box: a back/forward navigation or a reset
    // must move the input, not fight it.
    useEffect(() => setTerm(meta.search ?? ''), [meta.search]);
    useEffect(() => setJump(String(meta.page)), [meta.page]);

    // A new page of rows is a new selection universe; keeping ids across pages would let a bulk
    // action hit rows nobody can see.
    useEffect(() => {
        setSelected([]);
        setAllMatching(false);
    }, [meta.page, meta.search, meta.sort, meta.direction]);

    // A filter change is a new universe too — and unlike a page change it changes WHICH rows
    // "all matching" means, which is the one thing that must never go stale.
    const filterSignature = JSON.stringify(meta.filters);
    useEffect(() => {
        setSelected([]);
        setAllMatching(false);
    }, [filterSignature]);

    const visit = useCallback(
        (params: Record<string, string | number | null>) => {
            // The table's whole state lives in the query string. Start from what the SERVER just
            // told us it applied, overlay the change, drop empties, navigate.
            const base: Record<string, string | number | null> = {
                q: meta.search,
                sort: meta.sort,
                direction: meta.direction,
                per_page: meta.per_page,
                page: meta.page,
            };
            for (const [key, value] of Object.entries(meta.filters)) {
                base[`filters[${key}]`] = value;
            }

            const query: Record<string, string | number> = {};
            for (const [key, value] of Object.entries({ ...base, ...params })) {
                if (value !== null && value !== '') {
                    query[key] = value;
                }
            }

            router.get(window.location.pathname, query, {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            });
        },
        [meta, only],
    );

    /** Handed to the `filters` slot so a screen can change one filter without knowing the URL shape. */
    const setFilter = useCallback(
        (key: string, value: string | null) => visit({ [`filters[${key}]`]: value, page: 1 }),
        [visit],
    );

    const onSearch = useCallback(
        (value: string) => {
            setTerm(value);
            window.clearTimeout(debounce.current);
            // 350 ms: fast enough to feel live, slow enough that typing a SKU is one query.
            debounce.current = window.setTimeout(() => visit({ q: value === '' ? null : value, page: 1 }), 350);
        },
        [visit],
    );

    const toggleSort = useCallback(
        (key: string) => {
            const same = meta.sort === key;
            visit({ sort: key, direction: same && meta.direction === 'asc' ? 'desc' : 'asc', page: 1 });
        },
        [meta.direction, meta.sort, visit],
    );

    /*
     * The export is THIS url plus `export=csv`, which is why it is a plain link and not a router
     * visit: Inertia expects a page back, and this response is a file. Taking the address bar
     * verbatim is also what makes the file match the screen — every filter, the search and the
     * sort are already in it, including any the table does not know about because the screen put
     * them there itself.
     */
    const exportHref = useMemo(() => {
        if (typeof window === 'undefined') {
            return '';
        }
        const url = new URL(window.location.href);
        url.searchParams.set('export', 'csv');
        url.searchParams.delete('page');
        return `${url.pathname}${url.search}`;
    }, [meta]);

    const allOnPage = useMemo(() => data.map((row) => rowId(row)), [data, rowId]);
    const clearSelection = useCallback(() => {
        setSelected([]);
        setAllMatching(false);
    }, []);
    const headerState: boolean | 'indeterminate' =
        selected.length === 0 ? false : selected.length === allOnPage.length ? true : 'indeterminate';

    const canSort = (column: Column<Row>) => column.sortable !== false && meta.sortable.includes(sortName(column.key));
    const columnCount = columns.length + (bulkActions ? 1 : 0) + (rowActions ? 1 : 0);
    const filtered = meta.search !== null || Object.values(meta.filters).some((value) => value !== null);

    return (
        <Card className="overflow-hidden">
            <div className="flex flex-wrap items-center gap-3 border-b p-4">
                <div className="relative min-w-[14rem] flex-1">
                    <Search className="pointer-events-none absolute inset-y-0 start-3 my-auto h-4 w-4 text-muted-foreground" aria-hidden="true" />
                    <Input
                        type="search"
                        value={term}
                        onChange={(event) => onSearch(event.target.value)}
                        placeholder={searchText}
                        aria-label={searchText}
                        className="ps-9"
                    />
                </div>
                {filters?.(setFilter, meta.filters)}
                {filtered ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            const cleared: Record<string, string | number | null> = { q: null, page: 1 };
                            for (const key of Object.keys(meta.filters)) {
                                cleared[`filters[${key}]`] = null;
                            }
                            visit(cleared);
                        }}
                        className="gap-1.5"
                    >
                        <RotateCcw className="h-3.5 w-3.5" />
                        {t('table.clear_filters', 'إلغاء التصفية')}
                    </Button>
                ) : null}
                <span className="ms-auto text-xs text-muted-foreground" aria-live="polite">
                    {busy ? t('table.loading', 'جارٍ التحميل…') : t('table.row_count', ':count سجل', { count: meta.total })}
                </span>
                {meta.exportable ? (
                    <Button asChild variant="outline" size="sm" className="gap-1.5">
                        {/* Downloads what is on screen — the filters, the search, the sort — and
                            says how many rows that is, so nobody wonders whether they got the
                            filtered set or the whole table. */}
                        <a href={exportHref} download title={t('table.download_filtered', 'تنزيل :count سجل بعوامل التصفية الحالية', { count: meta.total })}>
                            <Download className="h-3.5 w-3.5" />
                            {t('common.export_csv', 'تصدير CSV')}
                        </a>
                    </Button>
                ) : null}
            </div>

            {error !== null ? (
                <div className="border-b p-4">
                    <Alert tone="error" title={t('table.load_failed', 'تعذّر تحميل البيانات')}>
                        {error}
                    </Alert>
                </div>
            ) : null}

            {bulkActions && selected.length > 0 ? (
                <div className="border-b bg-brand-muted px-4 py-2.5 text-sm">
                    <div className="flex flex-wrap items-center gap-3">
                        {/* The bar states plainly how many rows the action will touch (W-3). It
                            used to say "25 محدد" whether the operator meant 25 or 627, which is
                            the number that matters and the one they could not see. */}
                        <span className="font-medium">
                            {allMatching
                                ? t('table.selected_all_matching', 'كل :count سجل مطابق للتصفية', { count: meta.total })
                                : t('table.selected_count', ':count محدد', { count: selected.length })}
                        </span>
                        {bulkActions(selected, clearSelection, {
                            matching: allMatching,
                            count: allMatching ? meta.total : selected.length,
                            query: allMatching && typeof window !== 'undefined' ? window.location.search : '',
                        })}
                        <Button type="button" variant="ghost" size="sm" className="ms-auto" onClick={clearSelection}>
                            {t('table.clear_selection', 'إلغاء التحديد')}
                        </Button>
                    </div>

                    {/* Offered only when there is genuinely more to select: every row on the page
                        is ticked AND the filtered set is bigger than the page. Without this the
                        header checkbox capped every bulk action at one page per round trip. */}
                    {selected.length === allOnPage.length && meta.total > allOnPage.length ? (
                        <p className="mt-1.5 text-xs">
                            {allMatching ? (
                                <button
                                    type="button"
                                    className="underline underline-offset-2"
                                    onClick={() => setAllMatching(false)}
                                >
                                    {t('table.select_page_only', 'اقتصر على هذه الصفحة (:count)', { count: allOnPage.length })}
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    className="underline underline-offset-2"
                                    onClick={() => setAllMatching(true)}
                                >
                                    {t('table.select_all_matching', 'حدِّد كل :count سجل مطابق للتصفية', { count: meta.total })}
                                </button>
                            )}
                        </p>
                    ) : null}
                </div>
            ) : null}

            <Table>
                <TableHeader>
                    <TableRow>
                        {bulkActions ? (
                            <TableHead className="w-10">
                                <Checkbox
                                    checked={headerState}
                                    onCheckedChange={(value) => {
                                        setSelected(value === true ? allOnPage : []);
                                        // Unticking the header cancels "all matching" too — the
                                        // one control that clears a selection must clear all of it.
                                        if (value !== true) {
                                            setAllMatching(false);
                                        }
                                    }}
                                    aria-label={t('table.select_all_rows', 'تحديد كل الصفوف')}
                                />
                            </TableHead>
                        ) : null}
                        {columns.map((column) => (
                            <TableHead
                                key={column.key}
                                className={cn(column.headerClassName, column.hideOnMobile && 'hidden sm:table-cell')}
                                aria-sort={
                                    meta.sort === sortName(column.key)
                                        ? meta.direction === 'asc'
                                            ? 'ascending'
                                            : 'descending'
                                        : undefined
                                }
                            >
                                {canSort(column) ? (
                                    <button
                                        type="button"
                                        onClick={() => toggleSort(sortName(column.key))}
                                        className="inline-flex items-center gap-1 rounded transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {column.header}
                                        {meta.sort !== sortName(column.key) ? (
                                            <ArrowUpDown className="h-3 w-3 opacity-50" aria-hidden="true" />
                                        ) : meta.direction === 'asc' ? (
                                            <ArrowUp className="h-3 w-3" aria-hidden="true" />
                                        ) : (
                                            <ArrowDown className="h-3 w-3" aria-hidden="true" />
                                        )}
                                    </button>
                                ) : (
                                    column.header
                                )}
                            </TableHead>
                        ))}
                        {rowActions ? <TableHead className="w-16 text-end">{t('common.actions', 'إجراءات')}</TableHead> : null}
                    </TableRow>
                </TableHeader>

                <TableBody>
                    {busy && data.length === 0
                        ? Array.from({ length: 5 }).map((_, index) => (
                              <TableRow key={`skeleton-${index}`}>
                                  <TableCell colSpan={columnCount}>
                                      <Skeleton className="h-5 w-full" />
                                  </TableCell>
                              </TableRow>
                          ))
                        : null}

                    {!busy && data.length === 0 ? (
                        <TableRow>
                            <TableCell colSpan={columnCount}>
                                <div className="flex flex-col items-center gap-2 py-10 text-center">
                                    <Inbox className="h-8 w-8 text-muted-foreground" aria-hidden="true" />
                                    <p className="font-medium">{emptyHeading}</p>
                                    <p className="max-w-sm text-sm text-muted-foreground">
                                        {filtered ? emptyBody : t('table.no_data_yet', 'لا توجد بيانات لعرضها بعد.')}
                                    </p>
                                </div>
                            </TableCell>
                        </TableRow>
                    ) : null}

                    {data.map((row) => {
                        const id = rowId(row);
                        const isSelected = selected.includes(id);

                        return (
                            <TableRow key={id} data-state={isSelected ? 'selected' : undefined}>
                                {bulkActions ? (
                                    <TableCell>
                                        <Checkbox
                                            checked={isSelected}
                                            onCheckedChange={(value) =>
                                                setSelected((current) => (value === true ? [...current, id] : current.filter((item) => item !== id)))
                                            }
                                            aria-label={t('table.select_row', 'تحديد الصف')}
                                        />
                                    </TableCell>
                                ) : null}
                                {columns.map((column) => (
                                    <TableCell key={column.key} className={cn(column.className, column.hideOnMobile && 'hidden sm:table-cell')}>
                                        {column.cell(row)}
                                    </TableCell>
                                ))}
                                {rowActions ? <TableCell className="text-end">{rowActions(row)}</TableCell> : null}
                            </TableRow>
                        );
                    })}
                </TableBody>
            </Table>

            {meta.last_page > 1 ? (
                <div className="flex flex-wrap items-center justify-between gap-3 border-t px-4 py-3 text-sm">
                    <span className="text-muted-foreground">
                        {t('table.range', ':from–:to من :total', {
                            from: meta.from ?? 0,
                            to: meta.to ?? 0,
                            total: meta.total,
                        })}
                    </span>
                    {/* ── 309 pages used to be previous, next, and nothing else (§2.3, W-4) ──

                        7,713 products at 25 a page. Page 200 was 199 clicks; there was no way to
                        go to the end, and no way to make the page bigger. Every one of these
                        controls lives here rather than on a screen, so every table in the
                        dashboard gained them at once — which is the reason this component exists.

                        `per_page` is capped SERVER-side (`TableQuery::perPage()`), so the options
                        below are an offer, not a promise, and a hand-edited URL cannot ask for
                        50,000 rows. */}
                    <div className="flex flex-wrap items-center gap-2">
                        <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
                            {t('table.per_page', 'لكل صفحة')}
                            <select
                                className="h-8 rounded-md border border-input bg-background px-2 text-sm"
                                value={meta.per_page}
                                onChange={(event) => visit({ per_page: Number(event.target.value), page: 1 })}
                                aria-label={t('table.per_page', 'لكل صفحة')}
                            >
                                {/* The server's own value is always among the options, so a screen
                                    with a lower cap than 100 never shows a select whose displayed
                                    value is not in its list. */}
                                {Array.from(new Set([25, 50, 100, meta.per_page]))
                                    .sort((a, b) => a - b)
                                    .map((size) => (
                                        <option key={size} value={size}>
                                            {size}
                                        </option>
                                    ))}
                            </select>
                        </label>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={meta.page <= 1}
                            onClick={() => visit({ page: 1 })}
                            aria-label={t('table.first_page', 'الصفحة الأولى')}
                            title={t('table.first_page', 'الصفحة الأولى')}
                            className="px-2"
                        >
                            <ChevronsRight className="h-4 w-4 ltr:hidden" />
                            <ChevronsLeft className="h-4 w-4 rtl:hidden" />
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={meta.page <= 1}
                            onClick={() => visit({ page: meta.page - 1 })}
                            className="gap-1"
                        >
                            {/* Chevrons mirror in RTL: "previous" points toward the start edge. */}
                            <ChevronRight className="h-4 w-4 ltr:hidden" />
                            <ChevronLeft className="h-4 w-4 rtl:hidden" />
                            {t('table.previous', 'السابق')}
                        </Button>

                        {/* A page JUMP, not a label. Typing 200 and pressing Enter is the whole
                            point; the form wrapper is what makes Enter work without a button. */}
                        <form
                            className="flex items-center gap-1 px-1 text-xs text-muted-foreground"
                            onSubmit={(event) => {
                                event.preventDefault();
                                const wanted = Number(jump);
                                if (Number.isFinite(wanted) && wanted >= 1 && wanted <= meta.last_page) {
                                    visit({ page: Math.trunc(wanted) });
                                }
                            }}
                        >
                            <input
                                type="number"
                                min={1}
                                max={meta.last_page}
                                value={jump}
                                onChange={(event) => setJump(event.target.value)}
                                aria-label={t('table.go_to_page', 'اذهب إلى صفحة رقم')}
                                className="h-8 w-16 rounded-md border border-input bg-background px-2 text-center text-sm tabular-nums"
                                dir="ltr"
                            />
                            <span>/</span>
                            <span className="tabular-nums" dir="ltr">
                                {meta.last_page}
                            </span>
                        </form>

                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={meta.page >= meta.last_page}
                            onClick={() => visit({ page: meta.page + 1 })}
                            className="gap-1"
                        >
                            {t('table.next', 'التالي')}
                            <ChevronLeft className="h-4 w-4 ltr:hidden" />
                            <ChevronRight className="h-4 w-4 rtl:hidden" />
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={meta.page >= meta.last_page}
                            onClick={() => visit({ page: meta.last_page })}
                            aria-label={t('table.last_page', 'الصفحة الأخيرة')}
                            title={t('table.last_page', 'الصفحة الأخيرة')}
                            className="px-2"
                        >
                            <ChevronsLeft className="h-4 w-4 ltr:hidden" />
                            <ChevronsRight className="h-4 w-4 rtl:hidden" />
                        </Button>
                    </div>
                </div>
            ) : null}
        </Card>
    );
}
