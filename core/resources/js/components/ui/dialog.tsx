import * as React from 'react';
import * as DialogPrimitive from '@radix-ui/react-dialog';
import { X } from 'lucide-react';

import { cn } from '@/lib/utils';

const Dialog = DialogPrimitive.Root;
const DialogTrigger = DialogPrimitive.Trigger;
const DialogClose = DialogPrimitive.Close;

const DialogOverlay = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Overlay>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Overlay>
>(({ className, ...props }, ref) => (
    <DialogPrimitive.Overlay
        ref={ref}
        className={cn(
            'fixed inset-0 z-50 bg-foreground/40 backdrop-blur-sm',
            'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
            className,
        )}
        {...props}
    />
));
DialogOverlay.displayName = 'DialogOverlay';

/** Centred modal. `title` is required by Radix for the accessible name; pass it, always. */
const DialogContent = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Content>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content> & { title: string; description?: string }
>(({ className, children, title, description, ...props }, ref) => (
    <DialogPrimitive.Portal>
        <DialogOverlay />
        <DialogPrimitive.Content
            ref={ref}
            className={cn(
                'fixed start-1/2 top-1/2 z-50 w-full max-w-lg -translate-y-1/2 rtl:translate-x-1/2 ltr:-translate-x-1/2',
                'rounded-lg border bg-background p-6 shadow-lg',
                'data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0',
                className,
            )}
            {...props}
        >
            <div className="mb-4 space-y-1">
                <DialogPrimitive.Title className="text-lg font-semibold">{title}</DialogPrimitive.Title>
                {description ? (
                    <DialogPrimitive.Description className="text-sm text-muted-foreground">{description}</DialogPrimitive.Description>
                ) : null}
            </div>
            {children}
            <DialogPrimitive.Close
                className="absolute end-4 top-4 rounded-sm text-muted-foreground transition-colors hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                aria-label="إغلاق"
            >
                <X className="h-4 w-4" />
            </DialogPrimitive.Close>
        </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
));
DialogContent.displayName = 'DialogContent';

/**
 * The mobile navigation drawer, built on the same Radix Dialog (focus trap, Escape, scroll lock
 * come with it). It slides from the INLINE-START edge, so from the right in Arabic.
 */
const Sheet = React.forwardRef<
    React.ElementRef<typeof DialogPrimitive.Content>,
    React.ComponentPropsWithoutRef<typeof DialogPrimitive.Content> & { title: string }
>(({ className, children, title, ...props }, ref) => (
    <DialogPrimitive.Portal>
        <DialogOverlay />
        <DialogPrimitive.Content
            ref={ref}
            className={cn(
                'fixed inset-y-0 start-0 z-50 flex w-[17rem] flex-col border-e bg-background shadow-xl',
                'data-[state=open]:animate-in data-[state=closed]:animate-out',
                'data-[state=closed]:slide-out-to-right data-[state=open]:slide-in-from-right',
                'ltr:data-[state=closed]:slide-out-to-left ltr:data-[state=open]:slide-in-from-left',
                className,
            )}
            {...props}
        >
            <DialogPrimitive.Title className="sr-only">{title}</DialogPrimitive.Title>
            {children}
        </DialogPrimitive.Content>
    </DialogPrimitive.Portal>
));
Sheet.displayName = 'Sheet';

export { Dialog, DialogClose, DialogContent, DialogTrigger, Sheet };
