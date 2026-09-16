<?php

namespace App\Domain\Access;

use App\Support\ManageText;

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

    /*
     * A role that carries ONE ability and nothing else — the media prune.
     *
     * It exists because `media:prune` deletes files the LIVE legacy storefront still serves, and
     * "administrator" is too many people for that. Nobody holds it implicitly: an admin does not
     * get it from `Gate::before` (see `ABILITIES` below), and data-entry has never had it. Somebody
     * has to be granted it deliberately, from the server shell, and can be un-granted the same way.
     *
     * A ROLE rather than a per-user ability row because that is the mechanism this schema already
     * has — `core_user_roles` stores a role, `manage:role` grants one, and `Staff::clear()` revokes
     * every case. Inventing a second grant mechanism for one ability would be a second thing to
     * audit. It is additive: the holder keeps their admin grant and gains this on top.
     */
    case MediaPruner = 'media_pruner';

    /**
     * Everything an ADMINISTRATOR holds. `Abilities::register()` short-circuits `Gate::before` on
     * exactly this list, so adding an ability here hands it to every admin — which is right for
     * almost all of them and wrong for {@see self::RESTRICTED}.
     */
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
        self::MANAGE_PROMOTIONS,
        self::MANAGE_SHIPPING,
        self::EXPORT_DATA,
    ];

    /**
     * Abilities NO role holds implicitly — not even an administrator.
     *
     * Deliberately absent from {@see self::ABILITIES} so `Gate::before` does not grant them, and
     * present in {@see self::ALL} so they are still DEFINED and therefore grantable. An ability
     * that is merely undefined is denied for the wrong reason: it cannot be given to anybody, and
     * nothing says why.
     */
    public const RESTRICTED = [
        self::MANAGE_MEDIA_PRUNE,
    ];

    /** Every ability that exists, restricted ones included. Used to register the gates. */
    public const ALL = [...self::ABILITIES, ...self::RESTRICTED];

    public const VIEW_DASHBOARD = 'view-dashboard';

    public const MANAGE_CATALOG = 'manage-catalog';               // products, categories, units (4B)

    public const MANAGE_PLACEMENT = 'manage-placement';           // visibility, sort, featured (4B)

    public const MANAGE_LEGACY_CONTENT = 'manage-legacy-content'; // offers, banners, blogs (4C)

    public const MANAGE_MEDIA = 'manage-media';                   // uploads

    /**
     * DELETE FILES FROM THE SHARED TREE (wave 4D review).
     *
     * Separate from `MANAGE_MEDIA`, which is uploading, and from `MANAGE_SETTINGS`, which every
     * administrator holds. `media:prune` removes files the live legacy storefront is still serving;
     * getting it wrong shows broken images to customers and there is no undo. So it is the one
     * ability in {@see self::RESTRICTED}: granted by name, to a person, on purpose.
     *
     *     php artisan manage:role grant <email> media_pruner
     */
    public const MANAGE_MEDIA_PRUNE = 'manage-media-prune';

    /*
     * Orders are THREE abilities, not one (developer decision 2026-09-11, AGENTS §2.7).
     *
     * Data-entry runs the shop day to day: they need to see an order and move it along the whole
     * fulfilment flow — **pending → processing → shipped → delivered → completed** since the enum
     * was widened on 2026-09-12. What they must not do is the part that moves MONEY and STOCK
     * back — a cancellation returns units to the ledger and a refund is a financial act. Splitting
     * the ability is the only way to express that; a single `manage-orders` would have forced the
     * choice between "cannot work" and "can refund".
     *
     * **DECIDED 2026-09-13: `delivered → completed` stays HERE, with data-entry.** `completed` means
     * CLOSED, and it is `delivered` — not `completed` — that ends the cancel path
     * (`OrderFulfilment::UNCANCELLABLE`), because once the customer has the goods what follows is a
     * return. So closing an order carries no consequence that delivering it had not already
     * carried, and gating it would have queued every order behind an administrator for no safety
     * gain. `OrderStatusFlowTest` states this rule, so a future change to it cannot pass unnoticed.
     * If an admin lever after delivery is ever wanted, the cheaper shape is to let `cancel-orders`
     * cancel a `delivered` order too and then gate `completed` — one decision, not two.
     */
    public const VIEW_ORDERS = 'view-orders';                     // read an order, its lines, its history (4C)

    public const MANAGE_ORDER_FULFILMENT = 'manage-order-fulfilment'; // pending → … → completed (4C)

    public const CANCEL_ORDERS = 'cancel-orders';                 // cancel + refund: money and stock (4C, admin only)

    public const MANAGE_INVENTORY = 'manage-inventory';           // stock adjustments THROUGH InventoryService (4C)

    public const MANAGE_STOREFRONTS = 'manage-storefronts';       // storefront rows and settings

    public const MANAGE_USERS = 'manage-users';                   // role grants (4A: manage:role command, 4C: screen)

    public const MANAGE_SETTINGS = 'manage-settings';             // application settings

    public const MANAGE_PAYMENTS = 'manage-payments';             // providers and methods (4D, §3.9)

    /*
     * Promotions (4D, §3.16.6). ADMIN ONLY, and deliberately not given to data-entry: a promotion
     * moves money and gives away stock, which is the same reasoning that keeps `cancel-orders`
     * away from them. Data-entry can see the gift leave in the order and the ledger; they cannot
     * decide that it should.
     */
    public const MANAGE_PROMOTIONS = 'manage-promotions';

    /**
     * DOWNLOAD A SCREEN AS A FILE (review 🔴-2, 2026-09-15).
     *
     * An export is a different act from reading a screen. The screen shows one operator one page
     * at a time, inside a session, on a device the shop controls; a CSV is the whole filtered set,
     * detached, forwardable and permanent — the orders export carries customer names and phone
     * numbers out of the building in one click.
     *
     * So the screens keep FULL visibility for both roles — data-entry handle orders and telephone
     * customers, and hiding the phone number from them was the wrong fix — and the FILE is
     * admin-only. It is an ability rather than an `isAdmin()` check so it can be granted to a
     * specific person later without touching a route.
     */
    /**
     * SHIPPING PRICES (wave 4D, the handover blocker).
     *
     * The governorate list and what delivery to each one costs. Admin only, for the same reason
     * `MANAGE_PROMOTIONS` and `CANCEL_ORDERS` are: this is MONEY. A wrong number here is charged to
     * every customer in that governorate until somebody notices, and the shop either eats the
     * difference or overcharges — both of which are discovered from the accounts, not the screen.
     *
     * The SCREEN itself is readable by data-entry, exactly as the orders queue is: "how much is
     * delivery to Aswan?" is a question they answer on the telephone all day, and making them ask
     * an administrator to look it up would be the same mistake as hiding a customer's phone number
     * from the people who ring customers. Read the list, change nothing.
     */
    public const MANAGE_SHIPPING = 'manage-shipping';

    public const EXPORT_DATA = 'export-data';

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
            /*
             * EXACTLY one ability, and deliberately not `VIEW_DASHBOARD`: this grant is additive
             * to whatever else the person holds, so it adds a power rather than describing a job.
             * On its own it opens the prune screen and nothing else.
             */
            self::MediaPruner => [self::MANAGE_MEDIA_PRUNE],
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

    /**
     * What this role is CALLED. The enum's `value` (`admin`, `data_entry`) is what `core_user_roles`
     * stores and what every grant is compared on; it is not touched here.
     *
     * Two paths, because there are two audiences:
     *
     *  • `label('en')` is the CONSOLE's — `manage:role` prints an English report and has no operator
     *    session to read a locale preference from, so it answers in English directly.
     *  • `label()` is the dashboard's, and goes through the seam like everything else an operator
     *    reads. `media_pruner` shares `media.title` with the screen it opens: identical Arabic, and
     *    two keys for one phrase is two English strings waiting to disagree.
     */
    public function label(?string $locale = null): string
    {
        /*
         * ONE source for both audiences. The English used to be a second copy of the strings in
         * `lang/en/manage.php`, written inline for the console's benefit — exactly the duplication
         * this seam exists to remove. `ManageText::t()` takes an explicit locale instead, so
         * `label('en')` reads the same entry the dashboard reads and there is nothing to keep in
         * step by hand.
         *
         * `label()` with no argument follows the OPERATOR's locale; `label('ar')` and `label('en')`
         * answer in that language whoever is asking. The old default of `'ar'` fell through to the
         * seam, so asking for Arabic explicitly returned whatever the current operator happened to
         * be reading — right by accident in the tests, wrong for a caller that meant it.
         */
        return match ($this) {
            self::Admin => ManageText::t('users.role_admin', 'مدير النظام', [], $locale),
            self::DataEntry => ManageText::t('users.role_data_entry', 'إدخال بيانات', [], $locale),
            // `media_pruner` shares `media.title` with the screen it opens: identical Arabic, and
            // two keys for one phrase is two English strings waiting to disagree.
            self::MediaPruner => ManageText::t('media.title', 'تنظيف الوسائط', [], $locale),
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
