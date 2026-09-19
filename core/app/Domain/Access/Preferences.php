<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Models\User;
use App\Support\Coerce;
use App\Support\ManageText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one door for a dashboard operator's own preferences (wave 4D).
 *
 * ── What a profile save is ALLOWED to touch ─────────────────────────────────────────────────
 *
 * Exactly one table: `core_user_preferences`, which core owns. Nothing else. In particular it does
 * NOT touch `users` — not the name, not the e-mail, not the password — because that table is legacy
 * and shared with the live storefront, and AGENTS §3 forbids core writing it. The prohibition is
 * not a policy this class implements: the `legacy` connection runs in `tx_read_only = 1`, so the
 * UPDATE would be refused by the database server.
 *
 * That is why the profile screen shows identity read-only and says where it IS changed. A screen
 * that offered the field and then failed would be worse than one that never offered it.
 *
 * ── The locale whitelist ─────────────────────────────────────────────────────────────────────
 *
 * Two values, and an unknown one is a refusal with a sentence rather than a column constraint's
 * 1265. `ar` is the default and stays the default: this dashboard is Arabic first, and an operator
 * who has never expressed a preference gets Arabic without a row existing at all.
 */
final class Preferences
{
    /** The dashboard's languages. Arabic first, and first for a reason. */
    public const LOCALES = ['ar', 'en'];

    public const DEFAULT_LOCALE = 'ar';

    /*
     * `TEXT_LOCALE = 'ar'` stood here and was DELETED on 2026-09-17, with `TEXT_DIR` beside it.
     *
     * It answered "which language is the dashboard's text actually written in today", which was a
     * different question from "which locale did the operator choose" only while the shell was 1,422
     * inline Arabic literals. Its own docblock named the condition for its removal — *"step 1 of the
     * i18n backlog translates the shell and DELETES this constant"* — and step 1 is done at 99.8%
     * coverage.
     *
     * Removed rather than left at `'ar'` with no reader: a constant nothing calls is
     * indistinguishable from a working one, which is how the storefront cache went stale for ten
     * minutes behind a correct-looking invalidation map. If the two questions ever diverge again,
     * the new constant should be written then, with the reason that is true then.
     */

    /**
     * The writing direction for a locale — `rtl` for Arabic, `ltr` for everything else.
     *
     * ── This replaced a pinned constant on 2026-09-17, and the reason it was pinned has expired ──
     *
     * `TEXT_DIR = 'rtl'` used to be the honest answer: 1,422 shell strings were inline Arabic
     * literals, so an operator who chose English still READ Arabic, and flipping the layout to `ltr`
     * around Arabic text would have been a regression dressed as progress. The constant's own
     * docblock named the condition for removing it — *"step 1 of the i18n backlog translates the
     * shell and DELETES this constant"*.
     *
     * Step 1 is done. English coverage is 99.8%, so an operator who picks English now reads English,
     * and pinning the layout to `rtl` is the thing that looks broken: English text in a
     * right-to-left shell, with the sidebar on the wrong side and every chevron pointing the wrong
     * way.
     *
     * Derived rather than stored, so a third locale needs no second decision — and `ar` is the only
     * right-to-left language this dashboard will plausibly carry.
     */
    public static function directionFor(string $locale): string
    {
        return $locale === 'ar' ? 'rtl' : 'ltr';
    }

    /** This user's dashboard language — `ar` when they have never chosen. */
    public static function localeFor(?User $user): string
    {
        if ($user === null) {
            return self::DEFAULT_LOCALE;
        }

        $stored = Coerce::nstr(
            DB::table('core_user_preferences')
                ->where('user_id', Coerce::int($user->getAuthIdentifier()))
                ->value('locale')
        );

        return in_array($stored, self::LOCALES, true) ? $stored : self::DEFAULT_LOCALE;
    }

    /**
     * Set this user's dashboard language.
     *
     * @throws ValidationException when the locale is not one this dashboard has
     */
    public static function setLocale(User $user, string $locale): void
    {
        if (! in_array($locale, self::LOCALES, true)) {
            /*
             * The refusal is operator text; `self::LOCALES` is not — those are the STORED values
             * (`ar`, `en`) and they go in verbatim, joined by the shared list separator so the
             * sentence punctuates the way the reader's language does.
             */
            throw ValidationException::withMessages([
                'locale' => ManageText::t('profile.locale_unavailable', 'لغة غير متاحة. المتاح: :locales.', [
                    'locales' => implode(ManageText::t('common.list_separator', '، '), self::LOCALES),
                ]),
            ]);
        }

        // `updateOrInsert` rather than `upsert`: the row is keyed by `user_id` and there is exactly
        // one, so this is the whole of it.
        DB::table('core_user_preferences')->updateOrInsert(
            ['user_id' => Coerce::int($user->getAuthIdentifier())],
            ['locale' => $locale, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    /**
     * When this operator last opened the order queue, or null if they never have.
     *
     * NULL is "has never looked", and the caller decides what that means. It is deliberately NOT
     * "everything is new": a badge reading 75 on somebody's first login is noise, and a badge
     * people learn to ignore is worse than no badge. {@see self::newOrdersFor()} treats a first
     * visit as "nothing unseen" and starts counting from then.
     */
    public static function ordersSeenAt(?User $user): ?string
    {
        if (! $user instanceof User) {
            return null;
        }

        $value = DB::table('core_user_preferences')
            ->where('user_id', Coerce::int($user->getAuthIdentifier()))
            ->value('orders_seen_at');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Mark the queue as seen, now.
     *
     * Called when the operator OPENS the orders screen — not when an order is touched. "Seen" is
     * about the list, and the badge's promise is "there is something here you have not looked at",
     * which opening the list discharges.
     */
    public static function markOrdersSeen(User $user): void
    {
        DB::table('core_user_preferences')->updateOrInsert(
            ['user_id' => Coerce::int($user->getAuthIdentifier())],
            ['orders_seen_at' => now(), 'updated_at' => now(), 'created_at' => now()],
        );
    }
}
