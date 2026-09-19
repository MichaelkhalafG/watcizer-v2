<?php

declare(strict_types=1);

namespace App\Http\Controllers\Manage;

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
 * A profile screen normally edits a name, an e-mail and a password. This one edits NONE of the
 * three, and the reason is `users`: it is a LEGACY table shared with the live storefront, AGENTS §3
 * forbids core writing it, and the prohibition is enforced by the database — the `legacy`
 * connection runs in `tx_read_only = 1`, so the UPDATE would be refused by the server rather than
 * by this class. Four framework defaults that would have written it silently are already off
 * (`hashing.rehash_on_login`, the remember token, `last_login_at`, the password broker), and the
 * acceptance test for anything in this area is that the 65-table legacy digest does not move.
 *
 * So identity is shown READ-ONLY with a sentence saying where it is actually changed, and a save
 * here touches exactly one table:
 *
 *     core_user_preferences   (core-owned, M1m, DASHBOARD_TABLES)
 *
 * and exactly one column in it. Nothing else. The screen says so too, because an operator who
 * cannot find the "change password" button deserves to be told why rather than left hunting.
 *
 * A password change is a separate decision, not a quiet exception (developer, 2026-09-14).
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
            'identity_notice' => ManageText::t('profile.identity_notice', 'الاسم والبريد وكلمة المرور يملكها حساب المتجر، وجدول الحسابات مشترك مع المتجر والداشبورد القديم. لوحة التحكم الجديدة تقرأ هذا الجدول ولا تكتب فيه إطلاقًا، فالتعديل يتم من المتجر أو من الداشبورد القديم.'),
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
            'saves_notice' => ManageText::t('profile.saves_notice', 'الحفظ هنا يخصّ تفضيلات اللوحة وحدها، ولا يمس بيانات حسابك ولا كلمة مرورك.'),
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
