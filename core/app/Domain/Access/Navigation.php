<?php

namespace App\Domain\Access;

use App\Domain\Orders\NewOrders;
use App\Http\Middleware\EnsureStorefrontScope;
use App\Models\Storefront\Storefront;
use App\Models\User;
use App\Support\ManageText;
use Illuminate\Support\Facades\Route;

/**
 * The dashboard's navigation, defined ONCE in PHP and filtered by ability before it is shared with
 * the shell.
 *
 * Two reasons it lives here rather than in the React sidebar:
 *
 *  1. **The nav and the route guard read the same ability name.** A screen that a data-entry user
 *     cannot open also cannot appear in their sidebar, and neither statement is written twice.
 *     (The nav is presentation; the route middleware is the authorisation. Hiding is never the
 *     control — `RouteAuthorizationTest` proves the server refuses.)
 *  2. **Stub screens announce themselves.** Anything not built yet carries its wave (`4C`, `4D`)
 *     and renders disabled, so the team can see the shape of what is coming instead of finding
 *     dead links. Wave 4B turned four of those stubs into real links — products, categories,
 *     placement and the lookup lists — and `ShellTest` asserts the flip in both directions: a
 *     built item must carry an href and NO wave badge, which is the test that would catch a
 *     shipped screen the sidebar still calls "coming in 4B".
 *
 * @phpstan-type NavItem array{key: string, label: string, icon: string, route: string|null, href: string|null, ability: string, wave: string|null, active: bool, badge: int|null}
 * @phpstan-type NavGroup array{key: string, label: string, items: list<NavItem>}
 */
final class Navigation
{
    /**
     * @return list<NavGroup>
     */
    public static function for(?User $user): array
    {
        // One indexed COUNT, and only for somebody who may see orders — see {@see NewOrders}.
        $newOrders = app(NewOrders::class)->countFor($user);

        $roles = app(Roles::class);
        /*
         * MIRRORS `Abilities::register()` EXACTLY, and must keep doing so.
         *
         * This was `isAdmin($user) || can(...)` — a second authorisation rule that agreed with the
         * Gate only by coincidence. The moment `MANAGE_MEDIA_PRUNE` became an ability admins do
         * NOT hold, the two disagreed: the route refused and the sidebar still offered the link.
         *
         * So the admin short-circuit is scoped to `Role::ABILITIES` here for the same reason
         * `Gate::before` scopes it there. Presentation may hide more than the server refuses; it
         * must never offer more.
         */
        $can = fn (string $ability): bool => $user !== null && (
            (in_array($ability, Role::ABILITIES, true) && $roles->isAdmin($user))
            || $roles->can($user, $ability)
        );
        $storefrontId = self::storefrontForNav($user);

        /*
         * ── Labels come off the SHARED English file, and mostly off keys that already exist ──
         *
         * The nav says "الطلبات" and so does the orders screen's own breadcrumb, so both read
         * `common.orders`. That is the point of the server and the client sharing one
         * `lang/en/manage.php`: a second key with the same text is not a duplicate, it is two
         * English strings that will drift the first time somebody edits one of them.
         *
         * Only the GROUP headings are new (`nav.*`) — nothing else in the dashboard says them.
         */
        $groups = [
            [
                'key' => 'overview',
                'label' => ManageText::t('nav.overview', 'نظرة عامة'),
                'items' => [
                    self::item('home', ManageText::t('common.home', 'الرئيسية'), 'LayoutDashboard', Role::VIEW_DASHBOARD, route: 'manage.home'),
                ],
            ],
            [
                'key' => 'catalog',
                'label' => ManageText::t('nav.catalog', 'الكتالوج'),
                'items' => [
                    // Wave 4B, built. Three of these name a storefront in their URL, so the nav
                    // carries the storefront the user is currently looking at (or the first one
                    // their grant reaches) — a link that dropped the segment would 404.
                    self::item('products', ManageText::t('products.title', 'المنتجات'), 'Package', Role::MANAGE_CATALOG, route: 'manage.products.index', params: ['storefront' => $storefrontId]),
                    self::item('categories', ManageText::t('categories.title', 'التصنيفات'), 'FolderTree', Role::MANAGE_CATALOG, route: 'manage.categories.index', params: ['storefront' => $storefrontId]),
                    self::item('placement', ManageText::t('placement.title', 'العرض والترتيب'), 'ListOrdered', Role::MANAGE_PLACEMENT, route: 'manage.placement.index', params: ['storefront' => $storefrontId]),
                    // Brands and the eleven lookup lists are one screen, so one item. There is no
                    // "variants" item on purpose: the panel lives inside the product form, and a
                    // nav entry for it would promise a screen that does not exist.
                    self::item('lookups', ManageText::t('lookups.title', 'الماركات والقوائم'), 'Ruler', Role::MANAGE_CATALOG, route: 'manage.lookups.index', params: ['list' => 'brands']),
                    /*
                     * Units get an item of their OWN, beside the lookup lists rather than inside
                     * them (wave 4D, task C3). The lookup screen edits a row's name; this one moves
                     * 231 measurements off a unit that is really a clothing size and then takes it
                     * out of the picker. Different verb, different refusal, different screen.
                     */
                    self::item('units', ManageText::t('units.title', 'وحدات القياس'), 'Scale', Role::MANAGE_CATALOG, route: 'manage.units.index'),
                ],
            ],
            [
                'key' => 'operations',
                'label' => ManageText::t('nav.operations', 'التشغيل'),
                'items' => [
                    // Wave 4C, built. Neither names a storefront in its URL: the team works ONE
                    // order queue and ONE stock list and filters them, because partitioning the
                    // shop floor by storefront would mean two tabs to run one day.
                    self::item('orders', ManageText::t('common.orders', 'الطلبات'), 'ShoppingCart', Role::VIEW_ORDERS, route: 'manage.orders.index', badge: $newOrders),
                    // The people who buy — read-only, same ability as the queue (wave 4D).
                    self::item('customers', ManageText::t('common.customers', 'العملاء'), 'Contact', Role::VIEW_ORDERS, route: 'manage.customers.index'),
                    self::item('inventory', ManageText::t('common.inventory', 'المخزون'), 'Boxes', Role::MANAGE_INVENTORY, route: 'manage.inventory.index'),
                    // Shipping prices (wave 4D). The ability here is the one that opens the
                    // SCREEN — deliberately `VIEW_DASHBOARD`, because data-entry quote the
                    // delivery price on the telephone. Every write is behind MANAGE_SHIPPING
                    // on the route, so a data-entry operator sees the list and no buttons.
                    self::item('shipping', ManageText::t('shipping.title', 'أسعار الشحن'), 'Truck', Role::VIEW_DASHBOARD, route: 'manage.shipping.index'),
                    // Still a stub: offers, banners and blogs were NOT in the 4C brief (orders,
                    // inventory, users-and-roles and payments were), so the badge says 4D rather
                    // than keeping a wave number the screen missed.
                    /*
                     * Banners and Blogs are TWO items now (developer, 2026-09-14). The combined
                     * "offers, banners and articles" label is gone: offers became the promotions
                     * engine (§3.16), so the section is two unrelated jobs — a home-page image with
                     * a window, and long-form content — and one label for both described neither.
                     *
                     * Blogs keeps a `wave` stub: there is no screen and, on this dump, no data
                     * (legacy `blogs` is empty). An item with a real href and nothing behind it is
                     * worse than one that says "not yet".
                     */
                    self::item('banners', ManageText::t('banners.title', 'البانرات'), 'Image', Role::MANAGE_LEGACY_CONTENT, route: 'manage.banners.index', params: ['storefront' => $storefrontId]),
                    self::item('blogs', ManageText::t('nav.blogs', 'المقالات'), 'Newspaper', Role::MANAGE_LEGACY_CONTENT, wave: 'later'),
                    /*
                     * Promotions (4D, built 2026-09-13). No `{storefront}` segment, unlike
                     * payments: a rule is authored once and the operator ticks which storefronts
                     * it applies to, exactly like product placement. Admin-only — data-entry does
                     * not hold `manage-promotions`, so this item does not render for them.
                     */
                    self::item('promotions', ManageText::t('promotions.title', 'العروض الترويجية'), 'Gift', Role::MANAGE_PROMOTIONS, route: 'manage.promotions.index'),
                ],
            ],
            [
                'key' => 'settings',
                'label' => ManageText::t('nav.settings', 'الإعدادات'),
                'items' => [
                    self::item('storefronts', ManageText::t('common.storefronts', 'المتاجر'), 'Store', Role::MANAGE_STOREFRONTS, route: 'manage.storefronts.index'),
                    self::item('users', ManageText::t('users.title', 'المستخدمون والصلاحيات'), 'Users', Role::MANAGE_USERS, route: 'manage.users.index'),
                    // Who changed what (wave 4D). Beside the users screen because it answers the
                    // question that screen raises: these people can change things — what did they?
                    self::item('activity', ManageText::t('activity.title', 'سجل النشاط'), 'History', Role::MANAGE_USERS, route: 'manage.activity.index'),
                    // Payments ARE per-storefront — "which account takes this money" is a
                    // per-storefront question — so this link carries the segment like the catalog
                    // ones do, and the scope middleware checks the grant behind it.
                    self::item('payments', ManageText::t('payments.title', 'وسائل الدفع'), 'CreditCard', Role::MANAGE_PAYMENTS, route: 'manage.payments.index', params: ['storefront' => $storefrontId]),
                    /*
                     * Media cleanup sits in SETTINGS, not in the catalogue (wave 4D, task C4).
                     * `manage-catalog` is data-entry's ability and this page deletes files the live
                     * legacy storefront serves; it is gated on `manage-settings`, which only an
                     * administrator holds.
                     */
                    // Hidden unless the person holds the restricted ability — which no role
                    // grants implicitly, so for everybody else this item simply is not there.
                    self::item('media-prune', ManageText::t('media.title', 'تنظيف الوسائط'), 'Trash2', Role::MANAGE_MEDIA_PRUNE, route: 'manage.media.prune'),
                ],
            ],
        ];

        // Drop items the user may not reach, then drop groups that ended up empty.
        $out = [];
        foreach ($groups as $group) {
            $items = array_values(array_filter($group['items'], fn (array $item): bool => $can($item['ability'])));
            if ($items === []) {
                continue;
            }
            $group['items'] = $items;
            $out[] = $group;
        }

        return $out;
    }

    /**
     * The storefront the storefront-scoped nav links point at.
     *
     * The one in the URL when the user is already inside a storefront screen (so the sidebar keeps
     * them where they are), otherwise the lowest-id storefront their grant reaches. A data-entry
     * user scoped to Brand Fashion must not be handed a Watchizer link that answers 404
     * (study §3.11.14 — out of scope is 404, and a nav item that always 404s is worse than none).
     */
    private static function storefrontForNav(?User $user): int
    {
        $current = request()->route(EnsureStorefrontScope::PARAMETER);
        if ($current instanceof Storefront) {
            return $current->id;
        }
        if (is_numeric($current)) {
            return (int) $current;
        }

        $scope = $user === null ? [] : app(Roles::class)->storefrontScope($user);
        $query = Storefront::query()->where('is_active', true)->orderBy('id');
        if (is_array($scope) && $scope !== []) {
            $query->whereIn('id', $scope);
        }

        $id = $query->value('id');

        // Watchizer is storefront 1 by construction (deterministic ids, §2.9.3), so it is the
        // honest fallback when there is nothing else to go on.
        return is_numeric($id) ? (int) $id : Storefront::WATCHIZER_ID;
    }

    /**
     * @param  array<string, int|string>  $params
     * @return NavItem
     */
    private static function item(string $key, string $label, string $icon, string $ability, ?string $route = null, ?string $wave = null, array $params = [], ?int $badge = null): array
    {
        $href = $route !== null && Route::has($route) ? route($route, $params) : null;

        // Active means "this item's section is what the user is looking at": the route itself, or
        // anything below it (`manage.storefronts.edit` lights up `manage.storefronts.index`).
        // Compared on the route NAME, never on the URL, so a nested path cannot light two items.
        $current = (string) Route::currentRouteName();
        $section = $route === null ? null : (str_ends_with($route, '.index') ? substr($route, 0, -6) : $route);
        $active = $route !== null && ($current === $route || ($section !== null && str_starts_with($current, $section.'.')));

        return [
            'key' => $key,
            'label' => $label,
            'icon' => $icon,
            'route' => $route,
            'href' => $href,
            'ability' => $ability,
            'wave' => $wave,
            'active' => $active,
            /*
             * A COUNT, or null for "nothing to say". Zero is deliberately NOT rendered: a badge
             * reading 0 beside every item is chrome the eye learns to skip, and the one day it
             * says 3 it will be skipped too.
             */
            'badge' => $badge !== null && $badge > 0 ? $badge : null,
        ];
    }
}
