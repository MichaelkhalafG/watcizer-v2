<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * storefronts:support-email — EACH SHOP'S SUPPORT E-MAIL (Michael, 2026-11-25): `storefronts.settings.identity.support_email`,
 * the contact line of its order, abandoned-cart and campaign e-mails. WRITES that one key of two storefront rows and its
 * own backup table. Four passes, as every data step of this project:
 *   (default)  DRY RUN: what each shop holds now and what it would get. Writes nothing.
 *   --apply    each shop keyed by its id AND its code, written only when the key is absent or already the value; any
 *              other value is a STOP-ROW and nothing is written for any shop.
 *   --apply    again: nothing to change.
 *   --undo     each backed-up shop back to what it held (the key removed when it had none), only where the value is
 *              still this step's; the backup is dropped once both are back.
 * The team can change the address on the Storefronts screen at any time after.
 */
final class StorefrontSupportEmailCommand extends Command
{
    public const BACKUP = 'bk_support_email_20261125';

    /** @var array<int, array{code: string, email: string}> */
    public const VALUES = [
        1 => ['code' => 'watchizer', 'email' => 'watchizer@eleganceeg.com'],
        2 => ['code' => 'brandfashion', 'email' => 'brandfashion@eleganceeg.com'],
    ];

    protected $signature = 'storefronts:support-email {--apply : write (default: a dry run)} {--undo : restore the backed-up values}';

    protected $description = "Set each shop's support e-mail in its identity (dry run unless --apply)";

    public static function backupDdl(): string
    {
        return 'CREATE TABLE IF NOT EXISTS '.self::BACKUP.' (storefront_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, settings_before LONGTEXT NULL, settings_after LONGTEXT NOT NULL, created_at TIMESTAMP NULL)';
    }

    public function handle(StorefrontCache $cache): int
    {
        if ((bool) $this->option('undo')) {
            return $this->undo($cache);
        }
        $plan = [];
        foreach (self::VALUES as $id => $want) {
            $row = DB::table('storefronts')->where('id', $id)->first(['id', 'code', 'settings']);
            if ($row === null || Row::str(Row::cast($row), 'code') !== $want['code']) {
                $this->line("STOP-ROW storefront {$id}: not the {$want['code']} shop - nothing was written");

                return self::FAILURE;
            }
            $settings = Coerce::arr(json_decode((string) Row::nstr(Row::cast($row), 'settings'), true));
            $identity = Coerce::arr($settings['identity'] ?? null);
            $now = Coerce::nstr($identity['support_email'] ?? null);
            if ($now !== null && $now !== '' && mb_strtolower($now) !== $want['email']) {
                $this->line("STOP-ROW storefront {$id} ({$want['code']}): its support e-mail is {$now}, set by someone - nothing was written");

                return self::FAILURE;
            }
            $plan[$id] = ['settings' => $settings, 'raw' => Row::nstr(Row::cast($row), 'settings'), 'done' => $now === $want['email']];
            $this->line(sprintf('  shop %d (%s): %s -> %s', $id, $want['code'], $now ?? '(none)', $want['email']));
        }
        $todo = array_filter($plan, fn (array $p): bool => ! $p['done']);
        if ($todo === []) {
            $this->line('NOTHING TO DO: both shops hold their support e-mail already.');

            return self::SUCCESS;
        }
        if (! (bool) $this->option('apply')) {
            $this->line('DRY RUN: '.count($todo).' shop(s) would be set. Nothing was written; run again with --apply.');

            return self::SUCCESS;
        }
        DB::statement(self::backupDdl());
        foreach ($todo as $id => $p) {
            $settings = $p['settings'];
            $identity = Coerce::arr($settings['identity'] ?? null);
            $identity['support_email'] = self::VALUES[$id]['email'];
            $settings['identity'] = $identity;
            $after = (string) json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            // the whole value before, byte for byte: the undo puts back exactly what was there
            DB::table(self::BACKUP)->insertOrIgnore(['storefront_id' => $id, 'settings_before' => $p['raw'], 'settings_after' => $after, 'created_at' => now()]);
            DB::table('storefronts')->where('id', $id)->where('code', self::VALUES[$id]['code'])->update(['settings' => $after]);
            $cache->forgetStorefront($id, self::VALUES[$id]['code']);
        }
        $this->line('APPLIED: '.count($todo).' shop(s) set.');

        return self::SUCCESS;
    }

    private function undo(StorefrontCache $cache): int
    {
        if (! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            $this->line('NOTHING TO UNDO: no backup table.');

            return self::SUCCESS;
        }
        $restored = 0;
        $kept = 0;
        foreach (DB::table(self::BACKUP)->orderBy('storefront_id')->get() as $raw) {
            $b = Row::cast($raw);
            $id = Row::int($b, 'storefront_id');
            $now = DB::table('storefronts')->where('id', $id)->value('settings');
            if ($now !== Row::str($b, 'settings_after')) {
                $kept++;                                     // changed on the screen since: left as the team set it

                continue;
            }
            DB::table('storefronts')->where('id', $id)->update(['settings' => Row::nstr($b, 'settings_before')]);
            $cache->forgetStorefront($id, self::VALUES[$id]['code'] ?? '');
            $restored++;
        }
        if ($kept === 0) {
            DB::statement('DROP TABLE IF EXISTS '.self::BACKUP);
        }
        $this->line("UNDONE: {$restored} shop(s) back; {$kept} left as the team set them since".($kept === 0 ? '; the backup is dropped.' : '; the backup is kept.'));

        return self::SUCCESS;
    }
}
