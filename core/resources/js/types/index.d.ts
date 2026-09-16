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
    /** A count worth the operator's eye, or null. Zero is never sent. */
    badge: number | null;
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
    /** The active locale's dictionary. Empty until the i18n backlog's step 1 — `t()` falls back. */
    translations: Translations;
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

/**
 * The flat `key => string` dictionary for the active locale (i18n step 0).
 *
 * Empty today: `t()` from `@/lib/i18n` falls back to the literal it is given, so the seam exists
 * without anything having been translated yet.
 */
export type Translations = Record<string, string>;

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
    /**
     * Declared filters that are not columns — the server applies them itself
     * (App\Support\Table\TableQuery::virtual). The table treats them exactly like the others;
     * the distinction only matters on the server.
     */
    virtual: string[];
    /**
     * Did the SERVER offer a CSV of this view? The button is rendered from this and nothing else:
     * a screen whose controller declared no export columns has no export, and the client never
     * decides what may leave the database.
     */
    exportable: boolean;
}

export interface TablePayload<Row> {
    data: Row[];
    meta: TableMeta;
}

/**
 * Whether a CREATE control may be offered before the write-switch, and the sentence to show
 * instead (App\Domain\Catalog\PreSwitch).
 *
 * A created row is DELETED by the next rebuild — the catalogue tables are rebuilt from the legacy
 * app on every rehearsal and on switch night — so creating is refused until the switch, while
 * editing, browsing and training stay open. The server refuses again on the write path; this is
 * what stops the team discovering it by clicking.
 */
export interface PreSwitchState {
    write_switch_completed: boolean;
    blocked: boolean;
    message: string | null;
    label: string;
}
