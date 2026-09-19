import * as React from 'react';

import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Table shell, styled after the reference template's tables. The horizontal scroll lives on the
 * WRAPPER, so a wide table scrolls inside the card instead of pushing the whole page sideways —
 * which in RTL would otherwise scroll the shell the wrong way.
 *
 * ── A scroll nobody could find (second browser pass, item 9, 2026-09-19) ─────────────────────
 *
 * The wrapper was `overflow-x-auto scrollbar-thin`, and that was the whole affordance. Three
 * things made it useless:
 *
 *  • a 6 px thumb in `--border` is nearly invisible against the card it sits on;
 *  • on Windows and macOS an overlay scrollbar is not painted until something scrolls, so a
 *    table that has never been touched shows no bar at all — which is exactly when the reader
 *    needs to be told there is more;
 *  • in RTL `scrollLeft: 0` is the RIGHT edge, so the page opens on the columns nobody needs and
 *    the hidden ones sit past where the eye stops reading (measured: D-1).
 *
 * So the container now SAYS it scrolls. It measures its own overflow, and while there is more in
 * a direction it paints a soft edge on that side — the one cue that reads as "continues" at a
 * glance, in either writing direction, without occupying a row of its own.
 *
 * It is also focusable with a `role="region"` and a label, which is not decoration: a scrollable
 * box that cannot be reached from the keyboard is unreachable for anybody not using a mouse, and
 * arrow keys are the only way they have to see the rest.
 */
function Table({ className, ...props }: React.TableHTMLAttributes<HTMLTableElement>) {
    const t = useT();
    const box = React.useRef<HTMLDivElement>(null);
    const [edges, setEdges] = React.useState({ start: false, end: false });

    const measure = React.useCallback(() => {
        const node = box.current;
        if (node === null) {
            return;
        }
        /*
         * `scrollLeft` is NEGATIVE in a right-to-left box in every current browser, and was
         * positive-counting-down in older WebKit. Taking the absolute value and comparing against
         * the remaining width works in both directions without asking which one we are in — the
         * alternative is a `dir` check that silently breaks the day the shell is mirrored.
         */
        const offset = Math.abs(node.scrollLeft);
        const hidden = node.scrollWidth - node.clientWidth;

        setEdges({
            start: offset > 1,
            // 1 px of slack: fractional layout widths leave a permanent sub-pixel remainder that
            // would otherwise paint a "there is more" edge on a table with nothing more.
            end: hidden - offset > 1,
        });
    }, []);

    React.useEffect(() => {
        const node = box.current;
        if (node === null) {
            return;
        }
        measure();

        // Both, because the two things that change overflow are independent: the window resizing,
        // and the table's own content changing when a filter or a page lands.
        const observer = new ResizeObserver(measure);
        observer.observe(node);
        for (const child of Array.from(node.children)) {
            observer.observe(child);
        }

        return () => observer.disconnect();
    }, [measure]);

    return (
        <div className="relative">
            <div
                ref={box}
                onScroll={measure}
                // Reachable and scrollable from the keyboard when — and only when — there is
                // something to scroll to.
                tabIndex={edges.start || edges.end ? 0 : -1}
                role="region"
                aria-label={t('table.scroll_region', 'جدول قابل للتمرير أفقيًا')}
                className="w-full overflow-x-auto scrollbar-visible focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset"
            >
                <table className={cn('w-full caption-bottom border-collapse text-sm', className)} {...props} />
            </div>

            {/* Logical edges, so they land on the correct side in both directions. Pointer events
                off: the cue must never eat a click meant for the cell beneath it. */}
            {edges.start ? (
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-y-0 start-0 w-8 bg-gradient-to-r from-background to-transparent rtl:bg-gradient-to-l"
                />
            ) : null}
            {edges.end ? (
                <span
                    aria-hidden="true"
                    className="pointer-events-none absolute inset-y-0 end-0 w-8 bg-gradient-to-l from-background to-transparent rtl:bg-gradient-to-r"
                />
            ) : null}
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
