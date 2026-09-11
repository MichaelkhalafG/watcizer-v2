import { Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';

/**
 * The save/cancel pair, in one place so every form in the dashboard behaves the same:
 * primary action first in the reading order, a disabled-until-dirty save, and a spinner that
 * replaces the label rather than moving the button.
 */
export function FormActions({
    processing = false,
    dirty = true,
    onCancel,
    submitLabel = 'حفظ',
    cancelLabel = 'إلغاء',
    extra,
}: {
    processing?: boolean;
    dirty?: boolean;
    onCancel?: () => void;
    submitLabel?: string;
    cancelLabel?: string;
    extra?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button type="submit" disabled={processing || !dirty} className="gap-2">
                {processing ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : null}
                {processing ? 'جارٍ الحفظ…' : submitLabel}
            </Button>
            {onCancel ? (
                <Button type="button" variant="outline" onClick={onCancel} disabled={processing}>
                    {cancelLabel}
                </Button>
            ) : null}
            {dirty ? <span className="text-xs text-muted-foreground">تغييرات غير محفوظة</span> : null}
            {extra ? <div className="ms-auto flex items-center gap-2">{extra}</div> : null}
        </div>
    );
}
