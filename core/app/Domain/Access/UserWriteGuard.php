<?php

declare(strict_types=1);

namespace App\Domain\Access;

use App\Domain\Inventory\StockWriteGuard;
use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use WeakMap;

/**
 * The `users` door, closed at the QUERY level as well as at the model (review 🟠-7).
 *
 * ── The hole this closes ─────────────────────────────────────────────────────────────────────
 *
 * {@see UserWrites} is described as "the ONE lock on the shared `users` table", and it was enforced
 * in exactly one place: {@see User::booted()}. That covers `$user->save()`,
 * `User::create()` and every other path through Eloquent.
 *
 * It covers nothing else. `DB::table('users')->update([...])`, `DB::statement('UPDATE users SET
 * ...')`, an `updateOrInsert`, a `DB::table('users')->delete()` — all of them write the table
 * holding every customer account and every operator login, and the model never sees them. So the
 * guarantee the docblock claims was true of one access path and silently false of two others, and
 * the failure would look like a row that changed with nothing in any log to say who changed it.
 *
 * So a `users` write that no declared reason is open for is refused here, and the builder and raw
 * SQL are answered the same way a model save is.
 *
 * ── TWO mechanisms, and the first cut had only the second (review 🟠-7) ──────────────────────
 *
 * {@see self::refuse()} — `Connection::beforeExecuting()`, registered per connection. It runs
 * BEFORE `runQueryCallback()`, so the statement is never sent. **This is the door.**
 *
 * {@see self::arm()} — a `QueryExecuted` listener, registered per dispatcher. It runs AFTER the
 * statement. **This is the net.**
 *
 * The guard shipped with the net alone, copying `StockWriteGuard`'s shape, and the honest reading
 * of that was written down here rather than discovered later: inside a transaction the throw rolls
 * the write back, and outside one it does not. The review measured the difference rather than
 * taking the comment's word for it — a `DB::table('users')->update(...)` with no transaction open
 * really changed the row, and the exception arrived afterwards to describe it. *A guard that fires
 * after the row changed is an alarm, not a door*, and `users` is the wrong table for an alarm.
 *
 * Both are kept because they miss different things. The pre-execution callback lives on ONE
 * connection object and sees only what goes through that connection's `run()`; the event listener
 * sees any connection that dispatches, including one nobody remembered to arm. What NEITHER sees:
 *
 *  • `PDO::exec()` on a handle taken from `DB::getPdo()` — it never reaches Laravel's wrapper, so
 *    no callback runs and no event is dispatched. Nothing in the application can close that.
 *  • Anything outside this process — the legacy application, a `mysql` client, a DBA. The legacy
 *    app still writes this table and must keep being able to.
 *
 * What they add over the model guard is coverage of the two paths the model cannot see, in the same
 * process, at the moment the offending line runs.
 *
 * ── Armed in PRODUCTION too, unlike StockWriteGuard ─────────────────────────────────────────
 *
 * StockWriteGuard is a development tripwire (study §4.2) because stock has a nightly reconciliation
 * to catch what the tripwire misses. `users` has none, the model guard it mirrors is armed
 * everywhere, and a census of `app/` finds **zero** legitimate builder writes to this table — every
 * one of the seven call sites is a read. So the only thing that can trip this is a bug, and a 500
 * on a bug is the correct outcome for a table holding every customer account.
 *
 * ── DELETE is refused even inside an open window ─────────────────────────────────────────────
 *
 * {@see UserWrites::REASONS} declares no reason that deletes a row, and `User::booted()` refuses a
 * delete "with no escape hatch at all". So this does too: a `DELETE` naming `users` throws whatever
 * is open, which keeps the two enforcement points from disagreeing about the one operation the
 * rule is absolute on.
 */
final class UserWriteGuard
{
    /**
     * The guarded table.
     *
     * One name, matched on a word boundary, so `core_user_roles` and `core_user_token_epochs` —
     * both core-owned and freely writable — are none of its business.
     */
    public const TABLE = 'users';

    /** The dispatcher this guard is registered on; re-armed when the application is rebuilt. */
    private static ?int $armedOn = null;

    /** Depth of an open {@see self::fixture()} window. */
    private static int $fixtures = 0;

    /**
     * A window for TEST FIXTURES, and for nothing else.
     *
     * ── Why this exists rather than a new entry in UserWrites::REASONS ──────────────────────
     *
     * Arming this guard caught eleven tests, and every one of them was planting state that the
     * LEGACY application created and core must merely cope with:
     *
     *   • a legacy bcrypt hash, so `AuthTest` can prove core signs in against it and does not
     *     re-hash it;
     *   • a `remember_token`, a column core's model has deliberately turned off — the tests exist
     *     to prove it stays untouched;
     *   • `password = NULL`, the social-only account shape;
     *   • a whole pre-existing customer row with a chosen id, for the guest-order linking rules.
     *
     * None of those has a production analogue, and none should. Declaring a reason for them in
     * {@see UserWrites::REASONS} would widen the application's stated permission to describe
     * something the application never does — which is exactly the drift that constant exists to
     * prevent. So the fixture window is a separate thing with a separate name, and
     * `UserWriteGuardTest` censuses that nothing outside `tests/` calls it.
     *
     * It does NOT lift the delete arm: a fixture has no reason to delete a `users` row either.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function fixture(callable $work): mixed
    {
        self::$fixtures++;
        try {
            return $work();
        } finally {
            self::$fixtures--;
        }
    }

    /**
     * Refuse the write BEFORE it runs, on one connection (review 🟠-7).
     *
     * ── Why this exists next to {@see self::arm()} rather than instead of it ────────────────
     *
     * `arm()` listens on `QueryExecuted`, which fires AFTER the statement. Inside a transaction
     * the throw rolls the write back and the guard behaves as a door; OUTSIDE one the row has
     * already changed and the throw only reports it. That was measured rather than reasoned: a
     * `DB::table('users')->update(...)` with no transaction open really changed the row, and the
     * exception described a fact rather than preventing it. A guard that fires after the row
     * changed is an alarm, and this table is the wrong place for an alarm.
     *
     * `Connection::run()` invokes its `beforeExecuting` callbacks before `runQueryCallback()`, so
     * throwing here means the statement is never sent. It covers `select`, `insert`, `update`,
     * `delete`, `statement`, `affectingStatement` and `unprepared`, because all of them go through
     * `run()`.
     *
     * ── BOTH are kept, deliberately ─────────────────────────────────────────────────────────
     *
     * This one is per CONNECTION and only sees statements that go through the connection's own
     * `run()`. The `QueryExecuted` listener is per DISPATCHER and sees anything that dispatches
     * the event, including a connection nobody remembered to arm. Neither sees `PDO::exec()` on a
     * handle taken out of `DB::getPdo()`, and nothing in this application can.
     *
     * Idempotent per connection: a `beforeExecuting` callback list is append-only and a
     * reconnect re-fires `ConnectionEstablished`, so without the marker a long-lived process
     * would stack a copy per reconnect and pay for all of them on every query.
     */
    public static function refuse(Connection $connection): void
    {
        self::$refusing ??= new WeakMap;
        if (isset(self::$refusing[$connection])) {
            return;
        }
        self::$refusing[$connection] = true;

        $connection->beforeExecuting(function (string $query): void {
            $verb = self::writeVerb($query);
            if ($verb === null) {
                return;
            }

            if ($verb === 'delete') {
                throw new RuntimeException(self::message('delete a row from', $query));
            }

            if (! UserWrites::permitted() && self::$fixtures === 0) {
                throw new RuntimeException(self::message($verb, $query));
            }
        });
    }

    /**
     * Connections already carrying the pre-execution callback.
     *
     * A `WeakMap` keyed on the connection OBJECT, not a name or an `spl_object_id`. The suite
     * rebuilds the application — and its connections — for every test, and `spl_object_id` is
     * reused after an object is collected: a freed connection's id landing on a fresh one would
     * make this skip the registration and leave that connection unguarded, which is the one
     * failure mode a guard must not have.
     *
     * @var WeakMap<Connection, true>|null
     */
    private static ?WeakMap $refusing = null;

    /** Forget the pre-execution registrations. For a test that needs a known state. */
    public static function forgetRefusals(): void
    {
        self::$refusing = null;
    }

    /**
     * Register the NET on the current event dispatcher — see the class note for why the door is
     * {@see self::refuse()} and this is not it.
     *
     * Idempotent per dispatcher and not per process, for the reason `StockWriteGuard::arm()`
     * records: the test suite rebuilds the application, and with it the dispatcher, for every test.
     * A plain `static bool $armed` would arm the first test's dispatcher and leave every later one
     * unguarded — a guard that stops guarding without anybody noticing.
     */
    public static function arm(): void
    {
        $root = Event::getFacadeRoot();
        $dispatcher = is_object($root) ? spl_object_id($root) : 0;
        if (self::$armedOn === $dispatcher) {
            return;
        }
        self::$armedOn = $dispatcher;

        Event::listen(function (QueryExecuted $event): void {
            $verb = self::writeVerb($event->sql);
            if ($verb === null) {
                return;
            }

            // A delete is refused whatever is open: no declared reason deletes a row, and the
            // model guard refuses it with no escape hatch.
            if ($verb === 'delete') {
                throw new RuntimeException(self::message('delete a row from', $event->sql));
            }

            if (! UserWrites::permitted() && self::$fixtures === 0) {
                throw new RuntimeException(self::message($verb, $event->sql));
            }
        });
    }

    /**
     * The write verb this statement applies to `users`, or null when it does not write it.
     *
     * The statement is NORMALISED through {@see StockWriteGuard::normalise()} rather than through a
     * second copy of that logic — stripping comments, identifier quoting and whitespace runs is one
     * question with one answer, and the five shapes that guard's review found (an unquoted name, a
     * leading comment, upper case across several lines, a multi-table `UPDATE … JOIN … SET`, an
     * alias-qualified column) are shapes a hand-written statement really takes here too.
     *
     * The COLUMN logic is deliberately not reused: stock protects named columns, and here every
     * column of the table is protected, so the question is only "does this statement write
     * `users`".
     */
    public static function writeVerb(string $sql): ?string
    {
        /*
         * ── The fast path, and why it earns its line (review 🟠-7) ───────────────────────────
         *
         * This ran only on `QueryExecuted` before, which is already a dispatched event. It now
         * also runs in `beforeExecuting()`, i.e. on EVERY statement this application makes —
         * including the transform's ~13,700 inserts and every list query. `normalise()` is five
         * `preg_replace` passes over the whole statement, and paying that to discover that a
         * `SELECT` on `catalog_products` is not a `users` write is the sort of cost that gets a
         * guard removed later for being slow.
         *
         * A statement that writes `users` must contain the literal string somewhere — identifier
         * quoting keeps it intact, and no comment can split an identifier — so this is exact
         * rather than a heuristic: a `false` here cannot be a miss. A comment that merely mentions
         * the word costs one full check and no correctness.
         */
        if (stripos($sql, self::TABLE) === false) {
            return null;
        }

        $normalised = StockWriteGuard::normalise($sql);

        if (preg_match('/^(update|insert into|replace into|delete)\s+(.*)$/s', $normalised, $m) !== 1) {
            return null;                        // SELECT, DDL, SET, SHOW: not a write
        }
        $verb = $m[1] === 'insert into' || $m[1] === 'replace into' ? 'insert into' : $m[1];
        $rest = $m[2];

        /*
         * The TABLE CLAUSE is everything between the verb and the clause that ends it, and it is
         * scanned in full rather than at its first token — so `UPDATE users u JOIN addresses a SET
         * u.email = …` and `DELETE u FROM users u JOIN …` are both caught. Scanning only the first
         * token would miss a join whichever side carried the table.
         */
        $clause = match ($verb) {
            'update' => self::upTo($rest, '/\sset\s/'),
            'delete' => self::upTo($rest, '/\swhere\s|\slimit\s|\sorder by\s/'),
            default => self::upTo($rest, '/\s*\(|\sset\s|\sselect\s|\svalues\s/'),
        };

        return self::namesTable($clause) ? $verb : null;
    }

    /** Reset for a test that needs a known state. */
    public static function disarm(): void
    {
        self::$armedOn = null;
    }

    /** The part of a fragment before the first match of $boundary, or all of it. */
    private static function upTo(string $fragment, string $boundary): string
    {
        $parts = preg_split($boundary, $fragment, 2);

        return is_array($parts) && $parts !== [] ? (string) $parts[0] : $fragment;
    }

    /**
     * Does this clause name the guarded table?
     *
     * Word-bounded on both sides, and `.` is part of the left boundary so a schema-qualified
     * `mydb.users` still matches while `core_user_roles` does not.
     */
    private static function namesTable(string $clause): bool
    {
        return preg_match(
            '/(?<![a-z0-9_])(?:[a-z0-9_]+\.)?'.preg_quote(self::TABLE, '/').'(?![a-z0-9_])/',
            $clause,
        ) === 1;
    }

    /** The refusal, reusing {@see UserWrites}' own wording so the two read as one rule. */
    private static function message(string $operation, string $sql): string
    {
        $open = UserWrites::reason();

        /*
         * The call is named WITHOUT its parentheses, deliberately. `UserWritesTest` censuses the
         * source for `UserWrites::open(` to check every call site names a declared reason, and it
         * reads text — so a string literal spelling the call in full reads as a call site with no
         * reason at all, and the census fails saying the opposite of what is true. Prose about the
         * door must not be readable as a use of it.
         */
        return "core may not {$operation} the legacy `users` table with a raw statement or the query "
            .'builder. The permitted operations are declared in App\Domain\Access\UserWrites::REASONS '
            .'and opened through UserWrites::open, which the MODEL enforces — this guard closes the '
            .'same door at the query level (review 🟠-7). '
            .($open === null ? 'No reason was open. ' : "The open reason [{$open}] does not cover this. ")
            .'SQL: '.mb_substr($sql, 0, 200);
    }
}
