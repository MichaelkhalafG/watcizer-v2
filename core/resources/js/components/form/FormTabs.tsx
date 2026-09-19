import { type ReactNode, useCallback, useEffect, useRef } from 'react';

import { cn } from '@/lib/utils';
import { useT } from '@/lib/i18n';

/**
 * Tabs for a long form, and the error map that keeps them honest (§2.1).
 *
 * ── Why the product form needed this ────────────────────────────────────────────────────────
 *
 * Measured in the browser: **4,868 px against a 662 px viewport — 7.4 screens**, one column, no
 * section navigation, no sticky save bar, and the Save button placed above the variants panel so
 * the primary action was not even last. Filing one product meant scrolling a 200 px category pane
 * inside a page that was already seven screens long.
 *
 * ── Only the active panel is MOUNTED, and that is deliberate ────────────────────────────────
 *
 * The obvious implementation keeps every panel in the DOM and hides the inactive ones. It breaks
 * two things at once:
 *
 *  1. A hidden `<input required>` cannot be focused, so the browser refuses the submit with
 *     *"An invalid form control is not focusable"* — silently, in the console — and the operator
 *     presses Save and watches nothing happen. That is D-20 again, from a new direction.
 *  2. It keeps paying to render seven screens of fields nobody is looking at.
 *
 * Form STATE does not live in the DOM here — it lives in Inertia's `useForm` — so unmounting a
 * panel loses nothing. What it does mean is that a field on another tab cannot be guarded by the
 * browser, which is why every tab carries the count of what the SERVER refused in it, and why a
 * failed save switches to the first tab that has one.
 */

export interface FormTab {
    key: string;
    label: string;
    /** Field names from the error bag that belong to this tab, as prefixes. */
    fields: string[];
}

/** How many of the current errors belong to each tab, keyed by tab. */
export function errorsByTab(tabs: FormTab[], errors: Record<string, string>): Record<string, number> {
    const out: Record<string, number> = {};
    for (const tab of tabs) {
        out[tab.key] = 0;
    }

    for (const name of Object.keys(errors)) {
        for (const tab of tabs) {
            // A prefix match, because Laravel reports `title.ar` and `storefronts.1.slug` — the
            // tab owns the field, not the exact key.
            if (tab.fields.some((field) => name === field || name.startsWith(`${field}.`))) {
                out[tab.key] = (out[tab.key] ?? 0) + 1;
                break;
            }
        }
    }

    return out;
}

/** The first tab holding an error, or null — what a failed save should switch to. */
export function firstTabWithError(tabs: FormTab[], errors: Record<string, string>): string | null {
    const counts = errorsByTab(tabs, errors);
    for (const tab of tabs) {
        if ((counts[tab.key] ?? 0) > 0) {
            return tab.key;
        }
    }

    return null;
}

export function FormTabs({
    tabs,
    active,
    onChange,
    errors,
}: {
    tabs: FormTab[];
    active: string;
    onChange: (key: string) => void;
    errors: Record<string, string>;
}) {
    const t = useT();
    const counts = errorsByTab(tabs, errors);
    const list = useRef<HTMLDivElement>(null);

    /*
     * Arrow keys move between tabs, which is what the tablist pattern promises and what anybody
     * driving this from the keyboard will try. Home/End jump to the ends — on a seven-tab form
     * that is the difference between one key and six.
     */
    const onKeyDown = useCallback(
        (event: React.KeyboardEvent) => {
            const index = tabs.findIndex((tab) => tab.key === active);
            if (index === -1) {
                return;
            }
            // Arrow direction follows the WRITING direction: on an RTL page the visually-next tab
            // is the one to the left, so a hard-coded "right = next" walks backwards.
            const rtl = document.documentElement.getAttribute('dir') === 'rtl';
            const forward = rtl ? 'ArrowLeft' : 'ArrowRight';
            const back = rtl ? 'ArrowRight' : 'ArrowLeft';

            let next: number | null = null;
            if (event.key === forward) {
                next = (index + 1) % tabs.length;
            } else if (event.key === back) {
                next = (index - 1 + tabs.length) % tabs.length;
            } else if (event.key === 'Home') {
                next = 0;
            } else if (event.key === 'End') {
                next = tabs.length - 1;
            }

            if (next !== null) {
                event.preventDefault();
                const key = tabs[next]?.key;
                if (key !== undefined) {
                    onChange(key);
                    list.current?.querySelector<HTMLElement>(`[data-tab="${key}"]`)?.focus();
                }
            }
        },
        [tabs, active, onChange],
    );

    return (
        <div
            ref={list}
            role="tablist"
            aria-label={t('form.tabs_label', 'أقسام النموذج')}
            onKeyDown={onKeyDown}
            className="flex flex-wrap gap-1 border-b"
        >
            {tabs.map((tab) => {
                const count = counts[tab.key] ?? 0;
                const current = tab.key === active;

                return (
                    <button
                        key={tab.key}
                        type="button"
                        role="tab"
                        data-tab={tab.key}
                        id={`tab-${tab.key}`}
                        aria-selected={current}
                        aria-controls={`panel-${tab.key}`}
                        // Roving tabindex: one stop for the whole strip, arrows inside it.
                        tabIndex={current ? 0 : -1}
                        onClick={() => onChange(tab.key)}
                        className={cn(
                            'relative -mb-px flex items-center gap-1.5 rounded-t-md border border-transparent px-3 py-2 text-sm transition-colors',
                            'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                            current
                                ? 'border-border border-b-background bg-background font-medium text-foreground'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                    >
                        {tab.label}
                        {/* The count of what the SERVER refused here. With only the active panel
                            mounted, this is the only thing that can tell an operator there is a
                            problem on a tab they are not looking at. */}
                        {count > 0 ? (
                            <span
                                className="rounded-full bg-destructive/10 px-1.5 text-xs font-medium text-destructive dark:bg-red-950 dark:text-red-300"
                                aria-label={t('form.tab_error_count', ':count حقل يحتاج تصحيحًا', { count })}
                            >
                                {count}
                            </span>
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

/** One tab's contents. Rendered only while its tab is active — see the note on `FormTabs`. */
export function TabPanel({ when, active, children }: { when: string; active: string; children: ReactNode }) {
    if (when !== active) {
        return null;
    }

    return (
        <div id={`panel-${when}`} role="tabpanel" aria-labelledby={`tab-${when}`} className="space-y-6">
            {children}
        </div>
    );
}

/**
 * The save bar that follows the operator down the page.
 *
 * It is `sticky` rather than fixed so it sits inside the form's own column and never covers the
 * last field; `bottom-0` with a background and a top border so it reads as a floor rather than as
 * something floating over the content.
 */
export function StickySaveBar({ children }: { children: ReactNode }) {
    /*
     * A tiny bit of bottom padding is added to the document while this is mounted, so the final
     * field can always be scrolled clear of the bar. Done here rather than by asking every form to
     * remember a magic `pb-20`.
     */
    useEffect(() => {
        const previous = document.body.style.paddingBottom;
        document.body.style.paddingBottom = '4rem';

        return () => {
            document.body.style.paddingBottom = previous;
        };
    }, []);

    return (
        <div className="sticky bottom-0 z-20 -mx-4 mt-6 border-t bg-background/95 px-4 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80 sm:-mx-6 sm:px-6">
            {children}
        </div>
    );
}
