import { router } from '@inertiajs/react';
import { useEffect } from 'react';

import { useT } from '@/lib/i18n';

/**
 * Stops a half-finished form from being lost by a stray click.
 *
 * Two exits have to be covered and they are completely different mechanisms:
 *
 *  • **Closing or reloading the tab** — the browser's own `beforeunload` prompt. The message is
 *    the browser's, not ours; every engine ignores custom text.
 *  • **An Inertia navigation** (a sidebar link, a breadcrumb) — never touches `beforeunload`,
 *    because the page does not unload. Inertia's `before` event is the hook, and returning false
 *    cancels the visit.
 *
 * The confirm() is deliberate: a custom modal cannot block a synchronous navigation, and a team
 * that edits a product for ten minutes deserves the interruption.
 */
export function useDirtyGuard(dirty: boolean, message?: string): void {
    const t = useT();
    /*
     * Resolved HERE and not as a default parameter. A default is evaluated before the hook body
     * runs, and `useT()` cannot be called there — the same trap that caught `Combobox` and
     * `FormActions`.
     */
    const text = message ?? t('form.unsaved_leave_confirm', 'هناك تغييرات غير محفوظة. هل تريد المتابعة وفقدانها؟');

    useEffect(() => {
        if (!dirty) {
            return;
        }

        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        const stop = router.on('before', (event) => {
            // A form POST/PUT is the save itself — never guard against that, only against leaving.
            const method = event.detail.visit.method;
            if (method !== 'get') {
                return true;
            }

            return window.confirm(text);
        });

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            stop();
        };
    }, [dirty, text]);
}
