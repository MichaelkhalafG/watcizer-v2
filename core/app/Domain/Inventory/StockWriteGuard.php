<?php

namespace App\Domain\Inventory;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use RuntimeException;

/**
 * A DEVELOPMENT TRIPWIRE that DETECTS a stock column being written outside
 * {@see InventoryService}. It is not, and cannot be, a prevention mechanism.
 *
 * ── What it actually does ────────────────────────────────────────────────────────────────────
 *
 * It listens on `QueryExecuted`, which fires AFTER a statement has run. When it sees a write to a
 * stock column outside an `allow()` window it throws, and because every stock write in this
 * codebase runs inside a transaction, that throw rolls the offending statement back. So in
 * practice it usually undoes what it caught — but "usually" is the honest word: a statement run
 * outside a transaction is already committed by the time the listener sees it, and the throw then
 * only reports the fact.
 *
 * ── What it cannot see, at all ───────────────────────────────────────────────────────────────
 *
 *  • **`PDO::exec()`, or any statement issued on a raw handle** from `DB::getPdo()`. Those never
 *    reach Laravel's connection wrapper, so no `QueryExecuted` event is dispatched and this
 *    listener is simply not invoked. There is no way to close that from inside the application.
 *  • **Anything outside this process**: the legacy application, the Blade dashboard, a `mysql`
 *    client, an ERP job, a DBA.
 *  • **Production**, where it is deliberately not armed (study §4.2 scopes it to non-production).
 *
 * ── So what is the actual guarantee? ─────────────────────────────────────────────────────────
 *
 * Prevention is **discipline plus reconciliation**, not this class:
 *
 *  1. `InventoryService` is the only door, and `tests/Feature/Inventory/StockWriteGuardTest.php`
 *     asserts by source census that exactly three files may open the write window.
 *  2. `inventory:verify` runs nightly and asserts `Σ quantity_delta = the stock column` for every
 *     product and bucket. THAT is what catches a drift this listener never saw, including one
 *     caused by a different process entirely, and `--fix` re-bases the ledger onto the column
 *     while leaving the drift visible in the history.
 *
 * This guard's value is that it fails a developer's run loudly and immediately, at the moment the
 * offending line is written, instead of leaving it to a reconciliation report the next night.
 * That is worth having. It is not a security boundary.
 */
final class StockWriteGuard
{
    /** Columns whose value IS the stock number. `stock` and `market_stock` are the legacy names. */
    private const COLUMNS = ['stock_express', 'stock_market', 'in_stock', 'stock', 'market_stock'];

    /** Tables that carry one. `offers` is legacy and keeps its own column (study §4.2). */
    private const TABLES = ['catalog_products', 'catalog_product_variants', 'offers'];

    private static int $depth = 0;

    /** The dispatcher this guard is currently registered on; re-armed when the app is rebuilt. */
    private static ?int $armedOn = null;

    /**
     * Run $fn with stock writes permitted. Every legitimate writer — InventoryService and the
     * transform — wraps itself in this, and nothing else may.
     *
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    public static function allow(callable $fn): mixed
    {
        self::$depth++;
        try {
            return $fn();
        } finally {
            self::$depth--;
        }
    }

    public static function permitted(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Register the listener on the CURRENT event dispatcher.
     *
     * Idempotent per dispatcher, not per process: the test suite rebuilds the application (and
     * with it the dispatcher) for every test, so a plain `static bool $armed` would arm the first
     * test's dispatcher and leave every later one unguarded — which is exactly how this guard
     * would have silently stopped guarding anything.
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
            if (self::$depth > 0) {
                return;
            }
            $tables = self::stockWriteTables($event->sql);
            if ($tables !== []) {
                throw new RuntimeException(
                    'Stock column written outside InventoryService (table '.implode(', ', $tables).'). '.
                    'Every stock mutation goes through App\Domain\Inventory\InventoryService '.
                    '(study §4.2); the transform wraps its run in StockWriteGuard::allow(). SQL: '.
                    mb_substr($event->sql, 0, 200)
                );
            }
        });
    }

    /** Reset for a test that needs the counter at a known value. */
    public static function reset(): void
    {
        self::$depth = 0;
    }

    /**
     * The first protected table a statement writes a stock column of, or null. Kept because it
     * reads better at a single call site; {@see stockWriteTables()} is the real answer.
     */
    public static function stockWriteTable(string $sql): ?string
    {
        return self::stockWriteTables($sql)[0] ?? null;
    }

    /**
     * EVERY protected table a statement writes a stock column of.
     *
     * The review found five shapes the first version missed, all of them things a hand-written
     * raw statement really does: an unquoted table name, a leading comment, upper case spread
     * over several lines, a multi-table `UPDATE … JOIN … SET`, and a column qualified by an
     * alias. The statement is therefore normalised before anything is matched, and the table
     * clause of a multi-table UPDATE is scanned in full rather than only at its first token.
     *
     * Deliberately narrow in the other direction too: the protected column is looked for in the
     * SET clause (or the INSERT column list, or the ON DUPLICATE KEY UPDATE clause) and nowhere
     * else, so `UPDATE catalog_products SET rating_avg = 1 WHERE stock_express > 0` is correctly
     * none of its business.
     *
     * @return list<string>
     */
    public static function stockWriteTables(string $sql): array
    {
        $normalised = self::normalise($sql);

        if (preg_match('/^(update|insert into|replace into)\s+(.*)$/s', $normalised, $m) !== 1) {
            return [];                                  // SELECT, DELETE, DDL: not a stock write
        }
        $verb = $m[1];
        $rest = $m[2];

        if ($verb === 'update') {
            // Everything between the verb and the first ` set ` is the table clause: one table,
            // or several joined.
            $parts = preg_split('/\sset\s/', $rest, 2);
            if (! is_array($parts) || count($parts) !== 2) {
                return [];
            }
            [$tableClause, $assignments] = $parts;

            return self::columnTouched(self::upTo($assignments, '/\swhere\s|\sorder by\s|\slimit\s/'))
                ? self::tablesIn($tableClause)
                : [];
        }

        // insert into <table> (cols…) …   |   insert into <table> set col = …
        if (preg_match('/^([a-z0-9_.]+)\s*(.*)$/s', $rest, $t) !== 1) {
            return [];
        }
        $table = self::bareTable($t[1]);
        if (! in_array($table, self::TABLES, true)) {
            return [];
        }
        $tail = $t[2];

        $touched = false;
        if (preg_match('/^\((.*?)\)/s', $tail, $cols) === 1) {
            $touched = self::columnTouched($cols[1]);           // the column list
        }
        if (! $touched && preg_match('/\sset\s(.*)$/s', $tail, $set) === 1) {
            $touched = self::columnTouched(self::upTo($set[1], '/\swhere\s/'));
        }
        if (! $touched && preg_match('/on duplicate key update\s(.*)$/s', $tail, $dup) === 1) {
            $touched = self::columnTouched($dup[1]);
        }

        return $touched ? [$table] : [];
    }

    /**
     * Lower-cased, comment-free, quote-free, single-spaced SQL.
     *
     * String literals are NOT stripped, which is a conscious trade: a literal containing the word
     * `stock_express` inside the SET clause of a protected table would produce a false positive.
     * For a tripwire, a false positive costs a developer one line of SQL to read; a false negative
     * costs a stock number that silently drifts.
     */
    public static function normalise(string $sql): string
    {
        $out = (string) preg_replace('#/\*.*?\*/#s', ' ', $sql);        // /* block comments */
        $out = (string) preg_replace('/--[^\n]*/', ' ', $out);          // -- line comments
        $out = (string) preg_replace('/#[^\n]*/', ' ', $out);           // # line comments
        $out = str_replace(['`', '"', '[', ']'], '', $out);             // identifier quoting
        $out = (string) preg_replace('/\s+/', ' ', $out);               // newlines, tabs, runs

        return trim(strtolower($out));
    }

    /** The part of a fragment before the first match of $boundary, or all of it. */
    private static function upTo(string $fragment, string $boundary): string
    {
        $parts = preg_split($boundary, $fragment, 2);

        return is_array($parts) && $parts !== [] ? (string) $parts[0] : $fragment;
    }

    /** Does this fragment ASSIGN one of the protected columns? Alias-qualified names included. */
    private static function columnTouched(string $fragment): bool
    {
        $fragment = trim($fragment);
        foreach (self::COLUMNS as $column) {
            $quoted = preg_quote($column, '/');
            if (preg_match('/(?<![a-z0-9_])(?:[a-z0-9_]+\.)?'.$quoted.'\s*=/', $fragment) === 1) {
                return true;
            }
            // An INSERT column list has no `=`; there a bare name between commas is enough.
            if (preg_match('/(?<![a-z0-9_.])'.$quoted.'(?![a-z0-9_])\s*(,|$)/', $fragment) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every protected table named anywhere in an UPDATE's table clause, so a multi-table
     * `UPDATE a JOIN b SET …` is caught whichever side carries the column.
     *
     * @return list<string>
     */
    private static function tablesIn(string $tableClause): array
    {
        $found = [];
        foreach (self::TABLES as $table) {
            if (preg_match('/(?<![a-z0-9_.])'.preg_quote($table, '/').'(?![a-z0-9_])/', $tableClause) === 1) {
                $found[] = $table;
            }
        }

        return $found;
    }

    /** `schema.table` → `table`. */
    private static function bareTable(string $identifier): string
    {
        $pos = strrpos($identifier, '.');

        return $pos === false ? $identifier : substr($identifier, $pos + 1);
    }
}
