<?php

namespace App\Support;

/**
 * Which of a record's two names the READER gets — the server half of the rule.
 *
 * ── The defect this exists to stop ───────────────────────────────────────────────────────────
 *
 * Item 1b (2026-09-17) fixed this for product titles on the client: `resources/js/lib/title.ts`
 * decides, `ProductName` draws it, and `ProductNameSeamTest` greps for anybody picking a language
 * by hand. It worked, and then the same defect turned up again in places that rule could not reach
 * — because the choice had already been made ON THE SERVER, before the client ever saw a pair:
 *
 *     $label = $ar !== '' ? $ar : ($en !== '' ? $en : $slug);
 *
 * That line built the product form's category picker, its primary-category select, the products
 * list's category filter and the placement screen's tree. An English operator picking a category
 * read sixty-one Arabic names, and nothing in the client could have known: it was handed one string
 * and rendered it faithfully.
 *
 * ── The rule, stated once ────────────────────────────────────────────────────────────────────
 *
 * Show the name in the operator's own language. Where it is missing, show the other one — never an
 * empty label, because a picker with a blank row cannot be used at all. Where neither exists, fall
 * back to something the operator can still act on (a slug, a code), never to ''.
 *
 * ── Why the fallback is not marked here, when the client marks it ────────────────────────────
 *
 * `localisedTitle()` returns `fallback: true` so a TABLE CELL can show the other language and say
 * it is standing in. An `<option>` has nowhere to put that mark, and a parenthetical glued onto a
 * name inside a dropdown is noise on every row of a tree. So this returns the plain string, and the
 * screens that have room for a mark keep using the client rule with the full pair.
 *
 * Deliberately not a Blade directive, a macro or a trait: it is one function, it is called from
 * controllers only, and `ProductNameSeamTest` names it as the one place on the server allowed to
 * choose.
 */
final class LocalisedName
{
    /**
     * @param  string|null  $ar  the Arabic name, as stored — may be null or blank
     * @param  string|null  $en  the English name, as stored — may be null or blank
     * @param  string  $fallback  what to show when there is no name at all (a slug, a code)
     */
    public static function pick(?string $ar, ?string $en, string $fallback = ''): string
    {
        $arabic = trim($ar ?? '');
        $english = trim($en ?? '');

        /*
         * The ACTIVE locale, not a stored preference and not a request header: it is the language
         * the operator chose and the one every `ManageText::t()` on the same page answered in. A
         * label in a different language from the heading above it is the bug, not the edge case.
         */
        $arabicWanted = app()->getLocale() === 'ar';
        $wanted = $arabicWanted ? $arabic : $english;
        $other = $arabicWanted ? $english : $arabic;

        if ($wanted !== '') {
            return $wanted;
        }

        return $other !== '' ? $other : $fallback;
    }
}
