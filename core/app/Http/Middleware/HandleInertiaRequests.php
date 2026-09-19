<?php

namespace App\Http\Middleware;

use App\Domain\Access\Navigation;
use App\Domain\Access\Preferences;
use App\Domain\Access\Role;
use App\Domain\Access\Roles;
use App\Models\User;
use App\Support\Branding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;
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
            /*
             * The writing direction follows the operator's CHOSEN locale (2026-09-17).
             *
             * It used to be pinned to `rtl` whatever they picked, and that was right at the time:
             * the shell was 1,422 inline Arabic literals, so an English-speaking operator still read
             * Arabic and an `ltr` layout would have mirrored the furniture around Arabic text.
             *
             * English coverage is 99.8% now, so the condition the pin named has been met and the pin
             * itself became the defect — English words in a right-to-left shell, sidebar on the
             * wrong side, every chevron pointing the wrong way.
             */
            'dir' => Preferences::directionFor($locale),
            /*
             * The flat dictionary for the ACTIVE locale (i18n step 0). Empty today on purpose:
             * `t(key, fallback)` renders the fallback, which is the literal already in the JSX, so
             * sharing this changes nothing on screen and makes every string written from now on
             * translatable for free. docs/wave4d/I18N_BACKLOG_2026-09-14.md has the rest.
             */
            'translations' => self::dictionary($locale),
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

    /**
     * `lang/{locale}/manage.php`, flattened to `group.key => string`.
     *
     * Flattened rather than nested because the client looks a key up by its dotted name, and a
     * nested object would make the simplest possible helper walk a path. Missing file, missing
     * group and missing key all resolve the same way — the caller's fallback.
     *
     * @return array<string, string>
     */
    private static function dictionary(string $locale): array
    {
        /*
         * `fallback: false` — and this is load-bearing, not tidiness.
         *
         * `lang/ar/manage.php` is EMPTY by design: the Arabic lives in each component as `t()`'s
         * second argument, so there is exactly one source for it. But Laravel's translator falls
         * back to `app.fallback_locale` when a file yields nothing, so the default `Lang::get()`
         * handed an ARABIC reader the ENGLISH dictionary — and `useT()`, finding a key, would have
         * rendered English to somebody who asked for Arabic. The dashboard's default language would
         * have flipped silently the moment the first English string was added.
         *
         * Caught by `LocaleSeamTest` on the day the extraction started, which is the only reason it
         * is not in production.
         */
        $lines = Lang::get('manage', [], $locale, false);
        if (! is_array($lines)) {
            return [];
        }

        $flat = [];
        $walk = static function (array $node, string $prefix) use (&$walk, &$flat): void {
            foreach ($node as $key => $value) {
                $name = $prefix === '' ? (string) $key : $prefix.'.'.(string) $key;
                if (is_array($value)) {
                    $walk($value, $name);

                    continue;
                }
                if (is_string($value)) {
                    $flat[$name] = $value;
                }
            }
        };
        $walk($lines, '');

        return $flat;
    }
}
