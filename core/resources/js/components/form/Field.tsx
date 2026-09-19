import { type ReactNode, useId } from 'react';

import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/**
 * What `Field` hands a control. Spread it — never pick from it — so a field added here reaches
 * every form at once.
 */
export interface FieldControlAttrs {
    id: string;
    'aria-describedby': string | undefined;
    'aria-invalid': boolean | undefined;
    'aria-errormessage': string | undefined;
    required: true | undefined;
    'aria-required': true | undefined;
}

export interface FieldShellProps {
    label: string;
    /** Laravel's error-bag message for this field, straight from Inertia's `errors` prop. */
    error?: string | null;
    hint?: string;
    required?: boolean;
    className?: string;
    /** Rendered instead of the label column for a switch, which labels itself inline. */
    inline?: boolean;
}

/**
 * One field's chrome: label, control, hint, error — and the wiring that makes a screen reader
 * read all three as one thing.
 *
 * `render` receives the ids/aria attributes to spread onto the control, so no call site has to
 * remember `aria-describedby` or that an invalid field needs `aria-invalid`. That is the whole
 * reason this wrapper exists: the accessibility is in ONE place instead of every form.
 */
export function Field({
    label,
    error = null,
    hint,
    required = false,
    className,
    inline = false,
    render,
}: FieldShellProps & {
    render: (attrs: FieldControlAttrs) => ReactNode;
}) {
    /*
     * ── An id a selector can actually address (D-21, 2026-09-19) ────────────────────────────
     *
     * React's `useId()` returns `:r1:` — legal as an HTML id, and unusable everywhere else. A
     * leading colon makes `document.querySelector('#:r1:')` a syntax error, and anything that
     * resolves a `<label for>` by querying rather than by `getElementById` simply fails to find
     * the control. The login form's e-mail box was reported as having its PLACEHOLDER for an
     * accessible name even though the markup carried `htmlFor`/`id` correctly, which is exactly
     * what that failure looks like from the outside.
     *
     * The association was never missing; the id was unaddressable. Stripping the colons costs
     * nothing, keeps `useId()`'s uniqueness guarantee (including across server rendering), and
     * makes every id on every form a valid CSS identifier — which `ErrorSummary` now relies on
     * when it jumps to a field.
     */
    const id = `f-${useId().replace(/:/g, '')}`;
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [hint ? hintId : null, error !== null ? errorId : null].filter(Boolean).join(' ') || undefined;

    const control = render({
        id,
        'aria-describedby': describedBy,
        'aria-invalid': error !== null ? true : undefined,
        'aria-errormessage': error !== null ? errorId : undefined,
        /*
         * ── The asterisk is now attached to something (D-19, 2026-09-19) ────────────────────
         *
         * `required` used to reach the LABEL only, where it drew a red `*` and stopped. There was
         * no `required` on any input in the dashboard and no `aria-required` anywhere, so the mark
         * was decoration: pressing Save on an empty form posted it, the server answered 422, and
         * the messages rendered inline beside their fields — six screens above the button that had
         * just been pressed. Combined with the flash rendering off-screen (D-20), the operator saw
         * nothing happen at all.
         *
         * Both attributes, because they do different jobs. `required` is what makes the browser
         * refuse the submit and move focus to the offending field — the guard that was missing.
         * `aria-required` is what a screen reader announces when the field is ENTERED, which is
         * before the mistake rather than after it, and it is the half a red glyph could never do.
         *
         * Passed through `render()` on purpose: every field component in this folder already
         * spreads these attrs onto its control, so this one edit reaches every form in the
         * dashboard and no call site had to be found or remembered. That is the reason this
         * wrapper exists (see the docblock above), and it is the first time it has been claimed.
         */
        required: required || undefined,
        'aria-required': required || undefined,
    });

    return (
        <div className={cn('space-y-2', className)}>
            {inline ? (
                <div className="flex items-center justify-between gap-4">
                    <Label htmlFor={id} required={required}>
                        {label}
                    </Label>
                    {control}
                </div>
            ) : (
                <>
                    <Label htmlFor={id} required={required}>
                        {label}
                    </Label>
                    {control}
                </>
            )}

            {hint ? (
                <p id={hintId} className="text-xs text-muted-foreground">
                    {hint}
                </p>
            ) : null}
            {error !== null ? (
                // `role="alert"` so the message is announced when validation comes back from the
                // server, not only when the field is next focused.
                <p id={errorId} role="alert" className="text-xs font-medium text-destructive">
                    {error}
                </p>
            ) : null}
        </div>
    );
}
