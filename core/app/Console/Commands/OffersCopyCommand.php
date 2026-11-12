<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Promotions\OfferCopy;
use App\Support\Coerce;
use App\Support\WriteTarget;
use Illuminate\Console\Command;

/**
 * `php artisan offers:copy --from=1 --to=2` — copy a storefront's LIVE offers to another as its
 * own independent rules (2026-11-11, Brand Fashion step 3). Details in {@see OfferCopy}.
 *
 * WRITES only with `--apply`: promotion rules (+ their conditions, rewards, scopes, storefront
 * link) and campaigns (+ their products and campaign prices) on the TARGET storefront, one activity
 * row each. A dry run prints the plan. A coded rule needs `--code=OLD=NEW` (codes are unique
 * across the whole table). Undo: delete the created rules/campaigns from the dashboard (both
 * screens delete), ids printed at the end.
 */
final class OffersCopyCommand extends Command
{
    protected $signature = 'offers:copy
        {--from=1 : source storefront id}
        {--to=2 : target storefront id}
        {--code=* : OLD=NEW for every coded rule, e.g. --code=Black1000=BFBLACK1000}
        {--apply : write (default is a dry run)}
        {--allow-remote : required to write anywhere but a local copy}';

    protected $description = 'Copy one storefront\'s live promotions and campaigns to another as its own (dry run by default)';

    public function handle(OfferCopy $copy): int
    {
        $from = Coerce::int($this->option('from'), 1);
        $to = Coerce::int($this->option('to'), 2);
        if ($from === $to) {
            $this->error('--from and --to must differ.');

            return self::FAILURE;
        }
        $codeMap = [];
        foreach (Coerce::arr($this->option('code')) as $raw) {
            $pair = Coerce::str($raw);
            [$old, $new] = array_pad(explode('=', $pair, 2), 2, '');
            if (trim($old) === '' || trim($new) === '') {
                $this->error("--code takes OLD=NEW, got [{$pair}]");

                return self::FAILURE;
            }
            $codeMap[trim($old)] = trim($new);
        }
        $apply = (bool) $this->option('apply');
        if ($apply && ! (bool) $this->option(WriteTarget::ALLOW_REMOTE)) {
            $remote = WriteTarget::remote();
            if ($remote !== []) {
                WriteTarget::refuse($this, 'offers:copy', $remote,
                    ['promotion rules and campaigns on storefront '.$to],
                    ['run it on a local copy', 'or pass --allow-remote'],
                );

                return self::FAILURE;
            }
        }

        $plan = $copy->plan($from, $to, $codeMap);

        $this->info("offers:copy {$from} → {$to} — ".($apply ? 'APPLY' : 'DRY RUN (nothing is written)'));
        $this->line('  promotion rules (live, not the wheel):');
        foreach ($plan['rules'] as $r) {
            $this->line(sprintf('    #%d %s  code %s → %s%s', Coerce::int($r['source_id']), Coerce::str($r['name']),
                Coerce::str($r['code'] ?? null, '-'), Coerce::str($r['new_code'] ?? null, '-'), Coerce::bool($r['already'] ?? false) ? '  (already on the target — skipped)' : ''));
        }
        $this->line('  campaigns (live or upcoming):');
        foreach ($plan['campaigns'] as $c) {
            $this->line(sprintf('    #%d %s [%s] %s → %s: %d products (%d with a campaign price), %d left out (not visible on the target)%s',
                Coerce::int($c['source_id']), Coerce::str($c['name']), Coerce::str($c['presentation']), Coerce::str($c['starts_at']), Coerce::str($c['ends_at']),
                Coerce::int($c['products']), Coerce::int($c['priced']), count(Coerce::arr($c['left_out'] ?? null)),
                Coerce::nstr($c['overlap'] ?? null) !== null ? '  — OVERLAPS "'.Coerce::str($c['overlap']).'" on the target, skipped' : ''));
        }
        $this->line('  settings (not copied — compare):');
        foreach ($plan['settings'] as $sf => $s) {
            $this->line(sprintf('    storefront %s: free shipping over %s, money rewards %s', (string) $sf,
                json_encode(Coerce::arr($s)['free_over'] ?? null), json_encode(Coerce::arr($s)['money_rewards'] ?? null)));
        }
        foreach ($plan['refusals'] as $refusal) {
            $this->error('  REFUSED: '.$refusal);
        }
        if (! $apply) {
            return $plan['refusals'] === [] ? self::SUCCESS : self::FAILURE;
        }
        if ($plan['refusals'] !== []) {
            return self::FAILURE;
        }
        $done = $copy->apply($plan, $to);
        $this->info('  created rules: '.implode(', ', $done['rules']).' — campaigns: '.implode(', ', $done['campaigns']));

        return self::SUCCESS;
    }
}
