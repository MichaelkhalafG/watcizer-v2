import * as React from 'react';

import { cn } from '@/lib/utils';

/**
 * Table shell, styled after the reference template's tables. The horizontal scroll lives on the
 * WRAPPER, so a wide table scrolls inside the card instead of pushing the whole page sideways —
 * which in RTL would otherwise scroll the shell the wrong way.
 */
function Table({ className, ...props }: React.TableHTMLAttributes<HTMLTableElement>) {
    return (
        <div className="w-full overflow-x-auto scrollbar-thin">
            <table className={cn('w-full caption-bottom border-collapse text-sm', className)} {...props} />
        </div>
    );
}

function TableHeader({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
    return <thead className={cn('[&_tr]:border-b', className)} {...props} />;
}

function TableBody({ className, ...props }: React.HTMLAttributes<HTMLTableSectionElement>) {
    return <tbody className={cn('[&_tr:last-child]:border-0', className)} {...props} />;
}

function TableRow({ className, ...props }: React.HTMLAttributes<HTMLTableRowElement>) {
    return (
        <tr
            className={cn('border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-brand-muted', className)}
            {...props}
        />
    );
}

function TableHead({ className, ...props }: React.ThHTMLAttributes<HTMLTableCellElement>) {
    return (
        <th
            className={cn(
                'h-11 whitespace-nowrap px-4 text-start align-middle text-xs font-semibold uppercase tracking-wide text-muted-foreground',
                className,
            )}
            {...props}
        />
    );
}

function TableCell({ className, ...props }: React.TdHTMLAttributes<HTMLTableCellElement>) {
    return <td className={cn('px-4 py-3 align-middle', className)} {...props} />;
}

export { Table, TableBody, TableCell, TableHead, TableHeader, TableRow };
