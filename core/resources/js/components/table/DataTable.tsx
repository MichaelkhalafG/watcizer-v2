import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, Inbox, RotateCcw, Search } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useMemo, useRef, useState } from 'react';

import { Alert } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { TablePayload } from '@/types';

export interface Column<Row> {
    /** Matches a `sortable` name from the server when the column can be sorted. */
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
    /** Rendered when a selection exists — the bulk bar. */
    bulkActions?: (selected: Array<string | number>, clear: () => void) => ReactNode;
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
    searchPlaceholder = 'بحث…',
    emptyTitle = 'لا توجد نتائج',
    emptyDescription = 'جرِّب تعديل البحث أو عوامل التصفية.',
    only,
    error = null,
}: DataTableProps<Row>) {
    const { data, meta } = table;
    const [term, setTerm] = useState(meta.search ?? '');
    const [busy, setBusy] = useState(false);
    const [selected, setSelected] = useState<Array<string | number>>([]);
    const debounce = useRef<number | undefined>(undefined);

    // The server is the source of truth for the search box: a back/forward navigation or a reset
    // must move the input, not fight it.
    useEffect(() => setTerm(meta.search ?? ''), [meta.search]);

    // A new page of rows is a new selection universe; keeping ids across pages would let a bulk
    // action hit rows nobody can see.
    useEffect(() => setSelected([]), [meta.page, meta.search, meta.sort, meta.direction]);

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

    const allOnPage = useMemo(() => data.map((row) => rowId(row)), [data, rowId]);
    const headerState: boolean | 'indeterminate' =
        selected.length === 0 ? false : selected.length === allOnPage.length ? true : 'indeterminate';

    const canSort = (column: Column<Row>) => column.sortable !== false && meta.sortable.includes(column.key);
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
                        placeholder={searchPlaceholder}
                        aria-label={searchPlaceholder}
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
                        إلغاء التصفية
                    </Button>
                ) : null}
                <span className="ms-auto text-xs text-muted-foreground" aria-live="polite">
                    {busy ? 'جارٍ التحميل…' : `${meta.total} سجل`}
                </span>
            </div>

            {error !== null ? (
                <div className="border-b p-4">
                    <Alert tone="error" title="تعذّر تحميل البيانات">
                        {error}
                    </Alert>
                </div>
            ) : null}

            {bulkActions && selected.length > 0 ? (
                <div className="flex flex-wrap items-center gap-3 border-b bg-brand-muted px-4 py-2.5 text-sm">
                    <span className="font-medium">{selected.length} محدد</span>
                    {bulkActions(selected, () => setSelected([]))}
                    <Button type="button" variant="ghost" size="sm" className="ms-auto" onClick={() => setSelected([])}>
                        إلغاء التحديد
                    </Button>
                </div>
            ) : null}

            <Table>
                <TableHeader>
                    <TableRow>
                        {bulkActions ? (
                            <TableHead className="w-10">
                                <Checkbox
                                    checked={headerState}
                                    onCheckedChange={(value) => setSelected(value === true ? allOnPage : [])}
                                    aria-label="تحديد كل الصفوف"
                                />
                            </TableHead>
                        ) : null}
                        {columns.map((column) => (
                            <TableHead
                                key={column.key}
                                className={cn(column.headerClassName, column.hideOnMobile && 'hidden sm:table-cell')}
                                aria-sort={meta.sort === column.key ? (meta.direction === 'asc' ? 'ascending' : 'descending') : undefined}
                            >
                                {canSort(column) ? (
                                    <button
                                        type="button"
                                        onClick={() => toggleSort(column.key)}
                                        className="inline-flex items-center gap-1 rounded transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {column.header}
                                        {meta.sort !== column.key ? (
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
                        {rowActions ? <TableHead className="w-16 text-end">إجراءات</TableHead> : null}
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
                                    <p className="font-medium">{emptyTitle}</p>
                                    <p className="max-w-sm text-sm text-muted-foreground">
                                        {filtered ? emptyDescription : 'لا توجد بيانات لعرضها بعد.'}
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
                                            aria-label="تحديد الصف"
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
                        {meta.from ?? 0}–{meta.to ?? 0} من {meta.total}
                    </span>
                    <div className="flex items-center gap-2">
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
                            السابق
                        </Button>
                        <span className="px-1 text-xs text-muted-foreground">
                            {meta.page} / {meta.last_page}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={meta.page >= meta.last_page}
                            onClick={() => visit({ page: meta.page + 1 })}
                            className="gap-1"
                        >
                            التالي
                            <ChevronLeft className="h-4 w-4 ltr:hidden" />
                            <ChevronRight className="h-4 w-4 rtl:hidden" />
                        </Button>
                    </div>
                </div>
            ) : null}
        </Card>
    );
}
