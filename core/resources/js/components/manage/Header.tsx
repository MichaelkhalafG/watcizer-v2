import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Menu, Moon, Sun, UserRound } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { Crumb, SharedProps } from '@/types';

/**
 * Colour-scheme toggle. Per-browser and per-person (`localStorage`), because it is a comfort
 * setting for whoever is sitting there, not something the server should have an opinion about.
 */
function ThemeToggle() {
    const [dark, setDark] = useState<boolean>(() => document.documentElement.classList.contains('dark'));

    const apply = useCallback((next: boolean) => {
        document.documentElement.classList.toggle('dark', next);
        try {
            window.localStorage.setItem('manage.theme', next ? 'dark' : 'light');
        } catch {
            // A private window or blocked storage is not a reason to fail a click.
        }
        setDark(next);
    }, []);

    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            onClick={() => apply(!dark)}
            aria-label={dark ? 'الوضع النهاري' : 'الوضع الليلي'}
            title={dark ? 'الوضع النهاري' : 'الوضع الليلي'}
        >
            {dark ? <Sun className="h-[18px] w-[18px]" /> : <Moon className="h-[18px] w-[18px]" />}
        </Button>
    );
}

function UserMenu() {
    const { auth } = usePage<SharedProps>().props;
    const user = auth.user;
    if (user === null) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <button
                    type="button"
                    className="flex items-center gap-2 rounded-full py-1 pe-1 ps-3 text-sm transition-colors hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    aria-label="حساب المستخدم"
                >
                    <span className="hidden max-w-[12rem] truncate font-medium sm:block">{user.name}</span>
                    <span
                        aria-hidden="true"
                        className="flex h-8 w-8 items-center justify-center rounded-full bg-brand text-xs font-semibold text-brand-foreground"
                    >
                        {user.initials}
                    </span>
                </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-64">
                <DropdownMenuLabel>
                    <span className="block truncate">{user.name}</span>
                    <span className="mt-0.5 block truncate text-xs font-normal text-muted-foreground" dir="ltr">
                        {user.email}
                    </span>
                    <span className="mt-2 flex flex-wrap gap-1">
                        {user.roles.map((role) => (
                            <Badge key={role.value} variant="default">
                                {role.label}
                            </Badge>
                        ))}
                    </span>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem disabled className="gap-2">
                    <UserRound className="h-4 w-4" />
                    الملف الشخصي
                    <span className="ms-auto text-[11px] text-muted-foreground">4D</span>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    destructive
                    className="gap-2"
                    onSelect={() => router.post('/manage/logout')}
                >
                    <LogOut className="h-4 w-4" />
                    تسجيل الخروج
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The trail. The last crumb is the current page and is not a link. */
function Breadcrumbs({ crumbs }: { crumbs: Crumb[] }) {
    if (crumbs.length === 0) {
        return null;
    }

    return (
        <nav aria-label="مسار التنقل" className="min-w-0">
            <ol className="flex items-center gap-1.5 text-xs text-muted-foreground">
                {crumbs.map((crumb, index) => {
                    const last = index === crumbs.length - 1;

                    return (
                        <li key={`${crumb.label}-${index}`} className="flex min-w-0 items-center gap-1.5">
                            {crumb.href !== undefined && !last ? (
                                <Link href={crumb.href} className="truncate hover:text-foreground hover:underline">
                                    {crumb.label}
                                </Link>
                            ) : (
                                <span className={last ? 'truncate font-medium text-foreground' : 'truncate'} aria-current={last ? 'page' : undefined}>
                                    {crumb.label}
                                </span>
                            )}
                            {last ? null : <span aria-hidden="true">/</span>}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}

export function Header({ crumbs, onOpenNav }: { crumbs: Crumb[]; onOpenNav: () => void }) {
    // Restore the stored scheme once, on mount, before the first paint the user notices.
    useEffect(() => {
        try {
            const stored = window.localStorage.getItem('manage.theme');
            if (stored === 'dark') {
                document.documentElement.classList.add('dark');
            }
        } catch {
            // ignore
        }
    }, []);

    return (
        <header className="sticky top-0 z-30 flex h-header items-center gap-3 border-b bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/80">
            <Button type="button" variant="outline" size="icon" className="lg:hidden" onClick={onOpenNav} aria-label="فتح القائمة">
                <Menu className="h-[18px] w-[18px]" />
            </Button>

            <Breadcrumbs crumbs={crumbs} />

            <div className="ms-auto flex items-center gap-1">
                <ThemeToggle />
                <UserMenu />
            </div>
        </header>
    );
}
