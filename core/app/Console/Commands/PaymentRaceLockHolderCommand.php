<?php

namespace App\Console\Commands;

use App\Support\Coerce;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hold one order row's write lock, from its own process, across the moment the callbacks fire.
 *
 * Fault injection for `payment:prove-callback-race --hold-lock=MS`. The callbacks all want this
 * row, so while it is held they queue on it and reach MariaDB's `innodb_lock_wait_timeout` — which
 * the retry deliberately does NOT absorb, because hammering whoever holds a lock makes their
 * problem worse. That is the only reliable way to exercise the lost-callback recorder end to end:
 * left to chance, the retry swallows every deadlock and the recorder never runs.
 *
 * Its own process for the same reason the workers are: a lock held inside the prover's own
 * connection would not block the prover's own reads.
 */
final class PaymentRaceLockHolderCommand extends Command
{
    protected $signature = 'payment:race-lock-holder
        {--order= : orders.id to lock}
        {--ms=2000 : how long to hold it}
        {--at= : unix timestamp (float) to take the lock at}';

    protected $description = 'Internal: hold an order row locked for payment:prove-callback-race';

    protected $hidden = true;

    public function handle(): int
    {
        $orderId = Coerce::int($this->option('order'));
        $ms = max(1, Coerce::int($this->option('ms')));
        $at = (float) Coerce::str($this->option('at'));

        // Take the lock slightly BEFORE the workers fire, so they meet it already held.
        $lockAt = $at > 0.0 ? $at - 0.25 : microtime(true);
        while (microtime(true) < $lockAt) {
            // spin
        }

        DB::beginTransaction();
        try {
            DB::table('orders')->where('id', $orderId)->lockForUpdate()->value('id');
            usleep($ms * 1000);
        } finally {
            // Always let go: a held lock outliving this process would stall the next round.
            DB::rollBack();
        }

        $this->line('HELD '.$ms.'ms');

        return self::SUCCESS;
    }
}
