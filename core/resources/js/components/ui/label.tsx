import * as React from 'react';
import * as LabelPrimitive from '@radix-ui/react-label';

import { cn } from '@/lib/utils';

/** Every field has one. `htmlFor` is wired by the field components, never by hand at a call site. */
const Label = React.forwardRef<
    React.ElementRef<typeof LabelPrimitive.Root>,
    React.ComponentPropsWithoutRef<typeof LabelPrimitive.Root> & { required?: boolean }
>(({ className, required = false, children, ...props }, ref) => (
    <LabelPrimitive.Root
        ref={ref}
        className={cn('text-sm font-medium leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70', className)}
        {...props}
    >
        {children}
        {required ? (
            <span className="ms-1 text-destructive" aria-hidden="true">
                *
            </span>
        ) : null}
    </LabelPrimitive.Root>
));
Label.displayName = 'Label';

export { Label };
