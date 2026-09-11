import { Dialog, Sheet } from '@/components/ui/dialog';
import { Head, usePage } from '@inertiajs/react';
import { type PropsWithChildren, type ReactNode, useCallback, useEffect, useState } from 'react';

import { Header } from '@/components/manage/Header';
import { Sidebar } from '@/components/manage/Sidebar';
import { Alert } from '@/components/ui/alert';
import { cn } from '@/lib/utils';
import type { Crumb, SharedProps } from '@/types';

/**
 * The shell every dashboard screen lives in (wave 4A).
 *
 * Structure: a fixed rail on the inline-start edge, a sticky header, a scrolling content column,
 * and a footer that carries the developer credit — footer ONLY, per the brief, which is why no
 * other component in this tree mentions it.
 *
 * RTL is not an afterthought here: `dir` comes from the server, the rail uses `start-*`/`border-e`
 * and the content column uses `ps-*`, so the browser mirrors the whole layout instead of us
 * shipping two sets of classes. On a tablet (< lg) the rail becomes a drawer that slides from the
 * same inline-start edge.
 */
export default function ManageLayout({
    title,
    crumbs = [],
    actions,
    children,
}: PropsWithChildren<{ title?: string; crumbs?: Crumb[]; actions?: ReactNode }>) {
    const { dir, locale, branding, credit, flash } = usePage<SharedProps>().props;
    const [collapsed, setCollapsed] = useState(false);
    const [navOpen, setNavOpen] = useState(false);

    useEffect(() => {
        document.documentElement.setAttribute('dir', dir);
        document.documentElement.setAttribute('lang', locale);
    }, [dir, locale]);

    // The rail's width is a per-person preference, so it persists like the theme does.
    useEffect(() => {
        try {
            setCollapsed(window.localStorage.getItem('manage.sidebar') === 'collapsed');
        } catch {
            // ignore
        }
    }, []);

    const toggle = useCallback(() => {
        setCollapsed((current) => {
            const next = !current;
            try {
                window.localStorage.setItem('manage.sidebar', next ? 'collapsed' : 'expanded');
            } catch {
                // ignore
            }

            return next;
        });
    }, []);

    return (
        <div dir={dir} className="min-h-screen bg-muted/40">
            {title ? <Head title={title} /> : null}

            <Sidebar collapsed={collapsed} onToggle={toggle} />

            {/* Tablet/phone drawer: same component, same nav data, no second implementation. */}
            <Dialog open={navOpen} onOpenChange={setNavOpen}>
                <Sheet title="القائمة الرئيسية" className="lg:hidden">
                    <Sidebar collapsed={false} inSheet />
                </Sheet>
            </Dialog>

            <div className={cn('flex min-h-screen flex-col transition-[padding] duration-200', collapsed ? 'lg:ps-sidebar-rail' : 'lg:ps-sidebar')}>
                <Header crumbs={crumbs} onOpenNav={() => setNavOpen(true)} />

                <main className="flex-1 px-4 py-6 sm:px-6">
                    <div className="mx-auto w-full max-w-7xl space-y-6">
                        {flash.status ? <Alert tone="success">{flash.status}</Alert> : null}
                        {flash.error ? <Alert tone="error">{flash.error}</Alert> : null}

                        {title ? (
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h1 className="text-xl font-semibold tracking-tight sm:text-2xl">{title}</h1>
                                {actions ? <div className="flex items-center gap-2">{actions}</div> : null}
                            </div>
                        ) : null}

                        {children}
                    </div>
                </main>

                <footer className="border-t bg-background px-4 py-4 sm:px-6">
                    <div className="mx-auto flex w-full max-w-7xl flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                        <span>
                            {branding.name} · {branding.suffix}
                        </span>
                        {/* The developer credit, from config, rendered here and nowhere else.
                            It is a separate shared prop that only exists for a signed-in session,
                            so the login screen cannot render it even by mistake. */}
                        {credit !== null ? (
                            <span data-testid="developer-credit">
                                {credit.url !== null ? (
                                    <a href={credit.url} className="hover:text-foreground hover:underline" rel="noopener noreferrer" target="_blank">
                                        {credit.text}
                                    </a>
                                ) : (
                                    credit.text
                                )}
                            </span>
                        ) : null}
                    </div>
                </footer>
            </div>
        </div>
    );
}
