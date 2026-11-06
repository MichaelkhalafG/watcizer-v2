<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Watchizer's hero setting moves to the mode it actually shows (the twelve updates, Part 3.1,
 * 2026-11-05).
 *
 * `settings.home.hero` had two values: `three_d` and `banner`. On Watchizer `three_d` has, since
 * 2026-10-27, drawn the Hugo Boss Grand Prix campaign — the original 3D canvas was replaced under the
 * same value. The hero zone now has three modes that mean what they say:
 *
 *   three_d   the original 3D canvas, as it was before the campaign
 *   campaign  the campaign hero (Watchizer: the Grand Prix banner)
 *   banner    the large hero, with two side slots when they are filled
 *
 * "Watchizer stays on Campaign after the deploy" (the developer): so its stored `three_d` — or a missing
 * value, which reads as `three_d` — becomes `campaign` here, ONCE. Any other value is somebody's choice
 * and is left alone. Brand Fashion is not touched (it is on `banner`).
 *
 * Safe in either deploy order: the storefront build that is live when this runs draws the campaign for
 * every value that is not `banner`, so `campaign` shows what `three_d` showed.
 *
 * A data change on the preserved `storefronts` table — no column is added, so there is nothing for the
 * `hasColumn` guard to ask about; it is idempotent by its own condition.
 *
 * Rollback, by hand (the ledger is `core_migrations`) — only if the old CORE is restored too, which reads
 * `campaign` as `three_d` anyway, so this is tidiness, not a requirement:
 *
 *   UPDATE storefronts SET settings = JSON_REPLACE(settings, '$.home.hero', 'three_d')
 *    WHERE id = 1 AND JSON_UNQUOTE(JSON_EXTRACT(settings, '$.home.hero')) = 'campaign';
 *   DELETE FROM core_migrations WHERE migration = '2026_11_05_000004_hero_campaign_mode';
 */
return new class extends Migration
{
    /** Watchizer — the one storefront that has a campaign hero. */
    private const STOREFRONT = 1;

    public function up(): void
    {
        if (! Schema::hasTable('storefronts')) {
            return;
        }

        $raw = DB::table('storefronts')->where('id', self::STOREFRONT)->value('settings');
        if (! DB::table('storefronts')->where('id', self::STOREFRONT)->exists()) {
            return;
        }

        $settings = is_string($raw) ? json_decode($raw, true) : null;
        $settings = is_array($settings) ? $settings : [];
        $home = is_array($settings['home'] ?? null) ? $settings['home'] : [];
        $current = $home['hero'] ?? null;

        if ($current !== null && $current !== 'three_d') {
            return;                                     // `banner`, or already `campaign`: somebody's choice
        }

        $home['hero'] = 'campaign';
        $settings['home'] = $home;

        DB::table('storefronts')->where('id', self::STOREFRONT)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('storefronts')) {
            return;
        }

        $raw = DB::table('storefronts')->where('id', self::STOREFRONT)->value('settings');
        $settings = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($settings) || ! is_array($settings['home'] ?? null) || ($settings['home']['hero'] ?? null) !== 'campaign') {
            return;
        }

        $settings['home']['hero'] = 'three_d';
        DB::table('storefronts')->where('id', self::STOREFRONT)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
    }
};
