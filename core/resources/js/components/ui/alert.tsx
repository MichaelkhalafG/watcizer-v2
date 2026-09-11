import * as React from 'react';
import { AlertTriangle, CheckCircle2, Info, XCircle } from 'lucide-react';

import { cn } from '@/lib/utils';

const TONES = {
    info: { className: 'border-brand/30 bg-brand-muted text-foreground', Icon: Info },
    success: { className: 'border-emerald-500/30 bg-emerald-50 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200', Icon: CheckCircle2 },
    warning: { className: 'border-amber-500/40 bg-amber-50 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200', Icon: AlertTriangle },
    error: { className: 'border-destructive/40 bg-destructive/10 text-destructive', Icon: XCircle },
} as const;

export type AlertTone = keyof typeof TONES;

/** `role="status"` for calm tones and `role="alert"` for the loud ones, so a screen reader interrupts only when it should. */
function Alert({
    tone = 'info',
    title,
    children,
    className,
}: {
    tone?: AlertTone;
    title?: string;
    children?: React.ReactNode;
    className?: string;
}) {
    const { className: toneClass, Icon } = TONES[tone];

    return (
        <div
            role={tone === 'error' || tone === 'warning' ? 'alert' : 'status'}
            className={cn('flex items-start gap-3 rounded-lg border p-4 text-sm', toneClass, className)}
        >
            <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <div className="space-y-1">
                {title ? <p className="font-medium">{title}</p> : null}
                {children ? <div className="text-sm opacity-90">{children}</div> : null}
            </div>
        </div>
    );
}

export { Alert };
