<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * DatabaseTransactions wraps BOTH connections: the default (clean tables) and the
     * read-only `legacy` one, so a regression in the LegacyModel guard can never write
     * permanently to a legacy table from a test.
     *
     * @var array<int, string|null>
     */
    protected array $connectionsToTransact = [null, 'legacy'];

    protected function setUp(): void
    {
        parent::setUp();

        // Safety guard: refuse to run against anything that is not a local database.
        // The database also holds the legacy tables; there is no test-only copy.
        $host = DB::connection()->getConfig('host');
        $host = is_string($host) ? $host : '';

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new RuntimeException("Tests must run against a local database; got DB host [{$host}].");
        }

        $this->withoutVite();
    }

    /**
     * The wrapping transaction must still be open when a test ends.
     *
     * ── Why this is worth six lines (2026-10-05) ──────────────────────────────
     *
     * `DatabaseTransactions` is the only thing standing between this suite and the developer's
     * database — there is no test-only copy, because the same database holds the legacy tables.
     * Application code can end that transaction by accident: a method that opens one transaction
     * and rolls back twice unwinds its own savepoint and then the suite's.
     *
     * ── The blast radius, measured rather than assumed (2026-10-05) ─────────────────
     *
     * Demonstrated end to end with a scratch probe, with the bug reintroduced:
     *
     *   • after the offending call, `transactionLevel()` really is 0 — the suite's transaction is
     *     gone;
     *   • anything that SAME test writes afterwards **survives permanently**;
     *   • a write in a LATER test does NOT survive — `DatabaseTransactions` opens a fresh
     *     transaction in each test's `setUp`, so the next test is protected.
     *
     * So the damage is bounded to the test that causes it, and the first draft of this comment —
     * "from that test onward every test writes for real" — was wrong. It is still worth failing
     * loudly: a test that silently writes to a database with no test-only copy is a test nobody
     * can trust, and the write is invisible because the test still passes.
     *
     * Two methods had this shape and both were fixed on the same day:
     * `PromotionController::preview()` and `CheckoutCompatController::addOrder()`.
     *
     * This check turns the condition into an immediate, named failure on the test that causes it.
     * It is what found the first one — as a temporary probe — and it is permanent now because the
     * next `DB::beginTransaction()` somebody writes will not have read either fix.
     *
     * `Unit` tests carry no transaction, hence the `> 0` precondition rather than an assertion
     * that one exists.
     */
    protected function tearDown(): void
    {
        /*
         * BEFORE `parent::tearDown()`, which is where `DatabaseTransactions` does its own
         * rollback: after that call the level is legitimately 0 and the check would be meaningless.
         */
        if (in_array(DatabaseTransactions::class, class_uses_recursive(static::class), true)
            && DB::transactionLevel() < 1) {
            parent::tearDown();

            throw new RuntimeException(
                'This test ended OUTSIDE the suite transaction, so anything it wrote after that '
                .'point is now permanent in the database. Something it called rolled back or committed '
                .'one level too many — look for `DB::beginTransaction()` paired with more than one '
                .'`rollBack()`/`commit()` on some path, or for DDL, which commits implicitly. '
                .'The fix is to capture `DB::transactionLevel()` on entry and unwind only to it; '
                .'see `PromotionController::preview()` and `CheckoutCompatController::addOrder()`.'
            );
        }

        parent::tearDown();
    }
}
