/** Props shared with every Inertia page by App\Http\Middleware\HandleInertiaRequests. */

export interface BrandingProps {
    name: string;
    suffix: string;
    logo: string;
    logo_light: string;
    logo_width: number;
    logo_height: number;
}

/** Developer credit — shared only for an authenticated session, rendered only by the footer. */
export interface CreditProps {
    text: string;
    url: string | null;
}

export interface AuthUser {
    id: number;
    name: string;
    email: string;
    initials: string;
    roles: Array<{ value: string; label: string }>;
}

export interface NavItem {
    key: string;
    label: string;
    icon: string;
    route: string | null;
    href: string | null;
    ability: string;
    /** Set when the screen is not built yet ("4B"), which renders the item disabled. */
    wave: string | null;
    active: boolean;
}

export interface NavGroup {
    key: string;
    label: string;
    items: NavItem[];
}

/**
 * Ability map for hiding affordances. NOT authorisation — every route is checked on the server
 * (see EnsureDashboardAccess + the `can:` middleware, proven by RouteAuthorizationTest).
 */
export type Abilities = Record<string, boolean>;

export interface SharedProps {
    app: { name: string };
    locale: 'ar' | 'en';
    dir: 'rtl' | 'ltr';
    branding: BrandingProps;
    credit: CreditProps | null;
    auth: { user: AuthUser | null };
    abilities: Abilities;
    nav: NavGroup[];
    flash: { status: string | null; error: string | null };
    errors: Record<string, string>;
    [key: string]: unknown;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = SharedProps & T;

/** One crumb in the shell's breadcrumb trail; the last one is rendered as the current page. */
export interface Crumb {
    label: string;
    href?: string;
}

// ── the server-side table contract (App\Support\Table\TableQuery) ────────────────────────────

export interface TableMeta {
    page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number | null;
    to: number | null;
    sort: string | null;
    direction: 'asc' | 'desc';
    search: string | null;
    filters: Record<string, string | null>;
    sortable: string[];
    filterable: string[];
}

export interface TablePayload<Row> {
    data: Row[];
    meta: TableMeta;
}
