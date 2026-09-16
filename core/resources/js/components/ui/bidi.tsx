import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Latin text and numbers inside an Arabic (RTL) page.
 *
 * ── The bug this exists to stop ──────────────────────────────────────────────────────────────
 *
 * The obvious way to keep a SKU, a price or an email in logical order is `dir="ltr"`. Put that on a
 * BLOCK element — a `<td>`, a `<div>`, a table cell — and it does something nobody intends:
 * `text-align: start` resolves against the element's OWN direction, so the moment a cell says
 * `dir="ltr"` its content jumps to the LEFT edge while its header, which inherits the page's RTL,
 * stays on the RIGHT. Header and value stop lining up, and a row of five numbers becomes a row
 * where every number sits under the wrong heading. That is exactly what the dashboard home table
 * did (reported 2026-09-14).
 *
 * ── The fix ─────────────────────────────────────────────────────────────────────────────────
 *
 * Direction belongs on an INLINE run, not on the box. `<bdi>` is the element HTML provides for
 * precisely this: it isolates its contents from the surrounding bidi algorithm without being a
 * block, so the box keeps the page's alignment and the text inside keeps its own order.
 *
 * Use {@link Ltr} for text that can wrap (an English product title, a URL) and {@link Num} for the
 * things that must never break across lines — a SKU, a date, a money amount, a phone number.
 */

type BdiProps = React.HTMLAttributes<HTMLElement>;

/** An isolated left-to-right run. Inline: it never changes the alignment of the box it sits in. */
export function Ltr({ className, ...props }: BdiProps) {
    return <bdi dir="ltr" className={cn('inline', className)} {...props} />;
}

/**
 * A number, code or timestamp: left-to-right, lining figures, and never broken across lines.
 *
 * `whitespace-nowrap` is the point as much as the direction is — a SKU rendered as `BF-` on one
 * line and `31774` on the next is not a SKU anyone can read or copy.
 */
export function Num({ className, ...props }: BdiProps) {
    return <bdi dir="ltr" className={cn('inline whitespace-nowrap tabular-nums', className)} {...props} />;
}
