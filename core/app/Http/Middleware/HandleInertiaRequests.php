<?php

namespace App\Http\Middleware;

use App\Domain\Access\Navigation;
use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * Everything the SHELL needs and nothing a screen should fetch for itself. `auth`, `nav` and
     * `abilities` are computed from the same {@see Roles} instance the route middleware uses, so
     * the sidebar cannot disagree with what the server allows.
     *
     * `abilities` is a flat map for the UI to hide affordances with. It is a convenience, NOT a
     * control: every route is authorised server-side (see `EnsureDashboardAccess` and the `can:`
     * middleware), and `tests/Feature/Manage/RouteAuthorizationTest.php` proves a data-entry
     * session gets 403 on admin routes with the menu out of the picture entirely.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        $roles = app(Roles::class);

        return [
            ...parent::share($request),
            'app' => ['name' => config('app.name')],
            'locale' => $locale,
            'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
            'branding' => Branding::forStorefront(),
            // Footer-only, and therefore shell-only: a guest's payload never carries it.
            'credit' => $user === null ? null : Branding::credit(),
            'auth' => [
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => trim($user->first_name.' '.$user->last_name),
                    'email' => $user->email,
                    'initials' => self::initials($user),
                    'roles' => array_map(
                        /** @return array{value: string, label: string} */
                        fn (Role $role): array => ['value' => $role->value, 'label' => $role->label($locale)],
                        $roles->rolesFor($user),
                    ),
                ],
            ],
            'abilities' => $user === null ? [] : self::abilities($roles, $user),
            'nav' => $user === null ? [] : Navigation::for($user),
            // One-shot messages set with `->with('status', …)`; the shell renders and forgets them.
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    /** @return array<string, bool> */
    private static function abilities(Roles $roles, User $user): array
    {
        $out = [];
        foreach (Role::ABILITIES as $ability) {
            $out[$ability] = $roles->isAdmin($user) || $roles->can($user, $ability);
        }

        return $out;
    }

    private static function initials(User $user): string
    {
        $first = mb_substr(trim($user->first_name), 0, 1);
        $last = mb_substr(trim($user->last_name), 0, 1);
        $initials = trim($first.$last);

        return $initials === '' ? mb_substr($user->email, 0, 1) : $initials;
    }
}
