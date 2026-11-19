import * as React from 'react';

import { useT } from '@/lib/i18n';
import { DRAG_THRESHOLD, NO_DRAG_SELECTOR, dragScroll, headerShift, keyScroll, pinCount, pinOffsets, showsBottomBar, startsDrag } from '@/lib/tableScroll';
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
    const bar = React.useRef<HTMLDivElement>(null);
    const [edges, setEdges] = React.useState({ start: false, end: false });
    const [dragging, setDragging] = React.useState(false);
    const [barBox, setBarBox] = React.useState<{ left: number; width: number; inner: number } | null>(null);
    const drag = React.useRef<{ x: number; y: number; scroll: number; id: number; moved: boolean } | null>(null);
    const barDriving = React.useRef(false);
    const barIdle = React.useRef(0);

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

    /*
     * ── Moving a wide table sideways from anywhere (2026-11-19, the team's report on Catalogue > Brands: "the
     *    scrollbar sits under the last row, so we scroll all the way down to move sideways") ──────────────────────
     *
     * Once, here, so every table of the dashboard has it:
     *  • the header row follows the page under the app's top bar, and the first column (and the second after a narrow
     *    # or checkbox column) stays pinned at the start side, so the reader knows which row and column they are on;
     *  • a scrollbar pinned to the bottom of the screen while the table is on screen and its own bar is below the fold,
     *    kept in step with the table (fixed, not sticky: some screens wrap their table in a card that clips);
     *  • press and drag with the mouse to move it (never on a control, never inside selected text; Alt+drag selects);
     *  • Left / Right / Home / End when the table has focus (lib/tableScroll.ts holds the arithmetic and its tests).
     * Shift + wheel and the trackpad are the browser's own and unchanged.
     */
    const layout = React.useCallback(() => {
        const node = box.current;
        const table = node?.querySelector(':scope > table') as HTMLTableElement | null;
        if (node === null || table === null) {
            return;
        }
        const overflowing = node.scrollWidth - node.clientWidth > 1;
        // the pinned leading columns: by the header row's widths
        const headRow = table.tHead?.rows[0] ?? table.rows[0];
        const widths = headRow ? Array.from(headRow.cells).map((c) => c.getBoundingClientRect().width) : [];
        const count = overflowing ? pinCount(widths) : 0;
        const offsets = pinOffsets(widths, count);
        for (const row of Array.from(table.rows)) {
            Array.from(row.cells).forEach((cell, index) => {
                const pinned = index < count && cell.colSpan === 1;
                const el = cell as HTMLElement;
                if (pinned) {
                    el.style.position = 'sticky';
                    el.style.insetInlineStart = `${offsets[index]}px`;
                    el.style.zIndex = row.parentElement?.tagName === 'THEAD' ? '3' : '1';
                    el.dataset.pinned = 'true';
                } else if (el.dataset.pinned) {
                    el.style.position = '';
                    el.style.insetInlineStart = '';
                    el.style.zIndex = '';
                    delete el.dataset.pinned;
                }
            });
        }

        // the header row under the app's top bar
        const rect = table.getBoundingClientRect();
        const head = table.tHead;
        if (head) {
            const topBar = document.querySelector('header.sticky');
            const barBottom = topBar ? Math.max(0, topBar.getBoundingClientRect().bottom) : 0;
            const shift = headerShift(rect.top, rect.height, head.getBoundingClientRect().height, barBottom);
            for (const cell of Array.from(head.querySelectorAll('th'))) {
                const el = cell as HTMLElement;
                el.style.transform = shift > 0 ? `translateY(${shift}px)` : '';
                el.dataset.lifted = shift > 0 ? 'true' : 'false';
                if (shift > 0 && el.style.position !== 'sticky') {
                    el.style.position = 'relative';
                    el.style.zIndex = '2';
                }
            }
        }

        // the bottom scrollbar
        const boxRect = node.getBoundingClientRect();
        if (showsBottomBar(overflowing, boxRect.top, boxRect.bottom, window.innerHeight)) {
            setBarBox((prev) =>
                prev && prev.left === boxRect.left && prev.width === boxRect.width && prev.inner === node.scrollWidth
                    ? prev
                    : { left: boxRect.left, width: boxRect.width, inner: node.scrollWidth },
            );
        } else {
            setBarBox(null);
        }
    }, []);

    React.useEffect(() => {
        const node = box.current;
        if (node === null) {
            return;
        }
        measure();
        layout();

        // Both, because the two things that change overflow are independent: the window resizing,
        // and the table's own content changing when a filter or a page lands.
        const observer = new ResizeObserver(() => {
            measure();
            layout();
        });
        observer.observe(node);
        for (const child of Array.from(node.children)) {
            observer.observe(child);
        }
        const rows = new MutationObserver(() => layout());
        rows.observe(node, { childList: true, subtree: true });
        let frame = 0;
        const onPageScroll = () => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(layout);
        };
        window.addEventListener('scroll', onPageScroll, { capture: true, passive: true });
        window.addEventListener('resize', onPageScroll, { passive: true });

        return () => {
            observer.disconnect();
            rows.disconnect();
            cancelAnimationFrame(frame);
            window.removeEventListener('scroll', onPageScroll, { capture: true });
            window.removeEventListener('resize', onPageScroll);
        };
    }, [measure, layout]);

    // The table and the bottom scrollbar move together, ONE WAY AT A TIME: the bar mirrors the table, and the table
    // follows the bar only while someone is using the bar (pressing, wheeling or touching it). Scroll events arrive
    // after the move that caused them, so a two-way echo pulled the table back mid-drag, mid-key and at the end of a
    // smooth Home or End (measured 2026-11-19).
    const onBoxScroll = () => {
        measure();
        const node = box.current;
        if (bar.current !== null && node !== null && !barDriving.current) {
            bar.current.scrollLeft = node.scrollLeft;
        }
    };
    const onBarScroll = () => {
        const node = box.current;
        if (bar.current !== null && node !== null && barDriving.current) {
            node.scrollLeft = bar.current.scrollLeft;
        }
    };
    const driveFromBar = () => {
        barDriving.current = true;
        window.clearTimeout(barIdle.current);
        // a wheel or a flick ends without a pointer-up: the bar lets go once it has been still for a moment
        barIdle.current = window.setTimeout(() => {
            barDriving.current = false;
        }, 400);
    };
    React.useLayoutEffect(() => {
        if (bar.current && box.current) {
            bar.current.scrollLeft = box.current.scrollLeft;
        }
    }, [barBox]);

    const scrollable = edges.start || edges.end;

    const onPointerDown = (event: React.PointerEvent<HTMLDivElement>) => {
        const node = box.current;
        const target = event.target as Element;
        if (node === null || !scrollable || event.pointerType !== 'mouse' || event.button !== 0 || event.altKey) {
            return;
        }
        if (target.closest(NO_DRAG_SELECTOR)) {
            return;
        }
        // never inside text the reader has selected
        const selection = window.getSelection();
        if (selection && !selection.isCollapsed && selection.containsNode(target, true)) {
            return;
        }
        drag.current = { x: event.clientX, y: event.clientY, scroll: node.scrollLeft, id: event.pointerId, moved: false };
    };
    const onPointerMove = (event: React.PointerEvent<HTMLDivElement>) => {
        const node = box.current;
        const state = drag.current;
        if (node === null || state === null || event.pointerId !== state.id) {
            return;
        }
        const dx = event.clientX - state.x;
        if (!state.moved) {
            if (!startsDrag(dx, event.clientY - state.y)) {
                if (Math.abs(event.clientY - state.y) >= DRAG_THRESHOLD) {
                    drag.current = null; // mostly down: the reader is selecting text
                }
                return;
            }
            state.moved = true;
            node.setPointerCapture(event.pointerId);
            window.getSelection()?.removeAllRanges();
            setDragging(true);
        }
        node.scrollLeft = dragScroll(state.scroll, dx);
    };
    const endDrag = (event: React.PointerEvent<HTMLDivElement>) => {
        const node = box.current;
        const state = drag.current;
        drag.current = null;
        if (node === null || state === null || !state.moved) {
            return;
        }
        if (node.hasPointerCapture(event.pointerId)) {
            node.releasePointerCapture(event.pointerId);
        }
        setDragging(false);
        // the release after a drag is not a click on whatever is under the pointer
        const swallow = (click: MouseEvent) => {
            click.stopPropagation();
            click.preventDefault();
        };
        window.addEventListener('click', swallow, { capture: true, once: true });
        window.setTimeout(() => window.removeEventListener('click', swallow, { capture: true }), 0);
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLDivElement>) => {
        const node = box.current;
        const target = event.target as HTMLElement;
        if (node === null || !scrollable || event.altKey || event.ctrlKey || event.metaKey) {
            return;
        }
        // keys typed into a field, a list or a control inside the table are theirs
        if (target !== node && (target.isContentEditable || target.closest('input, textarea, select, [role="slider"], [role="combobox"], [role="listbox"]'))) {
            return;
        }
        const rtl = getComputedStyle(node).direction === 'rtl';
        const next = keyScroll(event.key, node.scrollLeft, node.scrollWidth - node.clientWidth, node.clientWidth, rtl);
        if (next === null) {
            return;
        }
        event.preventDefault();
        node.scrollTo({ left: next, behavior: 'smooth' });
    };

    return (
        <div className="relative">
            <div
                ref={box}
                onScroll={onBoxScroll}
                onPointerDown={onPointerDown}
                onPointerMove={onPointerMove}
                onPointerUp={endDrag}
                onPointerCancel={endDrag}
                onKeyDown={onKeyDown}
                // Reachable and scrollable from the keyboard when — and only when — there is
                // something to scroll to.
                tabIndex={scrollable ? 0 : -1}
                role="region"
                aria-label={t('table.scroll_region', 'جدول قابل للتمرير أفقيًا')}
                aria-description={scrollable ? t('table.scroll_help', 'اسحب الجدول بالفأرة أو استخدم الأسهم يمينًا ويسارًا وHome وEnd للتحرك جانبيًا.') : undefined}
                data-dragging={dragging ? 'true' : undefined}
                className={cn(
                    'w-full overflow-x-auto scrollbar-visible focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-inset',
                    // sideways only: no reserved vertical gutter — in Arabic that empty strip sits on the left and Chrome
                    // shifts the box's range by its width, so the table could never reach its own start (2026-11-19)
                    'overflow-y-hidden [scrollbar-gutter:auto]',
                    '[&_[data-pinned=true]]:bg-card [&_th[data-lifted=true]]:bg-card [&_th[data-lifted=true]]:shadow-sm',
                    scrollable && 'cursor-grab',
                    dragging && 'cursor-grabbing select-none',
                )}
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

            {/* the scrollbar pinned to the bottom of the screen while the table's own is below the fold */}
            {barBox ? (
                <div
                    ref={bar}
                    onScroll={() => {
                        if (barDriving.current) {
                            driveFromBar();
                        }
                        onBarScroll();
                    }}
                    onPointerDown={driveFromBar}
                    onWheel={driveFromBar}
                    onTouchStart={driveFromBar}
                    aria-hidden="true"
                    data-table-bottom-bar="true"
                    className="fixed bottom-0 z-20 overflow-x-auto overflow-y-hidden border-t bg-background/95 scrollbar-visible"
                    style={{ left: barBox.left, width: barBox.width, height: 16 }}
                >
                    <div style={{ width: barBox.inner, height: 1 }} />
                </div>
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
