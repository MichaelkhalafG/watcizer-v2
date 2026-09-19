import * as React from 'react';
import { cva, type VariantProps } from 'class-variance-authority';

import { cn } from '@/lib/utils';

const badgeVariants = cva(
    'inline-flex items-center gap-1 rounded-full border px-2.5 py-0.5 text-xs font-medium transition-colors',
    {
        variants: {
            variant: {
                default: 'border-transparent bg-brand-muted text-brand-strong',
                neutral: 'border-transparent bg-muted text-muted-foreground',
                success: 'border-transparent bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
                warning: 'border-transparent bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-300',
                // `--destructive` is a BACKGROUND-grade red in the dark palette (app.css:60,
                // `0 62.8% 30.6%`), so using it as TEXT on a near-black card measured ~2.0:1 —
                // against a 4.5:1 minimum, on the one variant that means "act now". The explicit
                // pair below matches what `success` and `warning` above already do.
                destructive:
                    'border-transparent bg-destructive/10 text-destructive dark:bg-red-950 dark:text-red-300',
                outline: 'border-border text-foreground',
            },
        },
        defaultVariants: { variant: 'default' },
    },
);

function Badge({ className, variant, ...props }: React.HTMLAttributes<HTMLSpanElement> & VariantProps<typeof badgeVariants>) {
    return <span className={cn(badgeVariants({ variant }), className)} {...props} />;
}

export { Badge, badgeVariants };
