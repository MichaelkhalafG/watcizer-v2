<?php

use App\Storefront\HomeHero;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `settings.home.hero` — which hero each storefront's home page draws (the developer's addendum, 2026-10-20).
 *
 * ADDITIVE and REVERSIBLE, like F1's: no column is added, dropped or retyped, and no existing key inside
 * `settings` is read or rewritten. One key is inserted per storefront, and `down()` takes exactly that key
 * back out — a row whose `settings` this release never touched comes out of a rollback byte-for-byte.
 *
 * WHAT IT WRITES, AND WHY EXPLICITLY: Watchizer gets `three_d` and every other storefront gets `banner`,
 * because that is what each storefront renders TODAY — Watchizer the 3D watch, Brand Fashion the dashboard's
 * banners. Nothing a shopper sees changes when this runs. The value is written out rather than left to
 * {@see HomeHero::of}'s fallback so that the two decisions are independent: the fallback protects the LIVE
 * shop if the value is ever missing (it is `three_d` for that reason alone), and this migration is what makes
 * Brand Fashion's banners survive it.
 *
 * A storefront that ALREADY names a mode is left alone, so a re-run after an operator has flipped the switch
 * does not undo them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('storefronts')->select(['id', 'code', 'settings'])->orderBy('id')->get() as $row) {
            $settings = self::settings($row->settings);
            $home = is_array($settings['home'] ?? null) ? $settings['home'] : [];
            if (is_string($home['hero'] ?? null) && $home['hero'] !== '') {
                continue;
            }
            // Watchizer (storefront 1) is the 3D hero; every other storefront is on banners today.
            $home['hero'] = $row->code === 'watchizer' ? HomeHero::THREE_D : HomeHero::BANNER;
            $settings['home'] = $home;
            DB::table('storefronts')->where('id', $row->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('storefronts')->select(['id', 'settings'])->orderBy('id')->get() as $row) {
            $settings = self::settings($row->settings);
            if (! is_array($settings['home'] ?? null)) {
                continue;
            }
            $home = $settings['home'];
            unset($home['hero']);
            // An empty `home` is not left behind: this release is the only thing that ever put one there.
            if ($home === []) {
                unset($settings['home']);
            } else {
                $settings['home'] = $home;
            }
            DB::table('storefronts')->where('id', $row->id)
                ->update(['settings' => $settings === [] ? null : json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    /**
     * The column as an array. A null, or anything that is not a JSON object, reads as no settings —
     * never as a reason to throw inside a migration.
     *
     * @return array<array-key, mixed>
     */
    private static function settings(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
};
