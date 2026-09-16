<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Actor;
use App\Domain\Inventory\InventoryService;
use App\Domain\Payment\CallbackPolicy;
use App\Models\Storefront\StorefrontPaymentMethod;
use App\Models\Storefront\StorefrontPaymentProvider;
use App\Support\Coerce;
use App\Support\WriteTarget;
use App\Transform\Row;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Two processes, one order, callbacks racing — and the proof that a lost callback is now RECORDED.
 *
 * ── Why a command and not a test ─────────────────────────────────────────────────────────────
 *
 * A deadlock needs two real connections interleaving inside two real transactions. The suite runs
 * inside `DatabaseTransactions` on a single connection, so it cannot produce one: this is the same
 * reason wave 3.5's `inventory:prove-*` commands exist beside the suite rather than inside it.
 *
 * ── What it does ─────────────────────────────────────────────────────────────────────────────
 *
 * Builds one order with reserved stock, then fires N worker processes at the SAME order's callback
 * URL at the same moment, each with its own transaction id. Every worker's callback wants the order
 * row and then the product rows, in that order — the same rows `InventoryService` takes — so under
 * contention MariaDB picks victims and rolls them back.
 *
 * The property being proved is **not** "no deadlock happens". It is: **no callback disappears.**
 * Every worker that got a 2xx/3xx answer must be findable afterwards, either as a recorded attempt
 * or as a `callback_lost_to_deadlock` finding naming its transaction id. Before the retry and the
 * outside-the-rollback recorder, a deadlocked callback answered 500 and left nothing at all — the
 * provider had taken money that nothing in this database pointed at.
 *
 * Each worker runs the request through the HTTP kernel in its OWN process, so no web server is
 * needed and — more importantly — nothing serialises them. The first version drove a booted
 * `php -S`, which is single-threaded: it answered the callbacks one at a time, so the race could
 * not race, and at sixteen workers it refused connections and the prover counted hard failures.
 *
 * Usage (no server required):
 *
 *     php artisan payment:prove-callback-race --workers=8 --rounds=12
 */
final class PaymentProveCallbackRaceCommand extends Command
{
    protected $signature = 'payment:prove-callback-race
                            {--path=/api/pay/watchizer/paymob/callback : the callback route to race}
                            {--workers=8 : concurrent callbacks per round}
                            {--rounds=10 : rounds to run, or until a deadlock is observed}
                            {--stop-on-deadlock : stop as soon as one round produced a recorded loss}
                            {--hold-lock=0 : hold the order row locked for this many ms, to force contention the retry cannot absorb}
                            {--keep : leave the probe orders, attempts and findings behind for inspection}
                            {--allow-remote : Permit a target that is NOT a local copy. This probe WRITES: it creates real orders, order_items and payment attempts}';

    protected $description = 'Race concurrent payment callbacks at one order and prove none is lost.';

    public function handle(): int
    {
        /*
         * ── this probe WRITES, so it asks the same question the harness does ────────────────
         *
         * Every round creates a real order with its line and races callbacks at it, writing
         * payment attempts and findings. `--keep` leaves all of it behind ON PURPOSE, and a run
         * that dies mid-round leaves it behind anyway — real rows in the shared commerce tables.
         *
         * Found by the 2026-09-15 sweep as the fourth of four tools that write as a side effect of
         * being run, all invisible for the same reason: none had ever been run. The guard is
         * `App\Support\WriteTarget`, shared with `compat:diff` and the release probe.
         */
        if ($this->refuseIfTargetIsRemote()) {
            return self::INVALID;
        }

        $base = Coerce::str($this->option('path'));
        $workers = max(2, Coerce::int($this->option('workers')));
        $rounds = max(1, Coerce::int($this->option('rounds')));

        $secret = 'race-hmac-'.bin2hex(random_bytes(4));

        /*
         * ── this command CHANGES which handler serves the live callback URL ────────────────
         *
         * A (storefront 1, paymob) contract WITH credentials is precisely the condition
         * `PaymentCallbackController::aliasIsLive()` reads, so creating one here performs the
         * switch-night cutover as a side effect: `/api/callback_payment` stops falling through to
         * wave-3's handler. The first version of this command left that behind and eight wave-3
         * tests started answering 403 to a valid signature.
         *
         * So the previous state is snapshotted and restored in the `finally` below, always.
         */
        $restore = $this->snapshotContract();
        $this->probeOrders = [];

        try {
            return $this->race($base, $workers, $rounds, $secret);
        } finally {
            $this->restoreContract($restore);
            $this->line('contract state restored (the live callback URL is back on whichever handler it was on).');

            if ((bool) $this->option('keep')) {
                $this->warn('--keep: '.count($this->probeOrders).' probe order(s) left behind, with their attempts and findings.');
            } else {
                $this->cleanUp();
            }
        }
    }

    /** One round per `--rounds`, each against its own probe order. */
    private function race(string $base, int $workers, int $rounds, string $secret): int
    {
        $this->prepareContract($secret);

        $totalSent = 0;
        $totalRecorded = 0;
        $totalLostFindings = 0;
        $deadlockSeen = false;

        for ($round = 1; $round <= $rounds; $round++) {
            $order = $this->prepareOrder();
            $transactionBase = random_int(100000000, 999999999);

            $before = [
                'attempts' => DB::table('payment_statuses')->where('order_id', $order['id'])->count(),
                'findings' => DB::table('payment_reconciliation_findings')->where('order_id', $order['id'])->count(),
            ];

            /*
             * Every worker is started first and told to FIRE AT THE SAME INSTANT (wave 3.5's
             * trick): starting processes and hoping measures process startup, not contention.
             */
            $fireAt = microtime(true) + 1.5;

            /*
             * Optional fault injection: a separate process takes the order row's write lock and
             * holds it across the moment the callbacks fire. The callbacks then wait on it and hit
             * MariaDB's `innodb_lock_wait_timeout` — contention the retry deliberately does NOT
             * absorb (hammering a lock holder makes it worse), so the recorder is exercised.
             *
             * This is how a lost callback is demonstrated end to end rather than only in the unit
             * test: without it the retry swallows every deadlock and the recorder never runs.
             */
            $holdMs = Coerce::int($this->option('hold-lock'));
            $holder = null;
            if ($holdMs > 0) {
                $holder = Process::timeout(120)->start([
                    PHP_BINARY, base_path('artisan'), 'payment:race-lock-holder',
                    '--order='.$order['id'], '--ms='.$holdMs, '--at='.$fireAt,
                ]);
            }

            $pending = [];
            for ($worker = 0; $worker < $workers; $worker++) {
                $url = $base.'?'.http_build_query(
                    self::payload($order['number'], $order['amountMinor'], $secret, $transactionBase + $worker)
                );
                $pending[] = Process::timeout(60)->start([
                    PHP_BINARY, base_path('artisan'), 'payment:race-worker',
                    '--url='.$url, '--at='.$fireAt,
                ]);
            }

            $codes = [];
            foreach ($pending as $process) {
                $result = $process->wait();
                $codes[] = trim($result->output());
            }
            $holder?->wait();

            $attempts = DB::table('payment_statuses')->where('order_id', $order['id'])->count() - $before['attempts'];
            $findings = DB::table('payment_reconciliation_findings')
                ->where('order_id', $order['id'])
                ->where('kind', CallbackPolicy::KIND_DEADLOCK)
                ->count() - 0;

            $answered = 0;
            $failed = 0;
            foreach ($codes as $code) {
                if (preg_match('/^(2|3)\d\d$/', $code) === 1) {
                    $answered++;
                } else {
                    $failed++;
                }
            }

            $totalSent += $workers;
            $totalRecorded += $attempts;
            $totalLostFindings += $findings;

            /*
             * A callback that answered but left NOTHING is the defect. A callback that hard-failed
             * is allowed to leave nothing — it is a 500 the provider will retry, and that is the
             * pre-existing behaviour for a deterministic error.
             */
            $lost = $answered - $attempts - $findings;

            $this->line(sprintf(
                'round %2d: sent %d, answered %d, hard-failed %d | attempts recorded %d, lost-callback findings %d%s',
                $round, $workers, $answered, $failed, $attempts, $findings,
                $lost > 0 ? '  <-- '.$lost.' UNACCOUNTED' : ''
            ));

            if ($lost > 0) {
                $this->error('A callback was answered and left no trace. That is the defect this command exists to catch.');

                return self::FAILURE;
            }

            if ($findings > 0) {
                $deadlockSeen = true;
                $this->info('  a deadlock outlived the retries and was RECORDED — the case this proves:');
                foreach (
                    DB::table('payment_reconciliation_findings')
                        ->where('order_id', $order['id'])
                        ->where('kind', CallbackPolicy::KIND_DEADLOCK)
                        ->get(['id', 'outcome', 'order_status', 'amount_cents', 'detail']) as $raw
                ) {
                    $row = Row::cast($raw);
                    $this->line('   finding #'.Row::int($row, 'id').' '.Row::nstr($row, 'detail'));
                }

                if ((bool) $this->option('stop-on-deadlock')) {
                    break;
                }
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'sent %d callbacks; %d recorded as attempts; %d recorded as lost-callback findings; 0 unaccounted.',
            $totalSent, $totalRecorded, $totalLostFindings
        ));

        if (! $deadlockSeen) {
            $this->warn('No deadlock outlived the retries in this run — the retry absorbed them all.');
            $this->line('That is the good outcome, but it does not exercise the recorder. Raise --workers or --rounds');
            $this->line('to force one, or read `PaymentLostCallbackTest` which proves the recorder directly.');
        }

        return self::SUCCESS;
    }

    /**
     * The probe orders this run created, so cleanup removes exactly those and nothing else.
     *
     * @var list<int>
     */
    private array $probeOrders = [];

    /**
     * Delete what THIS run made, and say what went.
     *
     * Never a blanket delete: only the ids this process collected. The orders are fabricated (an
     * `RC…` number, a probe guest, no customer), which is why removing them is right by default —
     * the compat harness's orders are real checkouts and its cleanup must be opt-in instead.
     */
    private function cleanUp(): void
    {
        if ($this->probeOrders === []) {
            return;
        }

        /*
         * ── the ledger is RELEASED, never deleted ────────────────────────────────────────────
         *
         * The first version of this cleanup deleted the movements along with the order. That looked
         * tidy and was wrong: a movement is the RECORD of a column that already changed, so
         * deleting it leaves `stock_express` decremented with nothing accounting for it —
         * `inventory:verify` reported eleven drifted ledgers, and the repair was a full rebuild.
         *
         * So each probe order is released through the service (which appends a compensating
         * movement and restores the column, exactly once), and the movements STAY. They net to zero
         * and reference an order id that no longer exists, which `inventory_movements` tolerates —
         * it has no foreign key to `orders` precisely because that table is shared.
         */
        $service = app(InventoryService::class);
        $released = 0;
        foreach ($this->probeOrders as $orderId) {
            if (! $service->isReleased($orderId) && $service->isCommitted($orderId)) {
                $service->releaseOrder($orderId, 'order_cancel', Actor::system(), 1, 'race probe cleanup');
                $released++;
            }
        }

        $findings = DB::table('payment_reconciliation_findings')->whereIn('order_id', $this->probeOrders)->delete();
        $attempts = DB::table('payment_statuses')->whereIn('order_id', $this->probeOrders)->delete();
        $items = DB::table('order_items')->whereIn('order_id', $this->probeOrders)->delete();
        $orders = DB::table('orders')->whereIn('id', $this->probeOrders)->delete();

        $this->line(sprintf(
            'cleaned up: %d order(s), %d item(s), %d attempt(s), %d finding(s); %d order(s) released back to stock. '
            .'Ledger movements KEPT (deleting them would leave the stock columns short). Use --keep to retain everything.',
            $orders, $items, $attempts, $findings, $released
        ));

        /*
         * The kept movements are real ledger rows the transform did not write, and wave 3's
         * baseline guard refuses to re-base over those — so every test that invokes `core:transform`
         * fails with exit 1 until the clean tables are rebuilt. Same rule, same reason as after a
         * compat-harness run (rehearsal protocol item 11).
         */
        $this->warn('Rebuild before running the suite: core:drop-clean --force && migrate --force && core:transform --force');
        $this->line('(this run left compensating ledger movements, and the transform refuses to re-base over movements it did not write)');
    }

    /**
     * What the (storefront 1, paymob) contract looked like before this command touched it.
     *
     * @return array<string, mixed>|null
     */
    private function snapshotContract(): ?array
    {
        $row = DB::table('storefront_payment_providers')
            ->where('storefront_id', 1)->where('provider', 'paymob')->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $out */
        $out = (array) $row;

        return $out;
    }

    /**
     * Put the contract back exactly as it was — including "there was none".
     *
     * @param  array<string, mixed>|null  $restore
     */
    private function restoreContract(?array $restore): void
    {
        $id = DB::table('storefront_payment_providers')
            ->where('storefront_id', 1)->where('provider', 'paymob')->value('id');

        if ($restore === null) {
            // There was no contract before: remove the one this command made, and its methods with
            // it (the FK cascades, but the delete is explicit so the intent is readable).
            if ($id !== null) {
                DB::table('storefront_payment_methods')->where('storefront_payment_provider_id', $id)->delete();
                DB::table('storefront_payment_providers')->where('id', $id)->delete();
            }

            return;
        }

        // There WAS one: restore its columns verbatim, credentials included (they are the encrypted
        // ciphertext exactly as it was stored, so nothing is re-encrypted or re-read).
        DB::table('storefront_payment_providers')
            ->where('storefront_id', 1)->where('provider', 'paymob')
            ->update([
                'is_enabled' => $restore['is_enabled'] ?? false,
                'credentials' => $restore['credentials'] ?? null,
                'settings' => $restore['settings'] ?? null,
                'updated_at' => $restore['updated_at'] ?? now(),
            ]);
    }

    /**
     * A Paymob contract with a known secret, so the workers can sign genuinely.
     *
     * Written through the MODEL, not through `DB::table()`: `credentials` is an `encrypted:array`
     * cast, i.e. `encrypt(json_encode($value))`. The first version of this command used
     * `encrypt($array)` on the raw table, which stores a PHP-SERIALIZED payload — the cast then
     * decrypts it, fails to `json_decode` it, and reads NULL. Every worker got a 404 for
     * "contract holds no credentials" and the race measured nothing.
     */
    private function prepareContract(string $secret): void
    {
        $provider = StorefrontPaymentProvider::query()->updateOrCreate(
            ['storefront_id' => 1, 'provider' => 'paymob'],
            [
                'is_enabled' => true,
                'credentials' => ['secret_key' => 'race-secret', 'public_key' => 'race-public', 'hmac_secret' => $secret],
                'settings' => null,
            ],
        );

        StorefrontPaymentMethod::query()->updateOrCreate(
            ['storefront_payment_provider_id' => $provider->getAttribute('id'), 'method' => 'card'],
            ['integration_id' => '4001', 'icon' => null, 'is_enabled' => true, 'sort' => 0, 'settings' => null],
        );

        // Read it back through the cast: if this is not an array, the workers cannot sign anything
        // and the run would silently prove nothing.
        $readBack = StorefrontPaymentProvider::query()
            ->where('storefront_id', 1)->where('provider', 'paymob')->firstOrFail()
            ->getAttribute('credentials');

        if (! is_array($readBack) || ($readBack['hmac_secret'] ?? null) !== $secret) {
            throw new RuntimeException('The contract credentials did not round-trip through the cast; the race would prove nothing.');
        }
    }

    /** @return array{id: int, number: string, amountMinor: int} */
    private function prepareOrder(): array
    {
        /*
         * The product id must exist in the LEGACY `products` table too: `order_items.product_id`
         * carries a foreign key to it (shared commerce table). The transform preserves ids, so a
         * join is the honest way to guarantee it rather than assuming the ranges line up — which is
         * what the first version of this command did, and it died on the constraint.
         */
        $candidate = DB::table('catalog_products as cp')
            ->join('products as p', 'p.id', '=', 'cp.id')
            ->whereNull('cp.deleted_at')->where('cp.stock_express', '>=', 1)
            ->orderByDesc('cp.stock_express')
            ->first(['cp.id']);

        /*
         * No silent fallback. The first version defaulted to `id => 0` when nothing matched, and
         * since it also asked for `stock_express >= 50` on a catalogue whose maximum is 5, every
         * run died on a foreign key instead of saying "there is no product to race over".
         */
        if ($candidate === null) {
            throw new RuntimeException(
                'No product with stock exists in BOTH catalog_products and legacy products. '
                .'Rebuild the clean tables (core:drop-clean → migrate → core:transform) and retry.'
            );
        }

        $product = Row::cast($candidate);

        $addressId = Coerce::nint(DB::table('addresses')->orderBy('id')->value('id'));
        $number = 'RC'.random_int(1000000, 9999999);

        $orderId = (int) DB::table('orders')->insertGetId([
            'user_id' => null,
            'address_id' => $addressId,
            'storefront_id' => 1,
            'total_price_for_order' => '100.00',
            'payment_method' => 'card',
            'order_number' => $number,
            'status' => 'pending',
            'guest_name' => 'race probe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'product_id' => Row::int($product, 'id'),
            'offer_id' => null,
            'quantity' => 1,
            'piece_price' => '100.00',
            'total_price' => '100.00',
            'type_stock' => 'Express',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(InventoryService::class)->commitOrder($orderId);

        $this->probeOrders[] = $orderId;

        return ['id' => $orderId, 'number' => $number, 'amountMinor' => 10000];
    }

    /**
     * A genuinely signed Paymob payload. Half the workers decline, so the race includes the
     * cancel+release path — the one that takes the product locks and therefore deadlocks.
     *
     * @return array<string, string>
     */
    private static function payload(string $reference, int $amountMinor, string $secret, int $transactionId): array
    {
        $payload = [
            'amount_cents' => (string) $amountMinor,
            'created_at' => '2026-09-13T10:00:00.000000',
            'currency' => 'EGP',
            'error_occured' => 'false',
            'has_parent_transaction' => 'false',
            'id' => (string) $transactionId,
            'integration_id' => '4001',
            'is_3d_secure' => 'true',
            'is_auth' => 'false',
            'is_capture' => 'false',
            'is_refunded' => 'false',
            'is_standalone_payment' => 'true',
            'is_voided' => 'false',
            'order' => (string) $transactionId,
            'owner' => '12345',
            'pending' => 'false',
            'source_data_pan' => '2346',
            'source_data_sub_type' => 'MasterCard',
            'source_data_type' => 'card',
            'success' => $transactionId % 2 === 0 ? 'true' : 'false',
            'merchant_order_id' => $reference,
        ];

        $fields = [
            'amount_cents', 'created_at', 'currency', 'error_occured', 'has_parent_transaction', 'id',
            'integration_id', 'is_3d_secure', 'is_auth', 'is_capture', 'is_refunded',
            'is_standalone_payment', 'is_voided', 'order', 'owner', 'pending', 'source_data_pan',
            'source_data_sub_type', 'source_data_type', 'success',
        ];

        // Every field in the list above is set by construction, so no `??` — PHPStan is right
        // that a fallback here would be dead.
        $concatenated = '';
        foreach ($fields as $field) {
            $concatenated .= Coerce::str($payload[$field]);
        }
        $payload['hmac'] = hash_hmac('sha512', $concatenated, $secret);

        return $payload;
    }

    /** True when this probe must not run, because it would write to something that is not a copy. */
    private function refuseIfTargetIsRemote(): bool
    {
        $remote = WriteTarget::remote();
        if ($remote === [] || $this->option(WriteTarget::ALLOW_REMOTE) === true) {
            return false;
        }

        return WriteTarget::refuse($this, 'this probe', $remote, [
            'a real order and its order_items per round',
            'a payment_statuses attempt per racing worker, and the findings they produce',
            'all of it left behind when --keep is passed, or when a round dies mid-race',
        ], [
            'run it against a LOCAL COPY — the only place a probe that manufactures orders belongs;',
            'or, deliberately, with --allow-remote, knowing --keep and a crash both leave rows.',
        ]);
    }
}
