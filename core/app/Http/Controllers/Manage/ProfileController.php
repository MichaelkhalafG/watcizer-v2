<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

use App\Domain\Access\DashboardAccounts;
use App\Domain\Access\Preferences;
use App\Domain\Access\Role;
use App\Domain\Activity\ActivityLog;
use App\Models\Storefront\Storefront;
use App\Models\User;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in operator's own profile (wave 4D).
 *
 * ── The shape of this screen is decided by a rule, not by taste ─────────────────────────────
 *
 * A profile screen normally edits a name, an e-mail and a password. This one edits the PASSWORD and
 * the dashboard language, and nothing else — and the asymmetry is the rule, not an omission.
 *
 * `users` is a LEGACY table. Until 2026-09-20 core wrote none of it, because the legacy application
 * serialised every column of that row into the proxied `login`/`register`/`me` responses the live
 * storefront consumed. On the standalone eleganceeg.com deployment those routes are closed and
 * nothing else writes the table, so AGENTS §2.18 now permits exactly two operations — creating a
 * dashboard account, and changing a password — both through {@see DashboardAccounts}, which is the
 * only door and refuses everything else at the model.
 *
 * So this screen writes:
 *
 *     users.password          (through DashboardAccounts, current password required)
 *     core_user_preferences   (core-owned, M1m, DASHBOARD_TABLES)
 *
 * and the NAME, E-MAIL and phone stay read-only, because those are the customer-facing identity the
 * storefront owns and no one asked for them. The three framework defaults wave 4A turned off —
 * password re-hashing, the remember token, the `last_login_at` stamp — stay off, which is why a
 * password change here does not end sessions on other devices. The screen says so rather than
 * letting an operator assume it did.
 *
 * ── No ability gate beyond the dashboard itself ─────────────────────────────────────────────
 *
 * Everyone who can open `/manage` has a profile, and it is their own: the route takes no id and
 * reads `$request->user()`, so there is no other person's profile to authorise or to guess at.
 */
final class ProfileController
{
    public function show(Request $request): Response
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 403);

        return Inertia::render('Manage/Profile/Index', [
            /*
             * Read-only identity, straight off the shared row. Presented as fields so the screen
             * looks like what it is — a profile — rather than pretending the data lives elsewhere.
             */
            'identity' => [
                'name' => trim(Coerce::str($user->getAttribute('first_name')).' '.Coerce::str($user->getAttribute('last_name'))),
                'first_name' => Coerce::nstr($user->getAttribute('first_name')),
                'last_name' => Coerce::nstr($user->getAttribute('last_name')),
                'email' => Coerce::str($user->getAttribute('email')),
                'phone' => Coerce::nstr($user->getAttribute('phone_number')),
                // The LEGACY enum, shown for contrast exactly as the grants screen shows it: it
                // means nothing here, and seeing both stops "but I am SuperAdmin".
                'legacy_type' => Coerce::nstr($user->getAttribute('type')),
            ],
            /*
             * Why those fields have no edit control. A sentence, not a disabled input with no
             * explanation — the operator is entitled to know that this is a decision and where the
             * change is actually made.
             */
            'identity_notice' => ManageText::t('profile.identity_notice', 'الاسم والبريد ورقم الهاتف تخص حساب المتجر ولا تُعدَّل من هنا. كلمة المرور تُغيَّر من هذه الصفحة.'),
            // What a password change here does, and the one thing it does NOT do.
            'password_note' => ManageText::t('profile.password_note', 'اكتب كلمة المرور الحالية ثم الجديدة. الأجهزة الأخرى المسجَّلة بحسابك تبقى مسجَّلة الدخول.'),
            'password_min' => DashboardAccounts::MIN_PASSWORD,
            'grants' => self::grantsFor($user),
            'locale' => Preferences::localeFor($user),
            'locales' => self::localeOptions(),
            /*
             * The honest state of the language setting: it is STORED from today, and the screens
             * follow as they are translated. Saying this is better than a control that appears to
             * do nothing — the dashboard is 1 422 hard-coded Arabic strings deep, and that work is
             * a costed backlog item rather than a toggle.
             */
            'locale_notice' => ManageText::t('profile.locale_notice', 'اللغة تُحفَظ الآن لحسابك، وتُطبَّق على الشاشات تدريجيًا مع ترجمتها. العربية تبقى لغة اللوحة الأساسية.'),
            // D-18: the table name was in the parentheses. What the sentence is FOR is the second
            // half — this screen cannot touch your account — and a table name neither supports
            // that nor is checkable by anybody reading it.
            'saves_notice' => ManageText::t('profile.saves_notice', 'حفظ اللغة يخصّ تفضيلات اللوحة وحدها، ولا يمس بيانات حسابك.'),
        ]);
    }

    /**
     * Save the profile.
     *
     * The validated payload has ONE key. That is not an oversight and it is not a first version:
     * it is the whole of what this screen may write, for the reason in the class docblock.
     *
     * ── And the one key is AUDITED, which it was not ─────────────────────────────────────
     *
     * `core_user_preferences` carried a changed `locale` on 2026-10-05 and NOTHING said where it
     * came from. The row has a `user_id` and an `updated_at` and neither answers the question that
     * was asked: whether a person had saved this screen, or a test run had written the row. One
     * column, one table, and the incident still could not be closed — which is the whole argument
     * for logging a screen that writes almost nothing.
     *
     * The subject id is the USER's id, because the preferences row is keyed by it and has no id of
     * its own; `ActivityLog::record()` separately stamps the ACTOR, and on this screen the two are
     * always the same person — there is no other profile to reach.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 403);

        $data = Coerce::arr($request->validate([
            'locale' => ['required', 'string', Rule::in(Preferences::LOCALES)],
        ]));

        // BEFORE the write, and read through the same door the screen reads it through — so an
        // operator who has never chosen shows as `ar`, which is what they were actually using,
        // rather than as an empty cell because no row existed yet.
        $before = ['locale' => Preferences::localeFor($user)];

        Preferences::setLocale($user, Coerce::str($data['locale']));

        // Re-saving the same language diffs to nothing and `ActivityLog::record()` drops it. That
        // is the wanted behaviour: this screen's Save is one button, and a log that recorded every
        // press would answer "who changed the language" with a page of entries where nobody did.
        ActivityLog::record(
            'core_user_preferences',
            Coerce::nint($user->getAuthIdentifier()),
            ActivityLog::UPDATED,
            $before,
            ['locale' => Preferences::localeFor($user)],
            label: self::operatorLabel($user),
        );

        return back()->with('status', ManageText::t('profile.saved', 'تم حفظ تفضيلاتك.'));
    }

    /**
     * Change this operator's own password.
     *
     * ── The second of the two things that kept the old dashboard alive ──────────────
     *
     * This screen used to say the password "is changed on the storefront or in the old dashboard".
     * On the standalone deployment neither is reachable, so a forgotten or shared password was a
     * lockout with no way back that did not involve the command line (live review §3.1).
     *
     * The route takes no id and there is no other person's profile to reach, so the account being
     * changed is always the one making the request. An administrator cannot set somebody else's
     * password here — that would be a second, different permission over a legacy table, and it is
     * not one that was granted. A locked-out operator gets a NEW account, or `manage:role` and a
     * hand-written row, deliberately: resetting another person's credential is the kind of power
     * that should stay awkward.
     *
     * The current password is verified inside {@see DashboardAccounts::changePassword()} rather
     * than here, because that class is the door and a check in front of it can be routed around.
     */
    public function password(Request $request, DashboardAccounts $accounts): RedirectResponse
    {
        $user = $request->user();
        abort_if(! $user instanceof User, 403);

        $data = Coerce::arr($request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:'.DashboardAccounts::MIN_PASSWORD, 'confirmed'],
        ]));

        $accounts->changePassword(
            $user,
            Coerce::str($data['current_password']),
            Coerce::str($data['password']),
        );

        /*
         * Says what it did NOT do, on purpose. `logoutOtherDevices()` rewrites `users.remember_token`
         * — one of the three framework writes wave 4A turned off at the model — so a password change
         * here does not end sessions elsewhere. An operator who assumes it did would stop looking
         * for the laptop they left signed in, which is worse than knowing.
         */
        return back()->with('status', ManageText::t(
            'profile.password_changed',
            'تم تغيير كلمة المرور. الأجهزة الأخرى تبقى مسجَّلة الدخول.',
        ));
    }

    /**
     * Whose preferences these are, as a name a reader recognises.
     *
     * The activity screen resolves live names for products and categories only, so what is
     * captured here is what the entry will say forever — including after the account is gone,
     * which is exactly the entry somebody will come looking for. The e-mail is the fallback
     * because an account with no name still has one, and `#12` names nobody.
     */
    private static function operatorLabel(User $user): string
    {
        $name = trim(Coerce::str($user->getAttribute('first_name')).' '.Coerce::str($user->getAttribute('last_name')));

        return $name !== '' ? $name : Coerce::str($user->getAttribute('email'));
    }

    /**
     * This user's grants, read-only — which abilities and over which storefronts.
     *
     * Shown because "what am I allowed to do here" is the second question anybody opens a profile
     * to answer, and the first place they look for it.
     *
     * @return list<array<string, mixed>>
     */
    private static function grantsFor(User $user): array
    {
        $names = [];
        foreach (Storefront::query()->orderBy('id')->get(['id', 'name']) as $storefront) {
            $names[Coerce::int($storefront->getAttribute('id'))] = Coerce::str($storefront->getAttribute('name'));
        }

        $out = [];
        foreach (
            DB::table('core_user_roles')
                ->where('user_id', Coerce::int($user->getAuthIdentifier()))
                ->orderBy('role')->orderBy('storefront_id')
                ->get(['role', 'storefront_id', 'created_at']) as $raw
        ) {
            $row = Row::cast($raw);
            $role = Role::tryFromValue(Row::nstr($row, 'role'));
            $storefrontId = Row::nint($row, 'storefront_id');

            $out[] = [
                'role' => Row::str($row, 'role'),
                'label' => $role === null ? Row::str($row, 'role') : $role->label(),
                // `null` scope is every storefront, and saying "كل المتاجر" is clearer than a blank.
                // The storefront NAME is data and is never translated; only the "all" case is text.
                'scope' => $storefrontId === null
                    ? ManageText::t('common.all_storefronts', 'كل المتاجر')
                    : ($names[$storefrontId] ?? ('#'.$storefrontId)),
                'abilities' => $role === null ? [] : $role->abilities(),
                'granted_at' => Row::nstr($row, 'created_at'),
            ];
        }

        return $out;
    }

    /**
     * The language picker's options.
     *
     * `value` is what {@see Preferences::setLocale()} STORES and must never change; `label` is the
     * only half a person reads, and the `<select>` renders it out of these props.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function localeOptions(): array
    {
        return [
            ['value' => 'ar', 'label' => ManageText::t('common.locale_arabic', 'العربية')],
            ['value' => 'en', 'label' => 'English'],   // i18n-exempt: the endonym is already the English word
        ];
    }
}
