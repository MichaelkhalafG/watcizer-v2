import { type ReactNode, useId } from 'react';

import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

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
    render: (attrs: { id: string; 'aria-describedby': string | undefined; 'aria-invalid': boolean | undefined; 'aria-errormessage': string | undefined }) => ReactNode;
}) {
    const id = useId();
    const hintId = `${id}-hint`;
    const errorId = `${id}-error`;
    const describedBy = [hint ? hintId : null, error !== null ? errorId : null].filter(Boolean).join(' ') || undefined;

    const control = render({
        id,
        'aria-describedby': describedBy,
        'aria-invalid': error !== null ? true : undefined,
        'aria-errormessage': error !== null ? errorId : undefined,
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
