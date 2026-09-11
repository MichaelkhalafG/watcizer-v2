import { Link, usePage } from '@inertiajs/react';
import {
    Boxes,
    ChevronsLeft,
    ChevronsRight,
    Circle,
    CreditCard,
    FolderTree,
    LayoutDashboard,
    Layers,
    ListOrdered,
    Megaphone,
    Package,
    Ruler,
    ShoppingCart,
    Store,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';

import { Brand } from '@/components/manage/Brand';
import { cn } from '@/lib/utils';
import type { NavGroup, NavItem, SharedProps } from '@/types';

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
    ShoppingCart,
    Boxes,
    Megaphone,
    Store,
    Users,
    CreditCard,
};

function NavIcon({ name, className }: { name: string; className?: string }) {
    const Icon = ICONS[name] ?? Circle;

    return <Icon className={className} />;
}

function Item({ item, collapsed }: { item: NavItem; collapsed: boolean }) {
    const stub = item.href === null;
    const shared = cn(
        'group relative flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm transition-colors',
        collapsed && 'justify-center px-0',
        item.active
            ? 'bg-brand text-brand-foreground shadow-sm'
            : 'text-zinc-300 hover:bg-white/10 hover:text-white',
        stub && 'cursor-not-allowed text-zinc-500 hover:bg-transparent hover:text-zinc-500',
    );

    const body = (
        <>
            <NavIcon name={item.icon} className="h-[18px] w-[18px] shrink-0" />
            {collapsed ? null : <span className="truncate">{item.label}</span>}
            {!collapsed && item.wave !== null ? (
                <span className="ms-auto rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-medium text-zinc-300">
                    {item.wave}
                </span>
            ) : null}
        </>
    );

    // A stub is a real, focusable element that announces itself rather than a dead link: the team
    // can see what is coming in 4B/4C/4D without clicking into a 404.
    if (stub) {
        return (
            <li>
                <span
                    className={shared}
                    aria-disabled="true"
                    title={collapsed ? `${item.label} — قادم في ${item.wave}` : `قادم في ${item.wave}`}
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
                    'mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-500',
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

    return (
        <div
            className={cn(
                'flex h-full flex-col bg-zinc-900 text-zinc-100',
                inSheet ? 'w-full' : 'fixed inset-y-0 start-0 z-40 hidden border-e border-zinc-800 lg:flex',
                !inSheet && (collapsed ? 'w-sidebar-rail' : 'w-sidebar'),
            )}
        >
            <div className={cn('flex h-header items-center gap-2 border-b border-zinc-800 px-4', collapsed && 'justify-center px-0')}>
                <Brand variant="light" className={cn('h-7', collapsed && 'h-6')} />
                {collapsed ? null : (
                    <span className="truncate text-sm font-medium text-zinc-400" title={`${branding.name} ${branding.suffix}`}>
                        {branding.suffix}
                    </span>
                )}
            </div>

            <nav aria-label="القائمة الرئيسية" className="flex-1 overflow-y-auto scrollbar-thin px-3 py-5">
                {nav.map((group) => (
                    <Group key={group.key} group={group} collapsed={collapsed} />
                ))}
            </nav>

            {onToggle ? (
                <button
                    type="button"
                    onClick={onToggle}
                    className="flex h-11 items-center justify-center gap-2 border-t border-zinc-800 text-xs text-zinc-400 transition-colors hover:bg-white/5 hover:text-white"
                    aria-pressed={collapsed}
                >
                    {/* The chevron points the way the rail will move, which in RTL is the mirror image. */}
                    {collapsed ? (
                        <ChevronsLeft className="h-4 w-4 rtl:rotate-180" />
                    ) : (
                        <>
                            <ChevronsRight className="h-4 w-4 rtl:rotate-180" />
                            <span>تصغير القائمة</span>
                        </>
                    )}
                </button>
            ) : null}
        </div>
    );
}
