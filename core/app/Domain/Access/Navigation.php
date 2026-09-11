<?php

namespace App\Domain\Access;

use App\Http\Middleware\EnsureStorefrontScope;
use App\Models\Storefront\Storefront;
use App\Models\User;
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
 * @phpstan-type NavItem array{key: string, label: string, icon: string, route: string|null, href: string|null, ability: string, wave: string|null, active: bool}
 * @phpstan-type NavGroup array{key: string, label: string, items: list<NavItem>}
 */
final class Navigation
{
    /**
     * @return list<NavGroup>
     */
    public static function for(?User $user): array
    {
        $roles = app(Roles::class);
        $can = fn (string $ability): bool => $user !== null && ($roles->isAdmin($user) || $roles->can($user, $ability));
        $storefrontId = self::storefrontForNav($user);

        $groups = [
            [
                'key' => 'overview',
                'label' => 'نظرة عامة',
                'items' => [
                    self::item('home', 'الرئيسية', 'LayoutDashboard', Role::VIEW_DASHBOARD, route: 'manage.home'),
                ],
            ],
            [
                'key' => 'catalog',
                'label' => 'الكتالوج',
                'items' => [
                    // Wave 4B, built. Three of these name a storefront in their URL, so the nav
                    // carries the storefront the user is currently looking at (or the first one
                    // their grant reaches) — a link that dropped the segment would 404.
                    self::item('products', 'المنتجات', 'Package', Role::MANAGE_CATALOG, route: 'manage.products.index', params: ['storefront' => $storefrontId]),
                    self::item('categories', 'التصنيفات', 'FolderTree', Role::MANAGE_CATALOG, route: 'manage.categories.index', params: ['storefront' => $storefrontId]),
                    self::item('placement', 'العرض والترتيب', 'ListOrdered', Role::MANAGE_PLACEMENT, route: 'manage.placement.index', params: ['storefront' => $storefrontId]),
                    // Brands and the eleven lookup lists are one screen, so one item. There is no
                    // "variants" item on purpose: the panel lives inside the product form, and a
                    // nav entry for it would promise a screen that does not exist.
                    self::item('lookups', 'الماركات والقوائم', 'Ruler', Role::MANAGE_CATALOG, route: 'manage.lookups.index', params: ['list' => 'brands']),
                ],
            ],
            [
                'key' => 'operations',
                'label' => 'التشغيل',
                'items' => [
                    self::item('orders', 'الطلبات', 'ShoppingCart', Role::VIEW_ORDERS, wave: '4C'),
                    self::item('inventory', 'المخزون', 'Boxes', Role::MANAGE_INVENTORY, wave: '4C'),
                    self::item('legacy-content', 'العروض والبانرات والمقالات', 'Megaphone', Role::MANAGE_LEGACY_CONTENT, wave: '4C'),
                ],
            ],
            [
                'key' => 'settings',
                'label' => 'الإعدادات',
                'items' => [
                    self::item('storefronts', 'المتاجر', 'Store', Role::MANAGE_STOREFRONTS, route: 'manage.storefronts.index'),
                    self::item('users', 'المستخدمون والصلاحيات', 'Users', Role::MANAGE_USERS, wave: '4C'),
                    self::item('payments', 'وسائل الدفع', 'CreditCard', Role::MANAGE_PAYMENTS, wave: '4D'),
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
    private static function item(string $key, string $label, string $icon, string $ability, ?string $route = null, ?string $wave = null, array $params = []): array
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
        ];
    }
}
