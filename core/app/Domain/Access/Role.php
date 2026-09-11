<?php

namespace App\Domain\Access;

/**
 * The two core roles (AGENTS §2.7, §2.18) and what each may do.
 *
 * Deliberately NOT derived from legacy `users.type` (`User|Admin|SuperAdmin`): that enum belongs to
 * the legacy Blade dashboard and to the storefront's own idea of an account, and a customer who is
 * `Admin` there must not inherit the new dashboard. Access to `/manage` exists only where a
 * `core_user_roles` row exists.
 *
 * Abilities are named after the JOB, not the screen, so 4B/4C/4D wire new screens to an existing
 * ability instead of inventing one per page. Every one of them is checked on the SERVER, at the
 * route; hiding a nav item is presentation, never authorisation.
 */
enum Role: string
{
    case Admin = 'admin';
    case DataEntry = 'data_entry';

    /** Everything the dashboard can authorise. Route middleware and `@can` both use these names. */
    public const ABILITIES = [
        self::VIEW_DASHBOARD,
        self::MANAGE_CATALOG,
        self::MANAGE_PLACEMENT,
        self::MANAGE_LEGACY_CONTENT,
        self::MANAGE_MEDIA,
        self::VIEW_ORDERS,
        self::MANAGE_ORDER_FULFILMENT,
        self::CANCEL_ORDERS,
        self::MANAGE_INVENTORY,
        self::MANAGE_STOREFRONTS,
        self::MANAGE_USERS,
        self::MANAGE_SETTINGS,
        self::MANAGE_PAYMENTS,
    ];

    public const VIEW_DASHBOARD = 'view-dashboard';

    public const MANAGE_CATALOG = 'manage-catalog';               // products, categories, units (4B)

    public const MANAGE_PLACEMENT = 'manage-placement';           // visibility, sort, featured (4B)

    public const MANAGE_LEGACY_CONTENT = 'manage-legacy-content'; // offers, banners, blogs (4C)

    public const MANAGE_MEDIA = 'manage-media';                   // uploads

    /*
     * Orders are THREE abilities, not one (developer decision 2026-09-11, AGENTS §2.7).
     *
     * Data-entry runs the shop day to day: they need to see an order and move it along
     * (processing → shipped). What they must not do is the part that moves MONEY and STOCK back —
     * a cancellation returns units to the ledger and a refund is a financial act. Splitting the
     * ability is the only way to express that; a single `manage-orders` would have forced the
     * choice between "cannot work" and "can refund".
     */
    public const VIEW_ORDERS = 'view-orders';                     // read an order, its lines, its history (4C)

    public const MANAGE_ORDER_FULFILMENT = 'manage-order-fulfilment'; // processing → shipped (4C)

    public const CANCEL_ORDERS = 'cancel-orders';                 // cancel + refund: money and stock (4C, admin only)

    public const MANAGE_INVENTORY = 'manage-inventory';           // stock adjustments THROUGH InventoryService (4C)

    public const MANAGE_STOREFRONTS = 'manage-storefronts';       // storefront rows and settings

    public const MANAGE_USERS = 'manage-users';                   // role grants (4A: manage:role command, 4C: screen)

    public const MANAGE_SETTINGS = 'manage-settings';             // application settings

    public const MANAGE_PAYMENTS = 'manage-payments';             // providers and methods (4D, §3.9)

    /**
     * What THIS role may do. `admin` is not listed: it passes everything through `Gate::before`,
     * which is why an ability added in 4B needs no edit here to reach an administrator.
     *
     * The order/stock split is settled (2026-09-11): data-entry views orders, moves fulfilment and
     * adjusts stock through `InventoryService`; cancelling, refunding, payments, storefronts and
     * users are admin. See {@see self::CANCEL_ORDERS} for why that is three abilities rather than
     * one, and AGENTS §2.7 for the decision itself.
     *
     * Stock adjustment being a data-entry job does NOT loosen anything underneath: every movement
     * still goes through the single door, is still ledgered, and is still refused at the wrong
     * level (wave 3.5). The role decides WHO may ask; the service decides what is allowed.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::Admin => self::ABILITIES,
            self::DataEntry => [
                self::VIEW_DASHBOARD,
                self::MANAGE_CATALOG,
                self::MANAGE_PLACEMENT,
                self::MANAGE_LEGACY_CONTENT,
                self::MANAGE_MEDIA,
                self::VIEW_ORDERS,
                self::MANAGE_ORDER_FULFILMENT,
                self::MANAGE_INVENTORY,
            ],
        };
    }

    public function can(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    /** Arabic first, English second — the dashboard's own convention. */
    public function label(string $locale = 'ar'): string
    {
        return match ($this) {
            self::Admin => $locale === 'en' ? 'Administrator' : 'مدير النظام',
            self::DataEntry => $locale === 'en' ? 'Data entry' : 'إدخال بيانات',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }
}
