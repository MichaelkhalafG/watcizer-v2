/**
 * The arithmetic of a wide table's sideways movement (2026-11-19, the team's report: "the scrollbar sits under the last
 * row, so we scroll all the way down to move sideways"). Pure functions, no DOM: the Table primitive
 * (components/ui/table.tsx) calls them, and `node --test` checks them (tableScroll.test.ts).
 *
 * `scrollLeft` is physical in every current browser: 0 at the LEFT edge in a left-to-right box and 0 at the RIGHT edge
 * (going negative toward the left) in a right-to-left one. So an arrow key moves the table the way it points in both
 * languages, and "start" (Home) is 0 in both, "end" is the far side: +max in English, -max in Arabic.
 */

/** Elements a drag never starts on: the controls inside a row keep their own press. */
export const NO_DRAG_SELECTOR =
    'input, textarea, select, button, a, label, summary, option, [role="switch"], [role="checkbox"], [role="button"], [role="slider"], [contenteditable=""], [contenteditable="true"], [data-no-drag]';

/** A press becomes a drag only after this many pixels, mostly sideways — a short click still clicks. */
export const DRAG_THRESHOLD = 6;

/** What one press of an arrow key moves. */
export function arrowStep(clientWidth: number): number {
    return Math.max(80, Math.round(clientWidth * 0.25));
}

/**
 * The new `scrollLeft` for a key, or null when the key is not one the table answers.
 *
 * @param max   scrollWidth - clientWidth (how far the table can move)
 */
export function keyScroll(key: string, current: number, max: number, clientWidth: number, rtl: boolean): number | null {
    const step = arrowStep(clientWidth);
    const low = rtl ? -max : 0;
    const high = rtl ? 0 : max;
    const clamp = (v: number) => Math.min(high, Math.max(low, v));
    switch (key) {
        case 'ArrowLeft':
            return clamp(current - step);
        case 'ArrowRight':
            return clamp(current + step);
        case 'Home':
            return 0;
        case 'End':
            return rtl ? -max : max;
        default:
            return null;
    }
}

/** Does a press-and-move decide to drag the table? Mostly sideways and past the threshold. */
export function startsDrag(dx: number, dy: number): boolean {
    return Math.abs(dx) >= DRAG_THRESHOLD && Math.abs(dx) > Math.abs(dy);
}

/** The new `scrollLeft` while dragging: the table follows the pointer. */
export function dragScroll(startScroll: number, dx: number): number {
    return startScroll - dx;
}

/**
 * How far the header row moves down so it stays under the app's top bar while the table scrolls under it — never above
 * its own place and never past the table's last row.
 *
 * @param tableTop     the table's top, relative to the viewport
 * @param tableHeight  the table's height
 * @param headHeight   the header row's height
 * @param barBottom    the bottom of the app's own sticky bar (0 when there is none)
 */
export function headerShift(tableTop: number, tableHeight: number, headHeight: number, barBottom: number): number {
    const wanted = barBottom - tableTop;
    const most = Math.max(0, tableHeight - headHeight * 2);
    return Math.round(Math.min(most, Math.max(0, wanted)));
}

/** How many leading columns stay pinned: the first, and the second too when the first is narrow (a # or a checkbox). */
export function pinCount(widths: number[]): number {
    if (widths.length < 2) {
        return widths.length;
    }
    return widths[0] <= 64 ? 2 : 1;
}

/** The inline-start offset of each pinned column: 0, then the widths before it. */
export function pinOffsets(widths: number[], count: number): number[] {
    const out: number[] = [];
    let at = 0;
    for (let i = 0; i < count; i++) {
        out.push(at);
        at += widths[i] ?? 0;
    }
    return out;
}

/**
 * Is the bottom scrollbar shown? While the table overflows sideways, its top is on screen and its own scrollbar (its
 * bottom) is below the screen's bottom edge.
 */
export function showsBottomBar(overflowing: boolean, boxTop: number, boxBottom: number, viewportHeight: number): boolean {
    return overflowing && boxTop < viewportHeight - 48 && boxBottom > viewportHeight;
}
