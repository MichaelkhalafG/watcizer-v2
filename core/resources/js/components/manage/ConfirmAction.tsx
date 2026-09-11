import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent } from '@/components/ui/dialog';

/**
 * A confirmation that SAYS WHAT WILL HAPPEN (task 4.4).
 *
 * ── Why this exists, and why it is not `window.confirm` ──────────────────────────────────────
 *
 * The wave-4B screens deleted a category and a variant on a single click, with nothing in
 * between. The obvious patch is "are you sure?", and it is worth being clear about why that is
 * not what this component does: a team member clicks through "are you sure?" because it carries
 * no information — it asks them to re-affirm an intention they already had. What stops a mistake
 * is being told the CONSEQUENCE they had not thought of: that the nine products in this category
 * will be reachable from nowhere, that this row's stock movements will be re-levelled onto the
 * product, that the old URL will start redirecting.
 *
 * So every use of this component passes a `consequence` written in plain Arabic, naming numbers
 * where there are numbers. The confirm button carries the VERB ("احذف التصنيف"), never "OK" —
 * the last thing the operator reads should be what they are about to do.
 *
 * `window.confirm` is kept in exactly one place (`useDirtyGuard`), where a synchronous answer is
 * the only thing that can block a navigation. Here there is a real dialog, so the text can be as
 * long as the truth needs.
 */
export function ConfirmAction({
    title,
    consequence,
    confirmLabel,
    tone = 'destructive',
    disabled = false,
    trigger,
    onConfirm,
}: {
    title: string;
    /** What will actually happen, in full. Numbers where there are numbers. */
    consequence: React.ReactNode;
    confirmLabel: string;
    tone?: 'destructive' | 'default';
    disabled?: boolean;
    /** The control the operator clicks first — rendered as-is, so it keeps its own tooltip. */
    trigger: React.ReactNode;
    onConfirm: () => void;
}) {
    const [open, setOpen] = useState(false);

    if (disabled) {
        // A blocked action keeps its own disabled control and never opens a dialog: the reason is
        // already on the trigger, and a dialog that explains a refusal is one click of theatre.
        return <>{trigger}</>;
    }

    return (
        <>
            <span onClick={() => setOpen(true)}>{trigger}</span>

            <Dialog open={open} onOpenChange={setOpen}>
                {/* `title` is the dialog's accessible name (Radix requires it) AND the heading. */}
                <DialogContent title={title} className="space-y-4">
                    <div className="text-sm text-muted-foreground">{consequence}</div>
                    <div className="flex flex-wrap justify-end gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="outline">
                                إلغاء
                            </Button>
                        </DialogClose>
                        <Button
                            type="button"
                            variant={tone === 'destructive' ? 'destructive' : 'default'}
                            onClick={() => {
                                setOpen(false);
                                onConfirm();
                            }}
                        >
                            {confirmLabel}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
