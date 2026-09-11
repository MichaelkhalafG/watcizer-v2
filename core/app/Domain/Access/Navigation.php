<?php

namespace App\Domain\Access;

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
 *  2. **Stub screens announce themselves.** Everything wave 4A does not build is listed with
 *     `wave: '4B'` and rendered disabled, so the team can see the shape of what is coming instead
 *     of finding dead links.
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
                    self::item('products', 'المنتجات', 'Package', Role::MANAGE_CATALOG, wave: '4B'),
                    self::item('categories', 'التصنيفات', 'FolderTree', Role::MANAGE_CATALOG, wave: '4B'),
                    self::item('variants', 'المقاسات والألوان', 'Layers', Role::MANAGE_CATALOG, wave: '4B'),
                    self::item('placement', 'العرض والترتيب', 'ListOrdered', Role::MANAGE_PLACEMENT, wave: '4B'),
                    self::item('units', 'الوحدات', 'Ruler', Role::MANAGE_CATALOG, wave: '4B'),
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
     * @return NavItem
     */
    private static function item(string $key, string $label, string $icon, string $ability, ?string $route = null, ?string $wave = null): array
    {
        $href = $route !== null && Route::has($route) ? route($route) : null;

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
