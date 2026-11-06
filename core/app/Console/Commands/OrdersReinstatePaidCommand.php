<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Inventory\Actor;
use App\Domain\Notifications\OrderMailer;
use App\Domain\Orders\LatePayment;
use App\Domain\Payment\CallbackPolicy;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `orders:reinstate-paid` — bring back a CANCELLED order whose payment SUCCEEDED (2026-11-05).
 *
 * Written for order 000092 (Watchizer, valU, 4,445 EGP): declined once, cancelled on the spot by the
 * rule of that day, then charged two minutes later on the same payment page. The client decided to
 * ship the watch. The callback now does this by itself for every new case (`LatePayment`); this
 * command is the same code for the orders that were stranded before the fix, and for any future one
 * a person decides to bring back by hand.
 *
 * ── It WRITES only with `--apply` ────────────────────────────────────────────────────────────
 *
 * Without the flag it reads and reports: the order, the payment it found, and per line what the
 * order needs against what is on the shelf now. It names the database it is connected to in both
 * modes, before anything else, so a run against the wrong one is seen before `--apply` is typed.
 *
 * ── What `--apply` does, in ONE transaction ──────────────────────────────────────────────────
 *
 *  1. reserves every line again through the stock ledger (`order_reinstate` movements, all or
 *     nothing — `InventoryService::reinstateOrder()`);
 *  2. sets the order `cancelled → processing` with `paid_via_provider` / `paid_via_method` from the
 *     payment attempt, and writes the activity entry;
 *  3. marks that attempt as the payment (`payment_statuses.success = true` — it was recorded
 *     `false` because the order did not accept it at the time);
 *  4. resolves the order's open `success` findings, with a note naming this command;
 *  5. queues the order confirmation for the customer and the notification for the shop, and sends
 *     them after the commit (`--no-customer-mail` keeps the customer's back).
 *
 * ── What it REFUSES, writing nothing ─────────────────────────────────────────────────────────
 *
 * An order that is not cancelled; no successful payment on record, or more than one without
 * `--transaction`; a payment whose amount is not the order's total; a refund or a void recorded
 * after it (the money went back); an order a person cancelled from the dashboard, unless
 * `--cancelled-by-operator`; an order already brought back once; and — the one that matters —
 * **stock that is no longer there**: it says which line, how many are needed and how many are on
 * the shelf, and stops. It never oversells to make a payment fit.
 *
 * No Meta Purchase event is sent: the browser that paid is long gone and Meta rejects events older
 * than seven days; the callback path sends it for payments it handles as they arrive.
 */
final class OrdersReinstatePaidCommand extends Command
{
    protected $signature = 'orders:reinstate-paid
        {order : the order number as the customer sees it (000092), or #id for the row id}
        {--storefront= : the storefront code (watchizer, brandfashion) when the number exists on more than one}
        {--transaction= : the provider transaction id of the successful payment, when the order has more than one}
        {--apply : write. Without it the command only reports what it would do}
        {--cancelled-by-operator : also bring back an order a person cancelled from the dashboard}
        {--no-customer-mail : do not e-mail the customer the order confirmation}';

    protected $description = 'Bring back a cancelled order whose payment succeeded: re-reserve its stock through the ledger, record the payment, resolve the finding (dry run unless --apply)';

    public function handle(LatePayment $latePayment, OrderMailer $mailer): int
    {
        $apply = (bool) $this->option('apply');
        $connection = DB::connection();
        $this->line('Database: '.$connection->getDatabaseName().' on '.(is_scalar($host = $connection->getConfig('host')) ? (string) $host : '?')
            .' — '.($apply ? 'APPLY: this run WRITES.' : 'dry run: nothing is written.'));

        $orderId = $this->resolveOrder();
        if ($orderId === null) {
            return self::FAILURE;
        }

        $payment = $this->resolvePayment($orderId);
        $assessment = $latePayment->assess($orderId, (bool) $this->option('cancelled-by-operator'));
        $order = $assessment['order'];
        if ($order === null) {
            $this->error('The order does not exist.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->line("Order #{$order['number']} (id {$order['id']}, storefront {$order['storefront_id']}): status {$order['status']}, total {$order['total']} EGP"
            .', cancelled by '.match ($assessment['cancelled_by']) {
                'payment_failed' => 'the system for non-payment (decline, expiry or supersede)',
                'order_cancel' => 'a person, from the dashboard',
                'reinstate_cancel' => 'a person, after an earlier reinstatement',
                default => 'nothing the stock ledger recorded',
            }.'.');

        if ($payment['row'] !== null) {
            $p = $payment['row'];
            $this->line("Payment: attempt {$p['id']}, {$p['provider']}/".($p['method'] ?? '?').", transaction {$p['transaction']}, "
                .number_format($p['amount_cents'] / 100, 2, '.', '').' EGP, recorded '.$p['created_at'].'.');
        }

        $rows = [];
        foreach ($assessment['lines'] as $line) {
            $rows[] = [
                $line['line_id'],
                $line['product_id'] ?? ('offer '.$line['offer_id']),
                $line['variant_id'] ?? '—',
                mb_strimwidth($line['name'], 0, 60, '…'),
                $line['bucket'],
                $line['quantity'],
                $line['available'] ?? '—',
                ! $line['sellable'] ? 'WITHDRAWN' : ($line['enough'] ? 'in stock' : 'NOT ENOUGH'),
            ];
        }
        $this->table(['line', 'product', 'variant', 'name', 'bucket', 'needed', 'on the shelf', 'verdict'], $rows);

        if ($assessment['newer_orders'] !== []) {
            $this->warn('The same customer has newer live order(s): #'.implode(', #', $assessment['newer_orders'])
                .'. Bringing this one back is still right when it was paid — but look at the pair, so nothing is delivered or paid for twice.');
        }

        $refusal = $payment['refusal'] ?? ($assessment['ok'] ? null : LatePayment::explain((string) $assessment['reason']));
        if ($refusal !== null) {
            $this->newLine();
            $this->error('REFUSED — nothing written: '.$refusal.'.');
            if ($assessment['reason'] === LatePayment::NO_STOCK) {
                foreach ($assessment['lines'] as $line) {
                    if (! $line['enough']) {
                        $this->line("  line {$line['line_id']}: needs {$line['quantity']}, {$line['bucket']} bucket holds ".($line['available'] ?? 0)
                            ." — {$line['name']} (product {$line['product_id']}".($line['variant_id'] === null ? '' : ", variant {$line['variant_id']}").')');
                    }
                }
                $this->line('  Restock the product from the dashboard (Inventory) if the watch is physically there, then run this again; otherwise refund the customer.');
            }

            return self::FAILURE;
        }

        /** @var array{id: int, provider: string, method: string|null, transaction: string, amount_cents: int, created_at: string} $paid */
        $paid = $payment['row'];
        $findings = DB::table('payment_reconciliation_findings')->where('order_id', $orderId)
            ->whereNull('resolved_at')->where('outcome', CallbackPolicy::OUTCOME_SUCCESS)->orderBy('id')->pluck('id')->all();
        $findingIds = array_values(array_map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, $findings));
        $lines = count($assessment['lines']);

        $this->newLine();
        $this->line(($apply ? 'Doing:' : 'Would do:'));
        $this->line("  1. reserve {$lines} line(s) again through the stock ledger (reason order_reinstate)");
        $this->line("  2. order #{$order['number']}: cancelled → processing, paid via {$paid['provider']}/".($paid['method'] ?? '?'));
        $this->line("  3. mark attempt {$paid['id']} (transaction {$paid['transaction']}) as the payment");
        $this->line('  4. resolve finding(s): '.($findingIds === [] ? 'none open' : implode(', ', $findingIds)));
        $this->line('  5. e-mail '.((bool) $this->option('no-customer-mail') ? 'the shop only' : 'the customer (order confirmation) and the shop'));

        if (! $apply) {
            $this->newLine();
            $this->info('Dry run: nothing written. Run again with --apply to do it.');

            return self::SUCCESS;
        }

        $note = 'orders:reinstate-paid — payment succeeded after the cancellation, transaction '.$paid['transaction'];
        $mailIds = [];
        $result = DB::transaction(function () use ($latePayment, $mailer, $orderId, $paid, $note, $findingIds, &$mailIds): array {
            $result = $latePayment->reinstate(
                $orderId,
                $paid['provider'],
                $paid['method'],
                Actor::system(),
                $note,
                (bool) $this->option('cancelled-by-operator'),
            );
            if (! $result['ok']) {
                return $result;
            }

            DB::table('payment_statuses')->where('id', $paid['id'])->update(['success' => 'true', 'updated_at' => now()]);

            if ($findingIds !== []) {
                DB::table('payment_reconciliation_findings')->whereIn('id', $findingIds)->whereNull('resolved_at')->update([
                    'resolved_at' => now(),
                    'resolved_by' => null,
                    'note' => mb_substr('Order brought back by orders:reinstate-paid: stock reserved again, payment recorded (transaction '.$paid['transaction'].').', 0, 255),
                ]);
            }

            $mailIds = $mailer->placed($orderId, notifyCustomer: ! (bool) $this->option('no-customer-mail'));

            return $result;
        });

        if (! $result['ok']) {
            // Between the report above and the write, something changed (a unit was sold, the
            // order moved). Nothing was written.
            $this->error('REFUSED at the write — nothing written: '.LatePayment::explain((string) $result['reason']).'.');

            return self::FAILURE;
        }

        $mailer->flush($mailIds);

        $status = DB::table('orders')->where('id', $orderId)->value('status');
        $this->newLine();
        $this->info("Done. Order #{$order['number']} is now ".(is_string($status) ? $status : '?').": {$result['movements']} stock movement(s) written, "
            .count($findingIds).' finding(s) resolved, '.count($mailIds).' e-mail(s) queued.');
        $this->line('Check: php artisan payments:findings --order='.$orderId.' --all   and   php artisan inventory:verify');

        return self::SUCCESS;
    }

    /** The order's id from `000092` (+ `--storefront`) or `#12166`; prints why when it cannot tell. */
    private function resolveOrder(): ?int
    {
        $given = trim($this->argument('order'));
        if ($given === '') {
            $this->error('Name the order: its number (000092) or #id.');

            return null;
        }

        if (str_starts_with($given, '#')) {
            $id = substr($given, 1);
            $found = ctype_digit($id) ? DB::table('orders')->where('id', (int) $id)->value('id') : null;
            if (! is_numeric($found)) {
                $this->error("No order with id {$id}.");

                return null;
            }

            return (int) $found;
        }

        $query = DB::table('orders as o')->leftJoin('storefronts as s', 's.id', '=', 'o.storefront_id')->where('o.order_number', $given);
        $code = $this->option('storefront');
        if (is_string($code) && $code !== '') {
            $query->where('s.code', $code);
        }
        $matches = $query->orderBy('o.id')->get(['o.id', 's.code']);

        if ($matches->count() === 0) {
            $this->error("No order numbered {$given}".(is_string($code) && $code !== '' ? " on storefront {$code}" : '').'.');

            return null;
        }
        if ($matches->count() > 1) {
            $this->error("The number {$given} exists on more than one storefront — say which with --storefront=<code>:");
            foreach ($matches as $match) {
                $this->line('  id '.Row::int($match, 'id').' on '.(Row::nstr($match, 'code') ?? '(no storefront)'));
            }

            return null;
        }

        return Row::int($matches->first() ?? throw new \LogicException('unreachable'), 'id');
    }

    /**
     * The successful payment this run is about, or why there is not exactly one usable one.
     *
     * @return array{row: array{id: int, provider: string, method: string|null, transaction: string, amount_cents: int, created_at: string}|null, refusal: string|null}
     */
    private function resolvePayment(int $orderId): array
    {
        $query = DB::table('payment_statuses')->where('order_id', $orderId)->where('outcome', CallbackPolicy::OUTCOME_SUCCESS);
        $wanted = $this->option('transaction');
        if (is_string($wanted) && $wanted !== '') {
            $query->where('pay_transaction_id', $wanted);
        }
        $attempts = $query->orderBy('id')->get(['id', 'provider', 'method', 'pay_transaction_id', 'amount_cents', 'created_at']);

        if ($attempts->count() === 0) {
            return ['row' => null, 'refusal' => 'no successful payment is on record for this order'
                .(is_string($wanted) && $wanted !== '' ? " with transaction {$wanted}" : '')];
        }
        if ($attempts->count() > 1) {
            $list = [];
            foreach ($attempts as $attempt) {
                $list[] = (string) (Row::nstr($attempt, 'pay_transaction_id') ?? '?');
            }

            return ['row' => null, 'refusal' => 'more than one successful payment is on record ('.implode(', ', $list).') — name one with --transaction=<id>, and refund the other'];
        }

        $attempt = Row::cast($attempts->first() ?? throw new \LogicException('unreachable'));
        $row = [
            'id' => Row::int($attempt, 'id'),
            'provider' => Row::nstr($attempt, 'provider') ?? 'paymob',
            'method' => Row::nstr($attempt, 'method'),
            'transaction' => (string) (Row::nstr($attempt, 'pay_transaction_id') ?? ''),
            'amount_cents' => Row::nint($attempt, 'amount_cents') ?? 0,
            'created_at' => Row::nstr($attempt, 'created_at') ?? '',
        ];

        $total = DB::table('orders')->where('id', $orderId)->value('total_price_for_order');
        $expected = is_numeric($total) ? (int) round(((float) $total) * 100) : -1;
        if ($row['amount_cents'] !== $expected) {
            return ['row' => $row, 'refusal' => 'the payment is for '.number_format($row['amount_cents'] / 100, 2, '.', '').' EGP and the order total is '
                .(is_numeric($total) ? number_format((float) $total, 2, '.', '') : '?').' EGP — an amount that disagrees with the order is never recorded as its payment'];
        }

        $reversed = DB::table('payment_statuses')->where('order_id', $orderId)->where('id', '>', $row['id'])
            ->whereIn('outcome', [CallbackPolicy::OUTCOME_REFUNDED, CallbackPolicy::OUTCOME_VOIDED])->exists();
        if ($reversed) {
            return ['row' => $row, 'refusal' => 'a refund or a void is recorded after this payment — the money went back, so there is nothing to ship against'];
        }

        return ['row' => $row, 'refusal' => null];
    }
}
