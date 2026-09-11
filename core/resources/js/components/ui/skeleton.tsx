import { cn } from '@/lib/utils';

/** Loading placeholder. `aria-hidden` because the live region announcing "loading" is the table's. */
function Skeleton({ className }: { className?: string }) {
    return <div aria-hidden="true" className={cn('animate-pulse rounded-md bg-muted', className)} />;
}

export { Skeleton };
