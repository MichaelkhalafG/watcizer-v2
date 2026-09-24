import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, Menu, Moon, Sun, UserRound } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Ltr } from '@/components/ui/bidi';
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
import { useT } from '@/lib/i18n';
import { cn } from '@/lib/utils';

/**
 * Colour-scheme toggle. Per-browser and per-person (`localStorage`), because it is a comfort
 * setting for whoever is sitting there, not something the server should have an opinion about.
 */
function ThemeToggle() {
    const t = useT();
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
            aria-label={dark ? t('shell.light_mode', 'الوضع النهاري') : t('shell.dark_mode', 'الوضع الليلي')}
            title={dark ? t('shell.light_mode', 'الوضع النهاري') : t('shell.dark_mode', 'الوضع الليلي')}
        >
            {dark ? <Sun className="h-[18px] w-[18px]" /> : <Moon className="h-[18px] w-[18px]" />}
        </Button>
    );
}

/**
 * The language switch, in the header (W-5, 2026-09-19).
 *
 * It was four steps deep — avatar → `الملف الشخصي` → scroll → select → Save — and not in the
 * header, which is where a bilingual team looks for it. That is the wrong cost for something the
 * same person flips several times a day when they are working with a colleague who reads the other
 * language.
 *
 * It posts to the SAME endpoint the profile screen uses, and `ProfileController::update()` is the
 * only writer: one place validates the locale, one place stores it, and the two controls cannot
 * drift into disagreeing about what a valid language is.
 *
 * Rendered as the two languages side by side rather than a dropdown, because there are exactly two
 * and each is written in ITSELF — `العربية` / `English` — which is the one label that needs no
 * translating and no guessing.
 */
function LocaleSwitch() {
    const t = useT();
    const { locale } = usePage<SharedProps>().props;

    const LANGUAGES = [
        { value: 'ar', label: 'العربية' }, // i18n-exempt: a language is named in its own language, whatever locale the page is in
        { value: 'en', label: 'English' },
    ];

    return (
        <div
            className="hidden items-center rounded-full border p-0.5 sm:flex"
            role="group"
            aria-label={t('profile.panel_language', 'لغة اللوحة')}
        >
            {LANGUAGES.map((language) => (
                <button
                    key={language.value}
                    type="button"
                    aria-pressed={locale === language.value}
                    onClick={() => {
                        if (locale === language.value) {
                            return;
                        }
                        /*
                         * A full visit, not `preserveState`: the locale changes the writing
                         * DIRECTION of the whole shell (`Preferences::directionFor`), so the page
                         * has to be re-rendered from the server rather than re-labelled in place.
                         */
                        router.put('/manage/profile', { locale: language.value });
                    }}
                    className={cn(
                        'rounded-full px-2.5 py-1 text-xs transition-colors',
                        locale === language.value
                            ? 'bg-accent font-medium text-foreground'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {language.label}
                </button>
            ))}
        </div>
    );
}

function UserMenu() {
    const t = useT();
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
                    aria-label={t('shell.user_account', 'حساب المستخدم')}
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
                    <span className="mt-0.5 block truncate text-xs font-normal text-muted-foreground">
                        <Ltr>{user.email}</Ltr>
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
                <DropdownMenuItem className="gap-2" onSelect={() => router.visit('/manage/profile')}>
                    <UserRound className="h-4 w-4" />
                    {t('common.profile', 'الملف الشخصي')}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    destructive
                    className="gap-2"
                    onSelect={() => router.post('/manage/logout')}
                >
                    <LogOut className="h-4 w-4" />
                    {t('shell.sign_out', 'تسجيل الخروج')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** The trail. The last crumb is the current page and is not a link. */
function Breadcrumbs({ crumbs }: { crumbs: Crumb[] }) {
    const t = useT();
    if (crumbs.length === 0) {
        return null;
    }

    return (
        <nav aria-label={t('shell.breadcrumb', 'مسار التنقل')} className="min-w-0">
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
    const t = useT();
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
            <Button type="button" variant="outline" size="icon" className="lg:hidden" onClick={onOpenNav} aria-label={t('shell.open_menu', 'فتح القائمة')}>
                <Menu className="h-[18px] w-[18px]" />
            </Button>

            <Breadcrumbs crumbs={crumbs} />

            <div className="ms-auto flex items-center gap-1">
                <LocaleSwitch />
                <ThemeToggle />
                <UserMenu />
            </div>
        </header>
    );
}
