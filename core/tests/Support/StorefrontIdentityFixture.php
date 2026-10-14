<?php

namespace Tests\Support;

use App\Storefront\StorefrontUrls;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Every Feature test runs with both storefronts IDENTIFIED (W2, 2026-10-13).
 *
 * `storefronts.settings.identity` is real configuration, set by SQL on each database (backlog
 * SF-IDENTITY-SQL) — like the storefronts' domains, which the suite also expects to find. Until
 * that SQL has been run on a database, nothing here can send a mail: every mailer parks the row
 * with "has no identity". So the suite fills the missing identity INSIDE each test's transaction
 * (rolled back with it — the dev database is never changed) from the same values the SQL sets, so
 * what the tests render is what production renders once the SQL is in. A test that wants a
 * storefront WITHOUT identity clears it itself, as the no-host tests clear the domain.
 */
final class StorefrontIdentityFixture
{
    /** The values the SQL sets — one source for the suite and the statement in the backlog. */
    public const VALUES = [
        'watchizer' => [
            'mail_wordmark' => 'assets/brand/watchizer-mail-wordmark@2x.png',
            'mail_wordmark_1x' => 'assets/brand/watchizer-mail-wordmark.png',
            'whatsapp_support' => '201551096234',
            'whatsapp_developer' => '201274550956',
            'copyright' => '© 2026 Watchizer. All rights reserved.',
        ],
        'brandfashion' => [
            'mail_wordmark' => 'assets/brand/brandfashion-mail-wordmark@2x.png',
            'mail_wordmark_1x' => 'assets/brand/brandfashion-mail-wordmark.png',
            'whatsapp_support' => '201283888490',
            'whatsapp_developer' => '201274550956',
            // The entity name is PARKED (developer, 2026-10-13: the client is asked for the legal
            // name); this is the shape, set by SQL afterwards.
            'copyright' => '© 2026 Brand Fashion. All rights reserved.',
        ],
    ];

    public static function ensure(): void
    {
        $changed = false;
        foreach (DB::table('storefronts')->whereIn('code', array_keys(self::VALUES))->get(['id', 'code', 'settings']) as $row) {
            $decoded = is_string($row->settings ?? null) ? json_decode($row->settings, true) : null;
            $settings = is_array($decoded) ? $decoded : [];
            if (is_array($settings['identity'] ?? null)) {
                continue;
            }
            $code = is_string($row->code ?? null) ? $row->code : '';
            if (! isset(self::VALUES[$code])) {
                continue;
            }
            $settings['identity'] = self::VALUES[$code];
            DB::table('storefronts')->where('id', $row->id)->update([
                'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $changed = true;
        }
        if ($changed) {
            Cache::forget(StorefrontUrls::CACHE_KEY);
        }
    }

    /** Take a storefront's identity away for one test (its row is rolled back with the test). */
    public static function clear(int $storefrontId): void
    {
        $raw = DB::table('storefronts')->where('id', $storefrontId)->value('settings');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $settings = is_array($decoded) ? $decoded : [];
        unset($settings['identity']);
        DB::table('storefronts')->where('id', $storefrontId)->update([
            'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        Cache::forget(StorefrontUrls::CACHE_KEY);
    }
}
