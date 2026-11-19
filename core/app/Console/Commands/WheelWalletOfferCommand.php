<?php

namespace App\Console\Commands;

use App\Domain\Activity\ActivityLog;
use App\Domain\Promotions\PromotionMoney;
use App\Domain\Promotions\PromotionRules;
use App\Domain\Promotions\PromotionScopes;
use App\Domain\Promotions\PromotionWriter;
use App\Domain\Wheel\WheelPrizes;
use App\Storefront\StorefrontCache;
use App\Support\Coerce;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * THE WHEELS' "N% OFF WALLETS" PRIZES BECOME "N% OFF A WALLET WITH ANY WATCH" (Michael, 2026-11-19). The deploy's data
 * step, in four passes: a dry run that prints exactly what will change (the default), --apply, a second run that must
 * change nothing, and --undo, which puts each row back exactly as it was and removes the offers this step made.
 *
 * Each prize is named by its ID AND ITS PERCENT NOW, as --prize=<id>:<percent> (the deploy: --prize=32:30 for
 * Watchizer's, --prize=46:20 for Brand Fashion's, from the production export of 19 Nov - the team set Brand Fashion's
 * to 20 % on 18 Nov and Michael keeps it). Each is GUARDED on what it is now, on its own shop: label "<percent>% off
 * wallets", type percent_discount, that percent, the shop's Wallets category, a cap of 1,500, no offer yet. Anything
 * else is a STOP-ROW and nothing is written. The shop's Watches category (its top-level `watches`) must be exactly one.
 *
 * The new rule, made with the offer builder's shape and kept for the wheel (issuer wheel_template, switched off, no code):
 * the same percent off a Wallets item, ONE per Watches item in the cart (apply_per paired: the most expensive wallets
 * first), the 1,500 cap kept. The row becomes type `offer` pointing at it, worded "<percent>% off a wallet with any
 * watch" / «خصم <percent>% على محفظة مع أي ساعة». Odds and colour stay. Every other prize is untouched. Codes already won
 * keep their own rule (a spin snapshots its terms).
 */
final class WheelWalletOfferCommand extends Command
{
    public const BACKUP = 'bk_offer_20261120_wheel_prizes';

    public const CAP = 1500.0;

    protected $signature = 'promotions:wallet-with-watch {--prize=* : <id>:<percent> of each prize to switch (the deploy: 32:30 and 46:20)} {--apply : write (default: a dry run)} {--undo : restore the backed-up rows}';

    protected $description = 'Switch the wheel prizes "N% off wallets" to "N% off a wallet with any watch" (dry run unless --apply)';

    /** The prize's label now, which the guard expects. */
    public static function labelBefore(int $percent): string
    {
        return $percent.'% off wallets';
    }

    public static function labelEn(int $percent): string
    {
        return $percent.'% off a wallet with any watch';
    }

    public static function labelAr(int $percent): string
    {
        return 'خصم '.$percent.'% على محفظة مع أي ساعة'; // i18n-exempt: DATA — the prize's own Arabic name, shown to shoppers
    }

    /** The backup table's CREATE, public so a test can make it on another connection first (Tests\Support\SideDdl). */
    public static function backupDdl(): string
    {
        return 'CREATE TABLE IF NOT EXISTS '.self::BACKUP.' (prize_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, row_json LONGTEXT NOT NULL, template_id BIGINT UNSIGNED NULL, created_at TIMESTAMP NULL)';
    }

    public function handle(): int
    {
        if (! PromotionRules::builderReady() || ! DB::getSchemaBuilder()->hasColumn(WheelPrizes::TABLE, 'offer_rule_id')) {
            $this->error('STOP: the offer-builder migration has not run here.');

            return self::FAILURE;
        }
        if ((bool) $this->option('undo')) {
            return $this->undo();
        }
        // each prize as <id>:<percent> - the percent is part of the guard, never guessed
        $wanted = [];
        foreach (Coerce::arr($this->option('prize')) as $raw) {
            if (preg_match('/^(\d+):(\d{1,3})$/', Coerce::str($raw), $m) !== 1 || (int) $m[2] < 1 || (int) $m[2] > 100) {
                $this->error('STOP: name each prize with its percent now: --prize=<id>:<percent> (got "'.Coerce::str($raw).'") — nothing was written.');

                return self::FAILURE;
            }
            $wanted[(int) $m[1]] = (int) $m[2];
        }
        if ($wanted === []) {
            $this->error('STOP: name the prizes: --prize=<id>:<percent> (one per wheel).');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');

        $plan = [];
        $done = 0;
        $stops = 0;
        foreach ($wanted as $id => $percent) {
            $row = DB::table(WheelPrizes::TABLE)->where('id', $id)->first();
            if ($row === null) {
                $this->line("STOP-ROW prize {$id}: no such prize");
                $stops++;

                continue;
            }
            $row = Row::cast($row);
            $sf = Row::int($row, 'storefront_id');
            $wallets = self::category($sf, 'wallets', false);
            $watches = self::category($sf, 'watches', true);
            if (self::isDone($row, $percent)) {
                $this->line("ALREADY prize {$id} (storefront {$sf}): \"".Row::str($row, 'label_en').'" → offer '.Row::nint($row, 'offer_rule_id'));
                $done++;

                continue;
            }
            $guard = [
                'label_en' => Row::str($row, 'label_en') === self::labelBefore($percent),
                'type' => Row::str($row, 'type') === 'percent_discount',
                'percent' => abs((Row::nfloat($row, 'value') ?? 0.0) - $percent) < 0.001,
                'category' => $wallets !== null && Row::nint($row, 'scope_category_id') === $wallets,
                'cap' => abs((Row::nfloat($row, 'cap') ?? 0.0) - self::CAP) < 0.001,
                'no offer yet' => Row::nint($row, 'offer_rule_id') === null,
                'one Watches category' => $watches !== null,
            ];
            $failed = array_keys(array_filter($guard, fn (bool $ok): bool => ! $ok));
            if ($failed !== []) {
                $this->line("STOP-ROW prize {$id} (storefront {$sf}): the guard does not match — ".implode(', ', $failed)
                    .'; now: "'.Row::str($row, 'label_en').'", '.Row::str($row, 'type').', '.(Row::nstr($row, 'value') ?? '-').', category '
                    .(Row::nint($row, 'scope_category_id') ?? '-').', cap '.(Row::nstr($row, 'cap') ?? '-'));
                $stops++;

                continue;
            }
            $plan[] = ['row' => $row, 'sf' => $sf, 'wallets' => (int) $wallets, 'watches' => (int) $watches, 'percent' => $percent];
            $this->line("WOULD prize {$id} (storefront {$sf}, position ".Row::int($row, 'position').'): "'.self::labelBefore($percent).'" ('.Row::str($row, 'type')
                .') → "'.self::labelEn($percent).'" / «'.self::labelAr($percent).'» (offer): '.$percent.'% off one Wallets item (category '.$wallets
                .') per Watches item (category '.$watches.'), the most expensive first, cap '.number_format(self::CAP, 0, '.', ',')
                .'; odds '.Row::int($row, 'odds').' and colour '.Row::str($row, 'color').' unchanged');
        }

        if ($stops > 0) {
            $this->line("STOP: {$stops} prize(s) did not match their guard — nothing was written.");

            return self::FAILURE;
        }
        if (! $apply) {
            $this->line('DRY RUN: '.count($plan).' to switch, '.$done.' already switched, 0 stop');

            return self::SUCCESS;
        }
        if ($plan === []) {
            $this->line('NOTHING TO DO: 0 switched, '.$done.' already switched');

            return self::SUCCESS;
        }
        if (! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            DB::statement(self::backupDdl());
        }

        $writer = app(PromotionWriter::class);
        DB::transaction(function () use ($plan, $writer): void {
            foreach ($plan as $p) {
                $row = $p['row'];
                $id = Row::int($row, 'id');
                $en = self::labelEn($p['percent']);
                $ar = self::labelAr($p['percent']);
                // the row exactly as it is, before anything changes
                DB::table(self::BACKUP)->insertOrIgnore(['prize_id' => $id, 'row_json' => (string) json_encode((array) $row, JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
                $template = $writer->save([
                    'name' => $en.' — '.ucfirst(Coerce::str(DB::table('storefronts')->where('id', $p['sf'])->value('name'))).' wheel',
                    'priority' => 0,
                    'is_active' => false,
                    'code' => null,
                    'label_en' => $en,
                    'label_ar' => $ar,
                    'apply_per' => PromotionMoney::PER_PAIRED,
                    'max_discount' => self::CAP,
                    'qualify_min_quantity' => 1,
                    'include_sale_items' => true,
                    'stackable' => false,
                    'unit_floor' => 0,
                    'storefronts' => [$p['sf']],
                    'conditions' => [],
                    'rewards' => [['type' => 'percent_discount', 'amount' => (float) $p['percent']]],
                    'scopes' => [
                        ['role' => PromotionScopes::ROLE_TARGET, 'dimension' => 'category', 'value_id' => $p['wallets']],
                        ['role' => PromotionScopes::ROLE_QUALIFY, 'dimension' => 'category', 'value_id' => $p['watches']],
                    ],
                ], null, null);
                DB::table('promotion_rules')->where('id', $template)->update(['issuer' => WheelPrizes::TEMPLATE_ISSUER]);
                DB::table(self::BACKUP)->where('prize_id', $id)->update(['template_id' => $template]);
                DB::table(WheelPrizes::TABLE)->where('id', $id)->update([
                    'type' => 'offer', 'offer_rule_id' => $template, 'label_en' => $en, 'label_ar' => $ar, 'updated_at' => now(),
                ]);
                ActivityLog::record(WheelPrizes::TABLE, $id, ActivityLog::UPDATED,
                    ['type' => Row::str($row, 'type'), 'label_en' => Row::str($row, 'label_en'), 'label_ar' => Row::str($row, 'label_ar')],
                    ['type' => 'offer', 'offer_rule_id' => $template, 'label_en' => $en, 'label_ar' => $ar],
                    label: $en, storefrontId: $p['sf']);
            }
        });
        foreach (array_unique(array_column($plan, 'sf')) as $sf) {
            app(StorefrontCache::class)->flush((int) $sf);
        }
        $this->line('APPLIED: '.count($plan).' switched, '.$done.' already switched');

        return self::SUCCESS;
    }

    private function undo(): int
    {
        if (! DB::getSchemaBuilder()->hasTable(self::BACKUP)) {
            $this->line('NOTHING TO UNDO: no backup table');

            return self::SUCCESS;
        }
        $rows = DB::table(self::BACKUP)->orderBy('prize_id')->get();
        $restored = 0;
        $already = 0;
        $writer = app(PromotionWriter::class);
        $sfs = [];
        DB::transaction(function () use ($rows, $writer, &$restored, &$already, &$sfs): void {
            foreach ($rows as $raw) {
                $b = Row::cast($raw);
                $before = Coerce::arr(json_decode(Row::str($b, 'row_json'), true));
                $id = Row::int($b, 'prize_id');
                $now = DB::table(WheelPrizes::TABLE)->where('id', $id)->first();
                $same = $now !== null && array_diff_assoc(array_map(self::text(...), $before), array_map(self::text(...), (array) $now)) === [];
                if ($same) {
                    $already++;
                } else {
                    // every column back exactly as it was (the row is still there: the step never deletes a prize)
                    $restore = $before;
                    unset($restore['id']);
                    DB::table(WheelPrizes::TABLE)->where('id', $id)->update($restore);
                    $restored++;
                    $sfs[] = Coerce::int($before['storefront_id'] ?? 0);
                }
                $template = Row::nint($b, 'template_id');
                if ($template !== null && DB::table('promotion_rules')->where('id', $template)->where('issuer', WheelPrizes::TEMPLATE_ISSUER)->exists()) {
                    $writer->delete($template);
                }
                DB::table(self::BACKUP)->where('prize_id', $id)->update(['template_id' => null]);
            }
        });
        foreach (array_unique($sfs) as $sf) {
            app(StorefrontCache::class)->flush($sf);
        }
        $this->line("UNDONE: {$restored} restored, {$already} already as before");

        return self::SUCCESS;
    }

    /** Is the prize already this step's offer? */
    private static function isDone(\stdClass $row, int $percent): bool
    {
        $template = Row::nint($row, 'offer_rule_id');

        return Row::str($row, 'type') === 'offer' && $template !== null && Row::str($row, 'label_en') === self::labelEn($percent)
            && DB::table('promotion_rules')->where('id', $template)->where('issuer', WheelPrizes::TEMPLATE_ISSUER)->exists();
    }

    /** A shop's category by its slug (top-level only when asked); null unless exactly one. */
    private static function category(int $sf, string $slug, bool $topLevel): ?int
    {
        $ids = DB::table('storefront_categories')->where('storefront_id', $sf)->where('slug', $slug)
            ->when($topLevel, fn ($q) => $q->whereNull('parent_id'))->pluck('id')->all();

        return count($ids) === 1 ? Coerce::int($ids[0]) : null;
    }

    /** A column's exact text for the before/after comparison: null stays "\0", so it never equals an empty string. */
    private static function text(mixed $value): string
    {
        return $value === null ? "\0" : (is_scalar($value) ? (string) $value : (string) json_encode($value));
    }
}
