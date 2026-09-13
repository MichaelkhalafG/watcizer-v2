<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The bounded deadlock retry wave 3.5 built for stock, extracted so the payment callbacks use the
 * SAME policy rather than a second copy of it (decision 2026-09-13).
 *
 * ── Why it moved out of `InventoryService` ───────────────────────────────────────────────────
 *
 * The callback path and the stock path lock the same rows in the same order — the order row, then
 * its products — so they deadlock against each other under concurrency, and both need the same
 * answer. A second implementation would be a second answer: the two would drift on attempts, on
 * jitter, on what counts as a deadlock, and the first time they disagreed the symptom would be a
 * lost payment rather than a failing test. `InventoryService` now delegates here, so its behaviour
 * is unchanged and there is one policy.
 *
 * ── The policy, unchanged from wave 3.5 ──────────────────────────────────────────────────────
 *
 *  - **Deadlocks only** (MariaDB 1213 / SQLSTATE 40001). A lock-wait timeout (1205) is NOT retried:
 *    it means someone is holding a lock for a long time, and hammering it makes that worse. Any
 *    other query failure is deterministic and retrying it just repeats the error.
 *  - **Bounded** at {@see self::ATTEMPTS} tries. Unbounded retry turns a deadlock storm into a
 *    request that never returns.
 *  - **Jittered, scaled by attempt** (1–10 ms, then 2–20 ms, …). Two victims of the same cycle must
 *    not wake together and reproduce it; short enough to stay inside a request.
 *  - **Never inside a caller's transaction.** If one is already open, retrying is a lie: the
 *    rollback would undo the caller's work too, and re-running only our closure would leave the
 *    outer transaction half-applied. So it delegates straight to `DB::transaction()` and lets the
 *    exception reach whoever owns the transaction.
 */
final class DeadlockRetry
{
    /** Three tries. Two is optimistic under real contention; more is a queue pretending to be a request. */
    public const ATTEMPTS = 3;

    /**
     * Run `$callback` in a transaction, retrying a genuine deadlock up to {@see self::ATTEMPTS}.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     *
     * @throws QueryException when the deadlock outlives the attempts, or the failure is not one
     */
    public static function run(Closure $callback): mixed
    {
        if (DB::transactionLevel() > 0) {
            return DB::transaction($callback);
        }

        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction($callback);
            } catch (QueryException $e) {
                if (! self::shouldRetry($e, $attempt)) {
                    throw $e;
                }
                usleep(random_int(1000, 10000) * $attempt);
            }
        }
    }

    /**
     * The loop's decision, as a pure function: retry this failure on this attempt, or give up?
     *
     * Extracted so it can be PROVEN. The loop itself cannot be tested inside the suite — every test
     * runs inside `DatabaseTransactions`, where `run()` deliberately does not retry at all, so a
     * test that counted attempts there would pass for the wrong reason and keep passing if the loop
     * broke. This function has no such problem: it is a truth table, and
     * `php artisan payment:prove-callback-race` exercises the wiring against real connections.
     */
    public static function shouldRetry(QueryException $e, int $attempt): bool
    {
        return $attempt < self::ATTEMPTS && self::isDeadlock($e);
    }

    /**
     * A genuine deadlock (MariaDB 1213 / SQLSTATE 40001), not a lock-wait timeout (1205) and not
     * any other query failure.
     */
    public static function isDeadlock(QueryException $e): bool
    {
        return self::driverCode($e) === 1213 || $e->getCode() === '40001';
    }

    /** A lock-wait timeout (MariaDB 1205): someone held a lock longer than we were willing to wait. */
    public static function isLockWaitTimeout(QueryException $e): bool
    {
        return self::driverCode($e) === 1205 || $e->getCode() === 'HY000' && str_contains($e->getMessage(), 'Lock wait timeout');
    }

    /**
     * CONTENTION of either kind — a deadlock or a lock-wait timeout.
     *
     * The distinction that matters: contention is transient and not the caller's fault, so a
     * payment lost to it must be RECORDED rather than dropped. A deterministic failure (a foreign
     * key, a bug, a broken config) is neither retried nor recorded — it will repeat on the
     * provider's next attempt, and recording it would file a bug under money.
     *
     * Only {@see self::isDeadlock()} is RETRIED. Retrying a timeout would hammer whoever is holding
     * the lock, which is the opposite of helping.
     */
    public static function isContention(QueryException $e): bool
    {
        return self::isDeadlock($e) || self::isLockWaitTimeout($e);
    }

    private static function driverCode(QueryException $e): ?int
    {
        $info = $e->errorInfo;
        $code = is_array($info) && array_key_exists(1, $info) ? $info[1] : null;

        return is_int($code) ? $code : null;
    }
}
