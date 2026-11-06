<?php

declare(strict_types=1);

namespace App\Storefront;

use App\Domain\Content\HomeRails;

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
 *
 * ── Three modes since 2026-11-05 (the twelve updates, Part 3.1) ────────────────────────────────
 *
 * On 2026-10-27 Watchizer's `three_d` started drawing the Hugo Boss Grand Prix campaign: the original
 * 3D canvas was replaced under the same value, so the setting no longer said what the page showed. The
 * modes now mean what they say — `three_d` the original canvas, `campaign` the campaign hero, `banner`
 * the large hero with its two side slots — and a migration moved Watchizer's stored value to
 * `campaign`, so its page did not change.
 *
 * `campaign` is offered only to a storefront that HAS one ({@see self::CAMPAIGN_SHOPS}): the Grand Prix
 * page, its model and its copy exist in Watchizer's app alone. Choosing it for a shop without one would
 * be a hero slot with nothing behind it, so the dashboard does not offer it there and the server refuses
 * it. The fallback for an unset or unknown value is still `three_d`.
 *
 * ── The hero AND the Banners block together (the design pass, 2026-11-05) ──────────────────────────
 *
 * This value says what LEADS the home. The Banners block (the large banner with its two side slots) can
 * be on at the same time as a hero: then it is a home section, at the place its row has among the sliders
 * ({@see HomeRails::BANNERS}). `banner` here means "nothing leads but the banners" —
 * the block is the first thing on the page and its row is passed over. So:
 *
 *   campaign | three_d  + the row on   → the hero on top, the block where the row is
 *   campaign | three_d  + the row off  → the hero alone
 *   banner                             → the block first, whatever the row says
 */
final class HomeHero
{
    /** The 3D watch (Watchizer's home page since launch). */
    public const THREE_D = 'three_d';

    /** The dashboard's home banners, rotating. */
    public const BANNER = 'banner';

    /** The campaign hero — on Watchizer, the Hugo Boss Grand Prix banner. */
    public const CAMPAIGN = 'campaign';

    /** @var list<string> */
    public const MODES = [self::THREE_D, self::CAMPAIGN, self::BANNER];

    /**
     * The storefront codes whose app carries a campaign hero. A code, not an id: the campaign is a thing
     * of the storefront APP (its component, its assets), and the app is deployed per code.
     *
     * @var list<string>
     */
    public const CAMPAIGN_SHOPS = ['watchizer'];

    /**
     * The modes a storefront can actually draw — what its dashboard offers and its server accepts.
     *
     * @return list<string>
     */
    public static function available(string $code): array
    {
        return array_values(array_filter(
            self::MODES,
            fn (string $mode): bool => $mode !== self::CAMPAIGN || in_array($code, self::CAMPAIGN_SHOPS, true),
        ));
    }

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
