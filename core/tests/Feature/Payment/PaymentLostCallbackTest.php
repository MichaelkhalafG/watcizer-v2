<?php

use App\Domain\Payment\CallbackPolicy;
use App\Support\DeadlockRetry;
use App\Transform\Row;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\PaymentFixture;
use Tests\Support\T;

/*
 * The callback that the database refused to let us write — recorded instead of lost.
 *
 * A deadlocked callback used to answer 500 with its transaction rolled back, so the provider had
 * taken money and nothing in this database pointed at it. The fix is two parts: the bounded retry
 * wave 3.5 built for stock (now shared, `App\Support\DeadlockRetry`), and — when the deadlock
 * outlives the retries — writing the attempt AND a finding OUTSIDE the rolled-back transaction.
 *
 * ── Why this test is deterministic and the race is not ──────────────────────────────────────
 *
 * A real deadlock needs two connections interleaving; the suite runs on one inside
 * `DatabaseTransactions`, so `php artisan payment:prove-callback-race` exists for that and runs
 * against booted hosts. What THIS file proves is the part that must hold every single time: given a
 * deadlock, the record is written outside the transaction, it names the transaction id and the
 * exception class, and a non-deadlock failure is NOT swallowed.
 */

it('writes the attempt and a finding OUTSIDE the transaction when a deadlock outlives the retries', function () {
    $orderId = PaymentFixture::order(total: 100.0);
    $attemptsBefore = DB::table('payment_statuses')->where('order_id', $orderId)->count();

    $lines = [];
    Log::listen(function (MessageLogged $event) use (&$lines): void {
        $lines[] = $event->message.' '.json_encode($event->context);
    });

    CallbackPolicy::recordLostCallback(
        'paymob',
        $orderId,
        'pending',
        CallbackPolicy::OUTCOME_SUCCESS,
        [
            'order_id' => $orderId,
            'provider' => 'paymob',
            'method' => 'card',
            'pay_transaction_id' => 778001,
            'amount_cents' => 10000,
            'success' => 'true',
            'outcome' => CallbackPolicy::OUTCOME_SUCCESS,
        ],
        new QueryException('mariadb', 'update `orders` …', [], new RuntimeException('Deadlock found when trying to get lock')),
        ['route' => 'scoped'],
    );

    // 1. the money is on record…
    expect(DB::table('payment_statuses')->where('order_id', $orderId)->count())->toBe($attemptsBefore + 1);
    $attempt = Row::cast(T::one(DB::table('payment_statuses')->where('pay_transaction_id', 778001)));
    expect(Row::nint($attempt, 'amount_cents'))->toBe(10000)
        ->and(Row::nstr($attempt, 'outcome'))->toBe(CallbackPolicy::OUTCOME_SUCCESS);

    // 2. …a human has been handed it…
    $finding = Row::cast(T::one(
        DB::table('payment_reconciliation_findings')
            ->where('order_id', $orderId)->where('kind', CallbackPolicy::KIND_DEADLOCK)
    ));

    expect(Row::nint($finding, 'payment_status_id'))->toBe(Row::int($attempt, 'id'))
        ->and(Row::nstr($finding, 'order_status'))->toBe('pending')
        ->and(Row::nint($finding, 'amount_cents'))->toBe(10000);

    // 3. …and the detail names the two things that separate contention from a bug: the transaction
    //    id to look up in the provider's portal, and the exception class.
    $detail = T::str(Row::nstr($finding, 'detail'));
    expect($detail)->toContain('778001')
        ->and($detail)->toContain('QueryException');

    // 4. The log says the same, because an operator who is not looking at the dashboard sees only
    //    this.
    $joined = implode("\n", $lines);
    expect($joined)->toContain(CallbackPolicy::KIND_DEADLOCK)
        ->and($joined)->toContain('778001');
});

it('leaves the ORDER untouched when it records a lost callback', function () {
    // The whole point of recording rather than repairing: nobody knows what the right repair is.
    $orderId = PaymentFixture::order(total: 100.0, status: 'processing');

    CallbackPolicy::recordLostCallback(
        'paymob',
        $orderId,
        'processing',
        CallbackPolicy::OUTCOME_SUCCESS,
        [
            'order_id' => $orderId, 'provider' => 'paymob', 'pay_transaction_id' => 778002,
            'amount_cents' => 10000, 'success' => 'true', 'outcome' => CallbackPolicy::OUTCOME_SUCCESS,
        ],
        new QueryException('mariadb', 'x', [], new RuntimeException('Deadlock found')),
    );

    expect(T::str(DB::table('orders')->where('id', $orderId)->value('status')))->toBe('processing')
        ->and(DB::table('orders')->where('id', $orderId)->value('paid_via_provider'))->toBeNull();
});

it('still records the finding when even the attempt row cannot be written', function () {
    // `payment_statuses.order_id` carries a foreign key, so a callback naming an order that does
    // not exist cannot be recorded as an attempt at all. The EVENT must still not vanish.
    CallbackPolicy::recordLostCallback(
        'paymob',
        99999999,
        null,
        CallbackPolicy::OUTCOME_SUCCESS,
        [
            'order_id' => 99999999, 'provider' => 'paymob', 'pay_transaction_id' => 778003,
            'amount_cents' => 10000, 'success' => 'true', 'outcome' => CallbackPolicy::OUTCOME_SUCCESS,
        ],
        new QueryException('mariadb', 'x', [], new RuntimeException('Deadlock found')),
    );

    $finding = Row::cast(T::one(
        DB::table('payment_reconciliation_findings')->where('order_id', 99999999)
    ));

    expect(Row::str($finding, 'kind'))->toBe(CallbackPolicy::KIND_DEADLOCK)
        // No attempt id, because there is no attempt — and that is recorded honestly rather than
        // papered over.
        ->and(Row::nint($finding, 'payment_status_id'))->toBeNull();
});

/** A QueryException carrying a driver code, which is what `isDeadlock()` reads. */
function failureWithCode(string $sqlState, int $driverCode, string $message): QueryException
{
    $e = new QueryException('mariadb', 'update `orders` …', [], new RuntimeException($message));
    $info = new ReflectionProperty(QueryException::class, 'errorInfo');
    $info->setValue($e, [$sqlState, $driverCode, $message]);

    return $e;
}

it('retries a deadlock up to the bounded attempt count and nothing else — the whole truth table', function () {
    /*
     * The DECISION, not the loop. The loop cannot be tested here: every test runs inside
     * `DatabaseTransactions`, where `run()` deliberately does not retry at all (the test below
     * proves that rule), so a test that counted attempts would pass for the wrong reason and keep
     * passing if the loop broke. My first version of this file did exactly that.
     *
     * The wiring is exercised for real by `php artisan payment:prove-callback-race`, against
     * separate processes and separate connections.
     */
    $deadlock = failureWithCode('40001', 1213, 'Deadlock found when trying to get lock');
    $timeout = failureWithCode('HY000', 1205, 'Lock wait timeout exceeded');
    $foreignKey = failureWithCode('23000', 1452, 'Cannot add or update a child row');

    // A deadlock is retried on every attempt before the last…
    expect(DeadlockRetry::shouldRetry($deadlock, 1))->toBeTrue()
        ->and(DeadlockRetry::shouldRetry($deadlock, 2))->toBeTrue()
        // …and NOT on the last: three tries, then it is the caller's problem.
        ->and(DeadlockRetry::shouldRetry($deadlock, DeadlockRetry::ATTEMPTS))->toBeFalse()
        ->and(DeadlockRetry::ATTEMPTS)->toBe(3);

    // A lock-wait TIMEOUT is not retried: someone is holding a lock for a long time, and hammering
    // it makes that worse. Nor is anything deterministic — retrying repeats the same error.
    expect(DeadlockRetry::shouldRetry($timeout, 1))->toBeFalse()
        ->and(DeadlockRetry::shouldRetry($foreignKey, 1))->toBeFalse()
        ->and(DeadlockRetry::isDeadlock($deadlock))->toBeTrue()
        ->and(DeadlockRetry::isDeadlock($timeout))->toBeFalse()
        ->and(DeadlockRetry::isDeadlock($foreignKey))->toBeFalse();
});

it('classifies a deadlock by SQLSTATE too, not only by the driver code', function () {
    /*
     * The second branch of `isDeadlock()` reads `$e->getCode()`, and `QueryException` copies that
     * from its PREVIOUS exception (`QueryException.php:67`) — which for a real failure is a
     * `PDOException` whose code is the SQLSTATE STRING. My first version of this test used a
     * `RuntimeException` (integer code 0), so it proved the branch was unreachable rather than
     * that it works. Built the way PDO builds it, it fires.
     */
    $pdo = new PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found');
    $code = new ReflectionProperty(Exception::class, 'code');
    $code->setValue($pdo, '40001');

    $bySqlState = new QueryException('mariadb', 'x', [], $pdo);

    expect($bySqlState->getCode())->toBe('40001')
        // …and no driver code at all, so only the SQLSTATE branch can be answering.
        ->and($bySqlState->errorInfo)->toBeNull()
        ->and(DeadlockRetry::isDeadlock($bySqlState))->toBeTrue();
});

it('never retries inside a caller’s transaction, because the rollback is not ours to redo', function () {
    $attempts = 0;

    $deadlock = new QueryException('mariadb', 'x', [], new RuntimeException('Deadlock found'));
    $reflection = new ReflectionProperty(QueryException::class, 'errorInfo');
    $reflection->setValue($deadlock, ['40001', 1213, 'Deadlock found']);

    // The suite itself runs inside a transaction (`DatabaseTransactions`), which is exactly the
    // situation this rule is about: re-running our closure would leave the OUTER transaction
    // half-applied, so the exception is handed to whoever owns it.
    expect(DB::transactionLevel())->toBeGreaterThan(0);

    try {
        DeadlockRetry::run(function () use (&$attempts, $deadlock): void {
            $attempts++;
            throw $deadlock;
        });
    } catch (QueryException) {
        // expected
    }

    expect($attempts)->toBe(1, 'no retry may happen inside a caller’s transaction');
});
