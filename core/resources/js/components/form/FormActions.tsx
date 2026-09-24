import { Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { useT } from '@/lib/i18n';

/**
 * The save/cancel pair, in one place so every form in the dashboard behaves the same:
 * primary action first in the reading order, a disabled-until-dirty save, and a spinner that
 * replaces the label rather than moving the button.
 */
export function FormActions({
    processing = false,
    dirty = true,
    onCancel,
    submitLabel,
    cancelLabel,
    extra,
    formId,
}: {
    processing?: boolean;
    dirty?: boolean;
    onCancel?: () => void;
    submitLabel?: string;
    cancelLabel?: string;
    extra?: ReactNode;
    /**
     * The id of the `<form>` this submits, for when the button is NOT inside it.
     *
     * HTML's own `form=` association, not a click handler: the button still submits, still
     * validates, and `Enter` in a field still works. Needed since 2026-10-05, because the product
     * form's save bar had to move OUTSIDE the form element — a `position: sticky` box only sticks
     * inside its own parent, so a bar inside the form stopped sticking the moment the page scrolled
     * into the variants panel, which is deliberately rendered after `</form>`.
     */
    formId?: string;
}) {
    /*
     * The labels default INSIDE the body, not in the parameter list. A default parameter is
     * evaluated before the component runs, where a hook cannot be called — so the Arabic literal
     * would have been frozen there and no locale could ever reach it.
     */
    const t = useT();
    const submit = submitLabel ?? t('common.save', 'حفظ');
    const cancel = cancelLabel ?? t('common.cancel', 'إلغاء');

    return (
        <div className="flex flex-wrap items-center gap-2">
            <Button type="submit" form={formId} disabled={processing || !dirty} className="gap-2">
                {processing ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : null}
                {processing ? t('common.saving', 'جارٍ الحفظ…') : submit}
            </Button>
            {onCancel ? (
                <Button type="button" variant="outline" onClick={onCancel} disabled={processing}>
                    {cancel}
                </Button>
            ) : null}
            {dirty ? <span className="text-xs text-muted-foreground">{t('common.unsaved_changes', 'تغييرات غير محفوظة')}</span> : null}
            {extra ? <div className="ms-auto flex items-center gap-2">{extra}</div> : null}
        </div>
    );
}
