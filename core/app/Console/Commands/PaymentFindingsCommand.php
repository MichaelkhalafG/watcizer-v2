<?php

namespace App\Console\Commands;

use App\Domain\Payment\CallbackPolicy;
use App\Support\Coerce;
use App\Support\ManageText;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * List the payment callbacks a human still has to judge (🔴-1's surface).
 *
 * A finding is raised when a callback must NOT move the order: it arrived for a terminal order, it
 * was a refund or a void, it declined an order that was already paid, or its amount disagreed with
 * the order. The money is on record, the order was left alone, and this is the queue that says so.
 *
 * It exists as a COMMAND as well as a dashboard banner because a finding that only appears on one
 * order's screen is a finding nobody sees: nothing makes an operator open the right order. This is
 * what a cron or a morning check can read, and its exit code is meant for that — **1 while anything
 * is unresolved**, so a scheduled run is loud by default.
 */
final class PaymentFindingsCommand extends Command
{
    protected $signature = 'payments:findings
                            {--all : include findings that have already been resolved}
                            {--order= : only this order}
                            {--quiet-exit : always exit 0, for a human reading rather than a monitor}';

    protected $description = 'List payment callbacks that need a human decision (unresolved by default).';

    public function handle(): int
    {
        $query = DB::table('payment_reconciliation_findings as f')
            ->leftJoin('orders as o', 'o.id', '=', 'f.order_id')
            ->select([
                'f.id', 'f.order_id', 'f.provider', 'f.kind', 'f.outcome', 'f.order_status',
                'f.amount_cents', 'f.detail', 'f.created_at', 'f.resolved_at', 'f.note',
                'o.order_number', 'o.status as current_status',
            ])
            ->orderByDesc('f.id');

        if (! (bool) $this->option('all')) {
            $query->whereNull('f.resolved_at');
        }
        $order = Coerce::nint($this->option('order'));
        if ($order !== null) {
            $query->where('f.order_id', $order);
        }

        $rows = [];
        foreach ($query->get() as $raw) {
            $row = Row::cast($raw);
            $minor = Row::nint($row, 'amount_cents');
            $rows[] = [
                Row::int($row, 'id'),
                Row::nstr($row, 'order_number') ?? ('#'.(Row::nint($row, 'order_id') ?? 0)),
                Row::str($row, 'kind'),
                Row::nstr($row, 'outcome') ?? '—',
                // What the order was WHEN the callback arrived, and what it is now: if they differ,
                // someone has already acted and the finding may be stale.
                (Row::nstr($row, 'order_status') ?? '—').' → '.(Row::nstr($row, 'current_status') ?? '—'),
                $minor === null ? '—' : number_format($minor / 100, 2, '.', ''),
                Row::nstr($row, 'created_at') ?? '—',
                Row::nstr($row, 'resolved_at') ?? 'OPEN',
            ];
        }

        if ($rows === []) {
            $this->info((bool) $this->option('all')
                ? 'No payment findings recorded.'
                : 'No open payment findings.');

            return self::SUCCESS;
        }

        $this->table(['id', 'order', 'kind', 'outcome', 'status when → now', 'amount', 'raised', 'resolved'], $rows);

        $open = DB::table('payment_reconciliation_findings')->whereNull('resolved_at')->count();
        $this->newLine();
        $this->warn("{$open} open finding(s). Each one means money and an order disagree.");
        $this->line('What they mean:');
        $this->line('  '.CallbackPolicy::KIND_TERMINAL.'      a callback arrived for an order that had already finished or been cancelled');
        $this->line('  '.CallbackPolicy::KIND_REVERSAL.'                  a refund or a void — the goods and the ledger need a decision');
        $this->line('  '.CallbackPolicy::KIND_FAILED_AFTER_PAID.'       a decline after the order was already paid');
        $this->line('  '.CallbackPolicy::KIND_AMOUNT.'            the provider named an amount the order does not agree with');
        $this->newLine();
        // The panel is named in the OPERATOR'S language so the sentence points at the words they
        // will actually see on the order screen.
        $panel = ManageText::t('payments.pending_reconciliations', 'المطابقات المعلّقة');
        $this->line('Clear one from the order screen ('.$panel.' on /manage/orders/{id}), which records who and why.');

        // Loud by default: a scheduled run should fail while anything is open.
        return (bool) $this->option('quiet-exit') || $open === 0 ? self::SUCCESS : self::FAILURE;
    }
}
