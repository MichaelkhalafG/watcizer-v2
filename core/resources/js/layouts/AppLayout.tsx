import { type PropsWithChildren, useEffect } from 'react';
import { usePage } from '@inertiajs/react';

import type { SharedProps } from '@/types';

/**
 * RTL-ready application shell. Direction and language come from the server
 * (`dir`/`locale` shared props); the <html> element is set server-side in
 * app.blade.php and kept in sync here for client-side navigations.
 */
export default function AppLayout({ title, children }: PropsWithChildren<{ title?: string }>) {
    const { app, locale, dir } = usePage<SharedProps>().props;

    useEffect(() => {
        document.documentElement.setAttribute('dir', dir);
        document.documentElement.setAttribute('lang', locale);
    }, [dir, locale]);

    return (
        <div dir={dir} className="min-h-screen bg-muted/40">
            <header className="border-b bg-background">
                <div className="mx-auto flex h-14 max-w-7xl items-center justify-between px-4">
                    <span className="text-base font-semibold">{app.name}</span>
                    <span className="text-xs text-muted-foreground" data-testid="direction">
                        {locale} · {dir}
                    </span>
                </div>
            </header>
            <main className="mx-auto max-w-7xl px-4 py-8">
                {title ? <h1 className="mb-6 text-2xl font-semibold tracking-tight">{title}</h1> : null}
                {children}
            </main>
        </div>
    );
}
