<?php

declare(strict_types=1);

namespace App\Storefront;

/**
 * Which hero a storefront's home page draws (the developer's addendum, 2026-10-20).
 *
 * Watchizer renders a 3D watch; Brand Fashion renders the dashboard's banners. Until today that was
 * decided by which APP was deployed — two different home components in two different repositories —
 * and a shop that wanted the other one needed a developer. It is now one value per storefront,
 * `settings.home.hero`, and one shared hero component in both apps reads it.
 *
 * ── Why the fallback is `three_d` and not "whatever this storefront looks like" ───────────────
 *
 * {@see self::THREE_D} is what an UNSET value means, everywhere, for one reason: Watchizer is live
 * and taking real orders, and its home page is the 3D hero. If the value went missing — a storefront
 * row created by hand, a `settings` column reset, this release deployed without its migration — the
 * live shop must keep rendering exactly what it renders today. The cost of the same fallback on
 * Brand Fashion is a 3D watch on a temporary domain with no public traffic: visible, reversible from
 * the dashboard, and not a lost order. So the migration writes `banner` for Brand Fashion
 * EXPLICITLY rather than leaning on a default, and nothing anywhere infers the mode from a code.
 */
final class HomeHero
{
    /** The 3D watch (Watchizer's home page since launch). */
    public const THREE_D = 'three_d';

    /** The dashboard's home banners, rotating. */
    public const BANNER = 'banner';

    /** @var list<string> */
    public const MODES = [self::THREE_D, self::BANNER];

    /** The JSON path inside `storefronts.settings` — one place, so a migration and a reader cannot disagree. */
    public const PATH = 'home.hero';

    /**
     * The mode a storefront's `settings` names, or {@see self::THREE_D} when it names nothing this
     * class serves. Takes the DECODED column (the model casts it to an array) or null.
     *
     * @param  array<array-key, mixed>|null  $settings  the DECODED column, whatever shape it holds
     */
    public static function of(?array $settings): string
    {
        $home = is_array($settings['home'] ?? null) ? $settings['home'] : [];
        $mode = $home['hero'] ?? null;

        return is_string($mode) && in_array($mode, self::MODES, true) ? $mode : self::THREE_D;
    }
}
