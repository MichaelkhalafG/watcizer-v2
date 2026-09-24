import { CalendarDays } from 'lucide-react';

import { Input } from '@/components/ui/input';
import { useT } from '@/lib/i18n';

/**
 * A date RANGE, as one control (§2.8, browser walkthrough 2026-09-18).
 *
 * ── What it replaces ────────────────────────────────────────────────────────────────────────
 *
 * Orders, Activity and the Ledger each rendered two bare `mm/dd/yyyy` boxes. On Orders and
 * Activity the filter row wrapped between them, so one sat at the end of the first row and the
 * other began the second — two identical boxes, on different lines, with nothing painted to say
 * which was "from". They carried `aria-label`s, so a screen reader was told; a sighted operator
 * was not, and could only find out by typing in one and watching what happened.
 *
 * ── The format ──────────────────────────────────────────────────────────────────────────────
 *
 * `mm/dd` is the one order nobody in Egypt writes, and `<input type="date">` picks its display
 * format from the BROWSER's locale, not from the page's — so `lang="ar-EG"` on the inputs is what
 * makes them render day-first for a browser that will honour it. The VALUE is `yyyy-mm-dd` either
 * way, which is what the server parses, so this changes what is read and never what is sent.
 *
 * ── The presets ─────────────────────────────────────────────────────────────────────────────
 *
 * An order queue is filtered by "today" and "this week" far more often than by a specific pair of
 * dates, and those are three clicks and two typed dates otherwise. They set both ends at once, so
 * they cannot leave a half-open range behind.
 */
export function DateRangeFilter({
    from,
    to,
    onChange,
    label,
}: {
    from: string | null;
    to: string | null;
    /** Both ends at once: a preset that set one and left the other would be a trap. */
    onChange: (from: string | null, to: string | null) => void;
    label?: string;
}) {
    const t = useT();
    const heading = label ?? t('table.date_range', 'المدة');

    /** `yyyy-mm-dd` in the OPERATOR's timezone, which is the one the dates on screen are in. */
    const day = (offset: number): string => {
        const date = new Date();
        date.setDate(date.getDate() + offset);

        return [
            date.getFullYear(),
            String(date.getMonth() + 1).padStart(2, '0'),
            String(date.getDate()).padStart(2, '0'),
        ].join('-');
    };

    const presets: Array<{ key: string; label: string; range: () => [string, string] }> = [
        { key: 'today', label: t('table.range_today', 'اليوم'), range: () => [day(0), day(0)] },
        { key: 'week', label: t('table.range_7', 'آخر 7 أيام'), range: () => [day(-6), day(0)] },
        {
            key: 'month',
            label: t('table.range_month', 'هذا الشهر'),
            range: () => {
                const now = new Date();

                return [
                    `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`,
                    day(0),
                ];
            },
        },
    ];

    return (
        <fieldset className="flex flex-wrap items-center gap-1.5 rounded-md border px-2 py-1">
            <legend className="flex items-center gap-1 px-1 text-[11px] text-muted-foreground">
                <CalendarDays className="h-3 w-3" aria-hidden="true" />
                {heading}
            </legend>

            {/* Visible words, not `aria-label`s alone. The two boxes are identical otherwise. */}
            <label className="flex items-center gap-1 text-xs text-muted-foreground">
                {t('common.from', 'من')}
                <Input
                    type="date"
                    lang="ar-EG"
                    className="h-8 w-[9.5rem] text-sm"
                    aria-label={t('common.from_date', 'من تاريخ')}
                    value={from ?? ''}
                    max={to ?? undefined}
                    onChange={(event) => onChange(event.target.value || null, to)}
                />
            </label>
            <label className="flex items-center gap-1 text-xs text-muted-foreground">
                {t('common.to', 'إلى')}
                <Input
                    type="date"
                    lang="ar-EG"
                    className="h-8 w-[9.5rem] text-sm"
                    aria-label={t('common.to_date', 'إلى تاريخ')}
                    value={to ?? ''}
                    // A range that ends before it starts returns nothing and looks like a broken
                    // filter. The browser refuses it here instead.
                    min={from ?? undefined}
                    onChange={(event) => onChange(from, event.target.value || null)}
                />
            </label>

            <span className="flex items-center gap-1">
                {presets.map((preset) => (
                    <button
                        key={preset.key}
                        type="button"
                        className="rounded px-1.5 py-0.5 text-[11px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        onClick={() => {
                            const [start, end] = preset.range();
                            onChange(start, end);
                        }}
                    >
                        {preset.label}
                    </button>
                ))}
                {from === null && to === null ? null : (
                    <button
                        type="button"
                        className="rounded px-1.5 py-0.5 text-[11px] text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        onClick={() => onChange(null, null)}
                    >
                        {t('table.range_clear', 'كل المدة')}
                    </button>
                )}
            </span>
        </fieldset>
    );
}
