import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * shadcn/ui input. `dir` is deliberately NOT set: it inherits from the document so an Arabic form
 * field is RTL and an English one inside a `dir="ltr"` wrapper is LTR — which is exactly what the
 * paired ar/en fields in `TranslatedField` need.
 */
const Input = React.forwardRef<HTMLInputElement, React.InputHTMLAttributes<HTMLInputElement>>(
    ({ className, type = 'text', ...props }, ref) => (
        <input
            ref={ref}
            type={type}
            className={cn(
                'flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background',
                'placeholder:text-muted-foreground',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'aria-[invalid=true]:border-destructive aria-[invalid=true]:focus-visible:ring-destructive',
                'file:border-0 file:bg-transparent file:text-sm file:font-medium',
                className,
            )}
            {...props}
        />
    ),
);
Input.displayName = 'Input';

const Textarea = React.forwardRef<HTMLTextAreaElement, React.TextareaHTMLAttributes<HTMLTextAreaElement>>(
    ({ className, rows = 4, ...props }, ref) => (
        <textarea
            ref={ref}
            rows={rows}
            className={cn(
                'flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background',
                'placeholder:text-muted-foreground',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'aria-[invalid=true]:border-destructive',
                className,
            )}
            {...props}
        />
    ),
);
Textarea.displayName = 'Textarea';

/**
 * A NATIVE select, on purpose. A custom listbox is worse here: the platform control is keyboard-
 * and screen-reader-correct for free, opens as a wheel on a tablet, and mirrors itself in RTL.
 * Searchable selects (hundreds of brands) are a different component — see `Combobox`.
 */
const Select = React.forwardRef<HTMLSelectElement, React.SelectHTMLAttributes<HTMLSelectElement>>(
    ({ className, children, ...props }, ref) => (
        <select
            ref={ref}
            className={cn(
                'flex h-10 w-full appearance-none rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background',
                'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2',
                'disabled:cursor-not-allowed disabled:opacity-50',
                'aria-[invalid=true]:border-destructive',
                className,
            )}
            {...props}
        >
            {children}
        </select>
    ),
);
Select.displayName = 'Select';

export { Input, Select, Textarea };
