import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Table shell, styled after the reference template's tables. The horizontal scroll lives on the
 * WRAPPER, so a wide table scrolls inside the card instead of pushing the whole page sideways —
 * which in RTL would otherwise scroll the shell the wrong way.
 */
function Table({ className, ...props }: React.TableHTMLAttributes<HTMLTableElement>) {
    return (
        <div className="w-full overflow-x-auto scrollbar-thin">
            <table className={cn('w-full caption-bottom border-collapse text-sm', className)} {...props} />
        </div>
    );
}

function TableHeader({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cn('[&_tr]:border-b', className)} {...props} />;
}

function TableBody({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('[&_tr:last-child]:border-0', className)} {...props} />;
}

function TableRow({ className, ...props }: React.HTMLAttributes<HTMLTableRowElement>) {
    return (
        <tr
            className={cn('border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-brand-muted', className)}
            {...props}
        />
    );
}

/**
 * A cell NEVER takes a `dir` of its own.
 *
 * `text-align: start` resolves against the element's own direction, so a cell that sets
 * `dir="ltr"` to keep a SKU or a price in logical order silently aligns itself to the LEFT while
 * its header — which inherits the page's RTL — stays on the RIGHT. Every column written that way
 * stopped lining up with its heading (reported 2026-09-14 on the dashboard home table).
 *
 * Rather than forbid the mistake in review, the primitive absorbs it: a `dir` handed to a cell is
 * moved OFF the box and onto a `<bdi>` around the contents, which is where direction belonged.
 * The cell keeps the table's alignment; the text keeps its order. Screens that want this
 * explicitly should reach for {@see Ltr}/{@see Num} in `components/ui/bidi`.
 */
function isolate(dir: string | undefined, children: React.ReactNode): React.ReactNode {
    return dir === undefined ? children : <bdi dir={dir}>{children}</bdi>;
}

/** Where a column's contents sit. `start`/`end` follow the PAGE, never the cell. */
export type CellAlign = 'start' | 'end' | 'center';

const ALIGN: Record<CellAlign, string> = {
    start: 'text-start',
    end: 'text-end',
    center: 'text-center',
};

/*
 * `align` deliberately SHADOWS the deprecated HTML attribute of the same name: `align="right"` is
 * the physical-direction habit this primitive exists to replace, so the logical version takes the
 * name and the physical one stops being reachable.
 */
interface HeadProps extends Omit<React.ThHTMLAttributes<HTMLTableCellElement>, 'align'> {
    align?: CellAlign;
}

function TableHead({ className, align = 'start', dir, children, ...props }: HeadProps) {
    return (
        <th
            className={cn(
                'h-11 whitespace-nowrap px-4 align-middle text-xs font-semibold uppercase tracking-wide text-muted-foreground',
                ALIGN[align],
                className,
            )}
            {...props}
        >
            {isolate(dir, children)}
        </th>
    );
}

interface CellProps extends Omit<React.TdHTMLAttributes<HTMLTableCellElement>, 'align'> {
    align?: CellAlign;
}

function TableCell({ className, align = 'start', dir, children, ...props }: CellProps) {
    return (
        <td className={cn('px-4 py-3 align-middle', ALIGN[align], className)} {...props}>
            {isolate(dir, children)}
        </td>
    );
}

export { Table, TableBody, TableCell, TableHead, TableHeader, TableRow };
