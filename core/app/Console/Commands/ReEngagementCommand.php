<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\PriceWatch;
use App\Domain\Notifications\ReEngagement;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * reengagement — the weekly "new picks for you" e-mail (2026-10-01).
 *
 *   php artisan reengagement plan     # this week's run per active storefront + the team's preview
 *                                     # (Monday 10:00; once per ISO week)
 *   php artisan reengagement send     # queue runs whose 24 h preview window has passed, unless the
 *                                     # storefront is paused now (hourly)
 *   php artisan reengagement prices   # refresh the price-age watch (daily 02:45)
 *
 * The e-mails themselves go out through `bulk-mail:drain`, within the day's bulk budget.
 */
final class ReEngagementCommand extends Command
{
    protected $signature = 'reengagement {step : plan | send | prices}';

    protected $description = 'Plan, send and support the weekly re-engagement e-mail';

    public function handle(ReEngagement $engine): int
    {
        return match ($this->argument('step')) {
            'plan' => $this->plan($engine),
            'send' => $this->send($engine),
            'prices' => $this->prices(),
            default => $this->fail('Unknown step. Use plan, send or prices.'),
        };
    }

    private function plan(ReEngagement $engine): int
    {
        foreach (DB::table('storefronts')->where('is_active', true)->orderBy('id')->get(['id', 'code']) as $raw) {
            $sf = Row::cast($raw);
            $result = $engine->plan(Row::int($sf, 'id'));
            $this->line(sprintf('%s: %s, %d recipient(s)%s', Row::str($sf, 'code'), $result['status'], $result['recipients'], $result['run_id'] !== null ? ' (run '.$result['run_id'].')' : ''));
        }

        return self::SUCCESS;
    }

    private function send(ReEngagement $engine): int
    {
        $runs = $engine->send();
        foreach ($runs as $r) {
            $this->line("run {$r['run_id']}: {$r['status']}, {$r['queued']} e-mail(s) queued");
        }
        if ($runs === []) {
            $this->line('nothing due');
        }

        return self::SUCCESS;
    }

    private function prices(): int
    {
        $out = PriceWatch::refresh();
        $this->line("price watch: {$out['added']} new, {$out['changed']} price change(s)");

        return self::SUCCESS;
    }
}
