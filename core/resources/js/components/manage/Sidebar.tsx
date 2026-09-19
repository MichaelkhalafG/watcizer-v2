import { Link, usePage } from '@inertiajs/react';
import {
    Boxes,
    Contact,
    ChevronsLeft,
    ChevronsRight,
    Circle,
    CreditCard,
    FolderTree,
    Gift,
    Image,
    LayoutDashboard,
    Layers,
    ListOrdered,
    Megaphone,
    Newspaper,
    Package,
    Ruler,
    Scale,
    ShoppingCart,
    Store,
    Trash2,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

import { Brand } from '@/components/manage/Brand';
import { cn } from '@/lib/utils';
import type { NavGroup, NavItem, SharedProps } from '@/types';
import { useT } from '@/lib/i18n';

/**
 * Nav items name their icon as a string on the server (App\Domain\Access\Navigation); this maps it
 * to a component.
 *
 * An EXPLICIT registry rather than `import * as Icons from 'lucide-react'`. The barrel import
 * works, is one line shorter, and put **874 KB** into the shell's chunk — the whole icon library,
 * shipped to a tablet on 4G, because a dynamic lookup defeats tree-shaking. Adding a nav item
 * means adding its icon here, which is the correct amount of friction.
 */
const ICONS: Record<string, ComponentType<{ className?: string }>> = {
    LayoutDashboard,
    Package,
    FolderTree,
    Layers,
    ListOrdered,
    Ruler,
    Scale,
    ShoppingCart,
    Boxes,
    Contact,
    Megaphone,
    Newspaper,
    Image,
    Gift,
    Store,
    Trash2,
    Users,
    CreditCard,
};

function NavIcon({ name, className }: { name: string; className?: string }) {
    const Icon = ICONS[name] ?? Circle;

    return <Icon className={className} />;
}

function Item({ item, collapsed }: { item: NavItem; collapsed: boolean }) {
    const t = useT();
    const stub = item.href === null;
    const shared = cn(
        'group relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-colors',
        collapsed && 'justify-center px-0',
        item.active
            ? 'bg-brand text-brand-foreground shadow-sm'
            : 'text-sidebar-foreground/75 hover:bg-sidebar-accent hover:text-sidebar-foreground',
        stub && 'cursor-not-allowed text-sidebar-muted hover:bg-transparent hover:text-sidebar-muted',
    );

    const body = (
        <>
            <NavIcon name={item.icon} className="h-[18px] w-[18px] shrink-0" />
            {collapsed ? null : <span className="truncate">{item.label}</span>}
            {!collapsed && item.later ? (
                <span className="ms-auto rounded-full bg-sidebar-accent px-2 py-0.5 text-[11px] font-medium text-sidebar-muted">
                    {t('shell.later', 'لاحقًا')}
                </span>
            ) : null}
            {/*
              * The unseen-orders count. The server sends null rather than 0, so there is no badge
              * to ignore on a quiet morning — and the one that appears means something.
              *
              * It survives the COLLAPSED rail as a dot: the whole point is to catch the eye of
              * somebody working another screen, and hiding it when the sidebar is narrow would
              * hide it exactly when it is least in the way.
              */}
            {item.badge !== null && item.badge > 0 ? (
                collapsed ? (
                    <span
                        className="absolute end-1.5 top-1.5 h-2 w-2 rounded-full bg-brand ring-2 ring-sidebar"
                        aria-label={t('shell.new_count', ':count جديد', { count: item.badge })}
                    />
                ) : (
                    <span
                        className="ms-auto min-w-[1.5rem] rounded-full bg-brand px-1.5 py-0.5 text-center text-[11px] font-semibold text-brand-foreground tabular-nums"
                        aria-label={t('shell.new_count', ':count جديد', { count: item.badge })}
                    >
                        {item.badge}
                    </span>
                )
            ) : null}
        </>
    );

    /*
     * A parked item is a real, focusable element that announces itself rather than a dead link, so
     * the team can see the shape of the dashboard without clicking into a 404.
     *
     * The badge used to print the server's raw token — the sidebar said `later`, in English, on an
     * Arabic screen, and the tooltip read "قادم في later". It now says the same thing in the
     * reader's own language and promises no date, because none of these has one.
     */
    if (stub) {
        const parked = t('shell.later_hint', 'غير متاح بعد. سيُفتح لاحقًا.');

        return (
            <li>
                <span
                    className={shared}
                    aria-disabled="true"
                    title={collapsed ? `${item.label} — ${parked}` : parked}
                >
                    {body}
                </span>
            </li>
        );
    }

    return (
        <li>
            <Link
                href={item.href ?? '#'}
                className={shared}
                aria-current={item.active ? 'page' : undefined}
                title={collapsed ? item.label : undefined}
            >
                {body}
            </Link>
        </li>
    );
}

function Group({ group, collapsed }: { group: NavGroup; collapsed: boolean }) {
    return (
        <div className="mb-6">
            <p
                className={cn(
                    'mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-sidebar-muted',
                    collapsed && 'px-0 text-center',
                )}
            >
                {collapsed ? '•' : group.label}
            </p>
            <ul className="space-y-1">
                {group.items.map((item) => (
                    <Item key={item.key} item={item} collapsed={collapsed} />
                ))}
            </ul>
        </div>
    );
}

/**
 * The dashboard's navigation rail.
 *
 * Layout structure and rhythm follow the TailAdmin reference (fixed rail, uppercase group labels,
 * icon+label rows); it is built from our own primitives and tokens. It sits on the INLINE-START
 * edge, which is the right-hand side in Arabic, using `start-0`/`border-e` so the browser mirrors
 * it rather than us maintaining two sets of classes.
 */
export function Sidebar({
    collapsed,
    onToggle,
    inSheet = false,
}: {
    collapsed: boolean;
    onToggle?: () => void;
    inSheet?: boolean;
}) {
    const { nav, branding } = usePage<SharedProps>().props;
    const t = useT();

    return (
        <div
            className={cn(
                // The shell's own tokens (item 11, 2026-09-19): the rail used to be literally
                // `bg-zinc-900`, so it stayed black while the rest of the dashboard went light.
                'flex h-full flex-col bg-sidebar text-sidebar-foreground',
                inSheet ? 'w-full' : 'fixed inset-y-0 start-0 z-40 hidden border-e border-sidebar-border lg:flex',
                !inSheet && (collapsed ? 'w-sidebar-rail' : 'w-sidebar'),
            )}
        >
            <div className={cn('flex h-header items-center gap-2 border-b border-sidebar-border px-4', collapsed && 'justify-center px-0')}>
                {/* Two marks, one shown at a time. The mark is dark ink on transparency, so the
                    white-ink file is the only one readable on the dark rail and the only one NOT
                    readable on the light one — which is why the sidebar cannot simply keep using
                    `variant="light"` now that the rail follows the theme. Swapped in CSS rather
                    than from React state: the theme is a class on <html> and this needs no
                    hydration to be correct. */}
                <Brand className={cn('h-7 dark:hidden', collapsed && 'h-6')} />
                <Brand variant="light" className={cn('hidden h-7 dark:block', collapsed && 'h-6')} />
                {collapsed ? null : (
                    // Through the seam (D-13). This rendered `branding.suffix`, whose ENV default
                    // is «لوحة التحكم» — so the ENGLISH shell opened with an Arabic word beside
                    // the logo. What a deployment owns is the brand NAME; what the reader sees
                    // beside it is UI copy like any other.
                    <span
                        className="truncate text-sm font-medium text-sidebar-muted"
                        title={`${branding.name} ${t('shell.dashboard', 'لوحة التحكم')}`}
                    >
                        {t('shell.dashboard', 'لوحة التحكم')}
                    </span>
                )}
            </div>

            <nav aria-label={t('shell.main_menu', 'القائمة الرئيسية')} className="flex-1 overflow-y-auto scrollbar-thin px-3 py-5">
                {nav.map((group) => (
                    <Group key={group.key} group={group} collapsed={collapsed} />
                ))}
            </nav>

            {onToggle ? (
                <button
                    type="button"
                    onClick={onToggle}
                    className="flex h-11 items-center justify-center gap-2 border-t border-sidebar-border text-xs text-sidebar-muted transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground"
                    aria-pressed={collapsed}
                >
                    {/* The chevron points the way the rail will move, which in RTL is the mirror image. */}
                    {collapsed ? (
                        <ChevronsLeft className="h-4 w-4 rtl:rotate-180" />
                    ) : (
                        <>
                            <ChevronsRight className="h-4 w-4 rtl:rotate-180" />
                            <span>{t('shell.collapse_menu', 'تصغير القائمة')}</span>
                        </>
                    )}
                </button>
            ) : null}
        </div>
    );
}
